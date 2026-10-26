<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Bounded scans enqueue work. No scanner is allowed to mutate a calendar/card. */
final class Scanner {
    public function __construct(private Store $store,private Queue $queue,private IDBConnection $db,
        private RuleRepository $rules,private IConfig $config,private ILockingProvider $locks,private LockNames $lockNames) {}
    public function scan(int $limit=50): array {
        $key=$this->lockNames->key('scan'); $app='deckcalendarbridge';
        try {
            $this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            // A concurrent scanner owns the cursors; pending card work can
            // still be processed by this caller without advancing that scan.
            return ['relations'=>0,'cards'=>0,'deferred'=>'scan_locked'];
        }
        try {
            $snapshot=$this->rules->read();
            if ($this->config->getAppValue($app,'v1_scan_revision','')!==$snapshot['revision']) {
                $this->config->setAppValue($app,'v1_source_cursor','0');
                $this->config->setAppValue($app,'v1_relation_cursor','0');
                $this->config->setAppValue($app,'v1_scan_revision',$snapshot['revision']);
            }
            $cursor=(int)$this->config->getAppValue($app,'v1_relation_cursor','0');
            $ids=$this->store->cardIdsAfter($cursor,$limit);
            foreach ($ids as $id) $this->queue->mark($id,false);
            $this->config->setAppValue($app,'v1_relation_cursor',count($ids)<$limit?'0':(string)max($ids));
            $legacyCursor=(int)$this->config->getAppValue($app,'v1_legacy_cursor','0');
            $legacy=$this->store->legacyCardsAfter($legacyCursor,$limit);
            foreach ($legacy as $old) {
                $legacyCursor=(int)$old['id'];
                if (ctype_digit($old['source_id'])) $this->queue->mark((int)$old['source_id'],false);
            }
            $this->config->setAppValue($app,'v1_legacy_cursor',count($legacy)<$limit?'0':(string)$legacyCursor);
            $rules=array_values(array_filter($snapshot['rules'],static fn(array $r):bool=>$r['enabled']));
            $cursor=(int)$this->config->getAppValue($app,'v1_source_cursor','0'); $rows=[];
            if ($rules!==[]) {
                $q=$this->db->getQueryBuilder(); $or=[];
                foreach ($rules as $r) $or[]=$q->expr()->andX(
                    $q->expr()->eq('s.board_id',$q->createNamedParameter($r['source']['boardIds'][0],IQueryBuilder::PARAM_INT)),
                    $q->expr()->eq('al.label_id',$q->createNamedParameter($r['source']['labelIds'][0],IQueryBuilder::PARAM_INT))
                );
                $q->selectDistinct('c.id')->from('deck_cards','c')
                    ->innerJoin('c','deck_stacks','s','s.id = c.stack_id')
                    ->innerJoin('s','deck_boards','b','b.id = s.board_id')
                    ->innerJoin('c','deck_assigned_labels','al','al.card_id = c.id')
                    ->where($q->expr()->gt('c.id',$q->createNamedParameter($cursor,IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->orX(...$or));
                foreach (['c.deleted_at','s.deleted_at','b.deleted_at'] as $f) $q->andWhere($q->expr()->eq($f,$q->createNamedParameter(0,IQueryBuilder::PARAM_INT)));
                foreach (['c.archived','b.archived'] as $f) $q->andWhere($q->expr()->eq($f,$q->createNamedParameter(false,IQueryBuilder::PARAM_BOOL)));
                $q->andWhere($q->expr()->orX($q->expr()->isNotNull('c.startdate'),$q->expr()->isNotNull('c.duedate')));
                $rows=$q->orderBy('c.id','ASC')->setMaxResults($limit)->executeQuery()->fetchAllAssociative();
                foreach ($rows as $row) { $cursor=(int)$row['id']; $this->queue->mark($cursor,false); }
            }
            $this->config->setAppValue($app,'v1_source_cursor',count($rows)<$limit?'0':(string)$cursor);
            return ['relations'=>count($ids),'cards'=>count($rows)];
        } finally { $this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
    }
}
