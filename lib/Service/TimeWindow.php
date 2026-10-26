<?php

declare(strict_types=1);

namespace OCA\DeckCalendarBridge\Service;

final readonly class TimeWindow {
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
        if ($end <= $start) {
            throw new \InvalidArgumentException('Event end must be after its start.');
        }
    }

    public function durationSeconds(): int {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }
}
