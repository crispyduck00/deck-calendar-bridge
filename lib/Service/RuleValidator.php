<?php
declare(strict_types=1);

namespace OCA\DeckCalendarBridge\Service;

use InvalidArgumentException;

/**
 * Validates rule syntax, never grants Deck/calendar permissions.
 * A rule is disabled unless explicitly enabled.
 */
final class RuleValidator {
    public function validate(mixed $input): array {
        if (!is_array($input) || !array_is_list($input) || count($input) > 100) {
            throw new InvalidArgumentException('Expected a list of at most 100 rules');
        }
        $output = [];
        $seen = [];
        foreach ($input as $idx => $r) {
            $ctx = "Rule $idx";
            $this->object($r, ['id','enabled','priority','source','target','timing','reminderMinutes','invitations','sync','presentation'], $ctx);
            $id = $this->str($r['id'] ?? null, 1, 64, "$ctx ID");
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $id) || isset($seen[$id])) {
                throw new InvalidArgumentException("$ctx has duplicate or invalid ID");
            }
            $seen[$id] = true;
            $src = $r['source'] ?? null;
            $this->object($src, ['type','boardIds','labelIds','match'], "$ctx source");
            if (($src['type'] ?? null) !== 'deck') {
                throw new InvalidArgumentException("$ctx source type must be deck");
            }
            $boards = $this->ids($src['boardIds'] ?? null, "$ctx boards");
            $labels = $this->ids($src['labelIds'] ?? null, "$ctx labels");

            $target = $r['target'] ?? null;
            $this->object($target, ['mode','principal','calendarUri'], "$ctx target");
            $targetMode = $this->choice($target['mode'] ?? null, ['fixed'], "$ctx target mode");
            $normalizedTarget = ['mode' => $targetMode];
            if ($targetMode === 'fixed') {
                $principal = $this->str($target['principal'] ?? null, 18, 250, "$ctx principal");
                $prefix = 'principals/users/';
                if (!str_starts_with($principal, $prefix)
                    || !preg_match('/^[A-Za-z0-9_.@-]+$/D', substr($principal, strlen($prefix)))
                    || str_contains($principal, '..')) {
                    throw new InvalidArgumentException("$ctx principal is invalid");
                }
                $uri = $this->str($target['calendarUri'] ?? null, 1, 128, "$ctx calendar URI");
                if (!preg_match('/^[A-Za-z0-9_.~%-]+$/D', $uri) || str_contains($uri, '..')) {
                    throw new InvalidArgumentException("$ctx calendar URI is invalid");
                }
                $normalizedTarget += ['principal' => $principal, 'calendarUri' => $uri];
            } elseif (isset($target['principal']) || isset($target['calendarUri'])) {
                throw new InvalidArgumentException("$ctx dynamic target must not set a fixed calendar");
            }

            $timing = $r['timing'] ?? [];
            $this->object($timing, ['durationMinutes','maxBlockMinutes','dueAnchor','dateOnly','dateTimeZone'], "$ctx timing");
            $duration = $this->int($timing['durationMinutes'] ?? 10, 1, 1440, "$ctx duration");
            $max = $this->int($timing['maxBlockMinutes'] ?? 240, 1, 10080, "$ctx max block");
            $dateOnlyMode=$this->choice($timing['dateOnly'] ?? 'skip',
                ['skip','all_day','day_edges'],"$ctx date only mode");
            $dateTimeZone=$this->str($timing['dateTimeZone'] ?? 'UTC',1,64,"$ctx date timezone");
            if (!in_array($dateTimeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)
                && $dateTimeZone!=='UTC') {
                throw new InvalidArgumentException("$ctx timezone must be a valid IANA timezone");
            }
            if ($duration > $max) {
                throw new InvalidArgumentException("$ctx duration exceeds max block");
            }

            $invite = $r['invitations'] ?? [];
            $this->object($invite, ['enabled','attendees','includeAssignees','descriptionPolicy','allowFullDescription','notifyOnCategoryChanges'], "$ctx invitations");
            $send = $this->boolean($invite['enabled'] ?? false, "$ctx invitation enabled");
            $includeAssignees = $this->boolean($invite['includeAssignees'] ?? false, "$ctx include assignees");
            $policy = $this->choice($invite['descriptionPolicy'] ?? 'link_only', ['link_only','full'], "$ctx description policy");
            $fullOptIn = $this->boolean($invite['allowFullDescription'] ?? false, "$ctx full description opt-in");
            $recipients = $invite['attendees'] ?? [];
            if (!is_array($recipients) || !array_is_list($recipients) || count($recipients) > 50) {
                throw new InvalidArgumentException("$ctx attendees must be a list of at most 50");
            }
            $emails = [];
            foreach ($recipients as $email) {
                $email = $this->str($email, 3, 254, "$ctx attendee");
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email)) {
                    throw new InvalidArgumentException("$ctx has invalid attendee email");
                }
                $emails[strtolower($email)] = $email;
            }
            if ($includeAssignees || $policy !== 'link_only' || $fullOptIn) {
                throw new InvalidArgumentException("$ctx unsupported invitation options");
            }
            if ($send && $emails === []) {
                throw new InvalidArgumentException("$ctx invitation enabled without recipients");
            }
            if ($send && $policy === 'full' && !$fullOptIn) {
                throw new InvalidArgumentException("$ctx full description invitation requires opt-in");
            }

            $sync = $r['sync'] ?? [];
            // Legacy onCalendarDelete=unlink (which formerly detached the
            // event while keeping the label) is migrated to the requested
            // authoritative behavior: remove exactly this trigger label.
            $calendarDeletion=$sync['onCalendarDelete']??'remove_tag';
            if ($calendarDeletion==='unlink') $calendarDeletion='remove_tag';
            $this->object($sync, ['direction','onCalendarDelete','onDone'], "$ctx sync");
            if (!in_array(($sync['direction'] ?? 'deck_to_calendar'), ['deck_to_calendar','bidirectional_times'], true)
                || $calendarDeletion !== 'remove_tag') {
                throw new InvalidArgumentException("$ctx only Deck-to-calendar or time-only reverse synchronization is supported");
            }
            $presentation = $r['presentation'] ?? [];
            $this->object($presentation, ['titlePrefixMode','titlePrefix','titleSeparator','categories','syncDeckTags'], "$ctx presentation");
            $titleSettings=(new EventTitlePolicy())->normalize($presentation);
            $categories = $presentation['categories'] ?? [];
            if (!is_array($categories) || !array_is_list($categories) || count($categories) > 20) {
                throw new InvalidArgumentException("$ctx categories must be a list of at most 20 items");
            }
            $categoryNames = [];
            foreach ($categories as $category) {
                $category = $this->str($category, 1, 80, "$ctx category");
                if (trim($category) === '' || preg_match('//u', $category) !== 1
                    || preg_match('/[\x00-\x1f\x7f]/', $category)) {
                    throw new InvalidArgumentException("$ctx invalid category");
                }
                $categoryNames[$category] = $category;
            }
            $reminder = $r['reminderMinutes'] ?? 5;
            $output[] = [
                'id' => $id,
                'enabled' => $this->boolean($r['enabled'] ?? false, "$ctx enabled"),
                'priority' => $this->int($r['priority'] ?? 0, 0, 1000, "$ctx priority"),
                'source' => [
                    'type' => 'deck',
                    'boardIds' => $boards,
                    'labelIds' => $labels,
                    'match' => $this->choice($src['match'] ?? 'any', ['any','all'], "$ctx match"),
                ],
                'target' => $normalizedTarget,
                'timing' => [
                    'durationMinutes' => $duration,
                    'maxBlockMinutes' => $max,
                    'dueAnchor' => $this->choice($timing['dueAnchor'] ?? 'before', ['before','center','after'], "$ctx due anchor"),
                    'dateOnly' => $dateOnlyMode,
                    'dateTimeZone' => $dateTimeZone,
                ],
                'reminderMinutes' => $reminder === null ? null : $this->int($reminder, 0, 10080, "$ctx reminder"),
                'invitations' => [
                    'enabled' => $send,
                    'attendees' => array_values($emails),
                    'includeAssignees' => $includeAssignees,
                    'descriptionPolicy' => $policy,
                    'allowFullDescription' => $fullOptIn,
                    'notifyOnCategoryChanges' => $this->boolean($invite['notifyOnCategoryChanges'] ?? false, "$ctx category notifications"),
                ],
                'presentation' => $titleSettings + [
                    'categories' => array_values($categoryNames),
                    'syncDeckTags' => $this->boolean($presentation['syncDeckTags'] ?? false, "$ctx sync Deck tags"),
                ],
                'sync' => [
                    'direction' => 'bidirectional_times',
                    'onCalendarDelete' => $calendarDeletion,
                    'onDone' => $this->choice($sync['onDone'] ?? 'keep', ['keep','delete'], "$ctx on done"),
                ],
            ];
        }
        return $output;
    }

    private function object(mixed $v, array $allowed, string $ctx): void {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw new InvalidArgumentException("$ctx must be a JSON object");
        }
        foreach (array_keys($v) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("$ctx contains unknown field $key");
            }
        }
    }
    private function str(mixed $v, int $min, int $max, string $ctx): string {
        if (!is_string($v) || strlen($v) < $min || strlen($v) > $max) {
            throw new InvalidArgumentException("$ctx must be a string of $min-$max bytes");
        }
        return $v;
    }
    private function choice(mixed $v, array $allowed, string $ctx): string {
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw new InvalidArgumentException("$ctx has unsupported value");
        }
        return $v;
    }
    private function boolean(mixed $v, string $ctx): bool {
        if (!is_bool($v)) {
            throw new InvalidArgumentException("$ctx must be boolean");
        }
        return $v;
    }
    private function int(mixed $v, int $min, int $max, string $ctx): int {
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new InvalidArgumentException("$ctx must be an integer between $min and $max");
        }
        return $v;
    }
    private function ids(mixed $v, string $ctx): array {
        if (!is_array($v) || !array_is_list($v) || count($v) < 1 || count($v) > 100) {
            throw new InvalidArgumentException("$ctx must be a nonempty list of max 100 IDs");
        }
        $ids = [];
        foreach ($v as $id) {
            $this->int($id, 1, PHP_INT_MAX, "$ctx ID");
            if (isset($ids[$id])) {
                throw new InvalidArgumentException("$ctx duplicate ID");
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }
}

