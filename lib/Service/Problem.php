<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
final class Problem extends \RuntimeException
{
    public function __construct(
        public string $errorCode,
        string $message,
        public int $status = 422,
        public array $details = [],
    ) {
        parent::__construct($message);
    }
    public static function missing(): self
    {
        return new self('not_found', 'This information is not available.', 404);
    }
}
