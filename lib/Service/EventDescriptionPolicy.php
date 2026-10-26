<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Service;

use InvalidArgumentException;

/** Stable public card link and field ownership notice; never exports card notes. */
final class EventDescriptionPolicy {
    public static function forCard(string $url,bool $bidirectionalTimes=true): string {
        if (strlen($url)>2048 || filter_var($url,FILTER_VALIDATE_URL)===false
            || parse_url($url,PHP_URL_SCHEME)!=='https'
            || parse_url($url,PHP_URL_USER)!==null || parse_url($url,PHP_URL_PASS)!==null) {
            throw new InvalidArgumentException('Invalid Deck HTTPS URL');
        }
        return 'Linked Deck card: '.$url."\n"
            .'Title and categories are managed in Deck. Please edit them on the card.'."\n"
            .($bidirectionalTimes
                ? 'Time changes made here are synchronized back to Deck.'
                : 'Times are managed in Deck for this rule. Please edit them on the card.');
    }
}
