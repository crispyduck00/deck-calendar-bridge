<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use DateTimeImmutable;
use DomainException;
use OCA\DeckCalendarBridge\Service\EventPlan;
use OCA\DeckCalendarBridge\Service\MultiEventPlanner;

/** Pure planning and reversible time mapping. No IO or user impersonation. */
final class Planning {
    public function __construct(private MultiEventPlanner $planner,private ?\OCP\IConfig $config=null) {}

    public static function times(array $card): array {
        return ['start'=>$card['start'], 'due'=>$card['due']];
    }
    public static function matches(array $card, array $rule): bool {
        return !$card['deleted'] && $card['board']===$rule['source']['boardIds'][0]
            && isset($card['labels'][$rule['source']['labelIds'][0]]);
    }
    public static function binding(array $row, array $rule): bool {
        return $row['board_id']===$rule['source']['boardIds'][0]
            && $row['tag_id']===$rule['source']['labelIds'][0]
            && $row['principal']===$rule['target']['principal']
            && $row['calendar_uri']===$rule['target']['calendarUri'];
    }
    public static function eligible(array $card, int $now,?array $rule=null): bool {
        // Admission uses SOURCE dates, as requested; never delete an existing
        // relation merely because its start/due has subsequently passed.
        if ($card['deleted'] || $card['archived']) return false;
        if ($rule!==null && ($rule['timing']['dateOnly']??'skip')!=='skip') {
            $zone=new \DateTimeZone($rule['timing']['dateTimeZone']);
            $today=(new DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Ymd');
            foreach ([$card['start'],$card['due']] as $v) {
                if ($v!==null && (new DateTimeImmutable('@'.$v))->setTimezone($zone)->format('Ymd') >= $today) return true;
            }
            return false;
        }
        return (($card['start']!==null && $card['start'] >= $now)
                || ($card['due']!==null && $card['due'] >= $now));
    }
    public static function recipe(array $rule): string {
        return hash('sha256', json_encode($rule['timing'], JSON_THROW_ON_ERROR));
    }
    public static function identity(int $cardId, string $ruleId, int $generation,string $instance='test'): string {
        return 'dcb-'.hash('sha256', "relations-v1\0$instance\0$cardId\0$ruleId\0$generation").'@deck-calendar-bridge';
    }
    public function uid(int $cardId,string $ruleId,int $generation): string {
        $instance=(string)($this->config?->getSystemValue('instanceid','test')??'test');
        $install=$this->config?->getAppValue('deckcalendarbridge','runtime_namespace','legacy')??'test';
        return self::identity($cardId,$ruleId,$generation,$instance."\0".$install);
    }
    public static function uri(string $uid): string {
        return 'dcb-'.hash('sha256',$uid).'.ics';
    }
    public function plan(array $card, array $rule, string $uid): EventPlan {
        $plans=$this->planner->plan((string)$card['id'],$card['board'],
            array_map('intval',array_keys($card['labels'])),$card['title'],
            self::date($card['start']), self::date($card['due']),false,false,[$rule],
            $card['done'],$card['labels'],$card['listName']??null);
        $p=$plans['rule-'.$rule['id']] ?? throw new DomainException('no_valid_event_window');
        return new EventPlan($uid,$p->ruleId,$p->principal,$p->calendarUri,$p->title,
            $p->start,$p->end,$p->allDay,$p->sourceShape,$p->categories);
    }
    public static function window(EventPlan $p): array {
        return ['start'=>$p->allDay?$p->start->format('Ymd'):$p->start->getTimestamp(),
            'end'=>$p->allDay?$p->end->format('Ymd'):$p->end->getTimestamp(),
            'allDay'=>$p->allDay];
    }
    public function reverse(array $card,array $rule,array $window): array {
        if ($window['allDay'] || ($rule['timing']['dateOnly']??'skip')!=='skip') {
            throw new DomainException('date_mode_is_forward_only');
        }
        $start=$window['start']; $end=$window['end'];
        if (!is_int($start) || !is_int($end) || $end <= $start) {
            throw new DomainException('invalid_reverse_window');
        }
        $both=$card['start']!==null && $card['due']!==null;
        $length=$end-$start;
        // A resize needs two source endpoints. This changes only time fields,
        // never the duration of every other card governed by this rule.
        if ($both || $length!==$rule['timing']['durationMinutes']*60) {
            return ['start'=>$start,'due'=>$end];
        }
        if ($card['start']!==null) return ['start'=>$start,'due'=>null];
        $due=match($rule['timing']['dueAnchor']) {
            'before'=>$end, 'after'=>$start, 'center'=>$start+intdiv($length,2)
        };
        return ['start'=>null,'due'=>$due];
    }
    public static function date(?int $time): ?DateTimeImmutable {
        return $time===null?null:new DateTimeImmutable('@'.$time);
    }
}
