<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Service;

use DomainException;

/**
 * Provenance-aware category reconciliation. Only a label previously inserted
 * by this bridge may be removed. Null provenance means an older event whose
 * existing categories are NOT owned by this bridge.
 */
final class ManagedCategoryPolicy {
    /**
     * @param list<string> $current Parsed calendar categories
     * @param list<string> $desired Current rule-managed categories
     * @param ?list<string> $previousOwned Last confirmed bridge-owned categories
     * @return array{categories:list<string>,owned:list<string>}
     */
    public function reconcile(array $current, array $desired, ?array $previousOwned): array {
        foreach ([$current,$desired,$previousOwned ?? []] as $values) {
            if (!array_is_list($values) || count($values) > 100) {
                throw new DomainException('Ambiguous category list');
            }
            foreach ($values as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 256
                    || preg_match('//u',$value)!==1
                    || preg_match('/[\x00-\x1f\x7f]/',$value)) {
                    throw new DomainException('Untrusted calendar category');
                }
            }
        }
        $old = array_fill_keys($previousOwned ?? [], true);
        $desiredMap = array_fill_keys($desired, true);
        $counts = array_count_values($current);
        $retained = [];
        foreach ($current as $value) {
            if (isset($old[$value]) && !isset($desiredMap[$value])) {
                // Duplicated category could contain a calendar-user label
                // indistinguishable from the previously owned one.
                if ($counts[$value] !== 1) {
                    throw new DomainException('Ambiguous duplicate owned category');
                }
                continue;
            }
            $retained[] = $value;
        }
        $owned=[];
        $present = array_fill_keys($retained, true);
        foreach ($desired as $category) {
            // A manually pre-existing category does not become bridge-owned.
            if (isset($old[$category]) || !isset($present[$category])) {
                $owned[$category] = true;
            }
            if (!isset($present[$category])) {
                $retained[]=$category;
                $present[$category]=true;
            }
        }
        return ['categories'=>$retained,'owned'=>array_keys($owned)];
    }
}
