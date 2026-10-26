<?php
declare(strict_types=1);

namespace OCA\DeckCalendarBridge\Service;

use OCP\IConfig;
use OCP\IAppConfig;
use OCA\DeckCalendarBridge\Sync\LockNames;
use OCP\Lock\ILockingProvider;
use RuntimeException;

/** Single JSON snapshot + SHA-256 revision; exclusive distributed NC lock guards writes. */
final class RuleRepository {
    private const APP = 'deckcalendarbridge';
    private const KEY = 'rules_snapshot_v1';

    public function __construct(
        private IConfig $config,
        private ILockingProvider $locks,
        private RuleValidator $validator,
        private IAppConfig $appConfig,
        private LockNames $lockNames,
    ) {}

    public function enabled(): bool {
        $this->appConfig->clearCache();
        return $this->config->getAppValue(self::APP,'relations_v1_enabled','no')==='yes'
            && $this->config->getAppValue(self::APP,'enabled','no')!=='no'
            && $this->config->getAppValue(self::APP,'uninstall_in_progress','no')!=='yes';
    }

    public function read(): array {
        // Native background-job:worker keeps services alive across jobs. Its
        // in-process AppConfig cache otherwise hides external rule changes.
        $this->appConfig->clearCache();
        $raw = $this->config->getAppValue(self::APP, self::KEY, '');
        if ($raw === '') {
            return ['revision' => hash('sha256', '[]'), 'rules' => []];
        }
        try {
            $rules = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $rules = $this->validator->validate($rules);
            (new TagRuleInvariants())->verify($rules);
        } catch (\Throwable $e) {
            throw new RuntimeException('Stored rule configuration is invalid', 0, $e);
        }
        return ['revision' => hash('sha256', $raw), 'rules' => $rules];
    }

    public function replace(mixed $rules, string $expectedRevision): array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedRevision)) {
            throw new \InvalidArgumentException('Invalid revision');
        }
        $normalized = $this->validator->validate($rules);
        // Do not let the admin save two active rules for the same board/tag:
        // calendar DELETE -> exact Deck label would otherwise be ambiguous
        // and could cancel an unrelated organizer event.
        (new TagRuleInvariants())->verify($normalized);
        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 131072) {
            throw new \InvalidArgumentException('Rules payload exceeds 128 KiB');
        }

        $lock=$this->lockNames->key('rules');
        $this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $current = $this->read();
            if (!hash_equals($current['revision'], $expectedRevision)) {
                throw new RuleRevisionConflict('Rules have changed; reload before saving');
            }
            $this->config->setAppValue(self::APP, self::KEY, $encoded);
            return ['revision' => hash('sha256', $encoded), 'rules' => $normalized];
        } finally {
            $this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }
}
