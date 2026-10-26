<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;

use OC\Installer;
use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/** Explicit CLI lifecycle operations; never an automatic app-disable hook. */
final class Maintenance {
    public const APP='deckcalendarbridge';
    public const TABLES=['dcb_event_links','dcb_dirty_cards','dcb_reverse_outbox','dcb_relations','dcb_sync_queue'];
    public const JOBS=['InspectDeckCardJob','ProcessReverseOutboxJob','ScanDirtyDeckCardsJob',
        'ScanExistingDeckCardsJob','ScanManagedCalendarDriftJob','ScanReverseOutboxJob','ReconcileCardJob','SyncScanJob'];
    public function __construct(private IConfig $config,private IAppConfig $appConfig,private IDBConnection $db,
        private IAppManager $apps,private Installer $installer,private IJobList $jobs,
        private RuleRepository $rules,private Store $store,private Queue $queue,private Runner $runner,
        private ILockingProvider $locks,private LockNames $names) {}
    public function counts(): array { return $this->store->counts(); }
    private function sourceIds(): array {
        $ids=[]; $cursor=0;
        do {
            $rows=$this->store->cardIdsAfter($cursor,100);
            foreach ($rows as $id) { $ids[$id]=true; $cursor=$id; }
        } while (count($rows)===100);
        $cursor=0;
        do {
            $rows=$this->store->legacyCardsAfter($cursor,100);
            foreach ($rows as $r) { $cursor=(int)$r['id']; if (ctype_digit($r['source_id'])) $ids[(int)$r['source_id']]=true; }
        } while (count($rows)===100);
        $q=$this->db->getQueryBuilder();
        foreach ($q->select('card_id')->from('dcb_sync_queue')->executeQuery()->fetchAllAssociative() as $r) $ids[(int)$r['card_id']]=true;
        return array_keys($ids);
    }
    public function uninstall(bool $execute,bool $keepEvents): array {
        $plan=['execute'=>$execute,'calendar_events'=>$keepEvents?'keep':'delete_verified_originals',
            'deck_cards_and_tags'=>'keep','app_files'=>'remove','tables'=>self::TABLES]+$this->counts();
        if (!$execute) return $plan;
        $appPath=$this->apps->getAppPath(self::APP);
        if (is_link($appPath) || !is_writable($appPath) || !is_writable(dirname($appPath))) {
            throw new \RuntimeException('App directory must be a writable real directory, not a development symlink');
        }
        $key=$this->names->key('uninstall');
        $this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        $held=[];
        try {
            $snapshot=$this->rules->read();
            if ($this->config->getAppValue(self::APP,'uninstall_in_progress','no')!=='yes') {
                $this->config->setAppValue(self::APP,'uninstall_rules_backup',json_encode($snapshot['rules'],JSON_THROW_ON_ERROR));
            }
            $this->config->setAppValue(self::APP,'uninstall_in_progress','yes');
            $this->config->setAppValue(self::APP,'relations_v1_enabled','no');
            if (!$keepEvents) $this->rules->replace([],$snapshot['revision']);
            $ids=$this->sourceIds();
            if (!$keepEvents) foreach ($ids as $id) {
                $this->queue->mark($id,false);
                $result=$this->runner->card($id,true);
                if (($result['blocked']??[])!==[] || ($result['pending']??false)) {
                    throw new \RuntimeException('Cleanup pending for card '.$id.': '.json_encode($result,JSON_THROW_ON_ERROR));
                }
            }
            // Services must be stopped before this explicit operation. Detect
            // any still-running operation; retain all successfully acquired
            // mutexes until metadata removal is complete.
            $scanKey=$this->names->key('scan');
            $this->locks->acquireLock($scanKey,ILockingProvider::LOCK_EXCLUSIVE); $held[]=$scanKey;
            $ids=array_unique(array_merge($ids,$this->sourceIds()));
            foreach ($ids as $id) {
                foreach (['card/','queue/'] as $kind) {
                    $cardKey=$this->names->key($kind.$id);
                    $this->locks->acquireLock($cardKey,ILockingProvider::LOCK_EXCLUSIVE); $held[]=$cardKey;
                }
            }
            if (!$keepEvents && $this->counts()['open_relations']!==0) throw new \RuntimeException('Original event cleanup is incomplete');
            $this->apps->disableApp(self::APP);
            if (!$this->installer->removeApp(self::APP)) throw new \RuntimeException('App file removal failed; metadata retained');
            foreach (self::JOBS as $job) $this->jobs->remove('OCA\\DeckCalendarBridge\\BackgroundJob\\'.$job);
            foreach (self::TABLES as $table) $this->db->dropTable($table);
            $q=$this->db->getQueryBuilder();
            $q->delete('migrations')->where($q->expr()->eq('app',$q->createNamedParameter(self::APP)))->executeStatement();
            $this->config->deleteAppFromAllUsers(self::APP);
            $this->appConfig->deleteApp(self::APP);
            return $plan+['removed'=>true];
        } finally {
            foreach (array_reverse($held) as $heldKey) $this->locks->releaseLock($heldKey,ILockingProvider::LOCK_EXCLUSIVE);
            $this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        }
    }
}
