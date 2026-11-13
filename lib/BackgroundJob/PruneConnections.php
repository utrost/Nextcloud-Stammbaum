<?php
declare(strict_types=1);
namespace OCA\Stammbaum\BackgroundJob;

use OCA\Stammbaum\Service\IntegrationConnections;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;

/** Prunes only private local receipts; never touches DAV objects or originals. */
final class PruneConnections extends TimedJob
{
    public function __construct(ITimeFactory $time, private IntegrationConnections $connections)
    {
        parent::__construct($time);
        $this->setInterval(86400);
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void
    {
        $this->connections->cleanup();
    }
}
