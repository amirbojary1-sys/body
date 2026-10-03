<?php
declare(strict_types=1);
namespace FitBot;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $errorKey = 'request_failed', public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
