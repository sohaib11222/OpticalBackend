<?php

namespace App\Services\Files;

use RuntimeException;

class ClamdScanner implements FileScanner
{
    public function scan($stream): bool
    {
        $address = config('documents.clamd_address');
        $socket = @stream_socket_client($address, $errno, $error, 5);
        if (! $socket) {
            throw new RuntimeException('File scanner unavailable.');
        }
        try {
            stream_set_timeout($socket, 30);
            $this->writeAll($socket, "zINSTREAM\0");
            $total = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('File read failed.');
                }$total += strlen($chunk);
                if ($total > config('documents.max_bytes')) {
                    throw new RuntimeException('File exceeds scan limit.');
                }if ($chunk !== '') {
                    $this->writeAll($socket, pack('N', strlen($chunk)).$chunk);
                }
            }
            $this->writeAll($socket, pack('N', 0));
            $reply = '';
            while (! str_contains($reply, "\0") && strlen($reply) < 4096 && ! feof($socket)) {
                $chunk = fread($socket, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }$reply .= $chunk;
            }
            if (stream_get_meta_data($socket)['timed_out']) {
                throw new RuntimeException('File scanner timed out.');
            }
            $reply = trim($reply, "\0\r\n ");
            if ($reply === 'stream: OK') {
                return true;
            }
            if (preg_match('/^stream: .+ FOUND$/D', $reply)) {
                return false;
            }
            throw new RuntimeException('File scanner did not return a clean verdict.');
        } finally {
            fclose($socket);
        }
    }

    private function writeAll($socket, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($socket, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Scanner stream failed.');
            }$bytes = substr($bytes, $written);
        }
    }
}
