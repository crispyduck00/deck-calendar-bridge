<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Sync;
use OCP\IConfig;

/** Persisted install identity isolates fresh installs from old Redis mutexes. */
final class LockNames {
    public function __construct(private IConfig $config) {}
    public function key(string $resource): string {
        $namespace=$this->config->getAppValue('deckcalendarbridge','runtime_namespace','');
        if (!preg_match('/^[a-f0-9]{32}$/D',$namespace)) {
            throw new \RuntimeException('Bridge installation is not initialized; run occ upgrade');
        }
        return 'deckcalendarbridge/v2/'.$namespace.'/'.$resource;
    }
}
