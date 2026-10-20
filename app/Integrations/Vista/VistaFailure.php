<?php

namespace App\Integrations\Vista;

class VistaFailure extends \RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}
