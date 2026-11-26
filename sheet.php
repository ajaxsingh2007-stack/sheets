









<?php
require_once "auth.php";
requireLogin();
require_once "db.php";

$sheet_id = (int)($_GET["id"] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM sheets WHERE id = ?");
$stmt->execute([$sheet_id]);
$currentSheet = $stmt->fetch();

if (!$currentSheet) {
    http_response_code(404);
    exit("Spreadsheet not found.");
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($currentSheet["sheet_name"]) ?> - AjaxSheet</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <h1>AjaxSheet</h1>
    <div>
        <a href="dashboard.php">← Dashboard</a>
        <a href="logout.php">Logout</a>
    </div>
</header>

<main>
    <h2><?= htmlspecialchars($currentSheet["sheet_name"]) ?></h2>

    <div class="toolbar">
        <button onclick="addRow()">+ Add Row</button>
        <button onclick="addColumn()">+ Add Column</button>

        <button onclick="document.getElementById('excelFile').click()">
            Import Excel
        </button>

        <a href="export.php?id=<?= $sheet_id ?>">
            <button type="button">Export Excel</button>
        </a>

        <form id="importForm" action="import.php" method="POST"
              enctype="multipart/form-data" style="display:none">
            <input type="hidden" name="sheet_id" value="<?= $sheet_id ?>">
            <input type="file" id="excelFile" name="excel_file"
                   accept=".xlsx,.xls,.csv"
                   onchange="confirmImport()">
        </form>
    </div>

    <table id="spreadsheet">
        <thead>
            <tr>
                <th>ID</th>

                <?php foreach ($columns as $index => $column): ?>
    <th class="filter-header">
        <div class="filter-header-content">
            <span><?= htmlspecialchars($column["column_name"]) ?></span>

            <div class="header-actions">
                <button
                    type="button"
                    class="filter-btn"
                    onclick="openFilter(event, <?= $index + 1 ?>)"
                    title="Filter this column">
                    ▼
                </button>

                <button
                    type="button"
                    class="delete-column-btn"
                    onclick="deleteColumn(<?= (int)$column['id'] ?>)">
                    ×
                </button>
            </div>
        </div>

        <small><?= htmlspecialchars($column["validation_type"]) ?></small>
    </th>
<?php endforeach; ?>

                <th>Action</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= (int)$row["id"] ?></td>

                    <?php foreach ($columns as $column): ?>
                      <td contenteditable="true"
    ondblclick="openMiniSpreadsheet(event, <?= (int)$row['id'] ?>, <?= (int)$column['id'] ?>)"
    data-rule="<?= htmlspecialchars($column['validation_type']) ?>"
    onblur="saveCell(
        <?= (int)$row['id'] ?>,
        <?= (int)$column['id'] ?>,
        this.innerText,
        this.dataset.rule
    )"><?= htmlspecialchars(
        $cellData[$row["id"]][$column["id"]] ?? ""
    ) ?></td>
                    <?php endforeach; ?>

                    <td>
                        <button onclick="deleteRow(<?= (int)$row["id"] ?>)">
                            Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</main>
<div id="miniModal" class="mini-modal">
    <div class="mini-box">
        <div class="mini-header">
            <div>
                <h2>Cell Details</h2>
                <p id="miniCellInfo">Additional information</p>
            </div>
            <button class="mini-close" onclick="closeMiniSpreadsheet()">×</button>
        </div>

        <div class="mini-toolbar">
            <button onclick="addMiniRow()">+ Add Row</button>
            <button onclick="addMiniColumn()">+ Add Column</button>
        </div>

        <div class="mini-table-container">
            <table id="miniTable"></table>
        </div>

        <div class="mini-footer">
            <span id="miniSaveStatus">Changes save automatically</span>
            <button onclick="closeMiniSpreadsheet()">Done</button>
        </div>
    </div>
</div>

<script>
    console.log("AJAXSHEET JAVASCRIPT LOADED"); 

    
const SHEET_ID = <?= $sheet_id ?>;

function sendData(data) {
    data.sheet_id = SHEET_ID;

    return fetch("api.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(data)
    }).then(async response => {
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Request failed");
        return result;
    });
}

function isValid(value, rule) {
    const patterns = {
        numbers: /^[0-9]*$/,
        alphabets: /^[a-zA-Z\s]*$/,
        alphanumeric: /^[a-zA-Z0-9\s]*$/,
        anything: /^[\s\S]*$/
    };
    return patterns[rule] ? patterns[rule].test(value) : true;
}

function saveCell(row_id, column_id, value, rule) {
    if (!isValid(value, rule)) {
        alert("Invalid input for this column.");
        location.reload();
        return;
    }

    sendData({
        action: "save_cell",
        row_id,
        column_id,
        value
    }).catch(error => {
        alert(error.message);
        location.reload();
    });
}

function addRow() {
    sendData({action: "add_row"})
        .then(() => location.reload())
        .catch(error => alert(error.message));
}

function addColumn() {
    const name = prompt("Enter column name:");
    if (!name || !name.trim()) return;

    const choice = prompt(
        "Choose column data type:\n\n" +
        "1. Numbers only\n" +
        "2. Alphabets only\n" +
        "3. Alphabets and numbers\n" +
        "4. Anything including special characters"
    );

    const rules = {
        "1": "numbers",
        "2": "alphabets",
        "3": "alphanumeric",
        "4": "anything"
    };

    if (!rules[choice]) {
        alert("Choose 1, 2, 3 or 4.");
        return;
    }

    sendData({
        action: "add_column",
        column_name: name.trim(),
        validation_type: rules[choice]
    }).then(() => location.reload())
      .catch(error => alert(error.message));
}

function deleteRow(id) {
    if (!confirm("Delete this row?")) return;

    sendData({action: "delete_row", id})
        .then(() => location.reload())
        .catch(error => alert(error.message));
}

function deleteColumn(id) {
    if (!confirm("Delete this column?")) return;

    sendData({action: "delete_column", id})
        .then(() => location.reload())
        .catch(error => alert(error.message));
}

function confirmImport() {
    const file = document.getElementById("excelFile").files[0];
    if (!file) return;

    if (confirm(
        "Importing this file will replace ALL rows and columns in this sheet. Continue?"
    )) {
        document.getElementById("importForm").submit();
    } else {
        document.getElementById("excelFile").value = "";
    }
}
let activeMiniCell = null;
let miniColumns = [];
let miniRows = [];
let miniCells = {};

function miniRequest(action, extra = {}) {
    return fetch("mini_api.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
            action,
            sheet_id: SHEET_ID,
            row_id: activeMiniCell.rowId,
            column_id: activeMiniCell.columnId,
            ...extra
        })
    }).then(async response => {
        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.error || "Request failed");
        }

        return result;
    });
}

async function openMiniSpreadsheet(event, rowId, columnId) {
    activeMiniCell = {rowId, columnId};

    const modal = document.getElementById("miniModal");
    const box = modal.querySelector(".mini-box");

    // Position the panel beside the clicked cell.
    const rect = event.currentTarget.getBoundingClientRect();

    modal.style.display = "block";
    box.style.visibility = "hidden";

    document.getElementById("miniCellInfo").textContent =
        "Additional information for row " + rowId;

    document.getElementById("miniTable").innerHTML =
        "<tr><td>Loading...</td></tr>";

    try {
        const result = await miniRequest("get_mini");

        miniColumns = result.columns;
        miniRows = result.rows;
        miniCells = {};

        result.cells.forEach(cell => {
            miniCells[cell.mini_row_id + "_" + cell.mini_column_id] =
                cell.cell_value;
        });

        renderMiniTable();

        // Calculate position after the panel has its dimensions.
        const boxWidth = box.offsetWidth;
        const boxHeight = box.offsetHeight;

        let left = rect.right + 12;
        let top = rect.top;

        if (left + boxWidth > window.innerWidth - 12) {
            left = rect.left - boxWidth - 12;
        }

        if (left < 12) {
            left = Math.max(12, window.innerWidth - boxWidth - 12);
        }

        if (top + boxHeight > window.innerHeight - 12) {
            top = window.innerHeight - boxHeight - 12;
        }

        top = Math.max(12, top);

        box.style.left = left + "px";
        box.style.top = top + "px";
        box.style.visibility = "visible";

    } catch (error) {
        alert(error.message);
        closeMiniSpreadsheet();
    }
}

function renderMiniTable() {
    const table = document.getElementById("miniTable");

    let html = "<thead><tr><th>Row</th>";

    miniColumns.forEach(column => {
        html += `
            <th>
                ${escapeMiniText(column.column_name)}
                <button onclick="deleteMiniColumn(${column.id})">×</button>
            </th>`;
    });

    html += "<th>Action</th></tr></thead><tbody>";

    miniRows.forEach((row, index) => {
        html += `<tr><td>${index + 1}</td>`;

        miniColumns.forEach(column => {
            const key = row.id + "_" + column.id;
            const value = miniCells[key] ?? "";

            html += `
                <td contenteditable="true"
                    onblur="saveMiniCell(${row.id}, ${column.id}, this)">${escapeMiniText(value)}</td>`;
        });

        html += `
            <td>
                <button onclick="deleteMiniRow(${row.id})">Delete</button>
            </td>
        </tr>`;
    });

    html += "</tbody>";
    table.innerHTML = html;
}

function escapeMiniText(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    })[char]);
}

async function saveMiniCell(rowId, columnId, element) {
    document.getElementById("miniSaveStatus").textContent = "Saving...";

    try {
        await miniRequest("save_mini_cell", {
            mini_row_id: rowId,
            mini_column_id: columnId,
            value: element.innerText
        });

        document.getElementById("miniSaveStatus").textContent =
            "All changes saved";
    } catch (error) {
        document.getElementById("miniSaveStatus").textContent =
            "Save failed";
        alert(error.message);
    }
}

async function addMiniRow() {
    try {
        await miniRequest("add_mini_row");
        await refreshMiniSpreadsheet();
    } catch (error) {
        alert(error.message);
    }
}

async function addMiniColumn() {
    const name = prompt("Enter column name:");
    if (!name || !name.trim()) return;

    try {
        await miniRequest("add_mini_column", {
            column_name: name.trim()
        });
        await refreshMiniSpreadsheet();
    } catch (error) {
        alert(error.message);
    }
}

async function deleteMiniRow(id) {
    if (!confirm("Delete this row?")) return;

    try {
        await miniRequest("delete_mini_row", {id});
        await refreshMiniSpreadsheet();
    } catch (error) {
        alert(error.message);
    }
}

async function deleteMiniColumn(id) {
    if (!confirm("Delete this column?")) return;

    try {
        await miniRequest("delete_mini_column", {id});
        await refreshMiniSpreadsheet();
    } catch (error) {
        alert(error.message);
    }
}

async function refreshMiniSpreadsheet() {
    if (!activeMiniCell) return;

    const rowId = activeMiniCell.rowId;
    const columnId = activeMiniCell.columnId;

    const result = await miniRequest("get_mini");

    miniColumns = result.columns;
    miniRows = result.rows;
    miniCells = {};

    result.cells.forEach(cell => {
        miniCells[cell.mini_row_id + "_" + cell.mini_column_id] =
            cell.cell_value;
    });

    renderMiniTable();
}

function closeMiniSpreadsheet() {
    document.getElementById("miniModal").style.display = "none";
    activeMiniCell = null;
}

document.addEventListener("keydown", function(event) {
    if (event.key === "Escape") {
        closeMiniSpreadsheet();
    }
});

// ========================================
// EXCEL-STYLE COLUMN FILTER
// ========================================

let activeFilters = {};
let currentFilterColumn = null;

// Create filter popup
const filterPopup = document.createElement("div");
filterPopup.id = "excelFilterPopup";
filterPopup.className = "excel-filter-popup";
filterPopup.style.display = "none";
document.body.appendChild(filterPopup);

function openFilter(event, columnIndex) {
    event.stopPropagation();

    currentFilterColumn = columnIndex;

    const rows = Array.from(
        document.querySelectorAll("#spreadsheet tbody tr")
    );

    const values = [...new Set(
        rows.map(row => row.cells[columnIndex].innerText.trim())
    )].sort((a, b) => a.localeCompare(b));

    const selected = activeFilters[columnIndex]
        ? activeFilters[columnIndex]
        : new Set(values);

    filterPopup.innerHTML = `
        <div class="filter-popup-header">
            <strong>Filter</strong>
            <button type="button" id="closeFilter">×</button>
        </div>

        <input
            type="text"
            id="filterSearch"
            placeholder="Search values..."
        >

        <div class="filter-sort">
            <button type="button" id="sortAsc">↑ Sort A to Z</button>
            <button type="button" id="sortDesc">↓ Sort Z to A</button>
        </div>

        <div class="filter-select-all">
            <label>
                <input type="checkbox" id="selectAll">
                Select All
            </label>
        </div>

        <div class="filter-values" id="filterValues"></div>

        <div class="filter-footer">
            <button type="button" id="clearFilter">Clear</button>
            <button type="button" id="applyFilter">Apply</button>
        </div>
    `;

    const valuesContainer = filterPopup.querySelector("#filterValues");

    function renderValues(search = "") {
        valuesContainer.innerHTML = "";

        const filteredValues = values.filter(value =>
            value.toLowerCase().includes(search.toLowerCase())
        );

        filteredValues.forEach(value => {
            const label = document.createElement("label");
            label.className = "filter-value";

            const checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.value = value;
            checkbox.checked = selected.has(value);

            checkbox.addEventListener("change", () => {
                if (checkbox.checked) {
                    selected.add(value);
                } else {
                    selected.delete(value);
                }
                updateSelectAll();
            });

            const text = document.createElement("span");
            text.textContent = value || "(Blank)";

            label.append(checkbox, text);
            valuesContainer.appendChild(label);
        });

        updateSelectAll();
    }

    function updateSelectAll() {
        const visibleValues = values.filter(value =>
            value.toLowerCase().includes(
                filterPopup.querySelector("#filterSearch").value.toLowerCase()
            )
        );

        const selectAll = filterPopup.querySelector("#selectAll");

        selectAll.checked = visibleValues.length > 0 &&
            visibleValues.every(value => selected.has(value));

        selectAll.indeterminate =
            visibleValues.some(value => selected.has(value)) &&
            !selectAll.checked;
    }

    filterPopup.querySelector("#filterSearch")
        .addEventListener("input", event => {
            renderValues(event.target.value);
        });

    filterPopup.querySelector("#selectAll")
        .addEventListener("change", event => {
            const search = filterPopup.querySelector("#filterSearch").value;

            values.filter(value =>
                value.toLowerCase().includes(search.toLowerCase())
            ).forEach(value => {
                if (event.target.checked) {
                    selected.add(value);
                } else {
                    selected.delete(value);
                }
            });

            renderValues(search);
        });

    filterPopup.querySelector("#applyFilter")
        .addEventListener("click", () => {
            activeFilters[columnIndex] = new Set(selected);

            if (selected.size === values.length) {
                delete activeFilters[columnIndex];
            }

            applyAllFilters();
            filterPopup.style.display = "none";
        });

    filterPopup.querySelector("#clearFilter")
        .addEventListener("click", () => {
            delete activeFilters[columnIndex];
            applyAllFilters();
            filterPopup.style.display = "none";
        });

    filterPopup.querySelector("#sortAsc")
        .addEventListener("click", () => {
            sortColumn(columnIndex, "asc");
            filterPopup.style.display = "none";
        });

    filterPopup.querySelector("#sortDesc")
        .addEventListener("click", () => {
            sortColumn(columnIndex, "desc");
            filterPopup.style.display = "none";
        });

    filterPopup.querySelector("#closeFilter")
        .addEventListener("click", () => {
            filterPopup.style.display = "none";
        });

    renderValues();

    const rect = event.currentTarget.getBoundingClientRect();

    filterPopup.style.display = "block";
    filterPopup.style.left = Math.min(
        rect.left,
        window.innerWidth - 280
    ) + "px";
    filterPopup.style.top = Math.min(
        rect.bottom + 5,
        window.innerHeight - 400
    ) + "px";
}

// Apply all active filters together
function applyAllFilters() {
    const rows = document.querySelectorAll(
        "#spreadsheet tbody tr"
    );

    rows.forEach(row => {
        let visible = true;

        for (const [columnIndex, allowedValues] of Object.entries(activeFilters)) {
            const cellValue = row.cells[Number(columnIndex)]
                .innerText.trim();

            if (!allowedValues.has(cellValue)) {
                visible = false;
                break;
            }
        }

        row.style.display = visible ? "" : "none";
    });
}

// Sort rows without changing database data
function sortColumn(columnIndex, direction) {
    const tbody = document.querySelector("#spreadsheet tbody");

    const rows = Array.from(tbody.querySelectorAll("tr"));

    rows.sort((a, b) => {
        const valueA = a.cells[columnIndex].innerText.trim();
        const valueB = b.cells[columnIndex].innerText.trim();

        const numberA = Number(valueA);
        const numberB = Number(valueB);

        let comparison;

        if (valueA !== "" && valueB !== "" &&
            !isNaN(numberA) && !isNaN(numberB)) {
            comparison = numberA - numberB;
        } else {
            comparison = valueA.localeCompare(
                valueB,
                undefined,
                { numeric: true, sensitivity: "base" }
            );
        }

        return direction === "asc" ? comparison : -comparison;
    });

    rows.forEach(row => tbody.appendChild(row));

    applyAllFilters();
}

// Close popup when clicking outside
document.addEventListener("click", event => {
    if (
        filterPopup.style.display === "block" &&
        !filterPopup.contains(event.target) &&
        !event.target.closest(".filter-btn")
    ) {
        filterPopup.style.display = "none";
    }
});

</script>


















