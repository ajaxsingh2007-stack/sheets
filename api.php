
<?php
require_once "auth.php";
requireLogin();
require_once "db.php";

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || empty($data["action"]) || empty($data["sheet_id"])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid request."]);
    exit;
}

$sheet_id = (int)$data["sheet_id"];
$action = $data["action"];

try {
    $stmt = $pdo->prepare("SELECT id FROM sheets WHERE id = ?");
    $stmt->execute([$sheet_id]);

    if (!$stmt->fetch()) {
        throw new Exception("Sheet not found.");
    }

    if ($action === "add_row") {
        $stmt = $pdo->prepare(
            "INSERT INTO spreadsheet_rows (sheet_id) VALUES (?)"
        );
        $stmt->execute([$sheet_id]);
    }

    elseif ($action === "add_column") {
        $name = trim($data["column_name"] ?? "");
        $rule = $data["validation_type"] ?? "anything";

        $allowed = ["numbers", "alphabets", "alphanumeric", "anything"];

        if ($name === "" || !in_array($rule, $allowed, true)) {
            throw new Exception("Invalid column name or data type.");
        }

        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(position), 0) + 1
             FROM spreadsheet_columns WHERE sheet_id = ?"
        );
        $stmt->execute([$sheet_id]);
        $position = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "INSERT INTO spreadsheet_columns
             (sheet_id, column_name, position, validation_type)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$sheet_id, $name, $position, $rule]);
    }

    elseif ($action === "save_cell") {
        $row_id = (int)($data["row_id"] ?? 0);
        $column_id = (int)($data["column_id"] ?? 0);
        $value = (string)($data["value"] ?? "");

        $stmt = $pdo->prepare(
            "SELECT validation_type FROM spreadsheet_columns
             WHERE id = ? AND sheet_id = ?"
        );
        $stmt->execute([$column_id, $sheet_id]);
        $column = $stmt->fetch();

        $stmt = $pdo->prepare(
            "SELECT id FROM spreadsheet_rows
             WHERE id = ? AND sheet_id = ?"
        );
        $stmt->execute([$row_id, $sheet_id]);

        if (!$column || !$stmt->fetch()) {
            throw new Exception("Invalid row or column.");
        }

        $patterns = [
            "numbers" => '/^[0-9]*$/',
            "alphabets" => '/^[a-zA-Z\s]*$/',
            "alphanumeric" => '/^[a-zA-Z0-9\s]*$/',
            "anything" => '/^[\s\S]*$/'
        ];

        $rule = $column["validation_type"];

        if (!isset($patterns[$rule]) ||
            !preg_match($patterns[$rule], $value)) {
            http_response_code(422);
            echo json_encode([
                "error" => "This value does not match the column's data rule."
            ]);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO spreadsheet_cells (row_id, column_id, cell_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cell_value = VALUES(cell_value)"
        );
        $stmt->execute([$row_id, $column_id, $value]);
    }

    elseif ($action === "delete_row") {
        $id = (int)($data["id"] ?? 0);

        $stmt = $pdo->prepare(
            "DELETE FROM spreadsheet_rows WHERE id = ? AND sheet_id = ?"
        );
        $stmt->execute([$id, $sheet_id]);
    }

    elseif ($action === "delete_column") {
        $id = (int)($data["id"] ?? 0);

        $stmt = $pdo->prepare(
            "DELETE FROM spreadsheet_columns WHERE id = ? AND sheet_id = ?"
        );
        $stmt->execute([$id, $sheet_id]);
    }

    else {
        http_response_code(400);
        echo json_encode(["error" => "Unknown action."]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE sheets SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$sheet_id]);

    echo json_encode(["success" => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
