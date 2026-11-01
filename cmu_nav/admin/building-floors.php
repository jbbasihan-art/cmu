
<?php
require_once __DIR__ . "/../config/database.php";

// Kunin ang building ID mula sa URL
$buildingId = isset($_GET["building_id"])
    ? (int) $_GET["building_id"]
    : 0;

// Kung walang valid building ID, bumalik sa campus map
if ($buildingId <= 0) {
    header("Location: ../campus-map.php");
    exit;
}

// Hanapin ang building sa database
$stmt = $conn->prepare("
    SELECT building_id
    FROM buildings
    WHERE building_id = :building_id
    LIMIT 1
");

$stmt->execute([
    "building_id" => $buildingId
]);

$building = $stmt->fetch(PDO::FETCH_ASSOC);

// Kung walang building na nahanap, bumalik sa campus map
if (!$building) {
    header("Location: ../campus-map.php");
    exit;
}

// Direktang buksan ang Floor 1 layout
header(
    "Location: floor-layout.php?building_id=" .
    $buildingId . "&floor=1"
);
exit;
?>

