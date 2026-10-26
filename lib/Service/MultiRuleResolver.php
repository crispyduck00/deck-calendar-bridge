<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Service;

use DomainException;
use InvalidArgumentException;

/**
 * Resolve independently configured label rules for one Deck card.
 * Each rule owns exactly one logical slot; multiple rules may target
 * different calendars. A rule is not itself a permission grant.
 */
final class MultiRuleResolver {
    public function resolve(int $boardId, array $labelIds, array $rules): array {
        if ($boardId < 1) { throw new InvalidArgumentException('Invalid board ID'); }
        $labelSet = [];
        foreach ($labelIds as $id) {
            if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('Invalid label ID'); }
            $labelSet[$id] = true;
        }
        $matched = [];
        foreach ($rules as $rule) {
            if (!($rule['enabled'] ?? false) || !in_array($boardId, $rule['source']['boardIds'] ?? [], true)) { continue; }
            $required = $rule['source']['labelIds'] ?? [];
            $present = array_filter($required, static fn (int $id): bool => isset($labelSet[$id]));
            $ok = ($rule['source']['match'] ?? 'any') === 'all'
                ? count($present) === count($required) : count($present) > 0;
            if (!$ok) { continue; }
            // One target per rule, and no silent coalescing of different policies.
            $slot = 'rule-' . $rule['id'];
            if (isset($matched[$slot])) { throw new DomainException('Duplicate rule slot'); }
            $matched[$slot] = $rule;
        }
        ksort($matched, SORT_STRING);
        return $matched;
    }
}
