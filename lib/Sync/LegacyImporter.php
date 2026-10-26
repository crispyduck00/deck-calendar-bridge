<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use DomainException;
use OCA\DeckCalendarBridge\Service\EventIdentity;

/** Verified adoption keeps old UIDs and never deletes the old link tables. */
final class LegacyImporter {
    public function __construct(private Store $store,private CalendarPort $calendar,private DeckPort $deck,
        private CalendarDocument $documents) {}
    public function import(int $cardId,array $rules): void {
        $byId=[]; foreach ($rules as $r) $byId[$r['id']]=$r;
        $existing=$this->store->current($cardId); $card=$this->deck->read($cardId);
        foreach ($this->store->legacy($cardId) as $old) {
            $slot=$old['event_slot'];
            if (!str_starts_with($slot,'rule-')) continue;
            $id=substr($slot,5);
            if (isset($existing[$id])) continue;
            $rule=$byId[$id]??null;
            $row=['card_id'=>$cardId,'rule_id'=>$id,'generation'=>1,'revision'=>1,
                'uid'=>$old['event_uid'],'object_uri'=>$old['object_uri']?:Planning::uri($old['event_uid']),
                'principal'=>$old['principal'],'calendar_uri'=>$old['calendar_uri'],
                'board_id'=>(int)($old['source_board_id']??0),'tag_id'=>(int)($old['source_label_id']??0),
                'state'=>'uncertain','data'=>['legacy_id'=>(int)$old['id'],'owned'=>null,
                    'attempt'=>['kind'=>'legacy_review'],'error'=>null]];
            try {
                if ($row['uid']!==(new EventIdentity())->uid('deck',(string)$cardId,$slot)) {
                    throw new DomainException('legacy_uid_not_verified');
                }
                $remote=$this->calendar->read($row);
                if ($remote['status']==='present') $this->documents->inspect($remote['body'],$row['uid']);
                // Exact rule ID + deterministic old UID + original target +
                // verified live object identify the relation. Never guess a
                // trigger from a title/category or a removed arbitrary rule.
                if (($row['board_id']===0 || $row['tag_id']===0) && $card!==null && $rule!==null
                    && Planning::matches($card,$rule)
                    && $rule['target']['principal']===$row['principal']
                    && $rule['target']['calendarUri']===$row['calendar_uri']) {
                    $row['board_id']=$rule['source']['boardIds'][0];
                    $row['tag_id']=$rule['source']['labelIds'][0];
                }
                if (in_array($old['sync_state'],['tombstone','detached'],true)) {
                    if ($remote['status']==='present') throw new DomainException('legacy_closed_original_restored');
                    $row['state']='deleted';
                } elseif ($remote['status']==='absent' && in_array($old['sync_state'],['prepared','uncertain'],true)) {
                    // Current explicit Deck intent wins an unknowable old
                    // failed attempt, AFTER authoritative absence was verified.
                    $row['state']='prepared';
                } else $row['state']='active';
                if (is_string($old['managed_categories']??null)) {
                    $owned=json_decode($old['managed_categories'],true,32,JSON_THROW_ON_ERROR);
                    if (is_array($owned)&&array_is_list($owned)) $row['data']['owned']=$owned;
                }
                unset($row['data']['attempt']);
            } catch (\Throwable $e) {
                $row['data']['error']=$e instanceof DomainException?$e->getMessage():'legacy_verification_failed';
            }
            $existing[$id]=$this->store->insert($row);
        }
    }
}
