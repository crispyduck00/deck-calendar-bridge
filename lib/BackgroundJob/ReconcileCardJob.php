<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\BackgroundJob;
use OCA\DeckCalendarBridge\Sync\Runner;
use OCA\DeckCalendarBridge\Sync\Queue;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
final class ReconcileCardJob extends QueuedJob {
    public function __construct(ITimeFactory $time,private Runner $runner,private Queue $queue,private IJobList $jobs) { parent::__construct($time); }
    protected function run($argument): void {
        if (!is_array($argument)||!is_int($argument['cardId']??null)||$argument['cardId']<1) return;
        $this->runner->card($argument['cardId']);
        $pending=$this->queue->get($argument['cardId']);
        // QueuedJob removes itself before running. A deferred/failed operation
        // must retain an actual future job, not only wait for the next cron.
        if ($pending!==null && $this->runner->enabled() && !$this->jobs->has(self::class,$argument)) {
            $this->jobs->scheduleAfter(self::class,max($this->time->getTime()+2,$pending['retry_after']),$argument);
        }
    }
}
