
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

$message = $_SESSION["building_message"] ?? "";
unset($_SESSION["building_message"]);

try {
    // Hide campus layout elements from this Buildings management page.
    // These records remain in the database and can still be used by the campus layout.
    $sql = "SELECT building_id, building_name, total_floors, status
            FROM buildings
            WHERE LOWER(TRIM(building_name)) NOT IN (
                'campus ground',
                'basketball court',
                'parking lot 1',
                'parking lot 2',
                'spiked border'
            )
            AND LOWER(TRIM(building_name)) NOT LIKE 'parking lot%'
            AND LOWER(TRIM(building_name)) NOT LIKE '%court%'
            AND LOWER(TRIM(building_name)) NOT LIKE '%spiked border%'
            ORDER BY building_id DESC";

    $stmt = $conn->query($sql);
    $buildings = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Unable to load buildings: " . $e->getMessage());
    die("Unable to load buildings. Please check the database connection.");
}

$adminName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Admin";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Buildings | CMU Campus Navigator</title>

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
            padding: 16px 14px;
            color: #0b3b82;
            text-align: left;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        td {
            padding: 16px 14px;
            border-bottom: 1px solid #e5eaf2;
            color: #43536b;
            font-size: 14px;
            line-height: 1.6;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #f8faff;
        }

        .building-name {
            color: #0b3b82;
            font-weight: 700;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #edf8ef;
            color: #216e39;
            font-size: 12px;
            font-weight: 700;
        }

        .status.inactive {
            background: #fff0ee;
            color: #b42318;
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
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .edit-btn {
            background: #edf3fc;
            color: #0b3b82;
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
            <h1>Manage Buildings</h1>
            <p>
                Welcome,
                <?= htmlspecialchars($adminName, ENT_QUOTES, "UTF-8") ?>!
                Manage campus buildings here.
            </p>
        </section>

        <section class="building-panel">

            <div class="panel-header">
                <h2>Campus Buildings</h2>
                <a href="add-building.php" class="add-btn">+ Add Building</a>
            </div>

            <?php if ($message !== ""): ?>
                <div class="message">
                    <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
                </div>
            <?php endif; ?>

            <?php if (count($buildings) > 0): ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Building Name</th>
                                <th>Floors</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($buildings as $building): ?>
                                <?php
                                $status = trim((string) ($building["status"] ?? ""));
                                $isInactive = strtolower($status) === "inactive";
                                ?>

                                <tr>
                                    <td>
                                        <?= (int) $building["building_id"] ?>
                                    </td>

                                    <td class="building-name">
                                        <?= htmlspecialchars(
                                            $building["building_name"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= (int) ($building["total_floors"] ?? 0) ?>
                                    </td>

                                    <td>
                                        <span class="status <?= $isInactive ? 'inactive' : '' ?>">
                                            <?= htmlspecialchars(
                                                $status !== "" ? $status : "Not set",
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="actions">
                                            <a
                                                href="edit-building.php?id=<?= (int) $building["building_id"] ?>"
                                                class="edit-btn"
                                            >Edit</a>

                                            <form
                                                action="delete-building.php"
                                                method="POST"
                                                onsubmit="return confirm('Are you sure you want to delete this building?');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="building_id"
                                                    value="<?= (int) $building["building_id"] ?>"
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

            <?php else: ?>

                <div class="empty-state">
                    <div class="icon">🏢</div>
                    <h3>No Buildings Yet</h3>
                    <p>
                        No buildings have been added yet.
                        Click below to add your first campus building.
                    </p>
                    <a href="add-building.php" class="add-btn">
                        + Add First Building
                    </a>
                </div>

            <?php endif; ?>

        </section>

    </main>
</div>

<footer>
    <p>© 2026 CMU Campus Navigator | City of Malabon University</p>
</footer>

</body>
</html>
