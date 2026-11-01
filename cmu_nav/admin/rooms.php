
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/*
 * Return numeric floor values with readable labels.
 * Example: ["1" => "1st Floor", "2" => "2nd Floor"]
 */
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

        $floors[(string)$i] = $i . $suffix . " Floor";
    }

    return $floors;
}

function formatFloorLabel($floor) {
    if ($floor === null || $floor === "") {
        return "Floor Not Set";
    }

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

/* Show database errors while troubleshooting. */
$showDbErrors = true;

/* Room, office, and facility name options */
$roomNameOptions = [
    "Room" => [
        "Classroom",
        "Computer Laboratory (ComLab)",
        "Faculty Room",
        "Science Laboratory"
    ],
    "Offices" => [
        "Registrar's Office",
        "Guidance Office",
        "Administrative Office",
        "Faculty Office"
    ],
    "Facilities" => [
        "Library",
        "Clinic",
        "Canteen",
        "Comfort Room (CR)",
        "Guardhouse"
    ]
];

$typeOptions = array_keys($roomNameOptions);

/* Load buildings and their available floors */
$allBuildings = [];

try {
    $bResult = $conn->query(
        "SELECT building_id, building_name, total_floors
         FROM buildings
         ORDER BY building_name"
    );

    while ($b = $bResult->fetch(PDO::FETCH_ASSOC)) {
        $buildingId = (int)$b["building_id"];

        $allBuildings[$buildingId] = [
            "id" => $buildingId,
            "name" => $b["building_name"],
            "floors" => getFloorOptions($b["total_floors"])
        ];
    }
} catch (Throwable $ex) {
    error_log("rooms.php buildings error: " . $ex->getMessage());
    die("Unable to load buildings. Please check your database connection and columns.");
}

/* Save edited room, office, or facility */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_room"])) {
    $roomId = (int)($_POST["room_id"] ?? 0);
    $roomType = trim((string)($_POST["room_type"] ?? ""));
    $buildingId = (int)($_POST["building_id"] ?? 0);
    $floor = trim((string)($_POST["floor"] ?? ""));
    $roomNumber = trim((string)($_POST["room_number"] ?? ""));
    $nameChoice = trim((string)($_POST["room_name_choice"] ?? ""));
    $nameOther = trim((string)($_POST["room_name_other"] ?? ""));

    $roomName = ($nameChoice === "Others") ? $nameOther : $nameChoice;
    $error = "";

    if ($roomId <= 0) {
        $error = "Invalid room.";
    } elseif (!in_array($roomType, $typeOptions, true)) {
        $error = "Please select a valid type.";
    } elseif (!isset($allBuildings[$buildingId])) {
        $error = "Please select a valid building.";
    } elseif (
        $floor === "" ||
        !ctype_digit($floor) ||
        !array_key_exists($floor, $allBuildings[$buildingId]["floors"])
    ) {
        $error = "Please select a valid floor.";
    } elseif ($roomType === "Room" && $roomNumber === "") {
        $error = "Room Number is required.";
    } elseif (
        $roomType === "Room" &&
        (!ctype_digit($roomNumber) || strlen($roomNumber) > 50)
    ) {
        $error = "Room Number must contain numbers only.";
    } elseif ($nameChoice === "") {
        $error = "Please select a Room Name.";
    } elseif (
        $nameChoice !== "Others" &&
        !in_array($nameChoice, $roomNameOptions[$roomType], true)
    ) {
        $error = "Please select a valid Room Name for the chosen type.";
    } elseif ($nameChoice === "Others" && $nameOther === "") {
        $error = "Please type the custom Room Name.";
    } elseif (mb_strlen($roomName) > 150) {
        $error = "Room Name must not exceed 150 characters.";
    }

    if ($error === "") {
        /* Offices and Facilities do not use a room number. */
        if ($roomType !== "Room") {
            $roomNumber = "";
        }

        try {
            $stmt = $conn->prepare(
                "UPDATE rooms
                 SET building_id = :building_id,
                     room_number = :room_number,
                     room_name = :room_name,
                     room_type = :room_type,
                     floor = :floor
                 WHERE room_id = :room_id"
            );

            $stmt->bindValue(":building_id", $buildingId, PDO::PARAM_INT);
            $stmt->bindValue(":room_number", $roomNumber, PDO::PARAM_STR);
            $stmt->bindValue(":room_name", $roomName, PDO::PARAM_STR);
            $stmt->bindValue(":room_type", $roomType, PDO::PARAM_STR);

            /*
             * IMPORTANT:
             * The Supabase rooms.floor column is INTEGER.
             * Save the numeric floor value, not "1st Floor".
             */
            $stmt->bindValue(":floor", (int)$floor, PDO::PARAM_INT);

            $stmt->bindValue(":room_id", $roomId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                /*
                 * PostgreSQL may report zero changed rows when the submitted
                 * values are identical to the existing values.
                 * Verify that the room exists before reporting an error.
                 */
                $check = $conn->prepare(
                    "SELECT room_id
                     FROM rooms
                     WHERE room_id = :room_id"
                );
                $check->bindValue(":room_id", $roomId, PDO::PARAM_INT);
                $check->execute();

                if ($check->fetch(PDO::FETCH_ASSOC)) {
                    $_SESSION["room_message"] = "Saved changes successfully!";
                } else {
                    $_SESSION["room_error"] =
                        "The room could not be found. Please refresh the page and try again.";
                }
            } else {
                $_SESSION["room_message"] = "Saved changes successfully!";
            }

        } catch (Throwable $ex) {
            error_log("rooms.php update error: " . $ex->getMessage());

            $_SESSION["room_error"] = $showDbErrors
                ? "Unable to save changes: " . $ex->getMessage()
                : "Unable to save changes. Please check your database columns.";
        }
    } else {
        $_SESSION["room_error"] = $error;
    }

    header("Location: rooms.php");
    exit;
}

/* Flash messages */
$message = $_SESSION["room_message"] ?? "";
unset($_SESSION["room_message"]);

$errorMessage = $_SESSION["room_error"] ?? "";
unset($_SESSION["room_error"]);

$adminName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Admin";

/* Load all rooms */
$sql = "SELECT
            r.room_id,
            r.building_id,
            r.room_number,
            r.room_name,
            r.room_type,
            r.floor,
            b.building_name,
            b.total_floors
        FROM rooms r
        LEFT JOIN buildings b ON r.building_id = b.building_id
        ORDER BY
            b.building_name ASC,
            r.floor ASC,
            r.room_number ASC,
            r.room_id ASC";

try {
    $result = $conn->query($sql);
    $roomRows = $result->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log("rooms.php load error: " . $ex->getMessage());
    die("Unable to load rooms. Please check your database columns.");
}

/* Group rooms by building and floor */
$buildings = [];

foreach ($roomRows as $room) {
    $buildingId = (int)$room["building_id"];
    $floorName = formatFloorLabel($room["floor"] ?? null);

    if (!isset($buildings[$buildingId])) {
        $buildings[$buildingId] = [
            "building_name" => $room["building_name"] ?? "Unknown Building",
            "total_floors" => (int)($room["total_floors"] ?? 0),
            "floors" => []
        ];
    }

    if (!isset($buildings[$buildingId]["floors"][$floorName])) {
        $buildings[$buildingId]["floors"][$floorName] = [];
    }

    $buildings[$buildingId]["floors"][$floorName][] = $room;
}

/* Sort floor names numerically */
function floorSortValue($floorName) {
    if (preg_match('/(\d+)/', $floorName, $matches)) {
        return (int)$matches[1];
    }

    if (stripos($floorName, "ground") !== false) {
        return 0;
    }

    return 9999;
}

foreach ($buildings as &$building) {
    uksort($building["floors"], function ($a, $b) {
        $valueA = floorSortValue($a);
        $valueB = floorSortValue($b);

        if ($valueA === $valueB) {
            return strnatcasecmp($a, $b);
        }

        return $valueA <=> $valueB;
    });
}
unset($building);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Rooms | CMU Campus Navigator</title>

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

        .room-panel {
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
            border-radius: 7px;
            background: #0b3b82;
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
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
            overflow-wrap: anywhere;
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

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
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

        .room-name {
            color: #0b3b82;
            font-weight: 700;
        }

        .type-badge {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 20px;
            background: #edf3fc;
            color: #0b3b82;
            font-size: 12px;
            font-weight: 700;
        }

        .type-badge.offices {
            background: #e3f4ea;
            color: #216e39;
        }

        .type-badge.facilities {
            background: #fdeee2;
            color: #a3490f;
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

        /* Edit modal */
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

        .field select:disabled {
            background: #f1f4f9;
            cursor: not-allowed;
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

            .room-panel {
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
        <a href="rooms.php" class="active">🚪 Rooms</a>
        <a href="offices.php">🏛️ Offices</a>
        <a href="facilities.php">🗺️ Facilities</a>
    </aside>

    <main class="dashboard-content">

        <section class="welcome">
            <span class="eyebrow">ADMINISTRATION</span>
            <h1>Manage Rooms</h1>

            <p>
                Welcome,
                <?= h($adminName) ?>!
                Manage campus rooms organized by building and floor.
            </p>
        </section>

        <section class="room-panel">

            <div class="panel-header">
                <h2>Campus Rooms</h2>
                <a href="add-room.php" class="add-btn">+ Add Room</a>
            </div>

            <?php if ($message !== ""): ?>
                <div class="message"><?= h($message) ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="message error"><?= h($errorMessage) ?></div>
            <?php endif; ?>

            <?php if (!empty($buildings)): ?>

                <?php foreach ($buildings as $buildingId => $building): ?>

                    <?php
                    $totalRooms = 0;

                    foreach ($building["floors"] as $floorRooms) {
                        $totalRooms += count($floorRooms);
                    }
                    ?>

                    <section class="building-section">

                        <div class="building-header">
                            <div class="building-title">
                                <span class="building-icon">🏢</span>
                                <h3><?= h($building["building_name"]) ?></h3>
                            </div>

                            <span class="building-count">
                                <?= $totalRooms ?>
                                <?= $totalRooms === 1 ? "Item" : "Items" ?>
                            </span>
                        </div>

                        <?php foreach ($building["floors"] as $floorName => $floorRooms): ?>

                            <div class="floor-section">

                                <div class="floor-header">
                                    <span>🏬</span>
                                    <h4><?= h($floorName) ?></h4>

                                    <span>
                                        <?= count($floorRooms) ?>
                                        <?= count($floorRooms) === 1 ? "Item" : "Items" ?>
                                    </span>
                                </div>

                                <div class="table-wrapper">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Type</th>
                                                <th>Room Number</th>
                                                <th>Room Name</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <?php foreach ($floorRooms as $room): ?>

                                                <?php
                                                $type = trim((string)($room["room_type"] ?? ""));

                                                if (!in_array($type, $typeOptions, true)) {
                                                    $type = "Room";
                                                }

                                                $roomName = trim((string)($room["room_name"] ?? ""));
                                                ?>

                                                <tr>
                                                    <td><?= (int)$room["room_id"] ?></td>

                                                    <td>
                                                        <span class="type-badge <?= h(strtolower($type)) ?>">
                                                            <?= h($type) ?>
                                                        </span>
                                                    </td>

                                                    <td class="room-name">
                                                        <?= h($room["room_number"] ?? "") !== ""
                                                            ? h($room["room_number"])
                                                            : "—" ?>
                                                    </td>

                                                    <td>
                                                        <?= h($roomName !== "" ? $roomName : "No room name") ?>
                                                    </td>

                                                    <td>
                                                        <div class="actions">

                                                            <button
                                                                type="button"
                                                                class="edit-btn"
                                                                data-id="<?= (int)$room["room_id"] ?>"
                                                                data-building="<?= (int)$room["building_id"] ?>"
                                                                data-floor="<?= h($room["floor"] ?? "") ?>"
                                                                data-type="<?= h($type) ?>"
                                                                data-number="<?= h($room["room_number"] ?? "") ?>"
                                                                data-name="<?= h($roomName) ?>"
                                                            >Edit</button>

                                                            <form
                                                                action="delete-room.php"
                                                                method="POST"
                                                                onsubmit="return confirm('Are you sure you want to delete this item?');"
                                                            >
                                                                <input
                                                                    type="hidden"
                                                                    name="room_id"
                                                                    value="<?= (int)$room["room_id"] ?>"
                                                                >

                                                                <button type="submit" class="delete-btn">
                                                                    Delete
                                                                </button>
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
                    <div class="icon">🚪</div>
                    <h3>No Rooms Yet</h3>

                    <p>
                        No rooms have been added yet.
                        Add a room to see it organized by building and floor.
                    </p>

                    <a href="add-room.php" class="add-btn">
                        + Add First Room
                    </a>
                </div>

            <?php endif; ?>

        </section>

    </main>
</div>

<footer>
    <p>© 2026 CMU Campus Navigator | City of Malabon University</p>
</footer>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="editTitle">

        <h2 id="editTitle">Edit Details</h2>
        <p class="modal-sub">Update the information below, then click Save Changes.</p>

        <form method="POST" action="rooms.php" id="editForm">
            <input type="hidden" name="update_room" value="1">
            <input type="hidden" name="room_id" id="editId">

            <div class="form-grid">

                <div class="field">
                    <label for="editType">Type *</label>
                    <select name="room_type" id="editType" required>
                        <?php foreach ($typeOptions as $type): ?>
                            <option value="<?= h($type) ?>"><?= h($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" id="editNumberField">
                    <label for="editNumber">Room Number *</label>
                    <input
                        type="text"
                        name="room_number"
                        id="editNumber"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="50"
                        title="Numbers only"
                    >
                </div>

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
                    <label for="editNameChoice">Room Name *</label>
                    <select name="room_name_choice" id="editNameChoice" required></select>
                </div>

                <div class="field full hidden" id="editOtherField">
                    <label for="editOther">Custom Room Name *</label>
                    <input
                        type="text"
                        name="room_name_other"
                        id="editOther"
                        maxlength="150"
                        placeholder="Type the room name"
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
const roomNameOptions = <?= json_encode($roomNameOptions, $jsonFlags) ?>;

/*
 * Each building's floors are stored as:
 * { "1": "1st Floor", "2": "2nd Floor" }
 */
const buildingFloors = <?= json_encode(
    array_map(function ($b) {
        return $b["floors"];
    }, $allBuildings),
    $jsonFlags
) ?>;

const modal = document.getElementById("editModal");
const editId = document.getElementById("editId");
const editType = document.getElementById("editType");
const editNumberField = document.getElementById("editNumberField");
const editNumber = document.getElementById("editNumber");
const editBuilding = document.getElementById("editBuilding");
const editFloor = document.getElementById("editFloor");
const editNameChoice = document.getElementById("editNameChoice");
const editOtherField = document.getElementById("editOtherField");
const editOther = document.getElementById("editOther");

/* Populate floors with numeric values and readable labels. */
function fillFloors(selected) {
    const floors = buildingFloors[editBuilding.value] || {};

    editFloor.innerHTML = "";

    Object.entries(floors).forEach(([value, label]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        editFloor.appendChild(option);
    });

    if (
        selected !== undefined &&
        selected !== null &&
        selected !== "" &&
        Object.prototype.hasOwnProperty.call(floors, String(selected))
    ) {
        editFloor.value = String(selected);
    }
}

function fillNames(selectedName) {
    const names = roomNameOptions[editType.value] || [];

    editNameChoice.innerHTML = "";

    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "Select Room Name";
    editNameChoice.appendChild(placeholder);

    names.forEach(name => {
        const option = document.createElement("option");
        option.value = name;
        option.textContent = name;
        editNameChoice.appendChild(option);
    });

    const other = document.createElement("option");
    other.value = "Others";
    other.textContent = "Others";
    editNameChoice.appendChild(other);

    if (selectedName !== undefined) {
        if (names.includes(selectedName)) {
            editNameChoice.value = selectedName;
            editOther.value = "";
        } else if (selectedName !== "") {
            editNameChoice.value = "Others";
            editOther.value = selectedName;
        } else {
            editNameChoice.value = "";
            editOther.value = "";
        }
    }

    toggleOther();
}

function toggleOther() {
    const isOther = editNameChoice.value === "Others";

    editOtherField.classList.toggle("hidden", !isOther);
    editOther.required = isOther;

    if (!isOther) {
        editOther.value = "";
    }
}

function toggleNumber() {
    const isRoom = editType.value === "Room";

    editNumberField.classList.toggle("hidden", !isRoom);
    editNumber.required = isRoom;
    editNumber.disabled = !isRoom;

    if (!isRoom) {
        editNumber.value = "";
    }
}

function openEdit(button) {
    const data = button.dataset;

    editId.value = data.id;
    editType.value = data.type;
    editBuilding.value = data.building;

    toggleNumber();
    editNumber.value = data.type === "Room" ? data.number : "";

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

editType.addEventListener("change", () => {
    toggleNumber();
    fillNames("");
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

/* Numbers only for Room Number. */
editNumber.addEventListener("input", () => {
    editNumber.value = editNumber.value.replace(/\D/g, "");
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
