<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Command;
use OCA\DeckCalendarBridge\Sync\{EnvironmentCheck,Store};
use OCA\DeckCalendarBridge\Service\RuleRepository;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
final class Doctor extends Command {
    public function __construct(private EnvironmentCheck $environment,private Store $store,private RuleRepository $rules,private IConfig $config) { parent::__construct(); }
    protected function configure(): void {
        $this->setName('deckcalendarbridge:doctor')->setDescription('Check process identity, file access, locking and bridge state without changing appointments');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        $enabled=$this->rules->enabled();
        $r=$this->environment->report()+['enabled'=>$enabled,
            'uninstall_in_progress'=>$this->config->getAppValue('deckcalendarbridge','uninstall_in_progress','no')==='yes']+$this->store->counts();
        try {
            $snapshot=$this->rules->read();
            $r['rules']=count($snapshot['rules']);
            $r['checks']['rules_valid']=true;
        } catch (\Throwable) {
            $r['checks']['rules_valid']=false;
            $r['configuration_error']='Stored rules are invalid; review the administrator configuration';
        }
        $r['ok']=!in_array(false,$r['checks'],true);
        $output->writeln(json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return $r['ok']?Command::SUCCESS:Command::FAILURE;
    }
}
