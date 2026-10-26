<?php
declare(strict_types=1);

namespace OCA\DeckCalendarBridge\Service;

/** Immutable desired state, not a calendar write operation. */
final class EventPlan {
    public function __construct(
        public readonly string $uid,
        public readonly string $ruleId,
        public readonly string $principal,
        public readonly string $calendarUri,
        public readonly string $title,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly bool $allDay,
        public readonly string $sourceShape,
        /** @var list<string> */ public readonly array $categories = [],
    ) {}
}
