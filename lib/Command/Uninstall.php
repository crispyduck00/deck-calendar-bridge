<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Command;
use OCA\DeckCalendarBridge\Sync\Maintenance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
final class Uninstall extends Command {
    public function __construct(private Maintenance $maintenance) { parent::__construct(); }
    protected function configure(): void {
        $this->setName('deckcalendarbridge:uninstall')->setDescription('Preview or explicitly remove bridge events, app files, jobs and metadata; stop the worker first')
            ->addOption('execute',null,InputOption::VALUE_NONE,'Perform the displayed removal plan')
            ->addOption('keep-events',null,InputOption::VALUE_NONE,'Retain calendar events as independent appointments');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        $r=$this->maintenance->uninstall($input->getOption('execute'),$input->getOption('keep-events'));
        $output->writeln(json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if (!$input->getOption('execute')) $output->writeln('Preview only. Stop the bridge service, then use --execute to perform this plan.');
        return Command::SUCCESS;
    }
}
