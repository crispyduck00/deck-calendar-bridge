<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/Service/EventPlan.php';
require_once __DIR__.'/../lib/Service/EventDescriptionPolicy.php';
require_once __DIR__.'/../lib/Service/VEventSerializer.php';
use OCA\DeckCalendarBridge\Service\{EventPlan,VEventSerializer};
function ensure(bool $ok,string $what):void {if(!$ok)throw new RuntimeException($what);echo "PASS $what\n";}
function rejection(callable $test,string $why):void {try{$test();}catch(InvalidArgumentException){ensure(true,$why);return;}throw new RuntimeException($why);}
$tz=new DateTimeZone('Europe/Vienna');
$start=new DateTimeImmutable('2027-03-28T03:00:00+02:00');
$end=$start->modify('+10 minutes');
$id='dcb-'.str_repeat('a',64).'@deck-calendar-bridge';
$plan=new EventPlan($id,'r1','principals/users/usera','personal',"Café, A;B\\C\nNext", $start,$end,false,'due_only');
$serializer=new VEventSerializer();
$stamp=new DateTimeImmutable('2026-10-14T20:00:00Z');
$ics=$serializer->serialize($plan,$stamp,0,'https://example.invalid/index.php/apps/deck/card/17');
$unfolded=str_replace("\r\n ", "", $ics);
ensure(str_contains($unfolded,"BEGIN:VEVENT\r\nUID:$id\r\n"),'stable UID');
ensure(str_contains($ics,"DTSTART:20270328T010000Z\r\n"),'UTC preserves DST event time');
ensure(str_contains($ics,"DTEND:20270328T011000Z\r\n"),'UTC duration preserved');
$slash=chr(92);
$expected='SUMMARY:Café'.$slash.', A'.$slash.';B'.$slash.$slash.'C'.$slash.'nNext';
ensure(str_contains($unfolded,$expected),'RFC text escaping');
ensure(str_contains($unfolded,'Linked Deck card: https://example.invalid/'),'safe link description');
ensure(str_contains($unfolded,'Time changes made here are synchronized back to Deck.'),'timed rule explains reverse sync');
$forward=$serializer->serialize($plan,$stamp,0,'https://example.invalid/deck',null,false);
ensure(str_contains(str_replace("\r\n ",'',$forward),'Times are managed in Deck for this rule.'),'lossy date rule notice does not promise reverse sync');
ensure(str_ends_with($ics,"END:VCALENDAR\r\n"),'CRLF calendar ending');
ensure(!str_contains($ics, "\r\nATTENDEE:"),'no invitations by default');
$reminded=$serializer->serialize($plan,$stamp,0,null,15);
ensure(str_contains($reminded,"BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nTRIGGER:-PT15M\r\nEND:VALARM"),'no-invitation reminder present');
$all=new EventPlan($id,'r1','principals/users/usera','personal','Day',new DateTimeImmutable('2027-03-28T00:00:00+01:00'),new DateTimeImmutable('2027-03-29T00:00:00+02:00'),true,'due_only');
$a=$serializer->serialize($all,$stamp);
ensure(str_contains($a,"DTSTART;VALUE=DATE:20270328\r\n"),'all-day local DTSTART');
ensure(str_contains($a,"DTEND;VALUE=DATE:20270329\r\n"),'exclusive all-day end on DST day');
$long=new EventPlan($id,'r1','principals/users/usera','personal',str_repeat('ä',100),$start,$end,false,'due_only');
$folded=$serializer->serialize($long,$stamp);
foreach(explode("\r\n",trim($folded)) as $line){ensure(strlen($line)<=75,'UTF-8 folded line <= 75 bytes');}
$bad=new EventPlan($id,'r1','principals/users/usera','personal',"A\x00B",$start,$end,false,'due_only');
rejection(fn()=>$serializer->serialize($bad,$stamp),'control character rejected');
rejection(fn()=>$serializer->serialize($plan,$stamp,0,'http://example.invalid'), 'insecure link rejected');
rejection(fn()=>$serializer->serialize($plan,$stamp,-1),'invalid sequence rejected');
echo "VEVENT serializer checks passed.\n";
