<?php
declare(strict_types=1);
namespace OCA\DeckCalendarBridge\Command;
use OCA\DeckCalendarBridge\Sync\Store;
use OCA\DeckCalendarBridge\Sync\CalendarPort;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
final class RelationsStatus extends Command {
    public function __construct(private Store $store,private CalendarPort $calendar) { parent::__construct(); }
    protected function configure(): void {
        $this->setName('deckcalendarbridge:relations:status')->setDescription('Read-only relation state and original calendar lookup')
            ->addOption('card-id',null,InputOption::VALUE_REQUIRED)->addOption('details',null,InputOption::VALUE_NONE)
            ->addOption('verify-local',null,InputOption::VALUE_NONE);
    }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        $id=$input->getOption('card-id');
        if ($id!==null&&(!ctype_digit((string)$id)||(int)$id<1)) return Command::INVALID;
        $rows=$this->store->status($id===null?null:(int)$id);
        if ($input->getOption('verify-local')) foreach ($rows as &$r) {
            try { $r['original']=$this->calendar->read($r)['status']; }
            catch (\Throwable $e) { $r['original']=$e->getMessage(); }
        }
        $output->writeln(json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $output->writeln('Read only. Legacy 0.3.x links are verified and adopted by the next enabled reconcile.');
        return Command::SUCCESS;
    }
}
