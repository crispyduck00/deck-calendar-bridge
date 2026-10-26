<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\BackgroundJob;
use OCA\DeckCalendarBridge\Sync\Runner;
use OCA\DeckCalendarBridge\Sync\Scanner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
final class SyncScanJob extends TimedJob {
    public function __construct(ITimeFactory $time,private Runner $runner,private Scanner $scanner) {
        parent::__construct($time); $this->setInterval(60); $this->setAllowParallelRuns(false);
    }
    protected function run($argument): void {
        if (!$this->runner->enabled()) return;
        $this->scanner->scan(); $this->runner->pending();
    }
}
