<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Pure fan-out, no calendar or Deck writes. */
final class MultiEventPlanner {
    public function __construct(
        private MultiRuleResolver $resolver,
        private TimeWindowCalculator $times,
        private EventIdentity $identities,
    ) {}

    /** @return array<string,EventPlan> keyed by permanent rule slot */
    public function plan(
        string $cardId, int $boardId, array $labelIds, string $title,
        ?DateTimeImmutable $start, ?DateTimeImmutable $due,
        bool $startDateOnly, bool $dueDateOnly, array $rules, bool $completed = false,
        array $deckLabelNames = [], ?string $listName = null
    ): array {
        if ($cardId === '' || trim($title) === '' || strlen($title) > 1024) {
            throw new InvalidArgumentException('Invalid card');
        }
        if (($startDateOnly && $start === null) || ($dueDateOnly && $due === null)) {
            throw new InvalidArgumentException('Invalid date-only flags');
        }
        $result = [];
        foreach ($this->resolver->resolve($boardId, $labelIds, $rules) as $slot => $rule) {
            if ($completed && ($rule['sync']['onDone'] ?? 'keep') === 'delete') { continue; }
            if ($start === null && $due === null) { continue; }
            if (($rule['target']['mode'] ?? '') !== 'fixed') {
                throw new InvalidArgumentException('Dynamic assignee target is not supported');
            }
            $timing = $rule['timing'];
            $dateMode=$timing['dateOnly'] ?? 'skip';
            if ($startDateOnly || $dueDateOnly || $dateMode !== 'skip') {
                if ($dateMode === 'skip') { continue; }
                // Deck 1.19 does not expose "date only" bits. Any non-skip
                // date mode deliberately ignores the Deck clock for THIS RULE.
                // An explicit IANA timezone determines the calendar-day edges
                // regardless of the UTC storage timezone of the Card datetime.
                $timezone=new \DateTimeZone($timing['dateTimeZone'] ?? 'UTC');
                $first=($start ?? $due)->setTimezone($timezone)->setTime(0,0);
                $last=($due ?? $start)->setTimezone($timezone)->setTime(0,0);
                $exclusiveEnd=$last->modify('+1 day');
                if ($exclusiveEnd<=$first
                    || $exclusiveEnd->getTimestamp()-$first->getTimestamp()>366*86400) {
                    throw new InvalidArgumentException('Invalid date-only calendar range');
                }
                if ($dateMode==='all_day') {
                    $window=new TimeWindow($first,$exclusiveEnd);
                    $allDay=true;
                } elseif ($dateMode==='day_edges') {
                    $duration=$timing['durationMinutes']*60;
                    if ($start === null) {
                        // Due date 2027-03-28 => last local minutes of that
                        // day, next-day 00:00 is exclusive end.
                        $end=$exclusiveEnd;
                        $begin=$end->setTimestamp($end->getTimestamp()-$duration);
                    } elseif ($due === null) {
                        // Start date 2027-03-28 => local 00:00 plus duration.
                        $begin=$first;
                        $end=$begin->setTimestamp($begin->getTimestamp()+$duration);
                    } else {
                        // With both dates provided the complete inclusive date
                        // range is a timed interval (may cross DST boundaries).
                        $begin=$first;
                        $end=$exclusiveEnd;
                    }
                    $window=new TimeWindow($begin,$end);
                    $allDay=false;
                } else {
                    throw new InvalidArgumentException('Invalid date-only rule mode');
                }
            } else {
                $window = $this->times->calculate($start, $due, $timing['durationMinutes'], $timing['dueAnchor'], $timing['maxBlockMinutes']);
                $allDay = false;
            }
            $shape = $start !== null && $due !== null ? 'both' : ($start !== null ? 'start_only' : 'due_only');
            $categories=array_values($rule['presentation']['categories'] ?? []);
            if (($rule['presentation']['syncDeckTags'] ?? false) === true) {
                // Only names of tags actually on this ACL-checked card.
                // Never expose tags without a per-rule opt-in.
                foreach ($labelIds as $labelId) {
                    if (!isset($deckLabelNames[$labelId])) {
                        throw new InvalidArgumentException('Deck tag name unavailable');
                    }
                    $name=$deckLabelNames[$labelId];
                    if (!is_string($name) || trim($name)==='' || strlen($name)>80
                        || preg_match('//u',$name)!==1
                        || preg_match('/[\x00-\x1f\x7f]/',$name)) {
                        throw new InvalidArgumentException('Invalid Deck category tag');
                    }
                    if (!in_array($name,$categories,true)) $categories[]=$name;
                }
            }
            if (count($categories)>20) {
                throw new InvalidArgumentException('Too many calendar categories');
            }
            $result[$slot] = new EventPlan(
                $this->identities->uid('deck', $cardId, $slot), $rule['id'],
                $rule['target']['principal'], $rule['target']['calendarUri'],
                (new EventTitlePolicy())->renderForRule($title, $rule['presentation'] ?? [],$listName),
                $window->start, $window->end, $allDay, $shape,
                $categories
            );
        }
        return $result;
    }
}
