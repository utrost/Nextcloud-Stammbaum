<?php
declare(strict_types=1);
namespace OCA\Stammbaum\BackgroundJob;
use OCA\Stammbaum\Service\Imports;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
final class ProcessImports extends TimedJob
{
    public function __construct(ITimeFactory $time, private Imports $imports)
    {
        parent::__construct($time);
        $this->setInterval(60);
        $this->setAllowParallelRuns(false);
    }
    protected function run($argument): void
    {
        $start = microtime(true);
        for ($i = 0; $i < 5 && microtime(true) - $start < 45; $i++) {
            if ($this->imports->work() === null) {
                break;
            }
        }
    }
}
