<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\AppInfo;

use OCA\DeckCalendarBridge\Listener\SyncListener;
use OCA\DeckCalendarBridge\Sync;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
    public const APP_ID='deckcalendarbridge';
    public function __construct() { parent::__construct(self::APP_ID); }
    public function register(IRegistrationContext $context): void {
        $context->registerServiceAlias(Sync\DeckPort::class,Sync\DelegatedDeck::class);
        $context->registerServiceAlias(Sync\CalendarPort::class,Sync\NativeCalendar::class);
        $context->registerServiceAlias(Sync\RelationStore::class,Sync\Store::class);
        foreach ([
            \OCA\Deck\Event\CardCreatedEvent::class,
            \OCA\Deck\Event\CardUpdatedEvent::class,
            \OCA\Deck\Event\CardDeletedEvent::class,
            \OCP\Calendar\Events\CalendarObjectUpdatedEvent::class,
            \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent::class,
            \OCP\Calendar\Events\CalendarObjectDeletedEvent::class,
            \OCP\Calendar\Events\CalendarObjectRestoredEvent::class,
        ] as $event) $context->registerEventListener($event,SyncListener::class);
    }
    public function boot(IBootContext $context): void {}
}
