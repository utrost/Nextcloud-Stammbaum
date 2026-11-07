<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Command;
use OCA\Stammbaum\Service\Imports;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
/** Same fenced worker as cron; no bypass of initiating account authority. */
final class ProcessImports extends Command
{
    public function __construct(private Imports $imports)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->setName('stammbaum:process-imports')->setDescription(
            'Process one pending Stammbaum import using its recorded authority.',
        );
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->imports->work();
        $output->writeln($id === null ? 'No pending import.' : 'Processed import ' . $id . '.');
        return self::SUCCESS;
    }
}
