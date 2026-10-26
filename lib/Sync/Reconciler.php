<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use OCA\DeckCalendarBridge\Service\EventDescriptionPolicy;
use OCA\DeckCalendarBridge\Service\InvitationEventComposer;
use OCA\DeckCalendarBridge\Service\VEventSerializer;

/**
 * The only mutation authority. Called under a per-card lock by Runner.
 * Listener, backfill, manual execution and recovery all use this same path.
 */
final class Reconciler {
    public function __construct(
        private DeckPort $deck,
        private CalendarPort $calendar,
        private RelationStore $store,
        private Planning $planning,
        private CalendarDocument $documents,
        private VEventSerializer $serializer,
        private InvitationEventComposer $invitations,
        private ?\Psr\Log\LoggerInterface $logger=null,
    ) {}

    public function run(int $cardId,array $rules,int $now,string $deckBaseUrl): array {
        $byId=[]; foreach ($rules as $rule) $byId[$rule['id']]=$rule;
        $result=['created'=>0,'updated'=>0,'deleted'=>0,'adopted'=>0,'unchanged'=>0,
            'skippedPast'=>0,'blocked'=>[],'notices'=>[]];
        $card=$this->deck->read($cardId);
        $observations=[];

        // Process old targets and ALL relation states, even if their rule has
        // disappeared or the card was archived/deleted. No archive early exit.
        foreach ($this->store->current($cardId) as $id=>$row) {
            if ($row['state']==='deleted') continue;
            try {
                $rule=$byId[$id]??null;
                if ($rule!==null && !$rule['enabled'] && $card!==null && !$card['deleted']) continue;
                $remote=$this->calendar->read($row);
                $row=$this->recover($row,$remote);
                if ($row['state']==='deleted') continue;

                $cause=$this->deleteCause($card,$row,$rule);
                if ($cause!==null) {
                    if ($this->erase($row,$remote,$cause,$rule)) $result['deleted']++;
                    continue;
                }
                if (!$rule['enabled']) continue; // paused, distinct from removed
                if ($row['state']==='prepared') {
                    // Durable failed create is continued later below, with the
                    // same UID and object URI, only if still desired.
                    continue;
                }
                if ($remote['status']!=='present') {
                    if ($remote['status']==='absent' && empty($row['data']['calendar_delete_observed'])) {
                        $since=$row['data']['missing_since']??$now;
                        $row['data']['missing_since']=$since;
                        $row=$this->store->save($row);
                        if ($now-$since<30) throw new DomainException('missing_original_confirmation_pending');
                    }
                    // A read failure/moved target never reaches here. A genuine
                    // original deletion may mutate ONLY its own trigger label.
                    $base=$row['data']['base']??null;
                    $deckChanged=$base===null
                        ? empty($row['data']['calendar_delete_observed'])
                        : $base['source']!==Planning::times($card);
                    if (!$deckChanged) {
                        $card=$this->deck->removeTrigger($row,$rule,$card);
                        $row['state']='deleted'; $row['data']['deleted_cause']='calendar_delete';
                        unset($row['data']['attempt'],$row['data']['missing_since']);
                        $row['data']['error']=null; $this->store->save($row);
                        $result['deleted']++;
                        continue;
                    }
                    // Deck changed after the last common state: don't apply a
                    // stale calendar deletion to the newer Deck intent.
                    $row['state']='deleted'; $row['data']['deleted_cause']='deck_wins_after_calendar_delete';
                    $this->store->save($row);
                    $result['notices'][$id]='deck_wins_after_calendar_delete';
                    continue;
                }
                $row['data']['missing_since']=null;
                $observations[$id]=['row'=>$row,'remote'=>$remote,
                    'document'=>$this->documents->inspect($remote['body'],$row['uid'])];
            } catch (\Throwable $e) { $this->block($row,$e,$result); }
        }

        $card=$this->deck->read($cardId);
        if ($card===null || $card['deleted']) return $result;

        // Evaluate every linked calendar against the SAME source snapshot.
        // Related calendar edits cannot win merely because their job ran first.
        $deckDirty=false; $proposals=[]; $proposalRule=null;
        foreach ($observations as $id=>$observation) {
            $row=$observation['row']; $rule=$byId[$id]??null;
            if ($rule===null || !$rule['enabled'] || !Planning::matches($card,$rule)) continue;
            $base=$row['data']['base']??null;
            if ($base===null || $base['source']!==Planning::times($card)
                || $base['recipe']!==Planning::recipe($rule)) {
                $deckDirty=true; continue;
            }
            if ($base['time']===$observation['document']['time']) continue;
            if (($rule['timing']['dateOnly']??'skip')!=='skip') continue;
            try {
                $proposal=$this->planning->reverse($card,$rule,$observation['document']['time']);
                $proposals[json_encode($proposal,JSON_THROW_ON_ERROR)]=$proposal;
                $proposalRule=$rule;
            } catch (DomainException $e) { $result['notices'][$id]=$e->getMessage(); }
        }
        if (!$deckDirty && count($proposals)===1 && $result['blocked']===[]) {
            try {
                $card=$this->deck->adoptTimes($proposalRule,$card,array_values($proposals)[0]);
                $result['adopted']++;
            } catch (\Throwable $e) {
                // A newly observed Deck version must be handled on the next
                // run. Never forward an old snapshot after a failed adoption.
                $result['blocked']['card']=$this->code($e);
                return $result;
            }
        } elseif ($proposals!==[]) {
            $result['notices']['times']='deck_wins_time_conflict';
        }

        foreach ($rules as $rule) {
            $id=$rule['id'];
            if (!$rule['enabled'] || !Planning::matches($card,$rule)
                || ($card['done'] && $rule['sync']['onDone']==='delete')
                || ($card['start']===null && $card['due']===null)) continue;
            $row=$this->store->current($cardId)[$id]??null;
            if (isset($result['blocked'][$id])) continue;
            try {
                if ($row!==null && $row['state']!=='deleted' && !Planning::binding($row,$rule)) {
                    throw new DomainException('old_target_cleanup_pending');
                }
                if ($row===null || $row['state']==='deleted') {
                    if (!Planning::eligible($card,$now,$rule)) { $result['skippedPast']++; continue; }
                    $generation=($row['generation']??0)+1;
                    $uid=$this->planning->uid($cardId,$id,$generation);
                    $row=$this->store->insert([
                        'card_id'=>$cardId,'rule_id'=>$id,'generation'=>$generation,
                        'uid'=>$uid,'object_uri'=>Planning::uri($uid),
                        'principal'=>$rule['target']['principal'],'calendar_uri'=>$rule['target']['calendarUri'],
                        'board_id'=>$rule['source']['boardIds'][0],'tag_id'=>$rule['source']['labelIds'][0],
                        'state'=>'prepared','revision'=>1,'data'=>['error'=>null,'owned'=>[]],
                    ]);
                }
                $plan=$this->planning->plan($card,$rule,$row['uid']);
                $url=rtrim($deckBaseUrl,'/').'/apps/deck/board/'.$card['board'].'/card/'.$cardId;
                $reverseTimes=($rule['timing']['dateOnly']??'skip')==='skip';
                $description=EventDescriptionPolicy::forCard($url,$reverseTimes);
                if ($row['state']==='prepared') {
                    if (!Planning::eligible($card,$now,$rule)) { $result['skippedPast']++; continue; }
                    $this->fresh($card);
                    $stamp=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                    $body=$rule['invitations']['enabled']
                        ? $this->invitations->compose($plan,$stamp,$this->calendar->organizerEmail($row['principal']),
                            $rule['invitations']['attendees'],$rule['reminderMinutes'],0,$url,$reverseTimes)
                        : $this->serializer->serialize($plan,$stamp,0,$url,$rule['reminderMinutes'],$reverseTimes);
                    $row=$this->attempt($row,'create',$card,$rule,$plan,$plan->categories,$body);
                    $this->calendar->create($row,$body);
                    $remote=$this->calendar->read($row);
                    $this->confirm($row,$remote);
                    $result['created']++;
                    continue;
                }
                if ($row['state']!=='active') throw new DomainException('relation_recovery_pending');
                // Use the version that participated in the common-baseline
                // decision. Re-reading here would silently adopt a newer ETag
                // without considering its incoming time edit. A later client
                // write must instead fail our old If-Match and be reconsidered.
                $remote=$observations[$id]['remote']??throw new DomainException('calendar_observation_missing_retry');
                if ($remote['status']!=='present') throw new DomainException('original_not_present');
                $patch=$this->documents->reconcile($remote['body'],$plan,$row['data']['owned']??null,
                    $description,$rule['invitations']['notifyOnCategoryChanges']??false);
                if (!$patch['changed']) {
                    $previous=$row['data'];
                    $row['data']['base']=['source'=>Planning::times($card),'time'=>Planning::window($plan),
                        'recipe'=>Planning::recipe($rule)];
                    $row['data']['etag']=$remote['etag']; $row['data']['owned']=$patch['owned'];
                    if (isset($remote['object_id'])) $row['data']['object_id']=$remote['object_id'];
                    $row['data']['error']=null; unset($row['data']['attempt'],$row['data']['missing_since'],$row['data']['calendar_delete_observed']);
                    if ($row['data']!==$previous) $this->store->save($row);
                    $result['unchanged']++;
                    continue;
                }
                $this->fresh($card);
                $row=$this->attempt($row,'update',$card,$rule,$plan,$patch['owned']);
                $this->calendar->update($row,$remote['etag'],$patch['body']);
                $this->confirm($row,$this->calendar->read($row));
                $result['updated']++;
            } catch (\Throwable $e) {
                if ($row!==null) $this->block($row,$e,$result);
                else $result['blocked'][$id]=$this->code($e);
            }
        }
        return $result;
    }

    private function deleteCause(?array $card,array $row,?array $rule): ?string {
        if ($rule===null) return 'rule_removed';
        if ($card===null || $card['deleted']) return 'card_deleted';
        if (!$rule['enabled']) return null;
        if (!Planning::binding($row,$rule)) return 'rule_target_changed';
        if (!Planning::matches($card,$rule)) return 'trigger_removed';
        if ($card['done'] && $rule['sync']['onDone']==='delete') return 'card_done';
        if ($card['start']===null && $card['due']===null) return 'source_dates_removed';
        return null;
    }
    private function fresh(array $expected): void {
        if ($this->deck->read($expected['id'])!==$expected) throw new DomainException('deck_changed_retry');
    }
    private function attempt(array $row,string $kind,array $card,array $rule,
        \OCA\DeckCalendarBridge\Service\EventPlan $plan,array $owned,?string $body=null): array {
        $row['state']='uncertain';
        $row['data']['attempt']=['kind'=>$kind,'source'=>Planning::times($card),
            'time'=>Planning::window($plan),'recipe'=>Planning::recipe($rule),
            'rule_hash'=>hash('sha256',json_encode($rule,JSON_THROW_ON_ERROR)),
            'owned'=>$owned,'body'=>$body,'started'=>time()];
        return $this->store->save($row);
    }
    private function confirm(array $row,array $remote): array {
        if ($remote['status']!=='present') throw new DomainException('write_confirmation_pending');
        $actual=$this->documents->inspect($remote['body'],$row['uid']);
        $a=$row['data']['attempt'];
        if ($actual['time']!==$a['time']) throw new DomainException('write_confirmation_changed');
        $row['state']='active';
        $row['data']['last_action']=$a['kind'];
        $row['data']['base']=['source'=>$a['source'],'time'=>$a['time'],'recipe'=>$a['recipe']];
        $row['data']['etag']=$remote['etag']; $row['data']['owned']=$a['owned'];
        if (isset($remote['object_id'])) $row['data']['object_id']=$remote['object_id'];
        $row['data']['error']=null; unset($row['data']['attempt'],$row['data']['missing_since']);
        return $this->store->save($row);
    }
    private function recover(array $row,array $remote): array {
        if ($row['state']!=='uncertain') return $row;
        $attempt=$row['data']['attempt']??null;
        if ($attempt===null) throw new DomainException('missing_attempt_metadata');
        if (!in_array($attempt['kind'],['create','update','delete'],true)) {
            throw new DomainException($row['data']['error']??'legacy_review_required');
        }
        if ($attempt['kind']==='delete') {
            if ($remote['status']!=='present') {
                $row['state']='deleted'; $row['data']['deleted_cause']=$attempt['cause'];
            } else $row['state']='active'; // re-evaluate the current deletion cause
        } elseif ($remote['status']==='present') {
            $this->documents->inspect($remote['body'],$row['uid']);
            // A uniquely verified original is adopted, even if a later manual
            // calendar edit followed the write. Preserve the attempted/common
            // baseline so normal three-way comparison resolves that later edit.
            if ($attempt['kind']==='create' || !isset($row['data']['base'])) {
                $row['data']['base']=['source'=>$attempt['source'],'time'=>$attempt['time'],
                    'recipe'=>$attempt['recipe']];
                $row['data']['owned']=$attempt['owned'];
            } elseif ($this->documents->inspect($remote['body'],$row['uid'])['time']===$attempt['time']) {
                $row['data']['base']=['source'=>$attempt['source'],'time'=>$attempt['time'],
                    'recipe'=>$attempt['recipe']];
                $row['data']['owned']=$attempt['owned'];
            }
            $row['state']='active'; $row['data']['etag']=$remote['etag'];
            if (isset($remote['object_id'])) $row['data']['object_id']=$remote['object_id'];
        } elseif ($remote['status']==='trashed' || !empty($row['data']['calendar_delete_observed'])) {
            $row['state']='active'; // normal confirmed-deletion handling below
            if ($attempt['kind']==='create' && !isset($row['data']['base'])) {
                $row['data']['base']=['source'=>$attempt['source'],'time'=>$attempt['time'],'recipe'=>$attempt['recipe']];
            }
        } elseif ($attempt['kind']==='create' && $remote['status']==='absent') {
            $row['state']='prepared'; // authoritative calendar lookup proved absence
        } else throw new DomainException('uncertain_original_not_found');
        unset($row['data']['attempt']); $row['data']['error']=null;
        return $this->store->save($row);
    }
    private function erase(array $row,array $remote,string $cause,?array $rule): bool {
        if ($remote['status']!=='present') {
            $row['state']='deleted'; $row['data']['deleted_cause']=$cause;
            $row['data']['error']=null; unset($row['data']['attempt']);
            $this->store->save($row); return true;
        }
        $this->documents->inspect($remote['body'],$row['uid']);
        $row['state']='uncertain';
        $row['data']['attempt']=['kind'=>'delete','cause'=>$cause,'started'=>time(),
            'rule_hash'=>$rule===null?null:hash('sha256',json_encode($rule,JSON_THROW_ON_ERROR))];
        $row=$this->store->save($row);
        $this->calendar->delete($row,$remote['etag']);
        $after=$this->calendar->read($row);
        if ($after['status']==='present') throw new DomainException('delete_confirmation_pending');
        $row['state']='deleted'; $row['data']['deleted_cause']=$cause;
        $row['data']['error']=null; unset($row['data']['attempt']);
        $this->store->save($row); return true;
    }
    private function block(array $row,\Throwable $e,array &$result): void {
        $result['blocked'][$row['rule_id']]=$this->code($e);
        $causes=[];
        for ($cause=$e;$cause!==null;$cause=$cause->getPrevious()) {
            $causes[]=['type'=>get_class($cause),'code'=>$cause->getCode()];
        }
        $this->logger?->warning('Deck Calendar Bridge relation operation failed',[
            'app'=>'deckcalendarbridge','cardId'=>$row['card_id'],'relationId'=>$row['id'],
            'ruleId'=>$row['rule_id'],'reason'=>$this->code($e),'causes'=>$causes,
            'location'=>$e->getFile().':'.$e->getLine()]);
        // A failed save must not replace a concurrently newer relation.
        try { $row['data']['error']=$this->code($e); $this->store->save($row); } catch (\Throwable) {}
    }
    private function code(\Throwable $e): string {
        return $e instanceof DomainException && preg_match('/^[a-z][a-z0-9_]{0,99}$/D',$e->getMessage())
            ? $e->getMessage() : 'operation_failed_'.(new \ReflectionClass($e))->getShortName();
    }
}
