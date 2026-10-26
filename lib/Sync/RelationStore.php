<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

interface RelationStore {
    /** Latest lifecycle per rule, including confirmed deletions. */
    public function current(int $cardId): array;
    public function insert(array $relation): array;
    /** Compare-and-swap on revision; increments revision. */
    public function save(array $relation): array;
}
