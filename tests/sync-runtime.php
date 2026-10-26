<?php
declare(strict_types=1);

$vendor=$argv[1]??(getenv('DCB_VENDOR_AUTOLOAD')?:__DIR__.'/../vendor/autoload.php');
if (!$vendor || !is_file($vendor)) throw new RuntimeException('Pass real Sabre VObject vendor autoload');
require $vendor;
spl_autoload_register(static function(string $class): void {
    $prefix='OCA\\DeckCalendarBridge\\';
    if (str_starts_with($class,$prefix)) require __DIR__.'/../lib/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
});

use OCA\DeckCalendarBridge\Sync\{CalendarDocument,CalendarPort,DeckPort,Planning,Reconciler,RelationStore};
use OCA\DeckCalendarBridge\Service\{InvitationEventComposer,MultiEventPlanner,MultiRuleResolver,RuleValidator,TimeWindowCalculator,EventIdentity,VEventSerializer};
use Sabre\VObject\Reader;

function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
final class MemoryStore implements RelationStore {
    public array $rows=[];
    public function current(int $cardId): array {
        $out=[]; foreach ($this->rows as $r) if ($r['card_id']===$cardId) $out[$r['rule_id']]=$r;
        return $out;
    }
    public function insert(array $r): array { $r['id']=count($this->rows)+1; $this->rows[$r['id']]=$r; return $r; }
    public function save(array $r): array {
        check($this->rows[$r['id']]['revision']===$r['revision'],'CAS rejects stale relation');
        $r['revision']++; $this->rows[$r['id']]=$r; return $r;
    }
}
final class MemoryDeck implements DeckPort {
    public ?array $card; public int $adoptions=0; public int $removals=0; public bool $race=false;
    public function __construct(array $card) { $this->card=$card; }
    public function read(int $id): ?array { return $this->card; }
    public function adoptTimes(array $rule,array $expected,array $times): array {
        if ($this->race) { $this->race=false; $this->card['due']+=900; throw new DomainException('deck_changed_retry'); }
        check($expected===$this->card,'Only current source snapshot is writable');
        $this->card=array_replace($this->card,$times); $this->adoptions++; return $this->card;
    }
    public function removeTrigger(array $row,array $rule,array $expected): ?array {
        check($expected===$this->card,'Deletion uses current source snapshot');
        check(Planning::binding($row,$rule),'Exact delegated rule binding');
        unset($this->card['labels'][$row['tag_id']]); $this->removals++; return $this->card;
    }
}
final class MemoryCalendar implements CalendarPort {
    public array $objects=[]; public int $creates=0,$updates=0,$deletes=0; public string $fail='';
    public ?Closure $afterRead=null;
    public function __construct(private MemoryStore $store) {}
    public function organizerEmail(string $principal): string { return 'organizer@example.invalid'; }
    public function read(array $r): array {
        $observed=$this->objects[$r['uid']]??['status'=>'absent'];
        if ($this->afterRead!==null) { $hook=$this->afterRead; $this->afterRead=null; $hook($r); }
        return $observed;
    }
    public function create(array $r,string $body): void {
        $saved=$this->store->current($r['card_id'])[$r['rule_id']];
        check($saved['object_uri']===$r['object_uri'] && $saved['tag_id']>0 && $saved['state']==='uncertain','Full relation persisted before DAV');
        if ($this->fail==='before_create') { $this->fail=''; throw new DomainException('timeout'); }
        check(($this->objects[$r['uid']]['status']??'absent')!=='present','CREATE must not overwrite an existing object');
        $this->creates++; $this->put($r['uid'],$body);
        if ($this->fail==='after_create') { $this->fail=''; throw new DomainException('timeout'); }
    }
    public function update(array $r,string $etag,string $body): void {
        check($etag===$this->objects[$r['uid']]['etag'],'ETag precondition');
        $this->updates++; $this->put($r['uid'],$body);
        if ($this->fail==='after_update') { $this->fail=''; throw new DomainException('timeout'); }
    }
    public function delete(array $r,string $etag): void {
        check($etag===$this->objects[$r['uid']]['etag'],'DELETE ETag precondition');
        $this->deletes++; $this->objects[$r['uid']]=['status'=>'trashed'];
        if ($this->fail==='after_delete') { $this->fail=''; throw new DomainException('timeout'); }
    }
    public function put(string $uid,string $body): void {
        $this->objects[$uid]=['status'=>'present','body'=>$body,'etag'=>'"'.hash('sha256',$body).'"'];
    }
    public function shift(string $uid,int $seconds): void {
        $cal=Reader::read($this->objects[$uid]['body']);
        foreach (['DTSTART','DTEND'] as $field) $cal->VEVENT->$field=$cal->VEVENT->$field->getDateTime()->modify("$seconds seconds");
        $this->put($uid,$cal->serialize());
    }
}
function fixture(): array {
    $now=1900000000;
    $card=['id'=>26,'board'=>3,'labels'=>[14=>'Abholung',19=>'Lieferung',99=>'Wichtig'],
        'title'=>'Collect item','start'=>$now+3600,'due'=>$now+7200,'done'=>false,'archived'=>false,'deleted'=>false];
    $raw=[];
    foreach (['a'=>14,'b'=>19] as $id=>$tag) $raw[]=[
        'id'=>$id,'enabled'=>true,'source'=>['type'=>'deck','boardIds'=>[3],'labelIds'=>[$tag],'match'=>'any'],
        'target'=>['mode'=>'fixed','principal'=>'principals/users/owner'.$id,'calendarUri'=>'work'],
        'timing'=>['durationMinutes'=>20,'maxBlockMinutes'=>10080,'dueAnchor'=>'before'],
        'invitations'=>['enabled'=>true,'attendees'=>['attendee@example.invalid']],
        'presentation'=>['titlePrefix'=>$id==='a'?'Abholung: ':'','categories'=>[],'syncDeckTags'=>true],
        'sync'=>['onDone'=>'keep']];
    $rules=(new RuleValidator())->validate($raw);
    $store=new MemoryStore(); $deck=new MemoryDeck($card); $calendar=new MemoryCalendar($store);
    $planning=new Planning(new MultiEventPlanner(new MultiRuleResolver(),new TimeWindowCalculator(),new EventIdentity()));
    $serializer=new VEventSerializer();
    $engine=new Reconciler($deck,$calendar,$store,$planning,new CalendarDocument(),$serializer,new InvitationEventComposer($serializer));
    return [$engine,$deck,$calendar,$store,$rules,$now];
}
function runCase(string $name,callable $test): void { $test(); echo "PASS $name\n"; }
function step(Reconciler $e,array $r,int $now): array {
    $x=$e->run(26,$r,$now,'https://bridge.example.invalid');
    check($x['blocked']===[],'Unexpected blocked outcome: '.json_encode($x)); return $x;
}

runCase('future existing-card backfill, immutable identities and equality NOOP',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $ids=array_column($s->current(26),'uid');
    check($c->creates===2,'Two tag relations create two original events');
    step($e,$r,$now); check($c->creates===2&&$c->updates===0,'Equal values do not write');
    check(array_column($s->current(26),'uid')===$ids,'Identity remains stable');
});
runCase('one calendar changes card, card changes other calendar without echo PUT',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now);
    $uid=$s->current(26)['a']['uid']; $c->shift($uid,3600);
    step($e,$r,$now); check($d->card['start']===$now+7200&&$d->adoptions===1,'Calendar shift adopted');
    check($c->updates===1,'Only other calendar updated');
    step($e,$r,$now); check($d->adoptions===1&&$c->updates===1,'No sync echo');
});
runCase('prefix mode changes update existing originals without replacing their identities',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); $d->card['listName']='Inbox';
    $r[0]['presentation']['titlePrefixMode']='list'; $r[0]['presentation']['titleSeparator']=' - ';
    step($e,$r,$now); $rows=$s->current(26); $uid=$rows['a']['uid'];
    $title=fn()=>(new CalendarDocument())->inspect($c->objects[$uid]['body'],$uid)['title'];
    check($title()==='Inbox - Collect item','List prefix on first create');
    $r[0]['presentation']['titlePrefixMode']='custom'; $r[0]['presentation']['titlePrefix']='Collection';
    $r[0]['presentation']['titleSeparator']=' / '; step($e,$r,$now);
    check($title()==='Collection / Collect item','Custom prefix replaces list prefix exactly once');
    $r[0]['presentation']['titlePrefixMode']='none'; step($e,$r,$now);
    check($title()==='Collect item','None removes previous prefix and separator');
    check($c->creates===2&&$c->deletes===0&&$s->current(26)['a']['uid']===$uid,'Existing relation and original retained');
    $updates=$c->updates; step($e,$r,$now); check($c->updates===$updates,'Unchanged title mode is a NOOP');
});
runCase('list rename and calendar time edit converge independently through the shared card',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); $d->card['listName']='Inbox';
    $r[0]['presentation']['titlePrefixMode']='list'; $r[0]['presentation']['titleSeparator']=' - ';
    step($e,$r,$now); $rows=$s->current(26); $uid=$rows['a']['uid'];
    $d->card['listName']='Ready'; $c->shift($uid,900); step($e,$r,$now);
    check($d->adoptions===1&&$d->card['start']===$now+4500,'List rename does not block reverse time adoption');
    check((new CalendarDocument())->inspect($c->objects[$uid]['body'],$uid)['title']==='Ready - Collect item','New list name follows current source');
    check((new CalendarDocument())->inspect($c->objects[$rows['b']['uid']]['body'],$rows['b']['uid'])['title']==='Collect item','Other plain title stays independent');
    check((new CalendarDocument())->inspect($c->objects[$rows['b']['uid']]['body'],$rows['b']['uid'])['time']['start']===$d->card['start'],'Other calendar receives adopted time');
    check($c->creates===2&&$c->updates===2,'Only the two actual title/time changes are written');
    step($e,$r,$now); check($c->updates===2,'No repeated prefix or echo update');
});
runCase('simultaneous conflicting dates favor Deck; title-only changes do not suppress reverse time',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now);
    $uid=$s->current(26)['a']['uid']; $c->shift($uid,3600); $d->card['start']+=7200; $d->card['due']+=7200;
    step($e,$r,$now); check($d->adoptions===0,'Deck conflict wins');
    $c->shift($uid,3600); $d->card['title']='Neuer Deck-Titel'; step($e,$r,$now);
    check($d->adoptions===1,'Independent Deck title permits date adoption');
    check((new CalendarDocument())->inspect($c->objects[$uid]['body'],$uid)['title']==='Abholung: Neuer Deck-Titel','Deck title wins independently');
});
runCase('conflicting calendar proposals favor common Deck baseline',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now);
    $rows=$s->current(26); $c->shift($rows['a']['uid'],3600); $c->shift($rows['b']['uid'],7200);
    step($e,$r,$now); check($d->adoptions===0&&$d->card['start']===$now+3600,'No arbitrary calendar order wins');
});
runCase('calendar DELETE removes exact trigger and keeps other tags/events',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $rows=$s->current(26);
    $c->objects[$rows['a']['uid']]=['status'=>'trashed']; step($e,$r,$now);
    check(!isset($d->card['labels'][14])&&isset($d->card['labels'][19],$d->card['labels'][99]),'Only trigger removed');
    check($s->current(26)['a']['state']==='deleted'&&$s->current(26)['b']['state']==='active','Other relation active');
    check($c->creates===2,'Deleted original not recreated');
    $d->card['labels'][14]='Abholung'; ksort($d->card['labels']); step($e,$r,$now);
    check($c->creates===3&&$s->current(26)['a']['generation']===2,'Explicit re-add begins one new lifecycle');
});
runCase('Deck tag DELETE and rule removal preserve Deck tags and handle archived cards',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); unset($d->card['labels'][14]); step($e,$r,$now);
    check($c->deletes===1&&$d->removals===0,'Own DELETE does not remove extra tags');
    $d->card['archived']=true; step($e,[],$now);
    check($c->deletes===2&&isset($d->card['labels'][19]),'Removed rule cleans archived source while retaining tags');
});
runCase('done keep/delete, archive keep and no first-time past events',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); $d->card['start']=$now-7200; $d->card['due']=$now-3600;
    step($e,$r,$now); check($c->creates===0&&$s->rows===[],'No fully historical create');
    $d->card['start']=$now+3600; $d->card['due']=$now+7200; step($e,$r,$now);
    $d->card['archived']=true; $d->card['done']=true; step($e,$r,$now); check($c->deletes===0,'Archive/done keep');
    $r[0]['sync']['onDone']='delete'; step($e,$r,$now); check($c->deletes===1,'Per-rule done delete');
});
runCase('attendees and RSVP remain calendar-owned; Deck categories remain current',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $uid=$s->current(26)['a']['uid'];
    $cal=Reader::read($c->objects[$uid]['body']); unset($cal->VEVENT->ATTENDEE);
    $cal->VEVENT->add('ATTENDEE','mailto:changed@example.invalid',['PARTSTAT'=>'ACCEPTED']);
    $cats=$cal->VEVENT->CATEGORIES->getParts(); $cats[]='Privat'; $cal->VEVENT->CATEGORIES=$cats;
    $cal->VEVENT->LOCATION='Werkstatt'; $c->put($uid,$cal->serialize());
    $d->card['labels'][99]='Dringend'; $d->card['title']='Aktuell'; step($e,$r,$now);
    $cal=Reader::read($c->objects[$uid]['body']);
    check((string)$cal->VEVENT->ATTENDEE==='mailto:changed@example.invalid'&&(string)$cal->VEVENT->ATTENDEE['PARTSTAT']==='ACCEPTED','Attendee choice and RSVP preserved');
    check((string)$cal->VEVENT->LOCATION==='Werkstatt','Unmanaged fields preserved');
    $cats=$cal->VEVENT->CATEGORIES->getParts();
    check(in_array('Privat',$cats,true)&&in_array('Dringend',$cats,true)&&!in_array('Wichtig',$cats,true),'Owned categories updated, manual category retained');
});
runCase('managed description is restored once; unrelated fields and times remain intact',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $uid=$s->current(26)['a']['uid'];
    $original=Reader::read($c->objects[$uid]['body']); $expected=(string)$original->VEVENT->DESCRIPTION;
    check(str_contains($expected,'/apps/deck/board/3/card/26'),'Description points to exact source card');
    check(!str_contains($expected,'Collect item'),'Description does not mirror card contents');
    $original->VEVENT->DESCRIPTION='A calendar note'; $original->VEVENT->LOCATION='Workshop';
    $c->put($uid,$original->serialize()); $updates=$c->updates;
    step($e,$r,$now); $actual=Reader::read($c->objects[$uid]['body']);
    check((string)$actual->VEVENT->DESCRIPTION===$expected&&$c->updates===$updates+1,'Managed description restored with one update');
    check((string)$actual->VEVENT->LOCATION==='Workshop'&&$d->adoptions===0,'Location and source dates unchanged');
    step($e,$r,$now); check($c->updates===$updates+1,'Description equality is a NOOP');
});
runCase('category notification is opt-in and targets actual current attendees only',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $uid=$s->current(26)['a']['uid'];
    check($r[0]['invitations']['notifyOnCategoryChanges']===false,'Old rules default to no category mail');
    $d->card['labels'][99]='Updated'; step($e,$r,$now);
    $cal=Reader::read($c->objects[$uid]['body']);
    check(!isset($cal->VEVENT->ATTENDEE['SCHEDULE-FORCE-SEND']),'Default category update does not force mail');
    $r[0]['invitations']['notifyOnCategoryChanges']=true; $updates=$c->updates;
    step($e,$r,$now); check($c->updates===$updates,'Enabling notifications does not resend equal event');
    unset($cal->VEVENT->ATTENDEE);
    $cal->VEVENT->add('ATTENDEE','mailto:current@example.invalid',['PARTSTAT'=>'ACCEPTED']);
    $cal->VEVENT->add('ATTENDEE','mailto:client@example.invalid',['SCHEDULE-AGENT'=>'CLIENT']);
    $cal->VEVENT->add('ATTENDEE','mailto:none@example.invalid',['SCHEDULE-AGENT'=>'NONE']);
    $c->put($uid,$cal->serialize()); $d->card['labels'][99]='Urgent'; step($e,$r,$now);
    $cal=Reader::read($c->objects[$uid]['body']); $attendees=$cal->VEVENT->select('ATTENDEE');
    check((string)$attendees[0]==='mailto:current@example.invalid'
        &&(string)$attendees[0]['SCHEDULE-FORCE-SEND']==='REQUEST'
        &&(string)$attendees[0]['PARTSTAT']==='ACCEPTED','Native resend preserves current recipient and RSVP');
    check(!isset($attendees[1]['SCHEDULE-FORCE-SEND'])&&!isset($attendees[2]['SCHEDULE-FORCE-SEND']),'Explicit scheduling opt-outs are respected');
    $updates=$c->updates; step($e,$r,$now); check($c->updates===$updates,'Notification is not repeated on equal values');
});
runCase('category notification without organizer never invents attendees',function() {
    [$e,$d,$c,$s,$r,$now]=fixture();
    $r[0]['invitations']['enabled']=false; $r[0]['invitations']['notifyOnCategoryChanges']=true;
    step($e,$r,$now); $uid=$s->current(26)['a']['uid']; $d->card['labels'][99]='Updated'; step($e,$r,$now);
    $cal=Reader::read($c->objects[$uid]['body']);
    check(!isset($cal->VEVENT->ORGANIZER)&&!isset($cal->VEVENT->ATTENDEE),'No scheduling participants are added');
});
runCase('category order is not a meaningful change or a notification',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); $r[0]['invitations']['notifyOnCategoryChanges']=true;
    step($e,$r,$now); $uid=$s->current(26)['a']['uid'];
    $cal=Reader::read($c->objects[$uid]['body']);
    $cal->VEVENT->CATEGORIES=array_reverse($cal->VEVENT->CATEGORIES->getParts()); $c->put($uid,$cal->serialize());
    $updates=$c->updates; step($e,$r,$now); check($c->updates===$updates,'Equivalent category order causes no PUT');
});
runCase('interrupted applied category notification is adopted without another PUT',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); $r=[$r[0]]; $r[0]['invitations']['notifyOnCategoryChanges']=true;
    step($e,$r,$now); $d->card['labels'][99]='Urgent'; $c->fail='after_update';
    $first=$e->run(26,$r,$now,'https://bridge.example.invalid');
    check($first['blocked']!==[]&&$c->updates===1,'Applied update remains uncertain after timeout');
    step($e,$r,$now); step($e,$r,$now);
    check($c->updates===1,'Outcome recovery does not repeat category scheduling');
});
foreach (['before_create','after_create','after_update','after_delete'] as $failure) runCase("crash/retry $failure",function() use($failure) {
    [$e,$d,$c,$s,$r,$now]=fixture(); $r=[$r[0]];
    if (str_contains($failure,'create')) $c->fail=$failure;
    else { step($e,$r,$now); $c->fail=$failure; if ($failure==='after_update') $d->card['title']='Update'; else unset($d->card['labels'][14]); }
    $first=$e->run(26,$r,$now,'https://bridge.example.invalid'); check($first['blocked']!==[],'Failure remains observable');
    step($e,$r,$now); step($e,$r,$now);
    check($c->creates===1,'Retry does not duplicate CREATE');
    if ($failure==='after_update') check($c->updates===1,'Already applied UPDATE not repeated');
    if ($failure==='after_delete') check($c->deletes===1,'Already applied DELETE not repeated');
});
runCase('reverse source race is retried with Deck winning',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $c->shift($s->current(26)['a']['uid'],3600); $d->race=true;
    $first=$e->run(26,$r,$now,'https://bridge.example.invalid'); check(isset($first['blocked']['card']),'Race blocks stale reverse');
    step($e,$r,$now); check($d->adoptions===0,'Newer Deck value preserved');
});
runCase('calendar edit after observation retains its ETag protection and is reconsidered',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now);
    $uid=$s->current(26)['a']['uid']; $d->card['title']='Neuer Titel';
    $c->afterRead=static function(array $row) use($c): void { $c->shift($row['uid'],3600); };
    $first=$e->run(26,$r,$now,'https://bridge.example.invalid');
    check(isset($first['blocked']['a']),'A late calendar edit rejects the stale outgoing write');
    check((new CalendarDocument())->inspect($c->objects[$uid]['body'],$uid)['time']['start']===$now+7200,'Late calendar edit is preserved');
    step($e,$r,$now);
    check($d->adoptions===1 && $d->card['start']===$now+7200,'Retry considers and fans out the calendar edit');
});
runCase('different due-only anchors propagate and a resize becomes explicit endpoints',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); $d->card['start']=null;
    $r[1]['timing']['dueAnchor']='after'; $r[1]['timing']['durationMinutes']=10;
    step($e,$r,$now); $rows=$s->current(26); $due=$d->card['due'];
    $c->shift($rows['a']['uid'],3600); step($e,$r,$now);
    check($d->card['start']===null && $d->card['due']===$due+3600,'Due-only shape preserved on shift');
    $other=(new CalendarDocument())->inspect($c->objects[$rows['b']['uid']]['body'],$rows['b']['uid'])['time'];
    check($other['start']===$d->card['due'] && $other['end']===$d->card['due']+600,'Other anchor uses updated card date');
    $cal=Reader::read($c->objects[$rows['b']['uid']]['body']);
    $cal->VEVENT->DTEND=$cal->VEVENT->DTEND->getDateTime()->modify('+10 minutes');
    $c->put($rows['b']['uid'],$cal->serialize()); step($e,$r,$now);
    check($d->card['start']!==null && $d->card['due']-$d->card['start']===1200,'Resize represented by explicit source start/end');
});
runCase('paused target editing retains existing original; source deletion still cleans it',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now);
    $r[0]['enabled']=false; $r[0]['target']['calendarUri']='changed'; step($e,$r,$now);
    check($c->deletes===0,'Editing a paused target does not cancel');
    $d->card=null; step($e,$r,$now); check($c->deletes===2,'Deleted source cleans paused and active originals');
});
runCase('DATE+duration preserves timezone and normal timed NOOP',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $uid=$s->current(26)['a']['uid'];
    $cal=Reader::read($c->objects[$uid]['body']); unset($cal->VEVENT->DTEND);
    $cal->VEVENT->DURATION='PT1H'; $c->put($uid,$cal->serialize());
    $updates=$c->updates; step($e,$r,$now); check($c->updates===$updates,'Equivalent duration requires no PUT');
});
runCase('independent installations have distinct new UIDs',function() {
    check(Planning::identity(26,'a',1,'instance-one')!==Planning::identity(26,'a',1,'instance-two'),'UID namespace includes instance');
});
runCase('equivalent explicit time zone representation causes no echo PUT',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $uid=$s->current(26)['a']['uid'];
    $cal=Reader::read($c->objects[$uid]['body']); $zone=new DateTimeZone('Europe/Paris');
    $start=$cal->VEVENT->DTSTART->getDateTime()->setTimezone($zone);
    $end=$cal->VEVENT->DTEND->getDateTime()->setTimezone($zone);
    unset($cal->VEVENT->DTSTART,$cal->VEVENT->DTEND);
    $cal->VEVENT->add('DTSTART',$start->format('Ymd\THis'),['TZID'=>'Europe/Paris']);
    $cal->VEVENT->add('DTEND',$end->format('Ymd\THis'),['TZID'=>'Europe/Paris']);
    $c->put($uid,$cal->serialize()); $etag=$c->objects[$uid]['etag'];
    step($e,$r,$now);
    check($c->objects[$uid]['etag']===$etag&&$c->updates===0&&$d->adoptions===0,'Semantic times compare equal across explicit time zones');
});
runCase('ambiguous fields, floating times and invalid sequences are refused',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $uid=$s->current(26)['a']['uid'];
    foreach (['duplicate'=>'ambiguous_calendar_property','floating'=>'floating_calendar_time_not_supported','sequence'=>'invalid_calendar_sequence'] as $kind=>$reason) {
        $cal=Reader::read($c->objects[$uid]['body']);
        if ($kind==='duplicate') $cal->VEVENT->add('SUMMARY','Another title');
        if ($kind==='floating') { unset($cal->VEVENT->DTSTART); $cal->VEVENT->add('DTSTART','20300101T120000'); }
        if ($kind==='sequence') $cal->VEVENT->SEQUENCE=-1;
        try { (new CalendarDocument())->inspect($cal->serialize(),$uid); throw new RuntimeException('Ambiguous object accepted'); }
        catch (DomainException $ex) { check($ex->getMessage()===$reason,'Unambiguous calendar data required: '.$kind); }
    }
});
runCase('sequence exhaustion blocks only its changed projection without overflow',function() {
    [$e,$d,$c,$s,$r,$now]=fixture(); step($e,$r,$now); $uid=$s->current(26)['a']['uid'];
    $cal=Reader::read($c->objects[$uid]['body']); $cal->VEVENT->SEQUENCE=2147483647; $c->put($uid,$cal->serialize());
    step($e,$r,$now); check($c->updates===0,'An unchanged maximum sequence is a NOOP');
    $d->card['title']='Updated title'; $result=$e->run(26,$r,$now,'https://bridge.example.invalid');
    check(($result['blocked']['a']??'')==='calendar_sequence_exhausted'&&$c->updates===1,'Only the exhausted projection blocks; other projection updates');
});
echo "All reconciliation scenarios passed.\n";
