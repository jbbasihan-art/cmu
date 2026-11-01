
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

$adminName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Admin";
$message = "";
$messageType = "error";

$buildingId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $buildingId = filter_input(INPUT_POST, "building_id", FILTER_VALIDATE_INT);
}

if (!$buildingId || $buildingId < 1) {
    $_SESSION["building_message"] = "Invalid building ID.";
    header("Location: buildings.php");
    exit;
}

/* Get the current building information */
$stmt = $conn->prepare(
    "SELECT building_id, building_name, total_floors, status
     FROM buildings
     WHERE building_id = :building_id"
);

$stmt->execute([
    "building_id" => $buildingId
]);

$building = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$building) {
    $_SESSION["building_message"] = "Building not found.";
    header("Location: buildings.php");
    exit;
}

$buildingName = $building["building_name"] ?? "";
$totalFloors = (int) ($building["total_floors"] ?? 1);
$status = $building["status"] ?? "Active";

/* Save the updated building information */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $buildingName = trim($_POST["building_name"] ?? "");
    $totalFloors = filter_input(INPUT_POST, "total_floors", FILTER_VALIDATE_INT);
    $status = trim($_POST["status"] ?? "");

    if ($buildingName === "") {
        $message = "Building name is required.";
    } elseif (mb_strlen($buildingName) > 150) {
        $message = "Building name must not exceed 150 characters.";
    } elseif (
        $totalFloors === false ||
        $totalFloors === null ||
        $totalFloors < 1 ||
        $totalFloors > 100
    ) {
        $message = "Please select a valid number of floors.";
    } elseif (!in_array($status, ["Active", "Inactive"], true)) {
        $message = "Please select a valid status.";
    } else {
        $check = $conn->prepare(
            "SELECT building_id
             FROM buildings
             WHERE building_name = :building_name
               AND building_id != :building_id
             LIMIT 1"
        );

        $check->execute([
            "building_name" => $buildingName,
            "building_id" => $buildingId
        ]);

        $duplicate = $check->fetch(PDO::FETCH_ASSOC) !== false;

        if ($duplicate) {
            $message = "That building name is already being used.";
        } else {
            $update = $conn->prepare(
                "UPDATE buildings
                 SET building_name = :building_name,
                     total_floors = :total_floors,
                     status = :status
                 WHERE building_id = :building_id"
            );

            if ($update->execute([
                "building_name" => $buildingName,
                "total_floors" => $totalFloors,
                "status" => $status,
                "building_id" => $buildingId
            ])) {
                $_SESSION["building_message"] =
                    "Building information updated successfully.";

                header("Location: buildings.php");
                exit;
            } else {
                $message = "Unable to update building information.";
            }
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Building | CMU Campus Navigator</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
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
            margin: 0 0 5px;
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
            margin-bottom: 30px;
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
            margin: 0;
            color: #43536b;
            font-size: 16px;
            line-height: 1.7;
        }

        .form-panel {
            max-width: 850px;
            padding: 28px;
            background: #ffffff;
            border: 1px solid #d8e1ee;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(11, 59, 130, 0.04);
        }

        .form-panel h2 {
            margin: 0 0 8px;
            color: #0b3b82;
            font-size: 23px;
            font-weight: 800;
        }

        .panel-description {
            margin: 0 0 25px;
            color: #43536b;
            font-size: 14px;
            line-height: 1.7;
        }

        .message {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 7px;
            background: #fff0ee;
            border: 1px solid #f1c0ba;
            color: #b42318;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            margin-bottom: 9px;
            color: #172b4d;
            font-size: 14px;
            font-weight: 700;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            min-height: 46px;
            padding: 12px 14px;
            border: 1px solid #cbd6e5;
            border-radius: 7px;
            background: #ffffff;
            color: #172b4d;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #0b3b82;
            box-shadow: 0 0 0 3px rgba(11, 59, 130, 0.1);
        }

        .form-group small {
            display: block;
            margin-top: 7px;
            color: #68758a;
            font-size: 12px;
            line-height: 1.5;
        }

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 28px;
            padding-top: 22px;
            border-top: 1px solid #e5eaf2;
        }

        .save-btn,
        .cancel-btn {
            display: inline-block;
            padding: 13px 20px;
            border: none;
            border-radius: 7px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            text-align: center;
        }

        .save-btn {
            background: #0b3b82;
            color: #ffffff;
        }

        .save-btn:hover {
            background: #082d63;
        }

        .cancel-btn {
            background: #edf3fc;
            color: #0b3b82;
        }

        .cancel-btn:hover {
            background: #dce8fa;
        }

        footer {
            padding: 20px;
            text-align: center;
            background: #ffffff;
            border-top: 1px solid #dce4ef;
        }

        footer p {
            margin: 0;
            color: #43536b;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (max-width: 1100px) {
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
                text-align: center;
                flex: 1;
            }

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
                padding: 22px 16px;
            }

            .welcome h1 {
                font-size: 28px;
            }

            .form-panel {
                padding: 22px 16px;
            }

            .form-panel h2 {
                font-size: 21px;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .save-btn,
            .cancel-btn {
                width: 100%;
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
        <a href="buildings.php" class="active">🏢 Buildings</a>
        <a href="rooms.php">🚪 Rooms</a>
        <a href="offices.php">🏛️ Offices</a>
        <a href="facilities.php">🗺️ Facilities</a>
    </aside>

    <main class="dashboard-content">

        <section class="welcome">
            <span class="eyebrow">ADMINISTRATION</span>
            <h1>Edit Building</h1>
            <p>
                Welcome, <?= htmlspecialchars($adminName, ENT_QUOTES, "UTF-8") ?>!
                Update the building information below.
            </p>
        </section>

        <section class="form-panel">

            <h2>Building Information</h2>
            <p class="panel-description">
                Change the building name, number of floors, or status.
            </p>

            <?php if ($message !== ""): ?>
                <div class="message">
                    <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
                </div>
            <?php endif; ?>

            <form action="edit-building.php?id=<?= (int) $buildingId ?>" method="POST">

                <input
                    type="hidden"
                    name="building_id"
                    value="<?= (int) $buildingId ?>"
                >

                <div class="form-group">
                    <label for="building_name">Building Name</label>

                    <input
                        type="text"
                        id="building_name"
                        name="building_name"
                        maxlength="150"
                        value="<?= htmlspecialchars($buildingName, ENT_QUOTES, "UTF-8") ?>"
                        placeholder="Enter building name"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="total_floors">Total Floors</label>

                    <select id="total_floors" name="total_floors" required>
                        <?php for ($floor = 1; $floor <= 100; $floor++): ?>
                            <option
                                value="<?= $floor ?>"
                                <?= (int) $totalFloors === $floor ? "selected" : "" ?>
                            >
                                <?= $floor ?>
                                <?= $floor === 1 ? "Floor" : "Floors" ?>
                            </option>
                        <?php endfor; ?>
                    </select>

                    <small>
                        This controls the number of floors available for this building.
                    </small>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>

                    <select id="status" name="status" required>
                        <option
                            value="Active"
                            <?= strcasecmp($status, "Active") === 0 ? "selected" : "" ?>
                        >
                            Active
                        </option>

                        <option
                            value="Inactive"
                            <?= strcasecmp($status, "Inactive") === 0 ? "selected" : "" ?>
                        >
                            Inactive
                        </option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn">
                        Save Changes
                    </button>

                    <a href="buildings.php" class="cancel-btn">
                        Cancel
                    </a>
                </div>

            </form>

        </section>

    </main>

</div>

<footer>
    <p>© 2026 CMU Campus Navigator | City of Malabon University</p>
</footer>

</body>
</html>
