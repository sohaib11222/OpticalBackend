<?php

namespace App\Services\Files;

use RuntimeException;
use Symfony\Component\Process\Process;

class DefenderScanner implements FileScanner
{
    public function executable(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Windows Defender requires a Windows worker.');
        }
        $paths = glob((getenv('ProgramData') ?: 'C:/ProgramData').'/Microsoft/Windows Defender/Platform/*/MpCmdRun.exe') ?: [];
        rsort($paths, SORT_NATURAL);
        $paths[] = (getenv('ProgramFiles') ?: 'C:/Program Files').'/Windows Defender/MpCmdRun.exe';
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        throw new RuntimeException('Windows Defender scanner is unavailable.');
    }

    public function scan($stream): bool
    {
        $binary = $this->executable();
        $directory = storage_path('app/private/scanning');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Private scan storage unavailable.');
        }
        $path = tempnam($directory, 'scan-');
        if (! $path) {
            throw new RuntimeException('Private scan file unavailable.');
        }
        try {
            $output = fopen($path, 'wb');
            try {
                $bytes = stream_copy_to_stream($stream, $output, config('documents.max_bytes') + 1);
            } finally {
                fclose($output);
            }
            if ($bytes === false || $bytes > config('documents.max_bytes')) {
                throw new RuntimeException('File exceeds scan limit.');
            }
            $hash = hash_file('sha256', $path);
            // DisableRemediation ignores exclusions and prevents "repaired" content being accepted as original.
            $process = new Process([$binary, '-Scan', '-ScanType', '3', '-File', $path, '-DisableRemediation']);
            $process->setTimeout(45)->run();
            // Defender code 2 can mean malware OR scan failure. Both remain quarantined; never infer clean.
            if (! $process->isSuccessful() || ! is_file($path) || ! hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException('Security scanner did not approve the original file.');
            }

            return true;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
