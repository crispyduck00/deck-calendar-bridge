<?php
declare(strict_types=1);
const OC_CONSOLE=1;
$root=$argv[1]??'';
if (!is_file(dirname($root).'/.dcb-disposable')) throw new RuntimeException('Disposable marker required');
require $root.'/lib/base.php';
\OC_App::loadApps();
use OCP\Server;
try {
    $db=Server::get(\OCP\IDBConnection::class); $q=$db->getQueryBuilder();
    $config=$q->select('configkey','configvalue')->from('appconfig')
        ->where($q->expr()->eq('appid',$q->createNamedParameter('deckcalendarbridge')))->executeQuery()->fetchAllAssociative();
    $q=$db->getQueryBuilder();
    $migrations=(int)$q->selectAlias($q->func()->count('version'),'n')->from('migrations')
        ->where($q->expr()->eq('app',$q->createNamedParameter('deckcalendarbridge')))->executeQuery()->fetchOne();
    $q=$db->getQueryBuilder();
    $jobClasses=$q->select('class')->from('jobs')->executeQuery()->fetchAllAssociative();
    $jobs=array_values(array_filter(array_column($jobClasses,'class'),static fn(string $s):bool=>str_starts_with($s,'OCA\\DeckCalendarBridge\\')));
    $q=$db->getQueryBuilder();
    $objects=$q->select('o.id','o.uri','o.uid','o.calendardata','o.deleted_at')
        ->selectAlias('c.principaluri','principal')->selectAlias('c.uri','calendar_uri')
        ->from('calendarobjects','o')->innerJoin('o','calendars','c','c.id = o.calendarid')
        ->orderBy('o.id','ASC')->executeQuery()->fetchAllAssociative();
    foreach ($objects as &$o) if (is_resource($o['calendardata'])) $o['calendardata']=stream_get_contents($o['calendardata']);
    unset($o);
    $q=$db->getQueryBuilder(); $labels=$q->select('card_id','label_id')->from('deck_assigned_labels')->orderBy('card_id','ASC')->addOrderBy('label_id','ASC')->executeQuery()->fetchAllAssociative();
    $prefix=Server::get(\OCP\IConfig::class)->getSystemValue('dbtableprefix','oc_');
    $schema=$db->createSchema(); $tables=[]; $managed=[]; $originals=[];
    foreach (['dcb_event_links','dcb_dirty_cards','dcb_reverse_outbox','dcb_relations','dcb_sync_queue'] as $table) $tables[$table]=$schema->hasTable($prefix.$table);
    foreach (['dcb_relations'=>'uid','dcb_event_links'=>'event_uid'] as $table=>$field) if ($tables[$table]) {
        $q=$db->getQueryBuilder();
        foreach ($q->select($field,'principal','calendar_uri','object_uri')->from($table)->executeQuery()->fetchAllAssociative() as $r) {
            $managed[]=$r[$field];
            foreach ($objects as $o) {
                // Participant copies intentionally share UID. Only the saved
                // organizer calendar and object URI identify our original.
                if ($o['uid']===$r[$field] && $o['principal']===$r['principal']
                    && $o['calendar_uri']===$r['calendar_uri'] && $o['uri']===$r['object_uri']) {
                    $originals[]=(string)$o['id'];
                }
            }
        }
    }
    if (($argv[2]??'')==='unrelated') {
        $backend=Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $calendar=$backend->getCalendarByUri('principals/users/bridge-owner','worker-test');
        $ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//worker fixture//EN\r\nBEGIN:VEVENT\r\nUID:unrelated-worker@example.invalid\r\nDTSTAMP:20261025T080000Z\r\nDTSTART:20271101T080000Z\r\nDTEND:20271101T090000Z\r\nSUMMARY:Independent appointment\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        if (!$backend->getCalendarObject($calendar['id'],'unrelated-worker.ics')) $backend->createCalendarObject($calendar['id'],'unrelated-worker.ics',$ics);
        $lookalike=str_replace('unrelated-worker@example.invalid','dcb-'.str_repeat('a',64).'@deck-calendar-bridge',$ics);
        if (!$backend->getCalendarObject($calendar['id'],'unrelated-lookalike.ics')) $backend->createCalendarObject($calendar['id'],'unrelated-lookalike.ics',$lookalike);
    }
    echo 'DCB_JSON:'.json_encode(['config'=>(object)array_column($config,'configvalue','configkey'),'migrations'=>$migrations,
        'jobs'=>$jobs,'objects'=>$objects,'managed_uids'=>array_values(array_unique($managed)),'managed_original_ids'=>array_values(array_unique($originals)),'labels'=>$labels,'tables'=>$tables],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) { fwrite(STDERR,(string)$e."\n"); exit(1); }
