<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure RFC5545 invite-capable payload composer. Never sends mail or writes a
 * calendar object; scheduling requires organizer authorization in the DAV layer.
 */
final class InvitationEventComposer {
    public function __construct(private VEventSerializer $serializer) {}

    /** @param list<string> $attendees */
    public function compose(
        EventPlan $plan, DateTimeImmutable $stamp, string $organizer,
        array $attendees, ?int $reminderMinutes, int $sequence = 0, ?string $deckUrl = null, bool $bidirectionalTimes = true,
    ): string {
        $organizer = $this->email($organizer);
        if ($attendees !== array_values($attendees) || count($attendees) > 50) {
            throw new InvalidArgumentException('Invalid attendee list');
        }
        if ($reminderMinutes !== null && ($reminderMinutes < 0 || $reminderMinutes > 10080)) {
            throw new InvalidArgumentException('Invalid reminder');
        }
        $seen = [];
        $properties = ['ORGANIZER:mailto:' . $organizer];
        foreach ($attendees as $attendee) {
            $email = $this->email($attendee);
            $normalized = strtolower($email);
            if ($normalized === strtolower($organizer) || isset($seen[$normalized])) continue;
            $seen[$normalized] = true;
            $properties[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $email;
        }
        if ($reminderMinutes !== null && $reminderMinutes > 0) {
            $properties[] = 'BEGIN:VALARM';
            $properties[] = 'ACTION:DISPLAY';
            $properties[] = 'DESCRIPTION:Reminder';
            $properties[] = 'TRIGGER:-PT' . $reminderMinutes . 'M';
            $properties[] = 'END:VALARM';
        }
        $ics = $this->serializer->serialize($plan, $stamp, $sequence, $deckUrl, null, $bidirectionalTimes);
        $needle = "END:VEVENT\r\n";
        if (substr_count($ics, $needle) !== 1) {
            throw new InvalidArgumentException('Unexpected VEVENT representation');
        }
        return str_replace($needle, implode("\r\n", array_map($this->fold(...), $properties)) . "\r\n" . $needle, $ics);
    }

    /** Invitation properties contain only ASCII octets; the fold marker counts. */
    private function fold(string $line): string {
        $pieces = [];
        $first = true;
        while ($line !== '') {
            $limit = $first ? 75 : 74;
            $pieces[] = substr($line, 0, $limit);
            $line = substr($line, $limit);
            $first = false;
        }
        return implode("\r\n ", $pieces);
    }

    private function email(mixed $value): string {
        if (!is_string($value) || strlen($value) > 254
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid email address');
        }
        return $value;
    }
}
