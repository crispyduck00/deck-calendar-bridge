<?php
declare(strict_types=1);
foreach (['EventPlan','EventDescriptionPolicy','VEventSerializer','InvitationEventComposer','EventIdentity'] as $name) require_once __DIR__.'/../lib/Service/'.$name.'.php';
use OCA\DeckCalendarBridge\Service\{EventPlan,VEventSerializer,InvitationEventComposer,EventIdentity};
function assertInvite(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$start=new DateTimeImmutable('2027-03-28T03:15:00+02:00');$end=$start->modify('+10 minutes');
$p=new EventPlan((new EventIdentity())->uid('deck','17','rule-usera'),'usera','principals/users/usera','personal','Meeting',$start,$end,false,'due_only');
$c=new InvitationEventComposer(new VEventSerializer());
$ics=$c->compose($p,$start,'usera@example.com',['userb@example.com','userb@example.com','usera@example.com'],5,1,'https://cloud.example.com/deck');
assertInvite(str_contains($ics,"ORGANIZER:mailto:usera@example.com\r\n"),'organizer serialized');
assertInvite(substr_count($ics,'ATTENDEE;')===1,'duplicate and self attendee eliminated');
assertInvite(str_contains(str_replace("\r\n ", '', $ics),'PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:userb@example.com'),'RSVP requested');
assertInvite(str_contains($ics,"TRIGGER:-PT5M\r\n"),'display alarm created');
assertInvite(str_contains($ics,'SEQUENCE:1'),'sequence retained');
assertInvite(str_contains(str_replace("\r\n ",'',$ics),'Linked Deck card: https://cloud.example.com/deck'),'description contains only card link and stable ownership notice');
assertInvite(!str_contains($c->compose($p,$start,'usera@example.com',[],null),'ATTENDEE;'),'no attendee allowed');
foreach ([['badaddress',['x@example.com']],['a@example.com',["foo\r\nbar@example.com"]]] as [$from,$to]) {
 try{$c->compose($p,$start,$from,$to,5);throw new RuntimeException('Invalid input accepted');}
 catch(InvalidArgumentException){echo "PASS email injection rejected\n";}
}
try{$c->compose($p,$start,'usera@example.com',[],10081);throw new RuntimeException('Invalid alarm accepted');}
catch(InvalidArgumentException){echo "PASS invalid alarm rejected\n";}
// New invitations must be composed from the most recently saved list.
$fresh=$c->compose($p,$start,'usera@example.com',['person@example.at'],null);
assertInvite(str_contains(str_replace("\r\n ", '', $fresh),'mailto:person@example.at'),'new event contains changed recipient');
assertInvite(!str_contains($fresh,'person@example.com'),'new event never reuses old rule recipient');
echo "Invitation serializer checks passed.\n";
