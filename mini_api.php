
<?php
require_once "auth.php";
requireLogin();
require_once "db.php";

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);

function respond($result, $status = 200) {
    http_response_code($status);
    echo json_encode($result);
    exit;
}

try {
    if (!$data || empty($data["action"]) || empty($data["sheet_id"])) {
        respond(["error" => "Invalid request."], 400);
    }

    $action = $data["action"];
    $sheetId = (int)$data["sheet_id"];
    $rowId = (int)($data["row_id"] ?? 0);
    $columnId = (int)($data["column_id"] ?? 0);

    // Confirm that the parent cell belongs to this sheet.
    if ($rowId <= 0 || $columnId <= 0) {
        respond(["error" => "Invalid parent cell."], 400);
    }

    $stmt = $pdo->prepare(
        "SELECT r.id
         FROM spreadsheet_rows r
         JOIN spreadsheet_columns c ON c.sheet_id = r.sheet_id
         WHERE r.id = ? AND c.id = ? AND r.sheet_id = ?"
    );
    $stmt->execute([$rowId, $columnId, $sheetId]);

    if (!$stmt->fetch()) {
        respond(["error" => "Parent cell not found."], 404);
    }

    // Find or create the mini-spreadsheet for this main cell.
    $stmt = $pdo->prepare(
        "SELECT id FROM mini_sheets
         WHERE parent_row_id = ? AND parent_column_id = ?"
    );
    $stmt->execute([$rowId, $columnId]);
    $mini = $stmt->fetch();

    if (!$mini) {
        $stmt = $pdo->prepare(
            "INSERT INTO mini_sheets (parent_row_id, parent_column_id)
             VALUES (?, ?)"
        );
        $stmt->execute([$rowId, $columnId]);
        $miniId = (int)$pdo->lastInsertId();
    } else {
        $miniId = (int)$mini["id"];
    }

    if ($action === "get_mini") {
        $stmt = $pdo->prepare(
            "SELECT * FROM mini_columns
             WHERE mini_sheet_id = ? ORDER BY position"
        );
        $stmt->execute([$miniId]);
        $columns = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT * FROM mini_rows
             WHERE mini_sheet_id = ? ORDER BY position, id"
        );
        $stmt->execute([$miniId]);
        $rows = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT mc.mini_row_id, mc.mini_column_id, mc.cell_value
             FROM mini_cells mc
             JOIN mini_rows mr ON mr.id = mc.mini_row_id
             WHERE mr.mini_sheet_id = ?"
        );
        $stmt->execute([$miniId]);
        $cells = $stmt->fetchAll();

        respond([
            "success" => true,
            "mini_id" => $miniId,
            "columns" => $columns,
            "rows" => $rows,
            "cells" => $cells
        ]);
    }

    if ($action === "add_mini_column") {
        $name = trim($data["column_name"] ?? "");

        if ($name === "") {
            respond(["error" => "Enter a column name."], 400);
        }

        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(position), 0) + 1
             FROM mini_columns WHERE mini_sheet_id = ?"
        );
        $stmt->execute([$miniId]);
        $position = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "INSERT INTO mini_columns
             (mini_sheet_id, column_name, position)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$miniId, $name, $position]);

        respond(["success" => true]);
    }

    if ($action === "add_mini_row") {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(position), 0) + 1
             FROM mini_rows WHERE mini_sheet_id = ?"
        );
        $stmt->execute([$miniId]);
        $position = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "INSERT INTO mini_rows (mini_sheet_id, position)
             VALUES (?, ?)"
        );
        $stmt->execute([$miniId, $position]);

        respond(["success" => true]);
    }

    if ($action === "save_mini_cell") {
        $miniRowId = (int)($data["mini_row_id"] ?? 0);
        $miniColumnId = (int)($data["mini_column_id"] ?? 0);
        $value = (string)($data["value"] ?? "");

        $stmt = $pdo->prepare(
            "SELECT mr.id
             FROM mini_rows mr
             JOIN mini_columns mc ON mc.mini_sheet_id = mr.mini_sheet_id
             WHERE mr.id = ? AND mc.id = ? AND mr.mini_sheet_id = ?"
        );
        $stmt->execute([$miniRowId, $miniColumnId, $miniId]);

        if (!$stmt->fetch()) {
            respond(["error" => "Invalid mini-spreadsheet cell."], 400);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO mini_cells
             (mini_row_id, mini_column_id, cell_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cell_value = VALUES(cell_value)"
        );
        $stmt->execute([$miniRowId, $miniColumnId, $value]);

        respond(["success" => true]);
    }

    if ($action === "delete_mini_row") {
        $id = (int)($data["id"] ?? 0);

        $stmt = $pdo->prepare(
            "DELETE FROM mini_rows WHERE id = ? AND mini_sheet_id = ?"
        );
        $stmt->execute([$id, $miniId]);

        respond(["success" => true]);
    }

    if ($action === "delete_mini_column") {
        $id = (int)($data["id"] ?? 0);

        $stmt = $pdo->prepare(
            "DELETE FROM mini_columns WHERE id = ? AND mini_sheet_id = ?"
        );
        $stmt->execute([$id, $miniId]);

        respond(["success" => true]);
    }

    respond(["error" => "Unknown action."], 400);

} catch (Throwable $e) {
    respond(["error" => $e->getMessage()], 500);
}
