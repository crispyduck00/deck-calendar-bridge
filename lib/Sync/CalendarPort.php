<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

interface CalendarPort {
    /** status: present, trashed, absent. Unavailable/moved/ambiguous throws. */
    public function read(array $relation): array;
    public function create(array $relation, string $body): void;
    public function update(array $relation, string $etag, string $body): void;
    public function delete(array $relation, string $etag): void;
    public function organizerEmail(string $principal): string;
}
