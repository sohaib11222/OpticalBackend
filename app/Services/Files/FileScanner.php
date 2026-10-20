<?php

namespace App\Services\Files;

interface FileScanner
{
    /** @param resource $stream */
    public function scan($stream): bool;
}
