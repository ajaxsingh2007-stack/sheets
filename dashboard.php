
<?php
require_once "auth.php";
requireLogin();
require_once "db.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "create") {
        $name = trim($_POST["sheet_name"] ?? "New Sheet");

        if ($name !== "") {
            $stmt = $pdo->prepare(
                "INSERT INTO sheets (sheet_name) VALUES (?)"
            );
            $stmt->execute([$name]);
            header("Location: sheet.php?id=" . $pdo->lastInsertId());
            exit;
        }
    }

    if ($action === "rename") {
        $id = (int)($_POST["id"] ?? 0);
        $name = trim($_POST["sheet_name"] ?? "");

        if ($id > 0 && $name !== "") {
            $stmt = $pdo->prepare(
                "UPDATE sheets SET sheet_name = ? WHERE id = ?"
            );
            $stmt->execute([$name, $id]);
        }

        header("Location: dashboard.php");
        exit;
    }

    if ($action === "delete") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM sheets WHERE id = ?");
            $stmt->execute([$id]);
        }

        header("Location: dashboard.php");
        exit;
    }
}

$sheets = $pdo->query(
    "SELECT s.*,
        (SELECT COUNT(*) FROM spreadsheet_rows r
         WHERE r.sheet_id = s.id) AS row_count,
        (SELECT COUNT(*) FROM spreadsheet_columns c
         WHERE c.sheet_id = s.id) AS column_count
     FROM sheets s
     ORDER BY s.updated_at DESC"
)->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AjaxSheet Dashboard</title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>

<header class="topbar">
    <h1>AjaxSheet</h1>
    <a class="logout" href="logout.php">Logout</a>
</header>

<main class="dashboard">
    <section class="welcome">
        <div>
            <p class="eyebrow">YOUR WORKSPACE</p>
            <h2>My Sheets</h2>
            <p>All your spreadsheets in one place.</p>
        </div>

        <form method="POST" class="create-form">
            <input type="hidden" name="action" value="create">
            <input
                type="text"
                name="sheet_name"
                placeholder="New spreadsheet name"
                required
            >
            <button type="submit">+ Create Sheet</button>
        </form>
    </section>

    <section class="sheet-grid">
        <?php foreach ($sheets as $sheet): ?>
            <article class="sheet-card">
                <div class="sheet-icon">▦</div>

                <h3>
                    <?= htmlspecialchars($sheet["sheet_name"]) ?>
                </h3>

                <p>
                    <?= (int)$sheet["row_count"] ?> rows ·
                    <?= (int)$sheet["column_count"] ?> columns
                </p>

                <a class="open-button"
                   href="sheet.php?id=<?= (int)$sheet["id"] ?>">
                    Open Sheet →
                </a>

                <details>
                    <summary>Sheet options</summary>

                    <form method="POST">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="id"
                               value="<?= (int)$sheet["id"] ?>">
                        <input type="text" name="sheet_name"
                               value="<?= htmlspecialchars($sheet["sheet_name"]) ?>"
                               required>
                        <button type="submit">Rename</button>
                    </form>

                    <form method="POST"
                          onsubmit="return confirm('Delete this sheet and all its data?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id"
                               value="<?= (int)$sheet["id"] ?>">
                        <button class="delete-button" type="submit">
                            Delete Sheet
                        </button>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>

        <?php if (!$sheets): ?>
            <p>No sheets yet. Create your first spreadsheet above.</p>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
