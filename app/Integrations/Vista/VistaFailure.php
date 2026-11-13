<?php

namespace App\Integrations\Vista;

class VistaFailure extends \RuntimeException
{
    public function __construct(public int $status, string $message, public ?string $reason = null, public array $details = [])
    {
        parent::__construct($message);
    }
}
