<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use DomainException;
use OCA\DeckCalendarBridge\Service\EventPlan;
use OCA\DeckCalendarBridge\Service\ManagedCategoryPolicy;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/** Normalizes managed fields; preserves attendees, RSVP, location and alarms. */
final class CalendarDocument {
    private function parse(string $body,string $uid): VCalendar {
        if (strlen($body)>1048576) throw new DomainException('calendar_object_too_large');
        $cal=Reader::read($body);
        if (!$cal instanceof VCalendar || count($cal->select('VEVENT'))!==1
            || (string)$cal->VEVENT->UID!==$uid) throw new DomainException('calendar_identity_mismatch');
        $e=$cal->VEVENT;
        foreach (['UID','SUMMARY','DESCRIPTION','SEQUENCE'] as $field) {
            if (count($e->select($field))>1) throw new DomainException('ambiguous_calendar_property');
        }
        $sequence=(string)($e->SEQUENCE??'0');
        if (!preg_match('/^[0-9]{1,10}$/D',$sequence) || (int)$sequence>2147483647) {
            throw new DomainException('invalid_calendar_sequence');
        }
        foreach (['RRULE','RDATE','EXDATE','RECURRENCE-ID'] as $field) {
            if (count($e->select($field))) throw new DomainException('recurrence_not_supported');
        }
        if (count($e->select('DTSTART'))!==1
            || count($e->select('DTEND'))+count($e->select('DURATION'))!==1) {
            throw new DomainException('calendar_endpoints_required');
        }
        foreach (['DTSTART','DTEND'] as $field) {
            if (isset($e->$field) && (string)($e->$field['VALUE']??'')!=='DATE'
                && !str_ends_with((string)$e->$field,'Z') && !isset($e->$field['TZID'])) {
                throw new DomainException('floating_calendar_time_not_supported');
            }
        }
        if (!isset($e->DTEND)) {
            $end=$e->DTSTART->getDateTime()->add(\Sabre\VObject\DateTimeParser::parseDuration((string)$e->DURATION));
            $allDay=(string)($e->DTSTART['VALUE']??'')==='DATE';
            $value=$allDay?$end->format('Ymd'):$end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $e->add('DTEND',$value,$allDay?['VALUE'=>'DATE']:[]);
            unset($e->DURATION);
        }
        return $cal;
    }
    public function inspect(string $body,string $uid): array {
        $e=$this->parse($body,$uid)->VEVENT;
        $allDay=(string)($e->DTSTART['VALUE']??'')==='DATE';
        if ($allDay!==((string)($e->DTEND['VALUE']??'')==='DATE')) {
            throw new DomainException('mixed_calendar_date_types');
        }
        $time=['start'=>$allDay?(string)$e->DTSTART:$e->DTSTART->getDateTime()->getTimestamp(),
            'end'=>$allDay?(string)$e->DTEND:$e->DTEND->getDateTime()->getTimestamp(),
            'allDay'=>$allDay];
        if ($time['end']<=$time['start']) throw new DomainException('invalid_calendar_window');
        $categories=[];
        foreach ($e->select('CATEGORIES') as $property) {
            foreach ($property->getParts() as $v) $categories[]=$v;
        }
        return ['time'=>$time,'title'=>(string)($e->SUMMARY??''),'description'=>(string)($e->DESCRIPTION??''),'categories'=>$categories,
            'sequence'=>(int)(string)($e->SEQUENCE??'0')];
    }
    /** Returns identical body on NOOP; irrelevant ETag changes don't cause PUTs. */
    public function reconcile(string $body,EventPlan $plan,?array $owned,string $description,bool $notifyCategoryChanges=false): array {
        $cal=$this->parse($body,$plan->uid); $e=$cal->VEVENT;
        $before=$this->inspect($body,$plan->uid);
        $categories=(new ManagedCategoryPolicy())->reconcile($before['categories'],$plan->categories,$owned);
        $oldCategories=$before['categories']; $newCategories=$categories['categories'];
        sort($oldCategories,SORT_STRING); sort($newCategories,SORT_STRING);
        $categoriesChanged=$oldCategories!==$newCategories;
        $changed=$before['time']!==Planning::window($plan) || $before['title']!==$plan->title
            || $before['description']!==$description || $categoriesChanged;
        if (!$changed) return ['body'=>$body,'changed'=>false,'owned'=>$categories['owned']];
        if ($before['sequence']===2147483647) throw new DomainException('calendar_sequence_exhausted');
        $e->SUMMARY=$plan->title;
        $e->DESCRIPTION=$description;
        unset($e->DTSTART,$e->DTEND,$e->DURATION);
        if ($plan->allDay) {
            $e->add('DTSTART',$plan->start->format('Ymd'),['VALUE'=>'DATE']);
            $e->add('DTEND',$plan->end->format('Ymd'),['VALUE'=>'DATE']);
        } else {
            $utc=new \DateTimeZone('UTC');
            $e->add('DTSTART',$plan->start->setTimezone($utc)->format('Ymd\THis\Z'));
            $e->add('DTEND',$plan->end->setTimezone($utc)->format('Ymd\THis\Z'));
        }
        unset($e->CATEGORIES);
        if ($categories['categories']!==[]) $e->add('CATEGORIES',$categories['categories']);
        if ($categoriesChanged && $notifyCategoryChanges && isset($e->ORGANIZER)) {
            foreach ($e->select('ATTENDEE') as $attendee) {
                // RFC 6638: request native scheduling, without changing the
                // title/notes, recipients or their participation state. Honor
                // attendees whose client explicitly manages delivery itself.
                if (strtoupper((string)($attendee['SCHEDULE-AGENT']??'SERVER'))==='SERVER') {
                    $attendee['SCHEDULE-FORCE-SEND']='REQUEST';
                }
            }
        }
        $e->SEQUENCE=$before['sequence']+1;
        $e->DTSTAMP=gmdate('Ymd\THis\Z');
        return ['body'=>$cal->serialize(),'changed'=>true,'owned'=>$categories['owned']];
    }
}
