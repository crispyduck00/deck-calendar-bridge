<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Controller;

use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCA\DeckCalendarBridge\Sync\DelegatedDeck;
use OCA\DeckCalendarBridge\Sync\Planning;
use OCA\DeckCalendarBridge\Sync\Queue;
use OCA\DeckCalendarBridge\Sync\Runner;
use OCA\DeckCalendarBridge\Sync\Scanner;
use OCA\DeckCalendarBridge\Sync\Store;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\Calendar\IManager;
use OCP\Calendar\ICreateFromString;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/** No non-admin route exposes the cross-app delegation or calendar contents. */
final class ControlController extends Controller {
    public function __construct(IRequest $request,private IUserSession $session,private IGroupManager $groups,
        private IConfig $config,private IDBConnection $db,private IManager $calendars,private IUserManager $users,
        private Runner $runner,private Scanner $scanner,private Queue $queue,private Store $store,
        private DelegatedDeck $deck,private Planning $planning,private RuleRepository $rules) {
        parent::__construct('deckcalendarbridge',$request);
    }
    private function admin(): bool {
        return $this->session->getUser()!==null && $this->groups->isAdmin($this->session->getUser()->getUID());
    }
    public function status(): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        return new DataResponse(['version'=>$this->config->getAppValue('deckcalendarbridge','installed_version','1.1.3'),
            'enabled'=>$this->runner->enabled(),'relations'=>$this->store->status()]);
    }
    public function mode(): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        if ($this->config->getAppValue('deckcalendarbridge','uninstall_in_progress','no')==='yes') {
            return new DataResponse(['error'=>'Uninstall is in progress; complete it before resuming synchronization'],409);
        }
        $this->request->throwDecodingExceptionIfAny(); $enabled=$this->request->getParam('enabled');
        if (!is_bool($enabled)) return new DataResponse(['error'=>'Expected enabled boolean'],400);
        $this->config->setAppValue('deckcalendarbridge','relations_v1_enabled',$enabled?'yes':'no');
        if ($enabled) $this->scanner->scan();
        return new DataResponse(['enabled'=>$enabled]);
    }
    public function sync(): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        if (!$this->runner->enabled()) return new DataResponse(['error'=>'Synchronization is disabled'],409);
        $id=$this->request->getParam('cardId');
        if ($id!==null) {
            if (!is_int($id)||$id<1) return new DataResponse(['error'=>'Invalid card ID'],400);
            $this->queue->mark($id); return new DataResponse($this->runner->card($id));
        }
        $scan=$this->scanner->scan(); return new DataResponse(['scan'=>$scan,'results'=>$this->runner->pending()]);
    }
    public function boards(): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        $q=$this->db->getQueryBuilder();
        $rows=$q->select('id','title','archived')->from('deck_boards')
            ->where($q->expr()->eq('deleted_at',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT)))
            ->orderBy('title','ASC')->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['archived']=(bool)$r['archived']; }
        return new DataResponse(['boards'=>$rows]);
    }
    public function labels(int $boardId): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        $q=$this->db->getQueryBuilder();
        $rows=$q->select('id','title')->from('deck_labels')
            ->where($q->expr()->eq('board_id',$q->createNamedParameter($boardId,IQueryBuilder::PARAM_INT)))
            ->orderBy('id','ASC')->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$r) $r['id']=(int)$r['id'];
        return new DataResponse(['labels'=>$rows]);
    }
    public function calendarOptions(): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        $uid=$this->request->getParam('userId',$this->session->getUser()->getUID());
        if (!is_string($uid)||!preg_match('/^[A-Za-z0-9_.@-]{1,128}$/D',$uid)||str_contains($uid,'..')) {
            return new DataResponse(['error'=>'Invalid organizer'],400);
        }
        $user=$this->users->get($uid);
        if ($user===null||!$user->isEnabled()) return new DataResponse(['error'=>'Organizer unavailable'],404);
        $out=[];
        foreach ($this->calendars->getCalendarsForPrincipal('principals/users/'.$uid) as $c) {
            if ($c->isDeleted()||!$c instanceof ICreateFromString||!ctype_digit($c->getKey())) continue;
            // Canonicalize a shared Family view to its ORIGINAL owner/URI.
            // Listener and DAV operations must not use a sharee's alias path.
            $q=$this->db->getQueryBuilder();
            $original=$q->select('principaluri','uri','deleted_at')->from('calendars')
                ->where($q->expr()->eq('id',$q->createNamedParameter((int)$c->getKey(),IQueryBuilder::PARAM_INT)))
                ->executeQuery()->fetchAssociative();
            if ($original===false||$original['deleted_at']!==null) continue;
            $key=$original['principaluri'].'|'.$original['uri'];
            $out[$key]=['principal'=>$original['principaluri'],'uri'=>$original['uri'],
                'name'=>$c->getDisplayName()?:$original['uri'],'organizerUser'=>substr($original['principaluri'],17)];
        }
        return new DataResponse(['calendars'=>array_values($out)]);
    }
    public function preview(int $cardId): DataResponse {
        if (!$this->admin()) return new DataResponse(['error'=>'Forbidden'],403);
        $card=$this->deck->read($cardId); if ($card===null) return new DataResponse(['error'=>'Card not found'],404);
        $out=[];
        foreach ($this->rules->read()['rules'] as $r) {
            if (!$r['enabled']||!Planning::matches($card,$r)) continue;
            try {
                $p=$this->planning->plan($card,$r,Planning::identity($cardId,$r['id'],1));
                $out[$r['id']]=['title'=>$p->title,'time'=>Planning::window($p),'categories'=>$p->categories,
                    'principal'=>$p->principal,'calendar'=>$p->calendarUri,
                    'eligibleForNewEvent'=>Planning::eligible($card,time(),$r)];
            } catch (\Throwable $e) { $out[$r['id']]=['error'=>$e->getMessage()]; }
        }
        return new DataResponse(['card'=>$card,'plans'=>$out,'readOnly'=>true]);
    }
}
