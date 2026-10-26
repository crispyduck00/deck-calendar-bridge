<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/** Minimal, server-rendered administration: does not execute sync operations. */
final class AdminSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('deckcalendarbridge', 'admin');
    }
    public function getSection(): string { return 'deckcalendarbridge'; }
    public function getPriority(): int { return 65; }
}
