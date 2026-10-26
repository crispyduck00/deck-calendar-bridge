<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Command;
use OCA\DeckCalendarBridge\Sync\Queue;
use OCA\DeckCalendarBridge\Sync\Runner;
use OCA\DeckCalendarBridge\Sync\Scanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
final class Sync extends Command {
    public function __construct(private Runner $runner,private Scanner $scanner,private Queue $queue) { parent::__construct(); }
    protected function configure(): void {
        $this->setName('deckcalendarbridge:sync')->setDescription('Run the canonical card/calendar reconciler')
            ->addOption('card-id',null,InputOption::VALUE_REQUIRED,'Reconcile a specific card');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        if (!$this->runner->enabled()) { $output->writeln('Disabled. Enable relations_v1_enabled=yes in settings.'); return Command::FAILURE; }
        $id=$input->getOption('card-id');
        if ($id!==null) {
            if (!ctype_digit((string)$id)||(int)$id<1) return Command::INVALID;
            $this->queue->mark((int)$id); $results=[(int)$id=>$this->runner->card((int)$id)];
        } else { $this->scanner->scan(); $results=$this->runner->pending(); }
        $output->writeln(json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        foreach ($results as $r) if (($r['blocked']??[])!==[]) return Command::FAILURE;
        return Command::SUCCESS;
    }
}
