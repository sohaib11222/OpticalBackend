<?php

namespace App\Services\Commerce;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Intl\Currencies;

class Money
{
    public const MAX_AMOUNT = 9000000000000;

    public static function precision(string $currency): int
    {
        if (! Currencies::exists($currency) || in_array($currency, ['XXX', 'XTS'])) {
            throw ValidationException::withMessages(['currency' => 'Choose a supported currency.']);
        }

        return Currencies::getFractionDigits($currency);
    }

    public static function parse(string $value, string $currency, string $field = 'price'): int
    {
        $digits = self::precision($currency);
        if (! preg_match('/^\d{1,10}'.($digits ? '(?:\.\d{1,'.$digits.'})?' : '').'$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Enter a non-negative amount with at most '.$digits.' decimal places.']);
        }
        $parts = explode('.', $value);
        $amount = ((int) $parts[0]) * (10 ** $digits) + (int) str_pad($parts[1] ?? '', $digits, '0');
        if ($amount > self::MAX_AMOUNT) {
            throw ValidationException::withMessages([$field => 'Amount exceeds the supported limit.']);
        }

        return $amount;
    }

    public static function decimal(int $minor, int $digits): string
    {
        $negative = $minor < 0;
        $value = (string) abs($minor);
        if ($digits) {
            $value = str_pad($value, $digits + 1, '0', STR_PAD_LEFT);
            $value = substr($value, 0, -$digits).'.'.substr($value, -$digits);
        }

        return ($negative ? '-' : '').$value;
    }
}
