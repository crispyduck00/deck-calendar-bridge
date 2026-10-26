<?php
declare(strict_types=1);

namespace OCA\DeckCalendarBridge\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Deterministic RFC 5545 VEVENT serialization. No IO, scheduling or participants.
 * Time-specific values are UTC; date-only values use the local calendar DATE.
 * All lines folded at UTF-8 character boundaries to <= 75 octets.
 */
final class VEventSerializer {
    public function serialize(EventPlan $plan, DateTimeImmutable $stamp, int $sequence = 0, ?string $deckUrl = null, ?int $reminderMinutes = null, bool $bidirectionalTimes = true): string {
        if ($sequence < 0 || $sequence > 2147483647) {
            throw new InvalidArgumentException('Invalid sequence');
        }
        if (!preg_match('/^dcb-[a-f0-9]{64}@deck-calendar-bridge$/D', $plan->uid)) {
            throw new InvalidArgumentException('Invalid event UID');
        }
        if ($plan->end <= $plan->start) {
            throw new InvalidArgumentException('Invalid event time range');
        }
        $description=$deckUrl===null?null:EventDescriptionPolicy::forCard($deckUrl,$bidirectionalTimes);
        $utc = new DateTimeZone('UTC');
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Deck Calendar Bridge//EN',
            'CALSCALE:GREGORIAN', 'BEGIN:VEVENT',
            'UID:' . $plan->uid,
            'DTSTAMP:' . $stamp->setTimezone($utc)->format('Ymd\THis\Z'),
            'SEQUENCE:' . $sequence,
        ];
        if ($plan->allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $plan->start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $plan->end->format('Ymd');
        } else {
            $lines[] = 'DTSTART:' . $plan->start->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $plan->end->setTimezone($utc)->format('Ymd\THis\Z');
        }
        $lines[] = 'SUMMARY:' . $this->escapeText($plan->title);
        if ($plan->categories !== []) {
            $lines[] = 'CATEGORIES:' . implode(',', array_map($this->escapeText(...), $plan->categories));
        }
        if ($description !== null) {
            $lines[] = 'DESCRIPTION:' . $this->escapeText($description);
        }
        if ($reminderMinutes !== null && $reminderMinutes > 0) {
            if ($reminderMinutes > 10080) throw new InvalidArgumentException('Reminder exceeds 7 days');
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:Reminder';
            $lines[] = 'TRIGGER:-PT' . $reminderMinutes . 'M';
            $lines[] = 'END:VALARM';
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    private function escapeText(string $text): string {
        if ($text === '' || strlen($text) > 4096 || preg_match('//u', $text) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text) === 1) {
            throw new InvalidArgumentException('Invalid calendar text');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }

    private function fold(string $line): string {
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new InvalidArgumentException('Invalid UTF-8');
        }
        $result = '';
        $length = 0;
        foreach ($characters as $character) {
            $n = strlen($character);
            if ($length + $n > 75) {
                $result .= "\r\n ";
                $length = 1;
            }
            $result .= $character;
            $length += $n;
        }
        return $result;
    }
}
