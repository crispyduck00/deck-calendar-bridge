<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

final class Runner {
    public function __construct(private Reconciler $engine,private LegacyImporter $importer,
        private Queue $queue,private RuleRepository $rules,private IConfig $config,
        private IURLGenerator $urls,private ILockingProvider $locks,private LoggerInterface $logger,private LockNames $lockNames) {}
    public function enabled(): bool {
        return $this->rules->enabled();
    }
    public function card(int $cardId,bool $cleanup=false): array {
        if ($cleanup) {
            $this->rules->read(); // refresh the process's config cache
            if ($this->config->getAppValue('deckcalendarbridge','uninstall_in_progress','no')!=='yes') {
                throw new \LogicException('Cleanup execution requires an explicit uninstall operation');
            }
        } elseif (!$this->enabled()) return ['disabled'=>true];
        $key=$this->lockNames->key('card/'.$cardId);
        try {
            $this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            // Another worker owns the card. Keep its queue generation intact,
            // continue the batch, and never release a lock we did not acquire.
            return ['pending'=>true,'deferred'=>'card_locked'];
        }
        try {
            $summary=['created'=>0,'updated'=>0,'deleted'=>0,'adopted'=>0,'unchanged'=>0,
                'skippedPast'=>0,'blocked'=>[],'notices'=>[]];
            for ($pass=0;$pass<3;$pass++) {
                $observed=$this->queue->get($cardId); if ($observed===null) return $summary;
                $rules=$this->rules->read()['rules'];
                try {
                    $this->importer->import($cardId,$rules);
                    $result=$this->engine->run($cardId,$rules,time(),$this->urls->getAbsoluteURL('/'));
                    foreach ($result as $field=>$value) {
                        if (is_int($value)) $summary[$field]+=$value;
                        elseif ($field==='blocked') $summary[$field]=$value;
                        elseif ($field==='notices') $summary[$field]=array_replace($summary[$field],$value);
                    }
                    if ($result['blocked']!==[]) {
                        $this->logger->warning('Deck Calendar Bridge reconciliation blocked',[
                            'app'=>'deckcalendarbridge','cardId'=>$cardId,'blocked'=>$result['blocked']]);
                        $this->queue->retry($cardId,$observed['generation']); return $summary;
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Deck Calendar Bridge reconciliation failed',[
                        'app'=>'deckcalendarbridge','cardId'=>$cardId,'type'=>get_class($e),
                        'code'=>$e->getCode(),'location'=>$e->getFile().':'.$e->getLine()]);
                    $this->queue->retry($cardId,$observed['generation']);
                    return ['blocked'=>['card'=>'reconciliation_failed']];
                }
                if ($this->queue->acknowledge($cardId,$observed['generation'])) return $summary;
            }
            $summary['pending']=true; return $summary;
        } finally { $this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
    }
    public function pending(int $limit=50): array {
        $out=[]; if (!$this->enabled()) return $out;
        foreach ($this->queue->pending($limit) as $row) $out[(int)$row['card_id']]=$this->card((int)$row['card_id']);
        return $out;
    }
}
