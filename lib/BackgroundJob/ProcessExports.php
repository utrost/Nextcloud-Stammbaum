<?php
declare(strict_types=1);
namespace OCA\Stammbaum\BackgroundJob;
use OCA\Stammbaum\Service\Exports;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
final class ProcessExports extends TimedJob
{
    public function __construct(ITimeFactory $time, private Exports $exports)
    {
        parent::__construct($time);
        $this->setInterval(60);
        $this->setAllowParallelRuns(false);
    }
    protected function run($argument): void
    {
        $this->exports->work();
    }
}
