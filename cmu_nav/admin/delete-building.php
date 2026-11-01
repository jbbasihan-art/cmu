

<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../config/database.php";

$error = "";
$building = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $building_id = filter_input(INPUT_POST, "building_id", FILTER_VALIDATE_INT);
    $confirmation = $_POST["confirmation"] ?? "";

    if (!$building_id || $confirmation !== "DELETE") {
        $error = "Invalid confirmation. Please type DELETE correctly.";
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare(
                "DELETE FROM building_layouts
                 WHERE building_id = :building_id"
            );
            $stmt->execute([
                "building_id" => $building_id
            ]);

            $stmt = $conn->prepare(
                "DELETE FROM buildings
                 WHERE building_id = :building_id"
            );
            $stmt->execute([
                "building_id" => $building_id
            ]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Building not found.");
            }

            $conn->commit();

            header("Location: buildings.php?deleted=1");
            exit;

        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            error_log("Delete building error: " . $e->getMessage());

            $error = "Unable to delete the building. It may still have related rooms, offices, or facilities. Check the database relationships before trying again.";
        }
    }
} else {
    $building_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
}

if (!isset($building_id) || !$building_id || $building_id < 1) {
    exit("Invalid building ID.");
}

$stmt = $conn->prepare(
    "SELECT building_id, building_name
     FROM buildings
     WHERE building_id = :building_id"
);

$stmt->execute([
    "building_id" => $building_id
]);

$building = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$building) {
    exit("Building not found.");
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Building</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
            background: #f3f5f9;
        }

        .container {
            width: 100%;
            max-width: 450px;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        h2 {
            margin-top: 0;
            color: #b42318;
        }

        .building-name {
            font-weight: bold;
            color: #173b70;
        }

        input {
            width: 100%;
            padding: 12px;
            margin: 10px 0 18px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        .buttons {
            display: flex;
            gap: 10px;
        }

        button, .back {
            flex: 1;
            padding: 12px;
            border: 0;
            border-radius: 6px;
            text-align: center;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }

        button {
            color: white;
            background: #c62828;
        }

        .back {
            color: #173b70;
            background: #e8edf5;
        }

        .error {
            padding: 10px;
            margin-bottom: 15px;
            color: #b42318;
            background: #fdecec;
            border-radius: 6px;
        }
    </style>
<link rel="stylesheet" href="../mobile.css">
</head>
<body>

<div class="container">
    <h2>Delete Building</h2>

    <p>
        Are you sure you want to delete
        <span class="building-name">
            <?= htmlspecialchars($building["building_name"]) ?>
        </span>?
    </p>

    <p>This action cannot be undone.</p>

    <?php if ($error !== ""): ?>
        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this building?');">
        <input
            type="hidden"
            name="building_id"
            value="<?= (int)$building["building_id"] ?>"
        >

        <label for="confirmation">Type DELETE to confirm:</label>
        <input
            type="text"
            id="confirmation"
            name="confirmation"
            placeholder="Type DELETE"
            required
        >

        <div class="buttons">
            <a href="buildings.php" class="back">Cancel</a>
            <button type="submit">Delete Building</button>
        </div>
    </form>
</div>

</body>
</html>