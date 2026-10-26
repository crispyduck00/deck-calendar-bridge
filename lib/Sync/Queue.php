<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use OCA\DeckCalendarBridge\BackgroundJob\ReconcileCardJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** Generations ensure an event arriving during work cannot be acknowledged away. */
final class Queue {
    public function __construct(private IDBConnection $db,private ILockingProvider $locks,private IJobList $jobs,private LockNames $lockNames) {}
    public function mark(int $cardId,bool $urgent=true): void {
        if ($cardId<1) return;
        $key=$this->lockNames->key('queue/'.$cardId);
        $this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try {
            // Current-read UPDATE avoids an old SELECT snapshot inside a
            // caller's existing transaction and needs only one query normally.
            $q=$this->db->getQueryBuilder();
            $q->update('dcb_sync_queue')->set('generation',$q->createFunction('generation + 1'))
                ->where($q->expr()->eq('card_id',$q->createNamedParameter($cardId,IQueryBuilder::PARAM_INT)));
            if ($urgent) $q->set('retry_after',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT));
            if ($q->executeStatement()===0) {
                $q=$this->db->getQueryBuilder();
                $q->insert('dcb_sync_queue')->values([
                    'card_id'=>$q->createNamedParameter($cardId,IQueryBuilder::PARAM_INT),
                    'generation'=>$q->createNamedParameter(1,IQueryBuilder::PARAM_INT),
                    // Old background work must precede newly rescanned low
                    // card IDs. Urgent events retain the explicit priority 0.
                    'retry_after'=>$q->createNamedParameter($urgent?0:time(),IQueryBuilder::PARAM_INT),
                ])->executeStatement();
            }
        } finally { $this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
        if ($urgent) {
            $arg=['cardId'=>$cardId];
            // A new event also wakes a job previously scheduled for retry.
            $this->jobs->add(ReconcileCardJob::class,$arg);
        }
    }
    public function get(int $id): ?array {
        $q=$this->db->getQueryBuilder();
        $r=$q->select('*')->from('dcb_sync_queue')->where($q->expr()->eq('card_id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
        return $r===false?null:array_map('intval',$r);
    }
    public function acknowledge(int $id,int $generation): bool {
        $key=$this->lockNames->key('queue/'.$id);
        try { $this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
        catch (LockedException) { return false; }
        try {
            // Serialize the full mark/update/insert and acknowledgement paths.
            // Only the observed generation may be removed; a newly marked
            // generation or a busy writer must remain pending.
            $q=$this->db->getQueryBuilder();
            return $q->delete('dcb_sync_queue')->where($q->expr()->eq('card_id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('generation',$q->createNamedParameter($generation,IQueryBuilder::PARAM_INT)))->executeStatement()===1;
        } finally { $this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
    }
    public function retry(int $id,int $generation): void {
        $q=$this->db->getQueryBuilder();
        $q->update('dcb_sync_queue')->set('retry_after',$q->createNamedParameter(time()+60,IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('card_id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('generation',$q->createNamedParameter($generation,IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    public function pending(int $limit=50): array {
        $q=$this->db->getQueryBuilder();
        return $q->select('card_id')->from('dcb_sync_queue')
            ->where($q->expr()->lte('retry_after',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT)))
            ->orderBy('retry_after','ASC')->addOrderBy('card_id','ASC')->setMaxResults($limit)
            ->executeQuery()->fetchAllAssociative();
    }
}
