<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

/** Only a saved administrator rule delegates these narrowly scoped operations. */
interface DeckPort {
    /** Current source snapshot, including live parent listName for title rules. */
    public function read(int $cardId): ?array;
    public function adoptTimes(array $rule, array $expected, array $times): array;
    public function removeTrigger(array $relation, array $rule, array $expected): ?array;
}
