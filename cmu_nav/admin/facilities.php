
<?php
session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

require_once "../config/database.php";

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function getFloorOptions($totalFloors) {
    $floors = [];
    $totalFloors = max(0, min(100, (int)$totalFloors));

    for ($i = 1; $i <= $totalFloors; $i++) {
        if ($i % 100 >= 11 && $i % 100 <= 13) {
            $suffix = "th";
        } else {
            switch ($i % 10) {
                case 1: $suffix = "st"; break;
                case 2: $suffix = "nd"; break;
                case 3: $suffix = "rd"; break;
                default: $suffix = "th";
            }
        }

        $floors[$i] = $i . $suffix . " Floor";
    }

    return $floors;
}

function floorSortValue($floorName) {
    if (preg_match('/(\d+)/', (string)$floorName, $matches)) {
        return (int)$matches[1];
    }

    if (stripos((string)$floorName, "ground") !== false) {
        return 0;
    }

    return 9999;
}

function formatFloorLabel($floor) {
    $floor = trim((string)$floor);

    if ($floor === "") {
        return "Floor Not Set";
    }

    if (ctype_digit($floor)) {
        $number = (int)$floor;

        if ($number % 100 >= 11 && $number % 100 <= 13) {
            $suffix = "th";
        } else {
            switch ($number % 10) {
                case 1: $suffix = "st"; break;
                case 2: $suffix = "nd"; break;
                case 3: $suffix = "rd"; break;
                default: $suffix = "th";
            }
        }

        return $number . $suffix . " Floor";
    }

    return $floor;
}

$facilityNameOptions = [
    "Library",
    "Clinic",
    "Canteen",
    "Comfort Room (CR)",
    "Guardhouse"
];

$adminName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Admin";

/* Load buildings and available floors */
$allBuildings = [];

$bResult = $conn->query(
    "SELECT building_id, building_name, total_floors
     FROM buildings
     ORDER BY building_name"
);

while ($b = $bResult->fetch(PDO::FETCH_ASSOC)) {
    $allBuildings[(int)$b["building_id"]] = [
        "id" => (int)$b["building_id"],
        "name" => $b["building_name"],
        "floors" => getFloorOptions($b["total_floors"])
    ];
}

/* ---------- Delete a facility ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"])) {
    $deleteId = (int)$_POST["delete_id"];

    if ($deleteId > 0) {
        try {
            $stmt = $conn->prepare(
                "DELETE FROM facilities
                 WHERE facility_id = :facility_id"
            );

            $stmt->execute([
                "facility_id" => $deleteId
            ]);

            $_SESSION["facility_message"] = $stmt->rowCount() > 0
                ? "Facility deleted successfully."
                : "Facility not found or already deleted.";

        } catch (Throwable $ex) {
            error_log("Facility delete error: " . $ex->getMessage());
            $_SESSION["facility_error"] = "Unable to delete facility.";
        }
    }

    header("Location: facilities.php");
    exit;
}

/* ---------- Save edited facility ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_facility"])) {
    $facilityId = (int)($_POST["facility_id"] ?? 0);
    $buildingId = (int)($_POST["building_id"] ?? 0);

    $floorInput = filter_var(
        $_POST["floor"] ?? null,
        FILTER_VALIDATE_INT
    );

    $nameChoice = trim((string)($_POST["facility_name_choice"] ?? ""));
    $nameOther = trim((string)($_POST["facility_name_other"] ?? ""));

    $facilityName = ($nameChoice === "Others") ? $nameOther : $nameChoice;
    $error = "";

    if ($facilityId <= 0) {
        $error = "Invalid facility.";
    } elseif (!isset($allBuildings[$buildingId])) {
        $error = "Please select a valid building.";
    } elseif (
        $floorInput === false ||
        $floorInput === null ||
        !array_key_exists($floorInput, $allBuildings[$buildingId]["floors"])
    ) {
        $error = "Please select a valid floor.";
    } elseif ($nameChoice === "") {
        $error = "Please select a Facility Name.";
    } elseif (
        $nameChoice !== "Others" &&
        !in_array($nameChoice, $facilityNameOptions, true)
    ) {
        $error = "Please select a valid Facility Name.";
    } elseif ($nameChoice === "Others" && $nameOther === "") {
        $error = "Please type the custom Facility Name.";
    } elseif (mb_strlen($facilityName) > 150) {
        $error = "Facility Name must not exceed 150 characters.";
    }

    if ($error === "") {
        try {
            $stmt = $conn->prepare(
                "UPDATE facilities
                 SET building_id = :building_id,
                     facility_name = :facility_name,
                     floor = :floor
                 WHERE facility_id = :facility_id"
            );

            $stmt->bindValue(":building_id", $buildingId, PDO::PARAM_INT);
            $stmt->bindValue(":facility_name", $facilityName, PDO::PARAM_STR);
            $stmt->bindValue(":floor", (int)$floorInput, PDO::PARAM_INT);
            $stmt->bindValue(":facility_id", $facilityId, PDO::PARAM_INT);

            $stmt->execute();

            $_SESSION["facility_message"] = "Saved changes successfully!";

        } catch (Throwable $ex) {
            error_log("Facility update error: " . $ex->getMessage());
            $_SESSION["facility_error"] =
                "Unable to save changes. Please check your facilities table columns.";
        }
    } else {
        $_SESSION["facility_error"] = $error;
    }

    header("Location: facilities.php");
    exit;
}

/* Flash messages */
$message = $_SESSION["facility_message"] ?? "";
unset($_SESSION["facility_message"]);

$errorMessage = $_SESSION["facility_error"] ?? "";
unset($_SESSION["facility_error"]);

/* Load facilities from the facilities table */
try {
    $result = $conn->query(
        "SELECT f.facility_id, f.building_id, f.facility_name, f.floor,
                b.building_name
         FROM facilities f
         LEFT JOIN buildings b ON f.building_id = b.building_id
         ORDER BY b.building_name ASC,
                  f.floor ASC,
                  f.facility_name ASC,
                  f.facility_id ASC"
    );

    $facilityRows = $result->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    error_log("Facilities loading error: " . $ex->getMessage());
    die("Unable to load facilities. Please check your database table and column names.");
}

/* Group facilities by building and floor */
$groups = [];
$totalFacilities = 0;

foreach ($facilityRows as $facility) {
    $groupId = (int)$facility["building_id"];
    $floorName = trim((string)($facility["floor"] ?? ""));

    if ($floorName === "") {
        $floorName = "Floor Not Set";
    }

    if (!isset($groups[$groupId])) {
        $groups[$groupId] = [
            "building_name" => $facility["building_name"] ?? "Unknown Building",
            "floors" => []
        ];
    }

    if (!isset($groups[$groupId]["floors"][$floorName])) {
        $groups[$groupId]["floors"][$floorName] = [];
    }

    $groups[$groupId]["floors"][$floorName][] = $facility;
    $totalFacilities++;
}

foreach ($groups as &$group) {
    uksort($group["floors"], function ($a, $b) {
        $valueA = floorSortValue($a);
        $valueB = floorSortValue($b);

        if ($valueA === $valueB) {
            return strnatcasecmp($a, $b);
        }

        return $valueA <=> $valueB;
    });
}

unset($group);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Facilities | CMU Campus Navigator</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f3f6fb;
    color: #172b4d;
    font-size: 16px;
}

a {
    -webkit-tap-highlight-color: transparent;
}

.navbar {
    min-height: 86px;
    padding: 15px 4%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: #ffffff;
    border-bottom: 1px solid #dce4ef;
    box-shadow: 0 2px 8px rgba(20, 45, 80, 0.06);
}

.logo {
    display: flex;
    align-items: center;
    gap: 14px;
}

.logo-icon img {
    width: 56px;
    height: 56px;
    object-fit: contain;
}

.logo h2 {
    margin-bottom: 5px;
    color: #0b3b82;
    font-size: 21px;
    font-weight: 800;
}

.logo span {
    color: #4d5f78;
    font-size: 14px;
    font-weight: 600;
}

.navbar nav {
    display: flex;
    align-items: center;
    gap: 12px;
}

.navbar nav a {
    padding: 12px 18px;
    border-radius: 7px;
    text-decoration: none;
    font-size: 15px;
    font-weight: 700;
}

.view-btn {
    color: #0b3b82;
    background: #edf3fc;
}

.view-btn:hover {
    background: #dce8fa;
}

.logout-btn {
    color: #ffffff;
    background: #b42318;
}

.logout-btn:hover {
    background: #8f1d14;
}

.admin-layout {
    display: flex;
    min-height: calc(100vh - 150px);
}

.sidebar {
    width: 255px;
    flex-shrink: 0;
    background: #0b3b82;
    padding: 30px 18px;
    color: #ffffff;
}

.sidebar h3 {
    margin: 0 12px 22px;
    color: #ffdf45;
    font-size: 14px;
    font-weight: 800;
    letter-spacing: 1.2px;
}

.sidebar a {
    display: block;
    margin-bottom: 8px;
    padding: 15px 14px;
    border-radius: 7px;
    color: #ffffff;
    text-decoration: none;
    font-size: 16px;
    font-weight: 600;
    transition: background 0.2s;
}

.sidebar a:hover,
.sidebar a.active {
    color: #ffffff;
    background: #24569b;
    outline: 1px solid rgba(255, 255, 255, 0.16);
}

.dashboard-content {
    flex: 1;
    min-width: 0;
    padding: 38px;
}

.welcome {
    margin-bottom: 28px;
}

.welcome .eyebrow {
    color: #805900;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 1.4px;
}

.welcome h1 {
    margin: 10px 0;
    color: #0b3b82;
    font-size: 34px;
    line-height: 1.3;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.welcome p {
    color: #43536b;
    font-size: 16px;
    line-height: 1.7;
}

.building-panel {
    padding: 28px;
    background: #ffffff;
    border: 1px solid #d8e1ee;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(11, 59, 130, 0.05);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 24px;
}

.panel-header h2 {
    color: #0b3b82;
    font-size: 23px;
    font-weight: 800;
}

.add-btn {
    display: inline-block;
    padding: 12px 18px;
    border: none;
    border-radius: 7px;
    background: #0b3b82;
    color: #ffffff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
}

.add-btn:hover {
    background: #082d63;
}

.message {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 7px;
    background: #edf8ef;
    border: 1px solid #b9e2c0;
    color: #216e39;
    line-height: 1.5;
}

.message.error {
    background: #fff0ee;
    border-color: #f2c3bd;
    color: #a1261c;
}

.building-section {
    margin-bottom: 28px;
    border: 1px solid #d8e1ee;
    border-radius: 10px;
    overflow: hidden;
    background: #ffffff;
}

.building-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 18px 20px;
    background: #0b3b82;
    color: #ffffff;
}

.building-title {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.building-icon {
    font-size: 25px;
}

.building-header h3 {
    font-size: 20px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.building-count {
    padding: 6px 10px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.floor-section {
    padding: 20px;
    border-bottom: 1px solid #e5eaf2;
}

.floor-section:last-child {
    border-bottom: none;
}

.floor-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
    padding-bottom: 12px;
    border-bottom: 2px solid #d9a900;
}

.floor-header h4 {
    color: #0b3b82;
    font-size: 17px;
    font-weight: 800;
}

.floor-header span {
    margin-left: auto;
    color: #68758a;
    font-size: 12px;
    font-weight: 600;
}

.floor-header span:first-child {
    margin-left: 0;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 450px;
}

thead {
    background: #edf3fc;
}

th {
    padding: 13px 12px;
    color: #0b3b82;
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}

td {
    padding: 14px 12px;
    border-bottom: 1px solid #e5eaf2;
    color: #43536b;
    font-size: 14px;
    line-height: 1.6;
    vertical-align: middle;
}

tbody tr:last-child td {
    border-bottom: none;
}

tbody tr:hover {
    background: #f8faff;
}

.item-name {
    color: #0b3b82;
    font-weight: 700;
}

.actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.actions form {
    margin: 0;
}

.edit-btn,
.delete-btn {
    display: inline-block;
    padding: 8px 11px;
    border-radius: 5px;
    text-decoration: none;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.edit-btn {
    border: none;
    background: #edf3fc;
    color: #0b3b82;
    cursor: pointer;
}

.edit-btn:hover {
    background: #dce8fa;
}

.delete-btn {
    border: none;
    background: #fff0ee;
    color: #b42318;
    cursor: pointer;
}

.delete-btn:hover {
    background: #ffe0dc;
}

.empty-state {
    padding: 45px 20px;
    text-align: center;
}

.empty-state .icon {
    margin-bottom: 14px;
    font-size: 42px;
}

.empty-state h3 {
    margin-bottom: 10px;
    color: #0b3b82;
    font-size: 21px;
}

.empty-state p {
    margin-bottom: 22px;
    color: #68758a;
    line-height: 1.7;
}

footer {
    padding: 20px;
    text-align: center;
    background: #ffffff;
    border-top: 1px solid #dce4ef;
}

footer p {
    color: #43536b;
    font-size: 14px;
    line-height: 1.6;
}

/* EDIT MODAL */
.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(23, 43, 77, 0.55);
    overflow-y: auto;
}

.modal-backdrop.open {
    display: flex;
}

.modal-box {
    width: 100%;
    max-width: 560px;
    max-height: 100%;
    overflow-y: auto;
    padding: 28px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 20px 50px rgba(11, 59, 130, 0.3);
}

.modal-box h2 {
    margin-bottom: 6px;
    color: #0b3b82;
    font-size: 22px;
    font-weight: 800;
}

.modal-sub {
    margin-bottom: 20px;
    color: #52627a;
    font-size: 14px;
    line-height: 1.6;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.field label {
    display: block;
    margin-bottom: 7px;
    color: #43536b;
    font-size: 14px;
    font-weight: 700;
}

.field input,
.field select {
    width: 100%;
    min-height: 42px;
    padding: 10px;
    border: 1px solid #d8e1ee;
    border-radius: 7px;
    background: #fff;
    color: #172b4d;
    font: inherit;
    font-size: 14px;
}

.field.full {
    grid-column: 1 / -1;
}

.field.hidden {
    display: none;
}

.modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 22px;
}

.btn-save,
.btn-cancel {
    padding: 12px 18px;
    border: 0;
    border-radius: 7px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}

.btn-save {
    background: #0b3b82;
    color: #fff;
}

.btn-save:hover {
    background: #082d63;
}

.btn-cancel {
    background: #edf3fc;
    color: #0b3b82;
}

@media (max-width: 900px) {
    .admin-layout {
        flex-direction: column;
    }

    .sidebar {
        width: 100%;
        padding: 20px 16px;
    }

    .sidebar h3 {
        margin-bottom: 14px;
    }

    .dashboard-content {
        padding: 28px;
    }
}

@media (max-width: 700px) {
    .navbar {
        flex-direction: column;
        align-items: stretch;
        padding: 18px;
    }

    .navbar nav {
        flex-wrap: wrap;
    }

    .navbar nav a {
        flex: 1;
        text-align: center;
    }

    .dashboard-content {
        padding: 22px 16px;
    }

    .welcome h1 {
        font-size: 28px;
    }

    .building-panel {
        padding: 20px 14px;
    }

    .panel-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .panel-header h2 {
        font-size: 21px;
    }

    .building-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .floor-section {
        padding: 15px 10px;
    }

    .floor-header h4 {
        font-size: 15px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 380px) {
    .logo h2 {
        font-size: 18px;
    }

    .logo span {
        font-size: 12px;
    }
}
</style>
<link rel="stylesheet" href="../mobile.css">
</head>

<body>

<header class="navbar">
    <div class="logo">
        <div class="logo-icon">
            <img src="../cmu logo.jpg" alt="CMU Logo">
        </div>
        <div>
            <h2>CMU Campus Navigator</h2>
            <span>Administrator Dashboard</span>
        </div>
    </div>

    <nav>
        <a href="../index.php" class="view-btn">View Website</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>
</header>

<div class="admin-layout">

    <aside class="sidebar">
        <h3>ADMIN MENU</h3>
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="buildings.php">🏢 Buildings</a>
        <a href="rooms.php">🚪 Rooms</a>
        <a href="offices.php">🏛️ Offices</a>
        <a href="facilities.php" class="active">🗺️ Facilities</a>
    </aside>

    <main class="dashboard-content">

        <section class="welcome">
            <span class="eyebrow">ADMINISTRATION</span>
            <h1>Manage Facilities</h1>
            <p>Welcome, <?= h($adminName) ?>! Manage campus facilities organized by building and floor.</p>
        </section>

        <section class="building-panel">

            <div class="panel-header">
                <h2>Campus Facilities</h2>
                <a href="add-room.php" class="add-btn">+ Add Facility</a>
            </div>

            <?php if ($message !== ""): ?>
                <div class="message"><?= h($message) ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="message error"><?= h($errorMessage) ?></div>
            <?php endif; ?>

            <?php if ($totalFacilities > 0): ?>

                <?php foreach ($groups as $group): ?>

                    <?php
                    $buildingTotal = 0;

                    foreach ($group["floors"] as $floorFacilities) {
                        $buildingTotal += count($floorFacilities);
                    }
                    ?>

                    <section class="building-section">

                        <div class="building-header">
                            <div class="building-title">
                                <span class="building-icon">🏢</span>
                                <h3><?= h($group["building_name"]) ?></h3>
                            </div>

                            <span class="building-count">
                                <?= $buildingTotal ?>
                                <?= $buildingTotal === 1 ? "Facility" : "Facilities" ?>
                            </span>
                        </div>

                        <?php foreach ($group["floors"] as $floorName => $floorFacilities): ?>

                            <div class="floor-section">

                                <div class="floor-header">
                                    <span>🏬</span>
                                    <h4><?= h(formatFloorLabel($floorName)) ?></h4>
                                    <span>
                                        <?= count($floorFacilities) ?>
                                        <?= count($floorFacilities) === 1 ? "Facility" : "Facilities" ?>
                                    </span>
                                </div>

                                <div class="table-wrapper">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Facility Name</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <?php foreach ($floorFacilities as $facility): ?>
                                                <tr>
                                                    <td><?= (int)$facility["facility_id"] ?></td>

                                                    <td class="item-name">
                                                        <?= h($facility["facility_name"]) ?>
                                                    </td>

                                                    <td>
                                                        <div class="actions">

                                                            <button
                                                                type="button"
                                                                class="edit-btn"
                                                                data-id="<?= (int)$facility["facility_id"] ?>"
                                                                data-building="<?= (int)$facility["building_id"] ?>"
                                                                data-floor="<?= h($facility["floor"] ?? "") ?>"
                                                                data-name="<?= h($facility["facility_name"] ?? "") ?>"
                                                            >Edit</button>

                                                            <form method="POST"
                                                                  onsubmit="return confirm('Are you sure you want to delete this facility?');">
                                                                <input
                                                                    type="hidden"
                                                                    name="delete_id"
                                                                    value="<?= (int)$facility["facility_id"] ?>"
                                                                >
                                                                <button type="submit" class="delete-btn">Delete</button>
                                                            </form>

                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div>

                        <?php endforeach; ?>

                    </section>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-state">
                    <div class="icon">🗺️</div>
                    <h3>No Facilities Yet</h3>
                    <p>No facilities have been added yet. Click the button below to add your first campus facility.</p>
                    <a href="add-room.php" class="add-btn">+ Add First Facility</a>
                </div>

            <?php endif; ?>

        </section>
    </main>
</div>

<footer>
    <p>© <?= date("Y") ?> CMU Campus Navigator | City of Malabon University</p>
</footer>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="editTitle">

        <h2 id="editTitle">Edit Facility</h2>
        <p class="modal-sub">Update the information below, then click Save Changes.</p>

        <form method="POST" action="facilities.php" id="editForm">
            <input type="hidden" name="update_facility" value="1">
            <input type="hidden" name="facility_id" id="editId">

            <div class="form-grid">

                <div class="field">
                    <label for="editBuilding">Building *</label>
                    <select name="building_id" id="editBuilding" required>
                        <?php foreach ($allBuildings as $b): ?>
                            <option value="<?= (int)$b["id"] ?>">
                                <?= h($b["name"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="editFloor">Floor *</label>
                    <select name="floor" id="editFloor" required></select>
                </div>

                <div class="field full">
                    <label for="editNameChoice">Facility Name *</label>
                    <select name="facility_name_choice" id="editNameChoice" required></select>
                </div>

                <div class="field full hidden" id="editOtherField">
                    <label for="editOther">Custom Facility Name *</label>
                    <input
                        type="text"
                        name="facility_name_other"
                        id="editOther"
                        maxlength="150"
                        placeholder="Type the facility name"
                    >
                </div>

            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" id="editCancel">Cancel</button>
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </form>

    </div>
</div>

<script>
const facilityNames = <?= json_encode($facilityNameOptions, $jsonFlags) ?>;

const buildingFloors = <?= json_encode(
    array_map(function ($b) {
        return $b["floors"];
    }, $allBuildings),
    $jsonFlags
) ?>;

const modal = document.getElementById("editModal");
const editId = document.getElementById("editId");
const editBuilding = document.getElementById("editBuilding");
const editFloor = document.getElementById("editFloor");
const editNameChoice = document.getElementById("editNameChoice");
const editOtherField = document.getElementById("editOtherField");
const editOther = document.getElementById("editOther");

function fillFloors(selected) {
    const floors = buildingFloors[editBuilding.value] || {};

    editFloor.innerHTML = "";

    Object.entries(floors).forEach(([floorNumber, floorLabel]) => {
        const option = document.createElement("option");
        option.value = String(floorNumber);
        option.textContent = floorLabel;
        editFloor.appendChild(option);
    });

    if (selected !== undefined && selected !== null && selected !== "") {
        editFloor.value = String(selected);
    }

    if (editFloor.value === "" && editFloor.options.length > 0) {
        editFloor.selectedIndex = 0;
    }
}

function toggleOther() {
    const isOther = editNameChoice.value === "Others";

    editOtherField.classList.toggle("hidden", !isOther);
    editOther.required = isOther;

    if (!isOther) {
        editOther.value = "";
    }
}

function fillNames(selectedName) {
    editNameChoice.innerHTML = "";

    facilityNames.forEach(name => {
        const option = document.createElement("option");
        option.value = name;
        option.textContent = name;
        editNameChoice.appendChild(option);
    });

    const other = document.createElement("option");
    other.value = "Others";
    other.textContent = "Others";
    editNameChoice.appendChild(other);

    if (facilityNames.includes(selectedName)) {
        editNameChoice.value = selectedName;
        editOther.value = "";
    } else {
        editNameChoice.value = "Others";
        editOther.value = selectedName || "";
    }

    toggleOther();
}

function openEdit(button) {
    const data = button.dataset;

    editId.value = data.id;
    editBuilding.value = data.building;

    if (!buildingFloors[editBuilding.value]) {
        editBuilding.selectedIndex = 0;
    }

    fillFloors(data.floor);
    fillNames(data.name);

    modal.classList.add("open");
    editNameChoice.focus();
}

function closeEdit() {
    modal.classList.remove("open");
}

document.querySelectorAll(".edit-btn").forEach(button => {
    button.addEventListener("click", () => openEdit(button));
});

editBuilding.addEventListener("change", () => {
    fillFloors();
});

editNameChoice.addEventListener("change", () => {
    toggleOther();

    if (editNameChoice.value === "Others") {
        editOther.focus();
    }
});

document.getElementById("editCancel").addEventListener("click", closeEdit);

modal.addEventListener("click", event => {
    if (event.target === modal) {
        closeEdit();
    }
});

document.addEventListener("keydown", event => {
    if (event.key === "Escape" && modal.classList.contains("open")) {
        closeEdit();
    }
});
</script>

</body>
</html>
