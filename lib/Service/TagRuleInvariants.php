<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Service;
use DomainException;

/** Enforce one enabled tag per rule and one enabled rule per board+label. */
final class TagRuleInvariants {
    public function verify(array $rules): void {
        $seen=[];
        foreach ($rules as $rule) {
            if (!($rule['enabled']??false)) continue;
            if (($rule['source']['type']??'')!=='deck'
                || ($rule['source']['match']??'')!=='any'
                || count($rule['source']['boardIds']??[])!==1
                || count($rule['source']['labelIds']??[])!==1
                || ($rule['target']['mode']??'')!=='fixed') {
                throw new DomainException('Active multi-sync rule requires exactly one label and fixed calendar');
            }
            $label=$rule['source']['labelIds'][0];
            foreach ($rule['source']['boardIds'] as $board) {
                $key=$board.':'.$label;
                if (isset($seen[$key])) throw new DomainException('Duplicate board/tag rule '.$key);
                $seen[$key]=true;
            }
        }
    }
}
