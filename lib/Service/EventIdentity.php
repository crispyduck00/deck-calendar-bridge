<?php
declare(strict_types=1);

namespace OCA\DeckCalendarBridge\Service;

/** Stable per source object and logical slot, independent of rule and calendar. */
final class EventIdentity {
    public function uid(string $sourceType, string $sourceId, string $slot = 'default'): string {
        foreach ([$sourceType, $sourceId, $slot] as $value) {
            if ($value === '') {
                throw new \InvalidArgumentException('Event identity components must not be empty.');
            }
        }
        return 'dcb-' . hash('sha256', implode("\0", [$sourceType, $sourceId, $slot])) . '@deck-calendar-bridge';
    }
}
