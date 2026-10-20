<?php

namespace App\Services\Communications;

class DeliveryFailure extends \RuntimeException
{
    public function __construct(public string $state, public string $reason, string $message)
    {
        parent::__construct($message);
    }
}
