<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use DomainException;
use OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin;
use OCA\DAV\CalDAV\EmbeddedCalDavServer;
use OCA\DeckCalendarBridge\Service\SilentDavSapi;
use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/** Original-owner lookup + ETag-conditional native DAV/Scheduling, no SMTP. */
final class NativeCalendar implements CalendarPort {
    public function __construct(private IDBConnection $db,private IUserManager $users,
        private IUserSession $session,private IManager $manager,private RuleRepository $rules) {}

    private function owner(array $r): string {
        if (!preg_match('~^principals/users/([A-Za-z0-9_.@-]{1,128})$~D',$r['principal'],$m)
            || str_contains($m[1],'..')
            || !preg_match('/^[A-Za-z0-9_.~%-]{1,128}$/D',$r['calendar_uri'])
            || str_contains($r['calendar_uri'],'..')
            || !preg_match('/^[A-Za-z0-9_.%-]{1,200}\.ics$/D',$r['object_uri'])
            || str_contains($r['object_uri'],'..')
            || !preg_match('/^dcb-[a-f0-9]{64}@deck-calendar-bridge$/D',$r['uid'])) {
            throw new DomainException('unsafe_original_identity');
        }
        $user=$this->users->get($m[1]);
        if ($user===null || !$user->isEnabled()) throw new DomainException('organizer_unavailable');
        return $m[1];
    }
    public function organizerEmail(string $principal): string {
        if (!str_starts_with($principal,'principals/users/')) throw new DomainException('invalid_organizer');
        $u=$this->users->get(substr($principal,17)); $email=$u?->getEMailAddress();
        if ($u===null || !$u->isEnabled() || !is_string($email) || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('organizer_email_missing');
        }
        return $email;
    }
    public function read(array $r): array {
        $this->owner($r);
        $q=$this->db->getQueryBuilder();
        $cal=$q->select('id','deleted_at')->from('calendars')
            ->where($q->expr()->eq('principaluri',$q->createNamedParameter($r['principal'])))
            ->andWhere($q->expr()->eq('uri',$q->createNamedParameter($r['calendar_uri'])))
            ->executeQuery()->fetchAssociative();
        if ($cal===false || $cal['deleted_at']!==null) throw new DomainException('original_calendar_unavailable');
        $q=$this->db->getQueryBuilder();
        $rows=$q->select('id','uri','uid','etag','calendardata','deleted_at')->from('calendarobjects')
            ->where($q->expr()->eq('calendarid',$q->createNamedParameter((int)$cal['id'],IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->orX(
                $q->expr()->eq('uid',$q->createNamedParameter($r['uid'])),
                $q->expr()->eq('uri',$q->createNamedParameter($r['object_uri']))
            ))->executeQuery()->fetchAllAssociative();
        if (count($rows)>1) throw new DomainException('ambiguous_original_object');
        if ($rows!==[]) {
            $o=$rows[0];
            if ($o['uid']!==$r['uid']) throw new DomainException('original_moved_or_replaced');
            if (isset($r['data']['object_id']) && $r['data']['object_id']!==(int)$o['id']) {
                throw new DomainException('original_database_identity_changed');
            }
            // NC35 frees the original filename by renaming it to *-deleted.ics
            // inside the SAME row/calendar/UID when moved into the trashbin.
            // This is deletion, not a client move to another calendar object.
            $trashUri=substr($r['object_uri'],0,-4).'-deleted.ics';
            if ($o['deleted_at']!==null && in_array($o['uri'],[$r['object_uri'],$trashUri],true)) {
                return ['status'=>'trashed','object_id'=>(int)$o['id']];
            }
            if ($o['uri']!==$r['object_uri']) throw new DomainException('original_moved_or_replaced');
            $body=is_resource($o['calendardata'])?stream_get_contents($o['calendardata']):$o['calendardata'];
            return ['status'=>'present','body'=>$body,'etag'=>'"'.$o['etag'].'"','object_id'=>(int)$o['id']];
        }
        // A move or a same-UID copy in another organizer collection cannot be
        // mistaken for authoritative proof of a manual source deletion.
        $q=$this->db->getQueryBuilder();
        $elsewhere=$q->select('o.id')->from('calendarobjects','o')
            ->innerJoin('o','calendars','c','c.id = o.calendarid')
            ->where($q->expr()->eq('c.principaluri',$q->createNamedParameter($r['principal'])))
            ->andWhere($q->expr()->eq('o.uid',$q->createNamedParameter($r['uid'])))
            ->andWhere($q->expr()->isNull('o.deleted_at'))->setMaxResults(1)->executeQuery()->fetchOne();
        if ($elsewhere!==false) throw new DomainException('original_uid_found_elsewhere');
        return ['status'=>'absent'];
    }
    public function create(array $r,string $body): void { $this->write('PUT',$r,$body,null,true); }
    public function update(array $r,string $etag,string $body): void { $this->write('PUT',$r,$body,$etag,false); }
    public function delete(array $r,string $etag): void { $this->write('DELETE',$r,null,$etag,false); }

    private function write(string $method,array $r,?string $body,?string $etag,bool $create): void {
        $uid=$this->owner($r);
        $attempt=$r['data']['attempt']??[]; $current=null;
        foreach ($this->rules->read()['rules'] as $rule) if ($rule['id']===$r['rule_id']) $current=$rule;
        if ($method==='PUT') {
            if ($current===null || !$current['enabled'] || !Planning::binding($r,$current)
                || ($attempt['rule_hash']??'')!==hash('sha256',json_encode($current,JSON_THROW_ON_ERROR))) {
                throw new DomainException('rule_changed_retry');
            }
        } else {
            $currentHash=$current===null?null:hash('sha256',json_encode($current,JSON_THROW_ON_ERROR));
            if (($attempt['kind']??'')!=='delete'
                || (($attempt['cause']??'')!=='card_deleted'
                    && (!array_key_exists('rule_hash',$attempt) || $attempt['rule_hash']!==$currentHash
                        || ($current!==null && !$current['enabled'])))) {
                // A removed rule may have been restored, paused or changed
                // since planning. Reconcile that intent before any CANCEL.
                throw new DomainException('delete_authorization_changed');
            }
        }
        if (!$create && (!is_string($etag)||!preg_match('/^"[\x21\x23-\x7e]{1,255}"$/D',$etag))) {
            throw new DomainException('strong_etag_required');
        }
        $previous=$this->session->getUser();
        try {
            // Volatile only, restored in finally even in an administrative
            // request. It never logs in the organizer or alters credentials.
            $this->session->setVolatileActiveUser($this->users->get($uid));
            // NC35 CalendarImpl maps DAV {write} to CREATE|UPDATE, not the
            // generic PERMISSION_DELETE bit. Object deletion is authorized by
            // the native DAV unbind privilege below; don't falsely reject an
            // owner merely because the discovery mask omits DELETE.
            $required=$create?Constants::PERMISSION_CREATE:Constants::PERMISSION_UPDATE;
            $eligible=[];
            foreach ($this->manager->getCalendarsForPrincipal($r['principal'],[$r['calendar_uri']]) as $calendar) {
                if (!$calendar->isDeleted() && $calendar->getUri()===$r['calendar_uri']
                    && ($calendar->getPermissions() & $required)!==0) $eligible[]=$calendar;
            }
            if (count($eligible)!==1) throw new DomainException('original_calendar_permission_denied');
            $embedded=new EmbeddedCalDavServer(false); $server=$embedded->getServer();
            $auth=$server->getPlugin('auth');
            if (!$auth instanceof CustomPrincipalPlugin) throw new DomainException('dav_auth_unavailable');
            $auth->setCurrentPrincipal($r['principal']);
            $path='calendars/'.rawurlencode($uid).'/'.rawurlencode($r['calendar_uri']).'/'.rawurlencode($r['object_uri']);
            $schedule=$server->getPlugin('caldav-schedule');
            if ($schedule===null || !is_callable([$schedule,'setPathOfCalendarObjectChange'])) {
                throw new DomainException('dav_scheduling_unavailable');
            }
            $schedule->setPathOfCalendarObjectChange($path);
            $headers=['Content-Type'=>'text/calendar; charset=utf-8'];
            if ($create) $headers['If-None-Match']='*'; else $headers['If-Match']=$etag;
            $request=new Request($method,$server->getBaseUri().$path,$headers,$body);
            $request->setBaseUrl($server->getBaseUri()); $response=new Response();
            $server->httpRequest=$request; $server->httpResponse=$response; $server->sapi=new SilentDavSapi();
            try { $server->invokeMethod($request,$response,false); }
            catch (\Sabre\DAV\Exception $e) {
                $code=$e->getHTTPCode();
                throw new DomainException(in_array($code,[409,412,423],true)
                    ?'dav_version_conflict_retry':'dav_http_'.$code,0,$e);
            }
            $status=$response->getStatus();
            if (in_array($status,[409,412,423],true)) throw new DomainException('dav_version_conflict_retry');
            if (!in_array($status,[200,201,204],true)) throw new DomainException('dav_http_'.$status);
        } finally { $this->session->setVolatileActiveUser($previous); }
    }
}
