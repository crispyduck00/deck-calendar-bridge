<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Migration;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Fresh app installs skip migration data hooks; use explicit lifecycle steps. */
final class InitializeInstance implements IRepairStep {
    public function __construct(private IConfig $config) {}
    public function getName(): string { return 'Initialize Deck Calendar Bridge installation identity'; }
    public function run(IOutput $output): void {
        $namespace=$this->config->getAppValue('deckcalendarbridge','runtime_namespace','');
        if ($namespace==='') {
            $this->config->setAppValue('deckcalendarbridge','runtime_namespace',bin2hex(random_bytes(16)));
        } elseif (!preg_match('/^[a-f0-9]{32}$/D',$namespace)) {
            throw new \RuntimeException('Invalid bridge installation identity');
        }
    }
}
