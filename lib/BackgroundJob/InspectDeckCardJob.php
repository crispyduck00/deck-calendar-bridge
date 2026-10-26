<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\BackgroundJob;
/** Retired 0.3.x job. New writes use SyncScanJob/ReconcileCardJob exclusively. */
final class InspectDeckCardJob extends \OCP\BackgroundJob\QueuedJob {
    protected function run($argument): void {}
}
