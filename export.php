
<?php
require_once "auth.php";
requireLogin();
require_once "db.php";
require_once __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$sheet_id = (int)($_GET["id"] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM sheets WHERE id = ?");
$stmt->execute([$sheet_id]);
$currentSheet = $stmt->fetch();

if (!$currentSheet) {
    http_response_code(404);
    exit("Sheet not found.");
}

$stmt = $pdo->prepare(
    "SELECT * FROM spreadsheet_columns
     WHERE sheet_id = ? ORDER BY position"
);
$stmt->execute([$sheet_id]);
$columns = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT * FROM spreadsheet_rows
     WHERE sheet_id = ? ORDER BY id"
);
$stmt->execute([$sheet_id]);
$rows = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT c.* FROM spreadsheet_cells c
     JOIN spreadsheet_rows r ON r.id = c.row_id
     WHERE r.sheet_id = ?"
);
$stmt->execute([$sheet_id]);
$cells = $stmt->fetchAll();

$cellData = [];

foreach ($cells as $cell) {
    $cellData[$cell["row_id"]][$cell["column_id"]] =
        $cell["cell_value"];
}

$excel = new Spreadsheet();
$worksheet = $excel->getActiveSheet();
$worksheet->setTitle("AjaxSheet");

$headers = array_column($columns, "column_name");

if ($headers) {
    $worksheet->fromArray($headers, null, "A1");
}

$excelRow = 2;

foreach ($rows as $row) {
    $values = [];

    foreach ($columns as $column) {
        $values[] = $cellData[$row["id"]][$column["id"]] ?? "";
    }

    if ($values) {
        $worksheet->fromArray($values, null, "A" . $excelRow);
    }

    $excelRow++;
}

// Basic formatting
$lastColumn = $worksheet->getHighestColumn();

if ($columns) {
    $worksheet->getStyle("A1:" . $lastColumn . "1")
        ->getFont()->setBold(true);

    foreach (range(1, count($columns)) as $columnNumber) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnNumber);
        $worksheet->getColumnDimension($letter)->setAutoSize(true);
    }
}

$filename = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    $currentSheet["sheet_name"]
);

header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header(
    'Content-Disposition: attachment; filename="' .
    $filename . '.xlsx"'
);
header("Cache-Control: max-age=0");

$writer = new Xlsx($excel);
$writer->save("php://output");
exit;
