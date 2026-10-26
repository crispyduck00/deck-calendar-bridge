<?php
declare(strict_types=1);
foreach (['TimeWindow','TimeWindowCalculator','EventIdentity','EventPlan','EventTitlePolicy',
          'RuleValidator','MultiRuleResolver','MultiEventPlanner'] as $n) {
    require_once __DIR__.'/../lib/Service/'.$n.'.php';
}
use OCA\DeckCalendarBridge\Service\{RuleValidator,MultiRuleResolver,MultiEventPlanner,
TimeWindowCalculator,EventIdentity};
function dateCheck(bool $b,string $m):void{if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
$rulesRaw=[['id'=>'date-rule','enabled'=>true,
    'source'=>['type'=>'deck','boardIds'=>[2],'labelIds'=>[19]],
    'target'=>['mode'=>'fixed','principal'=>'principals/users/usera','calendarUri'=>'family'],
    'timing'=>['durationMinutes'=>20,'maxBlockMinutes'=>240,'dueAnchor'=>'center',
        'dateOnly'=>'day_edges','dateTimeZone'=>'Europe/Vienna']]];
$validator=new RuleValidator();
$rules=$validator->validate($rulesRaw);
dateCheck($rules[0]['invitations']['notifyOnCategoryChanges']===false,'existing rules default to no category mail');
$notify=$rulesRaw; $notify[0]['invitations']=['notifyOnCategoryChanges'=>true];
dateCheck($validator->validate($notify)[0]['invitations']['notifyOnCategoryChanges']===true,'category notification independent of initial invitations');
$notify[0]['invitations']['notifyOnCategoryChanges']='yes';
try{$validator->validate($notify);throw new RuntimeException('nonboolean notification accepted');}
catch(InvalidArgumentException){dateCheck(true,'category notification requires a JSON boolean');}
$p=new MultiEventPlanner(new MultiRuleResolver(),new TimeWindowCalculator(),new EventIdentity());
$date=new DateTimeImmutable('2027-03-28T10:00:00+02:00');
$plan=function($start,$due) use ($p,&$rules) {
    return $p->plan('42',2,[19],'Test',$start,$due,false,false,$rules)['rule-date-rule'];
};
$due=$plan(null,$date);
dateCheck(!$due->allDay && $due->start->format('Y-m-d H:i')==='2027-03-28 23:40'
    && $due->end->format('Y-m-d H:i')==='2027-03-29 00:00',
    'due-only local date ends 23:40 to next day 00:00 exclusive');
$start=$plan($date,null);
dateCheck(!$start->allDay && $start->start->format('Y-m-d H:i')==='2027-03-28 00:00'
    && $start->end->format('Y-m-d H:i')==='2027-03-28 00:20',
    'start-only date begins 00:00 not 00:01');
$inputUtc=new DateTimeImmutable('2027-10-30T23:15:00+00:00');
$fall=$plan(null,$inputUtc);
dateCheck($fall->end->format('Y-m-d H:i')==='2027-11-01 00:00'
    && $fall->start->format('Y-m-d H:i')==='2027-10-31 23:40',
    'UTC Deck timestamps use Vienna local date through winter DST transition');
$rulesRaw[0]['timing']['dateOnly']='all_day';
$rules=$validator->validate($rulesRaw);
$all=$plan(null,$date);
dateCheck($all->allDay && $all->start->format('Y-m-d')==='2027-03-28'
    && $all->end->format('Y-m-d')==='2027-03-29'
    && $all->end->getTimestamp()-$all->start->getTimestamp()===23*3600,
    'all-day local date has DST-aware exclusive next midnight');
$rulesRaw[0]['timing']['dateOnly']='skip';
$rules=$validator->validate($rulesRaw);
$timed=$plan(null,$date);
dateCheck(!$timed->allDay && $timed->end->getTimestamp()!==$due->end->getTimestamp(),
    'default mode respects explicit Deck clock and leaves prior behavior intact');
$rulesRaw[0]['timing']['dateOnly']='day_edges';
$rulesRaw[0]['timing']['dateTimeZone']='Not/A_Timezone';
try{$validator->validate($rulesRaw);throw new RuntimeException('invalid timezone accepted');}
catch(InvalidArgumentException $e){dateCheck(true,'invalid timezone rejected');}
require_once __DIR__.'/../lib/Sync/Planning.php';
$reverse=new \OCA\DeckCalendarBridge\Sync\Planning($p);
$forced=$rules; $forced[0]['timing']['dateOnly']='all_day';
try {
    $reverse->reverse(['start'=>null,'due'=>$date->getTimestamp()],$forced[0],
        ['start'=>$date->getTimestamp(),'end'=>$date->getTimestamp()+1200,'allDay'=>false]);
    throw new RuntimeException('forced date rule adopted an arbitrary clock');
} catch (DomainException $e) { dateCheck($e->getMessage()==='date_mode_is_forward_only','lossy day rules do not reverse-push midnight'); }
echo "Date-only per-rule mode checks passed.\n";
