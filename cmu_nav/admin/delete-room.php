
<?php
session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

require_once "../config/database.php";

// Allow deletion only through POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: rooms.php");
    exit;
}

$room_id = filter_input(INPUT_POST, "room_id", FILTER_VALIDATE_INT);

if (!$room_id || $room_id <= 0) {
    $_SESSION["room_message"] = "Invalid room ID.";
    header("Location: rooms.php");
    exit;
}

try {
    $stmt = $conn->prepare(
        "DELETE FROM rooms WHERE room_id = :room_id"
    );

    $stmt->execute([
        "room_id" => $room_id
    ]);

    if ($stmt->rowCount() > 0) {
        $_SESSION["room_message"] = "Room successfully deleted.";
    } else {
        $_SESSION["room_message"] = "Room not found or already deleted.";
    }

} catch (Throwable $e) {
    error_log("Delete room error: " . $e->getMessage());

    $_SESSION["room_message"] =
        "Unable to delete this room. It may be linked to other records.";
}

header("Location: rooms.php");
exit;
?>

