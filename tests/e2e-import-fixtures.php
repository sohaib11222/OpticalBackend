<?php

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
if (getenv('APP_ENV') !== 'testing' || basename(getenv('DB_DATABASE') ?: '') !== 'e2e.sqlite') {
    throw new RuntimeException('Test fixture only');
}
$book = new Spreadsheet;
$sheet = $book->getActiveSheet();
$sheet->setTitle('Customers');
$sheet->fromArray([['First', 'Last', 'Email', 'CAP'], ['Workbook', 'Customer', 'workbook@example.test', '00124']]);
$sheet->setCellValueExplicit('D2', '00124', DataType::TYPE_STRING);
(new Xlsx($book))->save(__DIR__.'/../../.tmp/customer-import.xlsx');
$book->disconnectWorksheets();

$image = imagecreatetruecolor(60, 60);
$blue = imagecolorallocate($image, 30, 80, 180);
imagefill($image, 0, 0, $blue);
imagepng($image, __DIR__.'/../../.tmp/frame.png');
imagedestroy($image);
