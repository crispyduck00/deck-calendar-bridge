<?php
declare(strict_types=1);
const OC_CONSOLE=1;
$root=$argv[1]??''; $action=$argv[2]??'';
if (!is_file(dirname($root).'/.dcb-disposable')) throw new RuntimeException('Disposable test marker required');
require $root.'/lib/base.php';
\OC_App::loadApps();

use OCP\Server;
use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCA\DeckCalendarBridge\Sync\{CalendarDocument,DelegatedDeck,NativeCalendar,Queue,Store};
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Service\{BoardService,CardService,LabelService,PermissionService,StackService};

try {
$input=json_decode($argv[3]??'{}',true,512,JSON_THROW_ON_ERROR);
function actor(string $uid): void {
    Server::get(\OCP\IUserSession::class)->setVolatileActiveUser(Server::get(\OCP\IUserManager::class)->get($uid));
    Server::get(PermissionService::class)->setUserId($uid);
}
function save(array $rules): array {
    $repo=Server::get(RuleRepository::class);
    return $repo->replace($rules,$repo->read()['revision'])['rules'];
}
$result=[];
switch ($action) {
    case 'setup':
        $config=Server::get(\OCP\IConfig::class);
        $config->setAppValue('deckcalendarbridge','relations_v1_enabled','yes');
        actor('bridge-editor');
        $board=Server::get(BoardService::class)->create('Worker test '.time(),'bridge-editor','0082c9');
        $stack=Server::get(StackService::class)->create('Work',$board->getId(),0);
        $rules=[]; $tags=[];
        foreach (['a'=>'bridge-owner','b'=>'bridge-other'] as $slot=>$owner) {
            $label=Server::get(LabelService::class)->create('Worker '.$slot,'0082c9',$board->getId());
            $tags[]=$label->getId();
            $backend=Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
            if (!$backend->getCalendarByUri('principals/users/'.$owner,'worker-test')) $backend->createCalendar('principals/users/'.$owner,'worker-test',[]);
            $rules[]=['id'=>'worker-'.$board->getId().'-'.$slot,'enabled'=>true,
                'source'=>['type'=>'deck','boardIds'=>[$board->getId()],'labelIds'=>[$label->getId()],'match'=>'any'],
                'target'=>['mode'=>'fixed','principal'=>'principals/users/'.$owner,'calendarUri'=>'worker-test'],
                'presentation'=>['titlePrefix'=>'Initial: ','syncDeckTags'=>true]];
        }
        $result=['stack'=>$stack->getId(),'tags'=>$tags,'rules'=>save($rules)];
        break;
    case 'create':
        actor('bridge-editor');
        $card=Server::get(CardService::class)->create('Worker card',$input['stack'],'plain',0,'bridge-editor','Private source',gmdate('c',time()+7200),gmdate('c',time()+3600));
        foreach ($input['tags'] as $tag) Server::get(CardService::class)->assignLabel($card->getId(),$tag);
        $result=['id'=>$card->getId()];
        break;
    case 'state':
        $result=['card'=>Server::get(DelegatedDeck::class)->read($input['id']),
            'queue'=>Server::get(Queue::class)->get($input['id']),'relations'=>[]];
        foreach (Server::get(Store::class)->current($input['id']) as $key=>$row) {
            $remote=Server::get(NativeCalendar::class)->read($row);
            $result['relations'][$key]=['state'=>$row['state'],'uid'=>$row['uid'],'principal'=>$row['principal'],
                'revision'=>$row['revision'],'remote'=>$remote['status'],'error'=>$row['data']['error']??null];
            if ($remote['status']==='present') $result['relations'][$key]['document']=(new CalendarDocument())->inspect($remote['body'],$row['uid']);
        }
        break;
    case 'edit':
        actor('bridge-editor'); $c=Server::get(CardMapper::class)->find($input['id'],false);
        Server::get(CardService::class)->update($c->getId(),$input['title']??$c->getTitle(),$c->getStackId(),$c->getType(),$c->getOwner(),$c->getDescription(),$c->getOrder(),
            gmdate('c',$input['due']??$c->getDuedate()->getTimestamp()),startdate:gmdate('c',$input['start']??$c->getStartdate()->getTimestamp()));
        break;
    case 'rules':
        $result=['rules'=>save($input['rules'])];
        break;
    case 'queue':
        Server::get(Queue::class)->mark($input['id']);
        break;
    case 'shift':
    case 'calendar-delete':
        $rows=Server::get(Store::class)->current($input['id']); $row=$rows[$input['ruleId']];
        $remote=Server::get(NativeCalendar::class)->read($row);
        actor(substr($row['principal'],17));
        $embedded=new \OCA\DAV\CalDAV\EmbeddedCalDavServer(false); $server=$embedded->getServer();
        $server->getPlugin('auth')->setCurrentPrincipal($row['principal']);
        $path='calendars/'.rawurlencode(substr($row['principal'],17)).'/worker-test/'.rawurlencode($row['object_uri']);
        $server->getPlugin('caldav-schedule')->setPathOfCalendarObjectChange($path);
        $body=null;
        if ($action==='shift') {
            $cal=\Sabre\VObject\Reader::read($remote['body']);
            foreach (['DTSTART','DTEND'] as $field) $cal->VEVENT->$field=$cal->VEVENT->$field->getDateTime()->modify('+900 seconds');
            $body=$cal->serialize();
        }
        $req=new \Sabre\HTTP\Request($action==='shift'?'PUT':'DELETE',$server->getBaseUri().$path,['Content-Type'=>'text/calendar','If-Match'=>$remote['etag']],$body);
        $req->setBaseUrl($server->getBaseUri()); $response=new \Sabre\HTTP\Response();
        $server->httpRequest=$req; $server->httpResponse=$response; $server->sapi=new \OCA\DeckCalendarBridge\Service\SilentDavSapi();
        $server->invokeMethod($req,$response,false);
        if (!in_array($response->getStatus(),[200,201,204],true)) throw new RuntimeException('DAV client failed');
        break;
    case 'hold-lock':
        $locks=Server::get(\OCP\Lock\ILockingProvider::class);
        $key=($input['legacy']??false)?'deckcalendarbridge/v1/card/'.$input['id']
            :Server::get(\OCA\DeckCalendarBridge\Sync\LockNames::class)->key('card/'.$input['id']);
        $locks->acquireLock($key,\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
        echo "HELD\n"; flush();
        try { while (trim((string)fgets(STDIN))!=='release') { if (feof(STDIN)) break; } }
        finally { $locks->releaseLock($key,\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE); }
        break;
    default: throw new RuntimeException('Unknown worker fixture action');
}
echo 'DCB_JSON:'.json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) { fwrite(STDERR,(string)$e."\n"); exit(1); }
