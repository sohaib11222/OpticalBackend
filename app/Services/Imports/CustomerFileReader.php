<?php

namespace App\Services\Imports;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class CustomerFileReader
{
    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 32;

    public function read(string $path, string $extension, ?string $sheet, string $delimiter): array
    {
        return $extension === 'csv' ? $this->csv($path, $delimiter) : $this->xlsx($path, $sheet);
    }

    private function csv(string $path, string $delimiter): array
    {
        $stream = fopen($path, 'rb');
        $rows = [];
        $line = 0;
        try {
            while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($line > self::MAX_ROWS + 1) {
                    throw ValidationException::withMessages(['file' => 'Maximum 5,000 data rows per import.']);
                }
                if (count($cells) > self::MAX_COLUMNS) {
                    throw ValidationException::withMessages(['file' => 'Maximum 32 source columns.']);
                }
                foreach ($cells as &$value) {
                    $value = $value ?? '';
                    if (! mb_check_encoding($value, 'UTF-8')) {
                        throw ValidationException::withMessages(['file' => 'CSV must use UTF-8 encoding.']);
                    }if (strlen($value) > 4096) {
                        throw ValidationException::withMessages(['file' => 'A cell exceeds 4,096 bytes.']);
                    }$value = trim($value);
                }unset($value);
                if ($line === 1) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0] ?? '');
                    $headers = $cells;

                    continue;
                }
                if (count(array_filter($cells, fn ($v) => $v !== '')) === 0) {
                    continue;
                }$rows[] = ['row_number' => $line, 'values' => $cells, 'errors' => []];
            }
        } finally {
            fclose($stream);
        }
        if (empty($headers) || ! $rows) {
            throw ValidationException::withMessages(['file' => 'A header row and at least one data row are required.']);
        }

        return ['headers' => $headers, 'rows' => $rows, 'sheets' => ['CSV'], 'sheet_name' => 'CSV', 'date_system' => 1900];
    }

    private function xlsx(string $path, ?string $sheet): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'This is not a valid XLSX workbook.']);
        }
        try {
            $total = 0;
            if ($zip->numFiles > 2000) {
                throw new \RuntimeException;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $total += $entry['size'];
                if ($total > 30 * 1024 * 1024 || $entry['size'] > 20 * 1024 * 1024 || preg_match('/(?:vbaProject|externalLinks|embeddings|activeX)\b/i', $entry['name'])) {
                    throw new \RuntimeException;
                }
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Workbook has external links, active content or exceeds safe expansion limits.']);
        } finally {
            $zip->close();
        }
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true)->setReadEmptyCells(false)->setIncludeCharts(false);
        $info = $reader->listWorksheetInfo($path);
        $sheets = array_column($info, 'worksheetName');
        $sheet = $sheet ?: ($sheets[0] ?? null);
        $selected = array_values(array_filter($info, fn ($s) => $s['worksheetName'] === $sheet))[0] ?? null;
        if (! $selected) {
            throw ValidationException::withMessages(['sheet_name' => 'Select an existing worksheet.']);
        }
        if ($selected['totalRows'] > self::MAX_ROWS + 1 || $selected['totalColumns'] > self::MAX_COLUMNS) {
            throw ValidationException::withMessages(['file' => 'Selected worksheet exceeds 5,000 data rows or 32 columns.']);
        }
        $reader->setLoadSheetsOnly($sheet);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= CustomerFileReader::MAX_ROWS + 1 && Coordinate::columnIndexFromString($columnAddress) <= CustomerFileReader::MAX_COLUMNS;
            }
        });
        $book = $reader->load($path);
        try {
            $tab = $book->getSheetByName($sheet);
            $rows = [];
            $headers = [];
            for ($row = 1; $row <= $selected['totalRows']; $row++) {
                $cells = [];
                $errors = [];
                for ($col = 1; $col <= $selected['totalColumns']; $col++) {
                    $cell = $tab->getCell([$col, $row]);
                    $value = $cell->getValue();
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $errors[] = 'Column '.Coordinate::stringFromColumnIndex($col).' contains a formula; replace it with a literal value.';
                        $value = '[formula not imported]';
                    }
                    if (is_object($value)) {
                        $value = (string) $value;
                    }$value = $value === null ? '' : trim((string) $value);
                    if (strlen($value) > 4096) {
                        $errors[] = 'A cell exceeds 4,096 bytes.';
                        $value = '[oversized cell]';
                    }$cells[] = $value;
                }
                if ($row === 1) {
                    if ($errors) {
                        throw ValidationException::withMessages(['file' => 'Headers must contain literal values.']);
                    }$headers = $cells;

                    continue;
                }
                if (! array_filter($cells, fn ($v) => $v !== '')) {
                    continue;
                }$rows[] = ['row_number' => $row, 'values' => $cells, 'errors' => $errors];
            }
            if (! $headers || ! $rows) {
                throw ValidationException::withMessages(['file' => 'A header row and data are required.']);
            }

            return ['headers' => $headers, 'rows' => $rows, 'sheets' => $sheets, 'sheet_name' => $sheet, 'date_system' => $book->getExcelCalendar()];
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
