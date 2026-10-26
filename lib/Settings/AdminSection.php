<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Settings;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
final class AdminSection implements IIconSection {
    public function __construct(private IURLGenerator $urls) {}
    public function getID(): string { return 'deckcalendarbridge'; }
    public function getName(): string { return 'Deck Calendar Bridge'; }
    public function getPriority(): int { return 72; }
    public function getIcon(): string {
        return $this->urls->imagePath('core','actions/settings-dark.svg');
    }
}
