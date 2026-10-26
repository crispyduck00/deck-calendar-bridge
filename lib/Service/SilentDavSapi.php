<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Service;

use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;

/** Keep nested DAV precondition failures inside the calling Nextcloud request. */
final class SilentDavSapi extends Sapi {
    public static function sendResponse(ResponseInterface $response) {
        // Intentionally no external HTTP emission.
    }
}
