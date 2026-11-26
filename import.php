
<?php
require_once __DIR__ . "/auth.php";
requireLogin();

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

// Check that PhpSpreadsheet is loading.
if (!class_exists(IOFactory::class)) {
    exit("PhpSpreadsheet library not found. Check Composer installation.");
}

// Automatically detect column validation rules.
function detectRule(array $values): string {
    $values = array_filter(
        $values,
        fn($v) => trim((string)$v) !== ""
    );

    if (empty($values)) {
        return "anything";
    }

    $allNumbers = true;
    $allLetters = true;
    $allAlphanumeric = true;

    foreach ($values as $value) {
        $value = trim((string)$value);

        if (!preg_match('/^[0-9]+$/', $value)) {
            $allNumbers = false;
        }

        if (!preg_match('/^[a-zA-Z\s]+$/', $value)) {
            $allLetters = false;
        }

        if (!preg_match('/^[a-zA-Z0-9\s]+$/', $value)) {
            $allAlphanumeric = false;
        }
    }

    if ($allNumbers) return "numbers";
    if ($allLetters) return "alphabets";
    if ($allAlphanumeric) return "alphanumeric";

    return "anything";
}

// Get selected sheet ID.
$sheet_id = (int)($_POST["sheet_id"] ?? 0);

if ($sheet_id <= 0) {
    exit("Invalid sheet selected.");
}

// Check whether the sheet exists.
$stmt = $pdo->prepare("SELECT id FROM sheets WHERE id = ?");
$stmt->execute([$sheet_id]);

if (!$stmt->fetch()) {
    exit("Sheet not found.");
}

// Check uploaded file.
if (
    !isset($_FILES["excel_file"]) ||
    $_FILES["excel_file"]["error"] !== UPLOAD_ERR_OK
) {
    exit("Please select a valid Excel file.");
}

$extension = strtolower(
    pathinfo($_FILES["excel_file"]["name"], PATHINFO_EXTENSION)
);

if (!in_array($extension, ["xlsx", "xls", "csv"], true)) {
    exit("Only XLSX, XLS and CSV files are supported.");
}

try {
    // Read Excel file.
    $spreadsheet = IOFactory::load(
        $_FILES["excel_file"]["tmp_name"]
    );

    $worksheet = $spreadsheet->getActiveSheet();

    $data = $worksheet->toArray(null, true, true, false);

    if (empty($data)) {
        exit("The Excel file is empty.");
    }

    // First row contains column headings.
    $headers = array_map(
        fn($value) => trim((string)$value),
        array_shift($data)
    );

    // Remove unused trailing columns.
    while (
        count($headers) > 0 &&
        end($headers) === ""
    ) {
        array_pop($headers);
    }

    if (
        empty($headers) ||
        count(array_filter($headers, fn($v) => $v !== "")) === 0
    ) {
        exit("The first row must contain column headings.");
    }

    // Give empty headings default names.
    foreach ($headers as $index => $header) {
        if ($header === "") {
            $headers[$index] = "Column " . ($index + 1);
        }
    }

    $columnCount = count($headers);

    // Make every row have the same number of columns.
    $data = array_map(
        fn($row) => array_slice(
            array_pad($row, $columnCount, null),
            0,
            $columnCount
        ),
        $data
    );

    $pdo->beginTransaction();

    // Delete only the selected sheet's old rows and columns.
    $stmt = $pdo->prepare(
        "DELETE FROM spreadsheet_rows WHERE sheet_id = ?"
    );
    $stmt->execute([$sheet_id]);

    $stmt = $pdo->prepare(
        "DELETE FROM spreadsheet_columns WHERE sheet_id = ?"
    );
    $stmt->execute([$sheet_id]);

    // Create new columns.
    $columnIds = [];

    foreach ($headers as $index => $header) {

        $values = [];

        foreach ($data as $row) {
            $values[] = (string)($row[$index] ?? "");
        }

        $rule = detectRule($values);

        $stmt = $pdo->prepare(
            "INSERT INTO spreadsheet_columns
            (sheet_id, column_name, position, validation_type)
            VALUES (?, ?, ?, ?)"
        );

        $stmt->execute([
            $sheet_id,
            $header,
            $index + 1,
            $rule
        ]);

        $columnIds[] = (int)$pdo->lastInsertId();
    }

    // Insert rows and their cell values.
    foreach ($data as $row) {

        $stmt = $pdo->prepare(
            "INSERT INTO spreadsheet_rows (sheet_id) VALUES (?)"
        );

        $stmt->execute([$sheet_id]);

        $rowId = (int)$pdo->lastInsertId();

        foreach ($columnIds as $index => $columnId) {

            $value = (string)($row[$index] ?? "");

            $stmt = $pdo->prepare(
                "INSERT INTO spreadsheet_cells
                (row_id, column_id, cell_value)
                VALUES (?, ?, ?)"
            );

            $stmt->execute([
                $rowId,
                $columnId,
                $value
            ]);
        }
    }

    // Update modification time.
    $stmt = $pdo->prepare(
        "UPDATE sheets SET updated_at = NOW() WHERE id = ?"
    );

    $stmt->execute([$sheet_id]);

    $pdo->commit();

    // Return to the imported sheet.
    header("Location: sheet.php?id=" . $sheet_id);
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    exit(
        "Import failed: " .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8")
    );
}
