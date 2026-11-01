<?php
session_start();

/* Always load fresh numbers */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once "../config/database.php";

/* Check admin login */
if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

/* Initialize counts */
$buildingCount = 0;
$roomCount = 0;
$officeCount = 0;
$facilityCount = 0;

try {
    /* Count real buildings only. Campus layout elements stay in the database,
       but are excluded from the Buildings total on the dashboard. */
    $stmt = $conn->query(
        "SELECT COUNT(*) AS total
         FROM buildings
         WHERE LOWER(TRIM(building_name)) NOT IN (
             'campus ground',
             'basketball court',
             'parking lot 1',
             'parking lot 2',
             'spiked border'
         )
         AND LOWER(TRIM(building_name)) NOT LIKE '%campus ground%'
         AND LOWER(TRIM(building_name)) NOT LIKE '%basketball court%'
         AND LOWER(TRIM(building_name)) NOT LIKE '%parking lot%'
         AND LOWER(TRIM(building_name)) NOT LIKE '%spiked border%'
         AND LOWER(TRIM(building_name)) NOT LIKE '%court%'"
    );
    $buildingCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)["total"];

    // Total Rooms
    $stmt = $conn->query("SELECT COUNT(*) AS total FROM rooms");
    $roomCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)["total"];

    // Total Offices
    $stmt = $conn->query("SELECT COUNT(*) AS total FROM offices");
    $officeCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)["total"];

    // Total Facilities
    $stmt = $conn->query("SELECT COUNT(*) AS total FROM facilities");
    $facilityCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)["total"];

} catch (PDOException $e) {
    error_log("Dashboard database error: " . $e->getMessage());
    http_response_code(500);
    exit("Unable to load dashboard data. Please check your database connection and table names.");
}

$adminName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Admin";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | CMU Campus Navigator</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: #f3f6fb; color: #172b4d; font-size: 16px; }
        a { -webkit-tap-highlight-color: transparent; }
        .navbar { min-height: 86px; padding: 15px 4%; display: flex; align-items: center; justify-content: space-between; gap: 20px; background: #fff; border-bottom: 1px solid #dce4ef; box-shadow: 0 2px 8px rgba(20,45,80,.06); }
        .logo { display: flex; align-items: center; gap: 14px; }
        .logo-icon img { width: 56px; height: 56px; object-fit: contain; }
        .logo h2 { margin: 0 0 5px; color: #0b3b82; font-size: 21px; font-weight: 800; }
        .logo span { color: #4d5f78; font-size: 14px; font-weight: 600; }
        .navbar nav { display: flex; align-items: center; gap: 12px; }
        .navbar nav a { padding: 12px 18px; border-radius: 7px; text-decoration: none; font-size: 15px; font-weight: 700; }
        .view-btn { color: #0b3b82; background: #edf3fc; }
        .view-btn:hover { background: #dce8fa; }
        .logout-btn { color: #fff; background: #b42318; }
        .logout-btn:hover { background: #8f1d14; }
        .admin-layout { display: flex; min-height: calc(100vh - 150px); }
        .sidebar { width: 255px; flex-shrink: 0; background: #0b3b82; padding: 30px 18px; color: #fff; }
        .sidebar h3 { margin: 0 12px 22px; color: #ffdf45; font-size: 14px; font-weight: 800; letter-spacing: 1.2px; }
        .sidebar a { display: block; margin-bottom: 8px; padding: 15px 14px; border-radius: 7px; color: #fff; text-decoration: none; font-size: 16px; font-weight: 600; transition: background .2s; }
        .sidebar a:hover, .sidebar a.active { color: #fff; background: #24569b; outline: 1px solid rgba(255,255,255,.16); }
        .dashboard-content { flex: 1; min-width: 0; padding: 38px; }
        .welcome { margin-bottom: 30px; }
        .welcome .eyebrow { color: #805900; font-size: 13px; font-weight: 800; letter-spacing: 1.4px; }
        .welcome h1 { margin: 10px 0; color: #0b3b82; font-size: 34px; line-height: 1.3; font-weight: 800; overflow-wrap: anywhere; }
        .welcome p { margin: 0; color: #43536b; font-size: 16px; line-height: 1.7; }
        .stats-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { min-width: 0; padding: 25px 22px; background: #fff; border: 1px solid #d8e1ee; border-radius: 12px; box-shadow: 0 4px 14px rgba(11,59,130,.06); }
        .stat-icon { margin-bottom: 15px; font-size: 32px; }
        .stat-card h2 { margin: 0 0 9px; color: #0b3b82; font-size: 36px; line-height: 1.2; font-weight: 800; }
        .stat-card p { margin: 0; color: #43536b; font-size: 15px; line-height: 1.5; font-weight: 600; }
        .quick-actions { padding: 28px; background: #fff; border: 1px solid #d8e1ee; border-radius: 12px; box-shadow: 0 4px 14px rgba(11,59,130,.04); }
        .quick-actions h2 { margin: 0 0 22px; color: #0b3b82; font-size: 23px; font-weight: 800; }
        .action-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 16px; }
        .action-link { display: block; padding: 20px; border: 1px solid #d8e1ee; border-radius: 9px; color: #0b3b82; background: #fff; text-decoration: none; font-size: 17px; line-height: 1.5; font-weight: 800; transition: .2s; }
        .action-link:hover { border-color: #c39400; background: #fffbea; transform: translateY(-2px); }
        .action-link span { display: block; margin-top: 8px; color: #43536b; font-size: 14px; line-height: 1.6; font-weight: 400; }
        footer { padding: 20px; text-align: center; background: #fff; border-top: 1px solid #dce4ef; }
        footer p { margin: 0; color: #43536b; font-size: 14px; line-height: 1.6; }
        @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } .dashboard-content { padding: 28px; } }
        @media (max-width: 700px) {
            .navbar { flex-direction: column; align-items: stretch; padding: 18px; }
            .navbar nav { flex-wrap: wrap; }
            .navbar nav a { text-align: center; flex: 1; }
            .admin-layout { flex-direction: column; }
            .sidebar { width: 100%; padding: 20px 16px; }
            .sidebar h3 { margin-bottom: 14px; }
            .dashboard-content { padding: 22px 16px; }
            .welcome h1 { font-size: 28px; }
            .stats-grid { gap: 12px; }
            .stat-card { padding: 18px 14px; }
            .stat-card h2 { font-size: 30px; }
            .stat-card p { font-size: 14px; }
            .quick-actions { padding: 20px 16px; }
            .quick-actions h2 { font-size: 21px; }
            .action-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 380px) { .stats-grid { grid-template-columns: 1fr; } .logo h2 { font-size: 18px; } }
    </style>
<link rel="stylesheet" href="../mobile.css">
</head>
<body>
<header class="navbar">
    <div class="logo">
        <div class="logo-icon"><img src="../cmu logo.jpg" alt="CMU Logo"></div>
        <div><h2>CMU Campus Navigator</h2><span>Administrator Dashboard</span></div>
    </div>
    <nav><a href="../index.php" class="view-btn">View Website</a><a href="logout.php" class="logout-btn">Logout</a></nav>
</header>
<div class="admin-layout">
    <aside class="sidebar">
        <h3>ADMIN MENU</h3>
        <a href="dashboard.php" class="active">📊 Dashboard</a>
        <a href="buildings.php">🏢 Buildings</a>
        <a href="rooms.php">🚪 Rooms</a>
        <a href="offices.php">🏛️ Offices</a>
        <a href="facilities.php">🗺️ Facilities</a>
    </aside>
    <main class="dashboard-content">
        <section class="welcome">
            <span class="eyebrow">ADMINISTRATION</span>
            <h1>Welcome, <?= htmlspecialchars($adminName, ENT_QUOTES, "UTF-8") ?>!</h1>
            <p>Manage the campus information of City of Malabon University.</p>
        </section>
        <section class="stats-grid">
            <div class="stat-card"><div class="stat-icon">🏢</div><h2><?= $buildingCount ?></h2><p>Total Buildings</p></div>
            <div class="stat-card"><div class="stat-icon">🚪</div><h2><?= $roomCount ?></h2><p>Total Rooms</p></div>
            <div class="stat-card"><div class="stat-icon">🏛️</div><h2><?= $officeCount ?></h2><p>Total Offices</p></div>
            <div class="stat-card"><div class="stat-icon">🗺️</div><h2><?= $facilityCount ?></h2><p>Total Facilities</p></div>
        </section>
        <section class="quick-actions">
            <h2>Manage Campus Information</h2>
            <div class="action-grid">
                <a href="buildings.php" class="action-link">🏢 Manage Buildings<span>Add, edit, and view campus buildings.</span></a>
                <a href="rooms.php" class="action-link">🚪 Manage Rooms<span>View and manage room information.</span></a>
                <a href="offices.php" class="action-link">🏛️ Manage Offices<span>Manage campus offices and their details.</span></a>
                <a href="facilities.php" class="action-link">🗺️ Manage Facilities<span>Manage facilities available around CMU.</span></a>
            </div>
        </section>
    </main>
</div>
<footer><p>© 2026 CMU Campus Navigator | City of Malabon University</p></footer>
<script>
window.addEventListener("pageshow", function (event) {
    if (event.persisted) window.location.reload();
});
</script>
</body>
</html>
