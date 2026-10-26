<?php
declare(strict_types=1);
const OC_CONSOLE=1;
$root=$argv[1]??'';
if (!is_file(dirname($root).'/.dcb-disposable')) throw new RuntimeException('Disposable test marker required');
require $root.'/lib/base.php';
\OC_App::loadApps();

use OCP\Server;
use OCA\DeckCalendarBridge\Service\{EventIdentity,RuleRepository};
use OCA\DeckCalendarBridge\Sync\{CalendarDocument,DelegatedDeck,NativeCalendar,Planning,Queue,Runner,Scanner,Store};
use OCA\Deck\Db\{Acl,CardMapper};
use OCA\Deck\Service\{BoardService,CardService,LabelService,PermissionService,StackService};
use Sabre\HTTP\{Request,Response};
use Sabre\VObject\Reader;

function expect(bool $ok,string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS NC35 $name\n";
}
function editor(): void {
    Server::get(\OCP\IUserSession::class)->setVolatileActiveUser(Server::get(\OCP\IUserManager::class)->get('bridge-editor'));
    // Public Deck CLI contract, fixture setup only. The bridge never grants
    // the calendar owner this user's generic Deck permissions.
    Server::get(PermissionService::class)->setUserId('bridge-editor');
}
function saveRules(array $rules): array {
    $repo=Server::get(RuleRepository::class); return $repo->replace($rules,$repo->read()['revision'])['rules'];
}
function syncCard(int $id): array {
    Server::get(Queue::class)->mark($id);
    $r=Server::get(Runner::class)->card($id);
    expect(($r['blocked']??[])===[],'canonical reconcile succeeds for card '.$id.' '.json_encode($r)); return $r;
}
function editCard(int $id,array $changes): void {
    editor(); $mapper=Server::get(CardMapper::class);
    Server::get(PermissionService::class)->checkPermission($mapper,$id,Acl::PERMISSION_EDIT,'bridge-editor');
    $c=$mapper->find($id,false); $before=clone $c;
    foreach ($changes as $field=>$value) {
        if (in_array($field,['startdate','duedate','done'],true) && is_int($value)) $value=(new DateTime())->setTimestamp($value);
        $setter='set'.ucfirst($field); $c->$setter($value);
    }
    $mapper->update($c);
    Server::get(\OCA\Deck\Db\ChangeHelper::class)->cardChanged($id,false);
    Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->dispatchTyped(new \OCA\Deck\Event\CardUpdatedEvent($c,$before));
}
function client(string $verb,array $row,?string $body=null,?string $principal=null,?string $etag=null): int {
    $principal??=$row['principal'];
    Server::get(\OCP\IUserSession::class)->setVolatileActiveUser(Server::get(\OCP\IUserManager::class)->get(substr($principal,17)));
    $embedded=new \OCA\DAV\CalDAV\EmbeddedCalDavServer(false); $server=$embedded->getServer();
    $server->getPlugin('auth')->setCurrentPrincipal($principal);
    $path='calendars/'.rawurlencode(substr($row['principal'],17)).'/'.rawurlencode($row['calendar_uri']).'/'.rawurlencode($row['object_uri']);
    $server->getPlugin('caldav-schedule')->setPathOfCalendarObjectChange($path);
    $headers=['Content-Type'=>'text/calendar']; if ($etag!==null) $headers['If-Match']=$etag;
    $request=new Request($verb,$server->getBaseUri().$path,$headers,$body); $request->setBaseUrl($server->getBaseUri());
    $response=new Response(); $server->httpRequest=$request; $server->httpResponse=$response;
    $server->sapi=new \OCA\DeckCalendarBridge\Service\SilentDavSapi();
    try { $server->invokeMethod($request,$response,false); }
    catch (\Sabre\DAV\Exception $e) { return $e->getHTTPCode(); }
    return $response->getStatus();
}
function shift(array $row,int $seconds): void {
    $old=Server::get(NativeCalendar::class)->read($row); $cal=Reader::read($old['body']);
    foreach (['DTSTART','DTEND'] as $p) $cal->VEVENT->$p=$cal->VEVENT->$p->getDateTime()->modify("$seconds seconds");
    expect(client('PUT',$row,$cal->serialize(),null,$old['etag'])===204,'real CalDAV calendar edit accepted');
}
function receiptCount(string $uid,string $method,?string $recipient=null): int {
    $n=0;
    foreach (glob(getenv('DCB_MAIL_SPOOL').'/*.json') as $path) {
        $m=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        foreach ($m['calendars'] as $cal) if ($cal['uid']===$uid && $cal['method']===$method
            && ($recipient===null || in_array($recipient,$m['recipients'],true))) $n++;
    }
    return $n;
}
function latestRequest(string $uid,string $recipient): \Sabre\VObject\Component\VCalendar {
    $paths=glob(getenv('DCB_MAIL_SPOOL').'/*.json'); rsort($paths,SORT_STRING);
    foreach ($paths as $path) {
        $mail=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if (!in_array($recipient,$mail['recipients'],true)) continue;
        foreach ($mail['calendars'] as $cal) if ($cal['uid']===$uid && $cal['method']==='REQUEST') return Reader::read($cal['ics']);
    }
    throw new RuntimeException('Expected native REQUEST missing');
}
function legacyRow(int $card,array $rule,?string $body=null): array {
    $uid=(new EventIdentity())->uid('deck',(string)$card,'rule-'.$rule['id']);
    $row=['card_id'=>$card,'rule_id'=>$rule['id'],'uid'=>$uid,'object_uri'=>Planning::uri($uid),
        'principal'=>$rule['target']['principal'],'calendar_uri'=>$rule['target']['calendarUri']];
    if ($body!==null) {
        Server::get(\OCP\IUserSession::class)->setVolatileActiveUser(Server::get(\OCP\IUserManager::class)->get(substr($row['principal'],17)));
        foreach (Server::get(\OCP\Calendar\IManager::class)->getCalendarsForPrincipal($row['principal'],[$row['calendar_uri']]) as $cal) {
            if ($cal->getUri()===$row['calendar_uri']) $cal->createFromString($row['object_uri'],$body);
        }
    }
    $db=Server::get(\OCP\IDBConnection::class); $q=$db->getQueryBuilder();
    $v=[];
    foreach (['source_type'=>'deck','source_id'=>(string)$card,'event_slot'=>'rule-'.$rule['id'],
        'event_uid'=>$uid,'sync_state'=>'uncertain','revision'=>4,'principal'=>$row['principal'],
        'calendar_uri'=>$row['calendar_uri']] as $k=>$value) $v[$k]=$q->createNamedParameter($value);
    $q->insert('dcb_event_links')->values($v)->executeStatement();
    return $row;
}
try {
    $config=Server::get(\OCP\IConfig::class);
    foreach (['mail_smtpmode'=>'smtp','mail_smtphost'=>'127.0.0.1','mail_smtpport'=>(int)getenv('DCB_SMTP_PORT'),
        'mail_smtpsecure'=>'','mail_smtpauth'=>false,'mail_from_address'=>'bridge','mail_domain'=>'example.invalid'] as $k=>$v) $config->setSystemValue($k,$v);
    $config->setAppValue('deckcalendarbridge','relations_v1_enabled','yes');
    editor();
    $board=Server::get(BoardService::class)->create('Bridge integration '.time(),'bridge-editor','0082c9');
    $stack=Server::get(StackService::class)->create('Work',$board->getId(),0);
    $labels=Server::get(LabelService::class);
    $a=$labels->create('Abholung','e08000',$board->getId());
    $b=$labels->create('Lieferung','00a000',$board->getId());
    $extra=$labels->create('Priorität','0080e0',$board->getId());
    $backend=Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
    foreach (['bridge-owner','bridge-other'] as $owner) if (!$backend->getCalendarByUri('principals/users/'.$owner,'bridge-test')) {
        $backend->createCalendar('principals/users/'.$owner,'bridge-test',[]);
    }
    $newCard=function(?int $start,?int $due,array $tags) use($stack):int {
        editor(); $c=Server::get(CardService::class)->create('Collect item',$stack->getId(),'plain',0,'bridge-editor',
            'private source description',$due===null?null:gmdate('c',$due),$start===null?null:gmdate('c',$start));
        foreach ($tags as $tag) Server::get(CardService::class)->assignLabel($c->getId(),$tag);
        return $c->getId();
    };
    $raw=[];
    foreach (['a'=>[$a->getId(),'bridge-owner'],'b'=>[$b->getId(),'bridge-other']] as $id=>[$tag,$owner]) $raw[]=[
        'id'=>'board'.$board->getId().'-'.$id,'enabled'=>true,
        'source'=>['type'=>'deck','boardIds'=>[$board->getId()],'labelIds'=>[$tag],'match'=>'any'],
        'target'=>['mode'=>'fixed','principal'=>'principals/users/'.$owner,'calendarUri'=>'bridge-test'],
        'timing'=>['durationMinutes'=>20,'maxBlockMinutes'=>10080,'dueAnchor'=>'before'],
        'invitations'=>['enabled'=>false,'attendees'=>[]],
        'presentation'=>['titlePrefix'=>$id==='a'?'Abholung: ':'','syncDeckTags'=>true],
        'sync'=>['onDone'=>'keep']];
    $now=time(); $id=$newCard($now+3600,$now+7200,[$a->getId(),$b->getId()]);
    $rules=saveRules($raw); $ra=$rules[0]['id']; $rb=$rules[1]['id'];
    $savedSnapshot=Server::get(RuleRepository::class)->read();
    $duplicate=$rules; $duplicate[]=$rules[0]; $duplicate[2]['id']='duplicate-source';
    try { saveRules($duplicate); throw new RuntimeException('Duplicate active board/tag accepted'); }
    catch (DomainException) { expect(Server::get(RuleRepository::class)->read()===$savedSnapshot,'invalid rule invariants leave the saved snapshot unchanged'); }
    Server::get(Scanner::class)->scan();
    expect(Server::get(Queue::class)->get($id)!==null,'new rule backfills existing source card');
    // Independent native providers share the actual database / Redis lock
    // backend. Exercise the production collision, not a mocked exception.
    $timeFactory=Server::get(\OCP\AppFramework\Utility\ITimeFactory::class);
    $nativeLocks=Server::get(\OCP\Lock\ILockingProvider::class);
    $redis=getenv('DCB_TEST_LOCKING')==='redis';
    $expectedLockClass=$redis?\OC\Lock\MemcacheLockingProvider::class:\OC\Lock\DBLockingProvider::class;
    expect($nativeLocks instanceof $expectedLockClass,
        'fixture uses expected '.($redis?'Redis':'database').' locking provider');
    $foreignLocks=$redis
        ? new \OC\Lock\MemcacheLockingProvider(Server::get(\OCP\ICacheFactory::class)->createLocking('lock'),$timeFactory)
        : new \OC\Lock\DBLockingProvider(Server::get(\OCP\IDBConnection::class),$timeFactory,3600,false);
    $exclusive=\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE;
    $queue=Server::get(Queue::class);
    $queueKey=Server::get(\OCA\DeckCalendarBridge\Sync\LockNames::class)->key('queue/'.$id);
    $observed=$queue->get($id);
    $foreignLocks->acquireLock($queueKey,$exclusive);
    try {
        expect(!$queue->acknowledge($id,$observed['generation']),'acknowledgement defers while another writer owns the queue mutex');
        expect($queue->get($id)===$observed&&$foreignLocks->isLocked($queueKey,$exclusive),'busy acknowledgement retains the generation and never releases the writer lock');
    } finally { $foreignLocks->releaseLock($queueKey,$exclusive); }
    $queue->mark($id);
    expect($queue->get($id)['generation']===$observed['generation']+1,'mark atomically increments the current generation');
    expect(!$queue->acknowledge($id,$observed['generation'])&&$queue->get($id)!==null,'a stale acknowledgement cannot discard a newly marked generation');
    $cardKey=Server::get(\OCA\DeckCalendarBridge\Sync\LockNames::class)->key('card/'.$id);
    $otherCard=$newCard(time()+3600,time()+7200,[$a->getId()]);
    foreach ([$id,$otherCard] as $queuedId) {
        $currentQueue=$queue->get($queuedId);
        expect($queue->acknowledge($queuedId,$currentQueue['generation']),'fixture clears an observed queue generation');
    }
    $queue->mark($otherCard,false);
    $q=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
    $q->update('dcb_sync_queue')->set('retry_after',$q->createNamedParameter(time()-5))
        ->where($q->expr()->eq('card_id',$q->createNamedParameter($otherCard)))->executeStatement();
    $queue->mark($id,false);
    $pending=array_map('intval',array_column($queue->pending(),'card_id'));
    expect(in_array($otherCard,$pending,true)&&in_array($id,$pending,true),'both background cards remain pending');
    expect(array_search($otherCard,$pending,true)<array_search($id,$pending,true),'older background work precedes a newly rescanned lower card ID');
    $queuedAt=$queue->get($id)['retry_after']; $queue->mark($id,false);
    expect($queue->get($id)['retry_after']===$queuedAt,'repeated scans preserve the age of pending background work');
    $queue->mark($id,true);
    expect($queue->get($id)['retry_after']===0,'an actual event promotes pending background work to urgent');
    $foreignLocks->acquireLock($cardKey,$exclusive);
    try {
        $queued=Server::get(Queue::class)->get($id);
        $busy=Server::get(Runner::class)->card($id);
        expect(($busy['deferred']??null)==='card_locked' && ($busy['pending']??false),'contended card defers without throwing');
        expect(Server::get(Queue::class)->get($id)===$queued,'contended card preserves pending generation and retry state');
        $batch=Server::get(Runner::class)->pending();
        expect(($batch[$id]['deferred']??null)==='card_locked'
            && (Server::get(Store::class)->current($otherCard)[$ra]['state']??null)==='active',
            'batch continues to another card while first card is locked');
        expect($foreignLocks->isLocked($cardKey,$exclusive),'contender never releases another worker card lock');
    } finally { $foreignLocks->releaseLock($cardKey,$exclusive); }
    $scanKey=Server::get(\OCA\DeckCalendarBridge\Sync\LockNames::class)->key('scan');
    $cursorKeys=['v1_source_cursor','v1_relation_cursor','v1_legacy_cursor','v1_scan_revision'];
    $cursors=[]; foreach ($cursorKeys as $k) $cursors[$k]=$config->getAppValue('deckcalendarbridge',$k,'');
    $foreignLocks->acquireLock($scanKey,$exclusive);
    try {
        $busyScan=Server::get(Scanner::class)->scan();
        expect(($busyScan['deferred']??null)==='scan_locked','concurrent scanner defers without aborting pending work');
        foreach ($cursorKeys as $k) expect($config->getAppValue('deckcalendarbridge',$k,'')===$cursors[$k],'contended scanner preserves '.$k);
        expect($foreignLocks->isLocked($scanKey,$exclusive),'contender never releases another scanner lock');
    } finally { $foreignLocks->releaseLock($scanKey,$exclusive); }
    syncCard($id);
    expect(Server::get(Queue::class)->get($id)===null,'deferred card drains normally after owner releases lock');
    $store=Server::get(Store::class); $deck=Server::get(DelegatedDeck::class); $calPort=Server::get(NativeCalendar::class);
    $rows=$store->current($id);
    expect(count($rows)===2 && $rows[$ra]['state']==='active' && $rows[$rb]['state']==='active','two complete persistent relations');
    $original=$calPort->read($rows[$ra]);
    $oldDoneRule=$rules[0]; $oldDoneRule['sync']['onDone']='delete';
    $oldTargetRule=$rules[0]; $oldTargetRule['target']['calendarUri']='previous-target';
    foreach ([['rule_removed',null],['card_done',$oldDoneRule],['rule_target_changed',$oldTargetRule]] as [$cause,$oldRule]) {
        $stale=$rows[$ra];
        $stale['data']['attempt']=['kind'=>'delete','cause'=>$cause,
            'rule_hash'=>$oldRule===null?null:hash('sha256',json_encode($oldRule,JSON_THROW_ON_ERROR))];
        try { $calPort->delete($stale,$original['etag']); throw new RuntimeException('Stale rule authorized deletion'); }
        catch (DomainException $e) { expect($e->getMessage()==='delete_authorization_changed','changed rule rejects stale '.$cause.' deletion before DAV'); }
        expect($calPort->read($rows[$ra])['etag']===$original['etag'],'stale delete keeps the original and its ETag');
    }
    try {
        Server::get(PermissionService::class)->checkPermission(Server::get(CardMapper::class),$id,Acl::PERMISSION_READ,'bridge-owner');
        throw new RuntimeException('Organizer unexpectedly has Deck READ');
    } catch (\OCA\Deck\NoPermissionException) { expect(true,'organizer has NO Deck READ'); }
    expect(in_array(client('GET',$rows[$ra],null,'principals/users/bridge-editor'),[403,404],true),'Deck editor has NO target calendar DAV access');
    $before=$deck->read($id); shift($rows[$ra],3600);
    expect(Server::get(Queue::class)->get($id)!==null,'native calendar update listener enqueues source card');
    expect($deck->read($id)===$before,'DAV callback queues work without synchronously updating Deck');
    $r=syncCard($id); $after=$deck->read($id);
    expect($r['adopted']===1 && $after['start']===$before['start']+3600,'calendar without Deck rights updates card times');
    $other=(new CalendarDocument())->inspect($calPort->read($rows[$rb])['body'],$rows[$rb]['uid']);
    expect($other['time']['start']===$after['start'] && $other['time']['end']===$after['due'],'card fans time change to other organizer calendar');
    expect(Server::get(CardMapper::class)->find($id,false)->getDescription()==='private source description','unrelated private card description preserved');
    $etag=$calPort->read($rows[$ra])['etag']; $revision=$store->current($id)[$ra]['revision']; syncCard($id);
    expect($calPort->read($rows[$ra])['etag']===$etag && $store->current($id)[$ra]['revision']===$revision,'equal values perform NO calendar or relation update');
    shift($rows[$ra],900); editCard($id,['startdate'=>$after['start']+1800,'duedate'=>$after['due']+1800]); syncCard($id);
    expect($deck->read($id)['start']===$after['start']+1800,'Deck wins simultaneous conflicting time changes');
    $remote=$calPort->read($rows[$ra]); $body=Reader::read($remote['body']); $body->VEVENT->LOCATION='Werkstatt';
    expect(client('PUT',$rows[$ra],$body->serialize(),null,'"stale"')===412,'actual DAV enforces ETag precondition');
    expect(client('PUT',$rows[$ra],$body->serialize(),null,$remote['etag'])===204,'actual DAV accepts matching ETag');
    editor(); Server::get(CardService::class)->assignLabel($id,$extra->getId()); syncCard($id);
    $data=(new CalendarDocument())->inspect($calPort->read($rows[$ra])['body'],$rows[$ra]['uid']);
    expect(in_array('Priorität',$data['categories'],true),'all card tags kept as categories');
    $body=Reader::read($calPort->read($rows[$ra])['body']); expect((string)$body->VEVENT->LOCATION==='Werkstatt','unmanaged calendar location preserved');
    $remote=$calPort->read($rows[$ra]); expect(client('DELETE',$rows[$ra],null,null,$remote['etag'])===204,'native organizer DELETE enters trashbin');
    syncCard($id);
    expect(!isset($deck->read($id)['labels'][$a->getId()])&&isset($deck->read($id)['labels'][$b->getId()]),'calendar DELETE removes exact trigger without Deck ACL');
    expect($calPort->read($rows[$rb])['status']==='present','other tag and other event remain');
    editor(); Server::get(CardService::class)->assignLabel($id,$a->getId()); syncCard($id); $readded=$store->current($id)[$ra];
    expect($readded['generation']===2 && $readded['uid']!==$rows[$ra]['uid'],'explicit re-add creates one new confirmed lifecycle');
    editCard($id,['done'=>time()]); syncCard($id);
    expect($calPort->read($readded)['status']==='present','done normally keeps event');
    $rules[0]['sync']['onDone']='delete'; $rules=saveRules($rules); syncCard($id);
    expect($calPort->read($readded)['status']==='trashed'&&$calPort->read($rows[$rb])['status']==='present','done delete is per-rule configurable');
    editCard($id,['done'=>null]); $rules[0]['sync']['onDone']='keep'; $rules=saveRules($rules); syncCard($id);
    $current=$store->current($id); editCard($id,['archived'=>true]); syncCard($id);
    expect($calPort->read($current[$ra])['status']==='present','archive keeps event');
    $rules[0]['invitations']=['enabled'=>true,'attendees'=>['first@example.invalid'],'includeAssignees'=>false,'descriptionPolicy'=>'link_only','allowFullDescription'=>false];
    $rules=saveRules($rules); syncCard($id);
    $body=Reader::read($calPort->read($current[$ra])['body']); expect(count($body->VEVENT->select('ATTENDEE'))===0,'changing attendee rule does not alter existing participants');
    $invite=$newCard(time()+3600,time()+7200,[$a->getId()]); syncCard($invite); $inv=$store->current($invite)[$ra];
    expect(receiptCount($inv['uid'],'REQUEST','first@example.invalid')===1,'one real invitation received by SMTP sink');
    syncCard($invite); expect(receiptCount($inv['uid'],'REQUEST')===1,'NOOP does not resend invitation');
    $remote=$calPort->read($inv); $body=Reader::read($remote['body']); unset($body->VEVENT->ATTENDEE);
    $body->VEVENT->add('ATTENDEE','mailto:changed@example.invalid',['PARTSTAT'=>'ACCEPTED']);
    expect(client('PUT',$inv,$body->serialize(),null,$remote['etag'])===204,'calendar participant edit accepted');
    editCard($invite,['title'=>'Neuer Titel','duedate'=>time()+7500]); syncCard($invite);
    $body=Reader::read($calPort->read($inv)['body']);
    expect((string)$body->VEVENT->ATTENDEE==='mailto:changed@example.invalid' && (string)$body->VEVENT->ATTENDEE['PARTSTAT']==='ACCEPTED','Deck update preserves edited attendee and RSVP');
    $description=(string)$body->VEVENT->DESCRIPTION;
    expect(str_contains($description,'/apps/deck/board/'.$board->getId().'/card/'.$invite)
        &&str_contains($description,'Time changes made here are synchronized back to Deck.'),'managed description contains exact card link and time ownership notice');
    $remote=$calPort->read($inv); $body=Reader::read($remote['body']);
    $body->VEVENT->DESCRIPTION='Temporary calendar note';
    $body->VEVENT->SEQUENCE=(int)(string)$body->VEVENT->SEQUENCE+1;
    expect(client('PUT',$inv,$body->serialize(),null,$remote['etag'])===204,'manual description edit is accepted by native calendar');
    $requests=receiptCount($inv['uid'],'REQUEST','changed@example.invalid');
    syncCard($invite);
    $restored=$calPort->read($inv);
    expect((string)Reader::read($restored['body'])->VEVENT->DESCRIPTION===$description
        &&receiptCount($inv['uid'],'REQUEST','changed@example.invalid')===$requests+1,'description restored through native scheduling exactly once');
    syncCard($invite);
    expect($calPort->read($inv)['etag']===$restored['etag']
        &&receiptCount($inv['uid'],'REQUEST','changed@example.invalid')===$requests+1,'equal description does not rewrite or resend');
    expect(Server::get(CardMapper::class)->find($invite,false)->getDescription()==='private source description','calendar description never overwrites Deck notes');
    $requests=receiptCount($inv['uid'],'REQUEST','changed@example.invalid');
    editor(); Server::get(CardService::class)->assignLabel($invite,$extra->getId()); syncCard($invite);
    expect(receiptCount($inv['uid'],'REQUEST','changed@example.invalid')===$requests,'category-only update sends no email by default');
    $rules[0]['invitations']['notifyOnCategoryChanges']=true; $rules=saveRules($rules);
    $etag=$calPort->read($inv)['etag']; syncCard($invite);
    expect($calPort->read($inv)['etag']===$etag && receiptCount($inv['uid'],'REQUEST','changed@example.invalid')===$requests,'enabling category notifications alone does not resend');
    $oldRecipient=receiptCount($inv['uid'],'REQUEST','first@example.invalid');
    editor(); Server::get(CardService::class)->removeLabel($invite,$extra->getId()); syncCard($invite);
    expect(receiptCount($inv['uid'],'REQUEST','changed@example.invalid')===$requests+1
        &&receiptCount($inv['uid'],'REQUEST','first@example.invalid')===$oldRecipient,'category removal notifies current participant only');
    editor(); Server::get(CardService::class)->assignLabel($invite,$extra->getId()); syncCard($invite);
    $mail=latestRequest($inv['uid'],'changed@example.invalid');
    expect(receiptCount($inv['uid'],'REQUEST','changed@example.invalid')===$requests+2
        &&in_array('Priorität',$mail->VEVENT->CATEGORIES->getParts(),true)
        &&(string)$mail->VEVENT->DESCRIPTION===$description,'native category update mail contains actual current categories and stable description');
    $body=Reader::read($calPort->read($inv)['body']);
    expect((string)$body->VEVENT->ATTENDEE['PARTSTAT']==='ACCEPTED'
        &&!isset($body->VEVENT->ATTENDEE['SCHEDULE-FORCE-SEND']),'native scheduling preserves RSVP and consumes transient resend request');
    syncCard($invite);
    expect(receiptCount($inv['uid'],'REQUEST','changed@example.invalid')===$requests+2,'category update replay sends no second email');
    $cancels=receiptCount($inv['uid'],'CANCEL','changed@example.invalid');
    editor(); Server::get(CardService::class)->removeLabel($invite,$a->getId()); syncCard($invite);
    expect(receiptCount($inv['uid'],'CANCEL','changed@example.invalid')===$cancels+1,'tag deletion delivers actual attendee CANCEL');
    syncCard($invite); expect(receiptCount($inv['uid'],'CANCEL','changed@example.invalid')===$cancels+1,'delete replay does not resend CANCEL');
    $localRules=$rules;
    $localRules[0]['invitations']['attendees']=['bridge-other@example.invalid'];
    $localRules[0]['invitations']['notifyOnCategoryChanges']=false;
    saveRules($localRules);
    $localCard=$newCard(time()+3600,time()+7200,[$a->getId()]); syncCard($localCard);
    $localRow=$store->current($localCard)[$ra];
    $localPath=$backend->getCalendarObjectByUID('principals/users/bridge-other',$localRow['uid']);
    expect(is_string($localPath),'initial invitation creates a native local attendee copy');
    [$localCalendarUri,$localObjectUri]=explode('/',$localPath,2);
    $localCalendar=$backend->getCalendarByUri('principals/users/bridge-other',$localCalendarUri);
    $localRequests=receiptCount($localRow['uid'],'REQUEST','bridge-other@example.invalid');
    editor(); Server::get(CardService::class)->assignLabel($localCard,$extra->getId()); syncCard($localCard);
    $localObject=$backend->getCalendarObject($localCalendar['id'],$localObjectUri);
    $localBody=is_resource($localObject['calendardata'])?stream_get_contents($localObject['calendardata']):$localObject['calendardata'];
    expect(in_array('Priorität',Reader::read($localBody)->VEVENT->CATEGORIES->getParts(),true)
        &&receiptCount($localRow['uid'],'REQUEST','bridge-other@example.invalid')===$localRequests,'category update reaches local attendee copy without category email');
    $rules=saveRules($rules);
    $past=$newCard(time()-7200,time()-3600,[$a->getId()]); syncCard($past);
    expect($store->current($past)===[],'fully historical card is not first-created');
    editCard($past,['startdate'=>time()+3600,'duedate'=>time()+7200]); syncCard($past);
    expect($store->current($past)[$ra]['state']==='active','past card changed to future creates event');
    $archiveTag=isset($deck->read($id)['labels'][$b->getId()]); saveRules([]); Server::get(Scanner::class)->scan(); syncCard($id);
    expect($calPort->read($current[$rb])['status']==='trashed' && $archiveTag && isset($deck->read($id)['labels'][$b->getId()]),'removed rule cleans archived appointment and retains tag');
    syncCard($localCard);
    // The fixture's long-lived backend caches getCalendarObject() results.
    // Read the real row again, as a separate client request would.
    $cancelledCopy=$backend->getCalendarObjectById('principals/users/bridge-other',(int)$localObject['id']);
    $cancelledBody=is_resource($cancelledCopy['calendardata'])?stream_get_contents($cancelledCopy['calendardata']):$cancelledCopy['calendardata'];
    expect((string)Reader::read($cancelledBody)->VEVENT->STATUS==='CANCELLED','native cancellation retains a cancelled participant copy with the original UID');

    // Reproduce the user's legacy shape: unknown board/tag/object, uncertain,
    // revision 4. Exact organizer lookup decides adoption versus first create.
    $legacyRules=$raw; $legacyRules=array_slice($legacyRules,0,1); $legacyRules[0]['id']='legacy-'.$board->getId();
    $legacyRules=saveRules($legacyRules); $oldCard=$newCard(time()+3600,time()+7200,[$a->getId()]);
    $old=legacyRow($oldCard,$legacyRules[0]); syncCard($oldCard); $adopted=$store->current($oldCard)[$legacyRules[0]['id']];
    expect($adopted['uid']===$old['uid'] && $adopted['board_id']===$board->getId() && $adopted['tag_id']===$a->getId(),'unbound uncertain legacy create recovers exact UID and source');
    $oldEtag=$calPort->read($adopted)['etag']; syncCard($oldCard);
    expect($calPort->read($adopted)['etag']===$oldEtag,'legacy recovery replay is a NOOP');
    $existingCard=$newCard(time()+3600,time()+7200,[$a->getId()]);
    $uid=(new EventIdentity())->uid('deck',(string)$existingCard,'rule-'.$legacyRules[0]['id']);
    $plan=Server::get(Planning::class)->plan($deck->read($existingCard),$legacyRules[0],$uid);
    $ics=Server::get(\OCA\DeckCalendarBridge\Service\VEventSerializer::class)->serialize($plan,new DateTimeImmutable());
    $oldExisting=legacyRow($existingCard,$legacyRules[0],$ics); $original=$calPort->read($oldExisting);
    syncCard($existingCard); $new=$store->current($existingCard)[$legacyRules[0]['id']];
    expect($new['uid']===$oldExisting['uid'] && $calPort->read($new)['object_id']===$original['object_id'],'existing uncertain legacy object adopted without duplicate');
    try {
        $forged=$legacyRules[0]; $forged['target']['calendarUri']='unconfigured';
        $deck->adoptTimes($forged,$deck->read($existingCard),['start'=>time()+5000,'due'=>time()+6000]);
        throw new RuntimeException('Forged delegation accepted');
    } catch (DomainException $e) { expect($e->getMessage()==='delegated_scope_changed','unsaved/forged delegation cannot mutate a card'); }
    // Native permanent deletion is a trusted observed intent; it need not wait
    // for the fallback missed-event grace period before removing the trigger.
    $hard=$newCard(time()+3600,time()+7200,[$a->getId()]); syncCard($hard);
    $hardRow=$store->current($hard)[$legacyRules[0]['id']];
    $retention=\OCA\DAV\CalDAV\RetentionService::RETENTION_CONFIG_KEY;
    $config->setAppValue('dav',$retention,'0');
    $hr=$calPort->read($hardRow);
    expect(client('DELETE',$hardRow,null,null,$hr['etag'])===204,'native permanent DELETE accepted');
    syncCard($hard);
    expect(!isset($deck->read($hard)['labels'][$a->getId()]),'observed permanent DELETE removes trigger immediately');
    $config->deleteAppValue('dav',$retention);
    $deletedCard=$newCard(time()+3600,time()+7200,[$a->getId()]); syncCard($deletedCard);
    $deletedRow=$store->current($deletedCard)[$legacyRules[0]['id']];
    $paused=$legacyRules; $paused[0]['enabled']=false; saveRules($paused);
    editor(); Server::get(CardService::class)->delete($deletedCard); syncCard($deletedCard);
    expect($calPort->read($deletedRow)['status']==='trashed','source card deletion cleans the original even while its rule is paused');
    saveRules($legacyRules);
    editor();
    $movingStack=Server::get(StackService::class)->create('Movable column',$board->getId(),1);
    $moving=Server::get(CardService::class)->create('Move between boards',$movingStack->getId(),'plain',0,'bridge-editor','',gmdate('c',time()+7200),gmdate('c',time()+3600));
    Server::get(CardService::class)->assignLabel($moving->getId(),$a->getId()); syncCard($moving->getId());
    $movingRow=$store->current($moving->getId())[$legacyRules[0]['id']];
    expect(Server::get(CardMapper::class)->findBoardId($moving->getId())===$board->getId(),'fixture primes the native board cache');
    editor(); $destination=Server::get(BoardService::class)->create('Destination board','bridge-editor','0082c9');
    Server::get(StackService::class)->update($movingStack->getId(),'Moved column',$destination->getId(),0,0);
    expect($deck->read($moving->getId())['board']===$destination->getId(),'delegation reads the actual stack board instead of a stale mapper cache');
    syncCard($moving->getId());
    expect($calPort->read($movingRow)['status']==='trashed','moving a column to an unmatched board cleans its old original');
    // Title modes use the live parent. Moving a card emits a card event;
    // renaming a list need not, so exercise the actual recovery scan too.
    $prefixRules=$legacyRules;
    $prefixRules[0]['presentation']['titlePrefixMode']='list';
    $prefixRules[0]['presentation']['titleSeparator']=' - ';
    saveRules($prefixRules);
    $prefixCard=$newCard(time()+3600,time()+7200,[$a->getId()]); syncCard($prefixCard);
    $prefixRow=$store->current($prefixCard)[$legacyRules[0]['id']];
    $prefixObject=$calPort->read($prefixRow)['object_id'];
    $prefixTitle=fn()=>(new CalendarDocument())->inspect($calPort->read($prefixRow)['body'],$prefixRow['uid'])['title'];
    expect($prefixTitle()==='Work - Collect item','list prefix renders from the current native column');
    editor(); $ready=Server::get(StackService::class)->create('Ready',$board->getId(),2);
    $source=Server::get(CardMapper::class)->find($prefixCard,false);
    Server::get(CardService::class)->update($prefixCard,$source->getTitle(),$ready->getId(),$source->getType(),$source->getOwner(),$source->getDescription(),$source->getOrder(),
        $source->getDuedate()->format('c'),startdate:$source->getStartdate()->format('c'));
    expect(Server::get(Queue::class)->get($prefixCard)!==null,'native card move queues title synchronization');
    $moveResult=Server::get(Runner::class)->card($prefixCard);
    expect(($moveResult['blocked']??[])===[]&&$prefixTitle()==='Ready - Collect item','card move updates the list prefix through its observed event');
    editor(); Server::get(StackService::class)->update($ready->getId(),'Quality check',$board->getId(),2,0);
    expect($deck->read($prefixCard)['listName']==='Quality check','source reads renamed column without a cached title');
    Server::get(Scanner::class)->scan(); Server::get(Runner::class)->pending();
    expect($prefixTitle()==='Quality check - Collect item'&&$calPort->read($prefixRow)['object_id']===$prefixObject
        &&$store->current($prefixCard)[$legacyRules[0]['id']]['uid']===$prefixRow['uid'],'scan updates a renamed list without recreating the original');
    $prefixEtag=$calPort->read($prefixRow)['etag']; syncCard($prefixCard);
    expect($calPort->read($prefixRow)['etag']===$prefixEtag,'unchanged list prefix is a real calendar NOOP');
    saveRules($legacyRules);
    $stackCard=$newCard(time()+3600,time()+7200,[$a->getId()]); syncCard($stackCard);
    $stackRow=$store->current($stackCard)[$legacyRules[0]['id']];
    editor(); Server::get(StackService::class)->delete($stack->getId());
    expect($deck->read($stackCard)['deleted'],'soft-deleted parent column makes the source ineligible');
    Server::get(Scanner::class)->scan(); Server::get(Runner::class)->pending();
    expect($calPort->read($stackRow)['status']==='trashed'
        &&isset($deck->read($stackCard)['labels'][$a->getId()]),'periodic recovery cleans a deleted column without removing card tags');
    echo "Real NC35 + Deck + DAV + SMTP integration passed.\n";
} catch (Throwable $e) { fwrite(STDERR,(string)$e."\n"); exit(1); }
