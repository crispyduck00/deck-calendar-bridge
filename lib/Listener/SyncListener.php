<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Listener;
use OCA\DeckCalendarBridge\Sync\Queue;
use OCA\DeckCalendarBridge\Sync\Store;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\DeckCalendarBridge\Service\RuleRepository;
use Psr\Log\LoggerInterface;

/** Trusted Nextcloud/Deck events enqueue only; no nested cross-app mutations. */
final class SyncListener implements IEventListener {
    public function __construct(private Queue $queue,private Store $store,private RuleRepository $rules,private LoggerInterface $logger) {}
    public function handle(Event $event): void {
        try {
            if (!$this->rules->enabled()) return;
            if ($event instanceof \OCA\Deck\Event\CardCreatedEvent
                || $event instanceof \OCA\Deck\Event\CardUpdatedEvent
                || $event instanceof \OCA\Deck\Event\CardDeletedEvent) {
                $this->queue->mark((int)$event->getCard()->getId()); return;
            }
            if (!$event instanceof \OCP\Calendar\Events\CalendarObjectUpdatedEvent
                && !$event instanceof \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent
                && !$event instanceof \OCP\Calendar\Events\CalendarObjectDeletedEvent
                && !$event instanceof \OCP\Calendar\Events\CalendarObjectRestoredEvent) return;
            $calendar=$event->getCalendarData(); $object=$event->getObjectData();
            if (!is_string($object['uid']??null)) return;
            $row=$this->store->findUid($object['uid']); if ($row===null) return;
            // Reject attendee copies even if their UID matches. No dependence
            // on the editor's right to the OTHER side: saved rule is delegation.
            if (($calendar['principaluri']??null)!==$row['principal']
                || ($calendar['uri']??null)!==$row['calendar_uri']
                || ($object['uri']??null)!==$row['object_uri']) return;
            if (($event instanceof \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent
                || $event instanceof \OCP\Calendar\Events\CalendarObjectDeletedEvent)
                && $row['state']!=='deleted' && ($row['data']['attempt']['kind']??'')!=='delete') {
                // Persist only a verified observation, never mutate Deck in a
                // DAV callback. Our own DELETE is already uncertain and skips
                // this marker. Hard deletes can then settle without a grace wait.
                try {
                    $row['data']['calendar_delete_observed']=true;
                    $this->store->save($row);
                } catch (\Throwable) { /* Concurrent attempt: queue and re-read. */ }
            }
            $this->queue->mark($row['card_id']);
        } catch (\Throwable $e) {
            $this->logger->warning('Deck Calendar Bridge could not enqueue observed change',[
                'app'=>'deckcalendarbridge','exception'=>$e]);
        }
    }
}
