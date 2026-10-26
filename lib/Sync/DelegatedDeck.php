<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use DateTime;
use DomainException;
use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Event\CardUpdatedEvent;
use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Capability delegated by an ADMIN-SAVED rule, not the calendar owner's Deck
 * ACL. No generic card-update endpoint and no caller supplied target fields.
 * Mutates only dates or the exact bound label plus normal Deck metadata/events.
 */
final class DelegatedDeck implements DeckPort {
    public function __construct(
        private IDBConnection $db, private CardMapper $cards, private BoardMapper $boards,
        private LabelMapper $labels, private ChangeHelper $changes,
        private ActivityManager $activity, private IEventDispatcher $events,
        private RuleRepository $rules, private IConfig $config,
    ) {}
    public function read(int $cardId): ?array {
        try { $c=$this->cards->find($cardId,false); } catch (DoesNotExistException) { return null; }
        // Stack deletion does not delete its cards in Deck. Read the live
        // parent instead of a cached board mapping, including stack moves.
        $q=$this->db->getQueryBuilder();
        $stack=$q->select('board_id','deleted_at','title')->from('deck_stacks')
            ->where($q->expr()->eq('id',$q->createNamedParameter($c->getStackId(),IQueryBuilder::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
        if ($stack===false) return ['id'=>$cardId,'board'=>0,'labels'=>[],'title'=>$c->getTitle(),
            'start'=>null,'due'=>null,'done'=>false,'archived'=>false,'deleted'=>true];
        $boardId=(int)$stack['board_id'];
        try { $b=$this->boards->find($boardId); } catch (DoesNotExistException) { return null; }
        $labels=[];
        foreach ($this->labels->findAssignedLabelsForCard($cardId) as $label) $labels[(int)$label->getId()]=$label->getTitle();
        ksort($labels,SORT_NUMERIC);
        return ['id'=>$cardId,'board'=>$boardId,'labels'=>$labels,'title'=>$c->getTitle(),'listName'=>$stack['title'],
            'start'=>$c->getStartdate()?->getTimestamp(),'due'=>$c->getDuedate()?->getTimestamp(),
            'done'=>$c->getDone()!==null,'archived'=>$c->getArchived()||$b->getArchived(),
            'deleted'=>$c->getDeletedAt()>0||$b->getDeletedAt()>0||(int)$stack['deleted_at']>0];
    }
    private function authorize(array $rule,array $card): void {
        $current=null;
        foreach ($this->rules->read()['rules'] as $r) if ($r['id']===$rule['id']) $current=$r;
        if ($current!==$rule || !$rule['enabled'] || !Planning::matches($card,$rule)) {
            throw new DomainException('delegated_scope_changed');
        }
        $label=$this->labels->find($rule['source']['labelIds'][0]);
        if ((int)$label->getBoardId()!==$card['board']) throw new DomainException('delegated_label_scope_mismatch');
    }
    private function lockCard(int $id): void {
        $q=$this->db->getQueryBuilder();
        $q->select('id')->from('deck_cards')->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        if ($this->config->getSystemValue('dbtype','sqlite3')!=='sqlite3') $q->forUpdate();
        $q->executeQuery()->fetchOne();
    }
    public function adoptTimes(array $rule,array $expected,array $times): array {
        if (array_keys($times)!==['start','due']) throw new DomainException('invalid_delegated_time_fields');
        foreach ($times as $v) if ($v!==null && (!is_int($v)||$v<0)) throw new DomainException('invalid_delegated_time');
        $this->db->beginTransaction();
        try {
            $this->lockCard($expected['id']);
            $fresh=$this->read($expected['id']);
            if ($fresh!==$expected) throw new DomainException('deck_changed_retry');
            $this->authorize($rule,$fresh);
            $card=$this->cards->find($expected['id'],false); $before=clone $card;
            $card->setStartdate($times['start']===null?null:(new DateTime())->setTimestamp($times['start']));
            $card->setDuedate($times['due']===null?null:(new DateTime())->setTimestamp($times['due']));
            $card->setLastEditor(substr($rule['target']['principal'],17));
            // QBMapper writes changed entity properties only; do not call the
            // broad CardService update that would also set title/description/done.
            $this->cards->update($card);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        $this->changes->cardChanged($expected['id'],false);
        $this->activity->triggerEvent(ActivityManager::DECK_OBJECT_CARD,$card,
            ActivityManager::SUBJECT_CARD_UPDATE_DUEDATE,
            ['before'=>$before->getDuedate(),'after'=>$card->getDuedate()],substr($rule['target']['principal'],17));
        $this->events->dispatchTyped(new CardUpdatedEvent($card,$before));
        return $this->read($expected['id']);
    }
    public function removeTrigger(array $relation,array $rule,array $expected): ?array {
        if (!Planning::binding($relation,$rule) || $relation['card_id']!==$expected['id']) {
            throw new DomainException('delegated_relation_mismatch');
        }
        $this->db->beginTransaction();
        try {
            $this->lockCard($expected['id']); $fresh=$this->read($expected['id']);
            if ($fresh!==$expected) throw new DomainException('deck_changed_retry');
            $this->authorize($rule,$fresh);
            $card=$this->cards->find($expected['id'],false); $before=clone $card;
            $label=$this->labels->find($relation['tag_id']);
            $this->cards->removeLabel($expected['id'],$relation['tag_id']);
            $card->setLastEditor(substr($relation['principal'],17)); $this->cards->update($card);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        $this->changes->cardChanged($expected['id'],false);
        $this->activity->triggerEvent(ActivityManager::DECK_OBJECT_CARD,$card,
            ActivityManager::SUBJECT_LABEL_UNASSING,['label'=>$label],substr($relation['principal'],17));
        $this->events->dispatchTyped(new CardUpdatedEvent($card,$before));
        return $this->read($expected['id']);
    }
}
