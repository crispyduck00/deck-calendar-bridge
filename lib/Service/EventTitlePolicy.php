<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Service;

use InvalidArgumentException;

/** Literal title composition; legacy prefixes retain their exact spacing. */
final class EventTitlePolicy {
    public function normalize(array $presentation): array {
        $prefix=$presentation['titlePrefix']??'';
        if (!is_string($prefix)) throw new InvalidArgumentException('Title prefix must be a string');
        $this->validate($prefix);
        $legacy=!array_key_exists('titlePrefixMode',$presentation);
        $mode=$legacy?($prefix===''?'none':'custom'):$presentation['titlePrefixMode'];
        if (!in_array($mode,['none','custom','list'],true)) {
            throw new InvalidArgumentException('Invalid title prefix mode');
        }
        // Old titlePrefix already includes any punctuation and spaces. Never
        // append a new default separator to an existing literal prefix.
        $separator=array_key_exists('titleSeparator',$presentation)
            ?$presentation['titleSeparator']:($legacy?'':' - ');
        if (!is_string($separator) || strlen($separator)>32 || preg_match('//u',$separator)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$separator)) {
            throw new InvalidArgumentException('Invalid title separator');
        }
        return ['titlePrefixMode'=>$mode,'titlePrefix'=>$prefix,'titleSeparator'=>$separator];
    }

    public function validate(string $prefix): string {
        if (strlen($prefix) > 120 || preg_match('//u', $prefix) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $prefix)) {
            throw new InvalidArgumentException('Invalid calendar title prefix');
        }
        return $prefix;
    }

    public function render(string $deckTitle, string $prefix): string {
        $this->validate($prefix);
        return $this->join($deckTitle,$prefix);
    }

    public function renderForRule(string $deckTitle,array $presentation,?string $listName=null): string {
        $settings=$this->normalize($presentation);
        $prefix=match($settings['titlePrefixMode']) {
            'none'=>'',
            'custom'=>$settings['titlePrefix'],
            'list'=>$listName??throw new InvalidArgumentException('Deck list name unavailable'),
        };
        if ($settings['titlePrefixMode']==='list') {
            if (preg_match('//u',$prefix)!==1 || preg_match('/[\x00-\x1f\x7f]/',$prefix)) {
                throw new InvalidArgumentException('Invalid Deck list name');
            }
            if (trim($prefix)==='') $prefix='';
        }
        return $this->join($deckTitle,$prefix===''?'':$prefix.$settings['titleSeparator']);
    }

    private function join(string $deckTitle,string $prefix): string {
        if (trim($deckTitle) === '' || strlen($prefix . $deckTitle) > 1024) {
            throw new InvalidArgumentException('Invalid rendered event title');
        }
        return $prefix . $deckTitle;
    }

    /** null means conflict, not an instruction to remove the prefix. */
    public function reverse(string $calendarTitle, string $prefix): ?string {
        $this->validate($prefix);
        if (!str_starts_with($calendarTitle, $prefix)) return null;
        $title = substr($calendarTitle, strlen($prefix));
        if (trim($title) === '' || strlen($title) > 1024) return null;
        return $title;
    }
}
