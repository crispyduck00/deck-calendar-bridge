<?php

declare(strict_types=1);

namespace OCA\DeckCalendarBridge\Service;

/** Pure calculation, without side effects or Nextcloud dependencies. */
final class TimeWindowCalculator {
    public function calculate(
        ?\DateTimeImmutable $start,
        ?\DateTimeImmutable $due,
        int $durationMinutes = 10,
        string $anchor = 'before',
        int $maxBlockMinutes = 240,
    ): ?TimeWindow {
        if ($durationMinutes < 1 || $durationMinutes > 1440) {
            throw new \InvalidArgumentException('Duration must be between 1 and 1440 minutes.');
        }
        if ($maxBlockMinutes < 1) {
            throw new \InvalidArgumentException('Maximum block length must be positive.');
        }
        if (!in_array($anchor, ['before', 'center', 'after'], true)) {
            throw new \InvalidArgumentException('Unsupported due-time anchor.');
        }

        if ($start !== null && $due !== null) {
            // Explicit Deck start + due are authoritative VEVENT DTSTART/DTEND.
            // maxBlockMinutes limits only inferred time windows, never truncates
            // or rejects an explicitly specified Deck appointment.
            if ($due <= $start) {
                throw new \InvalidArgumentException('Due time must be after start time.');
            }
            return new TimeWindow($start, $due);
        } elseif ($due !== null) {
            $duration = $durationMinutes * 60;
            $startOffset = match ($anchor) {
                'before' => -$duration,
                'center' => -intdiv($duration, 2),
                'after' => 0,
            };
            $begin = $due->setTimestamp($due->getTimestamp() + $startOffset);
            $window = new TimeWindow($begin, $begin->setTimestamp($begin->getTimestamp() + $duration));
        } elseif ($start !== null) {
            $window = new TimeWindow($start, $start->setTimestamp($start->getTimestamp() + $durationMinutes * 60));
        } else {
            return null;
        }

        if ($window->durationSeconds() > $maxBlockMinutes * 60) {
            throw new \InvalidArgumentException('Computed block exceeds maximum duration.');
        }
        return $window;
    }
}
