<?php

namespace App\Services\Imports;

use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Conservative, explainable import mapping.  Header aliases do the primary
 * work; data patterns are only used for values with a strong signature (email,
 * phone and date).  Anything not safely recognised remains a custom field.
 */
class CustomerColumnMapper
{
    /** @var array<string,list<string>> */
    private const ALIASES = [
        'first_name' => ['first', 'first name', 'firstname', 'given name', 'name', 'nome', 'nome cliente'],
        'last_name' => ['last', 'last name', 'lastname', 'surname', 'family name', 'cognome'],
        'date_of_birth' => ['date of birth', 'birth date', 'birthdate', 'birthday', 'dob', 'data di nascita', 'nascita'],
        'phone' => ['phone', 'phone number', 'telephone', 'tel', 'mobile', 'cell', 'cellulare', 'telefono', 'numero telefono'],
        'email' => ['email', 'e mail', 'email address', 'mail', 'indirizzo email', 'posta elettronica'],
        'street_address' => ['address', 'street', 'street address', 'address line 1', 'address1', 'indirizzo', 'via'],
        'city' => ['city', 'town', 'municipality', 'comune', 'citta', 'localita', 'locality'],
        'postal_code' => ['postal code', 'postcode', 'post code', 'zip', 'zip code', 'cap', 'codice postale'],
        'sex' => ['sex', 'gender', 'sesso', 'genere'],
    ];

    /** @param list<string> $headers @param list<list<string>> $samples */
    public function detect(array $headers, array $samples = []): array
    {
        $used = [];
        $mapping = [];
        $mapped = [];
        foreach (Customer::FIELDS as $field) {
            [$column, $confidence] = $this->bestHeader($field, $headers, $used);
            if ($column === null) {
                [$column, $confidence] = $this->bestPattern($field, $headers, $samples, $used);
            }
            // A 72% threshold deliberately avoids guessing customer identity.
            if ($column !== null && $confidence >= 0.72) {
                $used[$column] = true;
                $mapping[$field] = $column;
                $mapped[$field] = ['column' => $column, 'header' => $headers[$column] ?: 'Column '.($column + 1), 'confidence' => $confidence];
            }
        }

        $additional = [];
        $customKeys = [];
        foreach ($headers as $column => $header) {
            if (isset($used[$column])) {
                continue;
            }
            $key = $this->customKey($header, $column, $customKeys);
            $customKeys[] = $key;
            $additional[$column] = ['action' => 'custom', 'key' => $key];
        }

        [$dateFormat, $dateConfidence] = $this->dateFormat(
            isset($mapping['date_of_birth']) ? $mapping['date_of_birth'] : null,
            $headers,
            $samples,
        );

        return [
            'mapping' => $mapping,
            'additional_mapping' => $additional,
            'date_format' => $dateFormat,
            'mapping_diagnostics' => [
                'mapped' => $mapped,
                'needs_mapping' => array_values(array_diff(['first_name', 'last_name'], array_keys($mapping))),
                'additional_fields' => array_values(array_map(fn ($column, $row) => [
                    'column' => (int) $column,
                    'header' => $headers[$column] ?: 'Column '.($column + 1),
                    'key' => $row['key'],
                ], array_keys($additional), $additional)),
                'date_format_confidence' => $dateConfidence,
            ],
        ];
    }

    /** @param array<int,true> $used @param list<string> $headers @return array{?int,float} */
    private function bestHeader(string $field, array $headers, array $used): array
    {
        $best = [null, 0.0];
        foreach ($headers as $column => $header) {
            if (isset($used[$column])) {
                continue;
            }
            $header = $this->normalise($header);
            if ($header === '') {
                continue;
            }
            foreach (self::ALIASES[$field] as $alias) {
                $alias = $this->normalise($alias);
                $score = $header === $alias ? 1.0 : (str_contains($header, $alias) || str_contains($alias, $header) ? 0.86 : 0.0);
                if ($score > $best[1]) {
                    $best = [$column, $score];
                }
            }
        }

        return $best;
    }

    /** @param array<int,true> $used @param list<string> $headers @param list<list<string>> $samples @return array{?int,float} */
    private function bestPattern(string $field, array $headers, array $samples, array $used): array
    {
        if (! in_array($field, ['email', 'phone', 'date_of_birth'], true) || ! $samples) {
            return [null, 0.0];
        }
        $best = [null, 0.0];
        foreach (array_keys($headers) as $column) {
            if (isset($used[$column])) {
                continue;
            }
            $values = array_values(array_filter(array_map(fn ($row) => trim((string) ($row[$column] ?? '')), $samples), fn ($value) => $value !== ''));
            if (! $values) {
                continue;
            }
            $matches = match ($field) {
                'email' => count(array_filter($values, fn ($value) => filter_var($value, FILTER_VALIDATE_EMAIL))),
                'phone' => count(array_filter($values, fn ($value) => preg_match('/^\+?[0-9 ()\-.]{7,40}$/', $value))),
                'date_of_birth' => count(array_filter($values, fn ($value) => $this->looksLikeDate($value))),
            };
            $score = $matches / count($values);
            if ($score > $best[1]) {
                $best = [$column, $score];
            }
        }

        return $best;
    }

    /** @param list<string> $headers @param list<list<string>> $samples @return array{string,float} */
    private function dateFormat(?int $column, array $headers, array $samples): array
    {
        if ($column === null) {
            return ['Y-m-d', 1.0];
        }
        $values = array_values(array_filter(array_map(fn ($row) => trim((string) ($row[$column] ?? '')), $samples), fn ($value) => $value !== ''));
        if (! $values) {
            return ['Y-m-d', 0.5];
        }
        if (count(array_filter($values, fn ($value) => is_numeric($value) && (float) $value >= 1 && (float) $value <= 2958465)) === count($values)) {
            return ['excel', 0.98];
        }
        if (count(array_filter($values, fn ($value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))) === count($values)) {
            return ['Y-m-d', 0.98];
        }
        $dayFirst = 0;
        $monthFirst = 0;
        foreach ($values as $value) {
            if (! preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $value, $parts)) {
                continue;
            }
            if ((int) $parts[1] > 12) {
                ++$dayFirst;
            }
            if ((int) $parts[2] > 12) {
                ++$monthFirst;
            }
        }
        if ($dayFirst > 0 && $monthFirst === 0) {
            return ['d/m/Y', 0.94];
        }
        if ($monthFirst > 0 && $dayFirst === 0) {
            return ['m/d/Y', 0.94];
        }
        // Italian headers make day/month the least surprising fallback. It is
        // marked low-confidence so the guided page asks for confirmation.
        $italian = str_contains($this->normalise($headers[$column] ?? ''), 'nascita');

        return [$italian ? 'd/m/Y' : 'm/d/Y', 0.60];
    }

    private function looksLikeDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            || preg_match('/^\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4}$/', $value) === 1
            || (is_numeric($value) && (float) $value >= 1 && (float) $value <= 2958465);
    }

    /** @param list<string> $used */
    private function customKey(string $header, int $column, array $used): string
    {
        $base = trim(preg_replace('/\s+/', ' ', $header) ?? '');
        $base = $base !== '' ? Str::limit($base, 90, '') : 'Additional field '.($column + 1);
        $candidate = $base;
        $suffix = 2;
        while (in_array(mb_strtolower($candidate), array_map('mb_strtolower', $used), true)) {
            $candidate = Str::limit($base, 90 - strlen((string) $suffix), '').' '.$suffix;
            ++$suffix;
        }

        return $candidate;
    }

    private function normalise(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
