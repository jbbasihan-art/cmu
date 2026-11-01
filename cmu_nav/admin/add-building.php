<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

$message = "";
$error = "";

/*
|--------------------------------------------------------------------------
| DATABASE HELPERS
|--------------------------------------------------------------------------
| Sinusuri ang actual database columns para maiwasan ang errors kapag
| may optional columns na wala sa current buildings table.
*/

function getTableColumns(PDO $conn, string $table): array
{
    $stmt = $conn->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = :table
    ");
    $stmt->execute(["table" => $table]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

try {
    $buildingColumns = getTableColumns($conn, "buildings");
    $layoutColumns = getTableColumns($conn, "building_layouts");
} catch (Throwable $e) {
    error_log("Campus layout schema error: " . $e->getMessage());
    $buildingColumns = [];
    $layoutColumns = [];
}

/*
|--------------------------------------------------------------------------
| DELETE CAMPUS ELEMENT ONLY
|--------------------------------------------------------------------------
| Actual buildings and their rooms must never be deleted from this page.
| A removable element must have a recognized campus-element name and no rooms.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_building"])) {
    try {
        $deleteId = (int)($_POST["delete_building_id"] ?? 0);
        if ($deleteId <= 0) {
            throw new Exception("Invalid campus element ID.");
        }

        $stmt = $conn->prepare(
            "SELECT b.building_name,
                    (SELECT COUNT(*) FROM rooms r WHERE r.building_id = b.building_id) AS room_count
             FROM buildings b
             WHERE b.building_id = :id"
        );
        $stmt->execute(["id" => $deleteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Campus element not found.");
        }

        $elementName = (string)($row["building_name"] ?? "");
        $isCampusElement = preg_match(
            '/\b(court|parking lot|parking|campus ground|ground|spiked wall|spiked border|boundary wall|campus border)\b/i',
            $elementName
        ) === 1;

        if (!$isCampusElement || (int)$row["room_count"] > 0) {
            throw new Exception("Actual buildings cannot be deleted here. Only campus elements can be removed.");
        }

        $conn->beginTransaction();
        $stmt = $conn->prepare("DELETE FROM building_layouts WHERE building_id = :id");
        $stmt->execute(["id" => $deleteId]);
        $stmt = $conn->prepare("DELETE FROM buildings WHERE building_id = :id");
        $stmt->execute(["id" => $deleteId]);

        $conn->commit();
        header("Location: add-building.php?element_deleted=1");
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Campus element delete error: " . $e->getMessage());
        $error = "Hindi na-delete ang campus element: " . $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_layout"])) {
    try {
        if (empty($buildingColumns) || empty($layoutColumns)) {
            throw new Exception(
                "Hindi makita ang required database tables. Check ang table names at database connection."
            );
        }

        $requiredBuildingColumns = [
            "building_id",
            "building_name"
        ];

        $requiredLayoutColumns = [
            "building_id",
            "shape_type",
            "position_x",
            "position_y",
            "width",
            "height",
            "rotation"
        ];

        foreach ($requiredBuildingColumns as $column) {
            if (!hasColumn($buildingColumns, $column)) {
                throw new Exception(
                    "Missing required buildings column: " . $column
                );
            }
        }

        foreach ($requiredLayoutColumns as $column) {
            if (!hasColumn($layoutColumns, $column)) {
                throw new Exception(
                    "Missing required building_layouts column: " . $column
                );
            }
        }

        $ids = $_POST["building_id"] ?? [];
        $names = $_POST["building_name"] ?? [];
        $statuses = $_POST["status"] ?? [];
        $floors = $_POST["total_floors"] ?? [];
        $shapes = $_POST["shape_type"] ?? [];
        $xs = $_POST["position_x"] ?? [];
        $ys = $_POST["position_y"] ?? [];
        $widths = $_POST["width"] ?? [];
        $heights = $_POST["height"] ?? [];
        $rotations = $_POST["rotation"] ?? [];

        if (!is_array($names) || count($names) === 0) {
            throw new Exception("Walang building na ise-save.");
        }

        $conn->beginTransaction();

        foreach ($names as $i => $rawName) {
            $name = trim((string)($rawName ?? ""));

            if ($name === "") {
                throw new Exception(
                    "Please enter a name for every building."
                );
            }

            $floorCount = max(1, (int)($floors[$i] ?? 1));
            $status = (($statuses[$i] ?? "Active") === "Inactive") ? "Inactive" : "Active";
            $buildingId = (int)($ids[$i] ?? 0);

            $shape = (string)($shapes[$i] ?? "rectangle");

            if (!in_array($shape, ["rectangle", "square", "rounded"], true)) {
                $shape = "rectangle";
            }

            $x = max(0, (float)($xs[$i] ?? 50));
            $y = max(0, (float)($ys[$i] ?? 50));
            $w = max(40, (float)($widths[$i] ?? 140));
            $h = max(40, (float)($heights[$i] ?? 90));
            $rotation = (float)($rotations[$i] ?? 0);

            if (
                !is_finite($x) ||
                !is_finite($y) ||
                !is_finite($w) ||
                !is_finite($h) ||
                !is_finite($rotation)
            ) {
                throw new Exception("May invalid na building dimensions.");
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE EXISTING BUILDING
            |--------------------------------------------------------------------------
            */

            if ($buildingId > 0) {
                $setParts = ["building_name = :name"];
                $params = ["name" => $name, "id" => $buildingId];

                if (hasColumn($buildingColumns, "total_floors")) {
                    $setParts[] = "total_floors = :floors";
                    $params["floors"] = $floorCount;
                }

                if (hasColumn($buildingColumns, "status")) {
                    $setParts[] = "status = :status";
                    $params["status"] = $status;
                }

                $sql = "UPDATE buildings SET "
                    . implode(", ", $setParts)
                    . " WHERE building_id = :id";

                $stmt = $conn->prepare($sql);
                $stmt->execute($params);

                /*
                 * Check kung existing ang layout record.
                 */
                $stmt = $conn->prepare(
                    "SELECT layout_id
                     FROM building_layouts
                     WHERE building_id = :id
                     LIMIT 1"
                );
                $stmt->execute(["id" => $buildingId]);
                $existingLayout = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingLayout) {
                    $stmt = $conn->prepare(
                        "UPDATE building_layouts
                         SET shape_type = :shape,
                             position_x = :x,
                             position_y = :y,
                             width = :width,
                             height = :height,
                             rotation = :rotation
                         WHERE building_id = :id"
                    );

                    $stmt->execute([
                        "shape" => $shape,
                        "x" => $x,
                        "y" => $y,
                        "width" => $w,
                        "height" => $h,
                        "rotation" => $rotation,
                        "id" => $buildingId
                    ]);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO building_layouts
                         (building_id, shape_type, position_x, position_y,
                          width, height, rotation)
                         VALUES
                         (:id, :shape, :x, :y, :width, :height, :rotation)"
                    );

                    $stmt->execute([
                        "id" => $buildingId,
                        "shape" => $shape,
                        "x" => $x,
                        "y" => $y,
                        "width" => $w,
                        "height" => $h,
                        "rotation" => $rotation
                    ]);
                }
            } else {
                /*
                |--------------------------------------------------------------------------
                | INSERT NEW BUILDING
                |--------------------------------------------------------------------------
                | Isinasama lamang ang optional fields kung mayroon sa database.
                */

                $insertColumns = [];
                $insertValues = [];
                $params = [];

                $availableValues = [
                    "admin_id" => [
                        "placeholder" => ":admin_id",
                        "value" => (int)$_SESSION["admin_id"]
                    ],
                    "building_name" => [
                        "placeholder" => ":name",
                        "value" => $name
                    ],
                    "description" => [
                        "placeholder" => ":description",
                        "value" => ""
                    ],
                    "latitude" => [
                        "placeholder" => ":latitude",
                        "value" => 0
                    ],
                    "longitude" => [
                        "placeholder" => ":longitude",
                        "value" => 0
                    ],
                    "total_floors" => [
                        "placeholder" => ":floors",
                        "value" => $floorCount
                    ],
                    "panorama_360" => [
                        "placeholder" => ":panorama",
                        "value" => ""
                    ],
                    "virtual_tour_link" => [
                        "placeholder" => ":tour",
                        "value" => ""
                    ],
                    "status" => [
                        "placeholder" => ":status",
                        "value" => $status
                    ]
                ];

                foreach ($availableValues as $column => $data) {
                    if (hasColumn($buildingColumns, $column)) {
                        $insertColumns[] = $column;
                        $insertValues[] = $data["placeholder"];

                        $parameterName = ltrim(
                            $data["placeholder"],
                            ":"
                        );

                        $params[$parameterName] = $data["value"];
                    }
                }

                if (
                    !hasColumn($buildingColumns, "admin_id") &&
                    !hasColumn($buildingColumns, "building_name")
                ) {
                    throw new Exception(
                        "Hindi tugma ang database columns ng buildings table."
                    );
                }

                if (!in_array("building_name", $insertColumns, true)) {
                    throw new Exception(
                        "Hindi makita ang building_name column."
                    );
                }

                $sql = "INSERT INTO buildings ("
                    . implode(", ", $insertColumns)
                    . ") VALUES ("
                    . implode(", ", $insertValues)
                    . ") RETURNING building_id";

                $stmt = $conn->prepare($sql);
                $stmt->execute($params);

                $newBuildingId = (int)$stmt->fetchColumn();

                if ($newBuildingId <= 0) {
                    throw new Exception(
                        "Hindi nakuha ang bagong building ID."
                    );
                }

                $stmt = $conn->prepare(
                    "INSERT INTO building_layouts
                     (building_id, shape_type, position_x, position_y,
                      width, height, rotation)
                     VALUES
                     (:id, :shape, :x, :y, :width, :height, :rotation)"
                );

                $stmt->execute([
                    "id" => $newBuildingId,
                    "shape" => $shape,
                    "x" => $x,
                    "y" => $y,
                    "width" => $w,
                    "height" => $h,
                    "rotation" => $rotation
                ]);
            }
        }

        $conn->commit();

        header("Location: add-building.php?saved=1");
        exit;

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        error_log(
            "Campus layout save error: " . $e->getMessage()
        );

        if (
            $e instanceof Exception &&
            $e->getMessage() === "Please enter a name for every building."
        ) {
            $error = $e->getMessage();
        } else {
            // Temporary diagnostic message para makita ang actual error.
            $error = "Hindi na-save ang layout: " . $e->getMessage();
        }
    }
}

if (isset($_GET["saved"])) {
    $message = "Campus layout saved successfully!";
}

if (isset($_GET["element_deleted"])) {
    $message = "Campus element deleted successfully!";
}

$savedBuildings = [];

try {
    $floorSelect = hasColumn($buildingColumns, "total_floors")
        ? "b.total_floors"
        : "1 AS total_floors";
    $statusSelect = hasColumn($buildingColumns, "status")
        ? "b.status"
        : "'Active' AS status";

    /*
     * room_count = rooms, offices and facilities of the building.
     * Shown in the delete confirmation so the admin knows what will go too.
     */
    $sql = "SELECT
                b.building_id,
                b.building_name,
                {$floorSelect},
                {$statusSelect},
                l.shape_type,
                l.position_x,
                l.position_y,
                l.width,
                l.height,
                l.rotation,
                (
                    SELECT COUNT(*)
                    FROM rooms r
                    WHERE r.building_id = b.building_id
                ) AS room_count
            FROM buildings b
            LEFT JOIN building_layouts l
                ON b.building_id = l.building_id
            ORDER BY b.building_id ASC";

    $stmt = $conn->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $savedBuildings[] = [
            "databaseId" => (int)$row["building_id"],
            "id" => (int)$row["building_id"],
            "name" => $row["building_name"] ?? "",
            "status" => (($row["status"] ?? "Active") === "Inactive") ? "Inactive" : "Active",
            "floors" => (int)($row["total_floors"] ?? 1),
            "shape" => $row["shape_type"] ?? "rectangle",
            "x" => (float)($row["position_x"] ?? 50),
            "y" => (float)($row["position_y"] ?? 50),
            "w" => (float)($row["width"] ?? 140),
            "h" => (float)($row["height"] ?? 90),
            "rotation" => (float)($row["rotation"] ?? 0),
            "roomCount" => (int)($row["room_count"] ?? 0)
        ];
    }
} catch (Throwable $e) {
    error_log("Campus layout load error: " . $e->getMessage());

    if ($error === "") {
        $error = "Hindi ma-load ang campus layout: " . $e->getMessage();
    }
}

$adminName = $_SESSION["full_name"]
    ?? $_SESSION["username"]
    ?? "Admin";
?>



<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campus Layout Editor | CMU Campus Navigator</title>

<!-- Shared fade animation (must stay in <head>, no defer) -->
<script src="../fade.js"></script>

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

.editor-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 310px;
    gap: 20px;
    align-items: start;
}

.panel {
    padding: 25px;
    margin-bottom: 22px;
    background: #ffffff;
    border: 1px solid #d8e1ee;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(11, 59, 130, 0.04);
    min-width: 0;
}

.panel h2 {
    margin: 0 0 12px;
    color: #0b3b82;
    font-size: 23px;
    font-weight: 800;
}

.panel-help {
    margin: 0 0 18px;
    color: #43536b;
    font-size: 14px;
    line-height: 1.6;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
}

.elements-menu {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 8px;
    border: 1px solid #d5deeb;
    border-radius: 9px;
    background: #f8faff;
}

.elements-menu[hidden] {
    display: none;
}

button {
    border: 0;
    border-radius: 7px;
    padding: 12px 16px;
    cursor: pointer;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    font-weight: 700;
}

.btn-blue {
    background: #0b3b82;
    color: #fff;
}

.btn-gold {
    background: #ffdf45;
    color: #172b4d;
}

.btn-light {
    background: #edf3fc;
    color: #0b3b82;
}

.btn-red {
    background: #b42318;
    color: #fff;
}

button:hover {
    filter: brightness(0.96);
}

button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    filter: none;
}

/* Auto align toggle */
.align-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border: 1px solid #d8e1ee;
    border-radius: 7px;
    background: #f8faff;
    color: #0b3b82;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    user-select: none;
}

.align-toggle input {
    width: 16px;
    height: 16px;
    margin: 0;
    cursor: pointer;
}

.canvas-wrap {
    max-width: 100%;
    overflow: auto;
    border: 1px solid #d8e1ee;
    border-radius: 9px;
    background: #fff;
}

#canvas {
    width: 1200px;
    height: 650px;
    position: relative;
    overflow: hidden;
    touch-action: pan-x pan-y;
    background-color: #fff;
    background-image: radial-gradient(#cbd6e5 1px, transparent 1px);
    background-size: 20px 20px;
}

/* Alignment guide lines shown while dragging */
.align-guide {
    position: absolute;
    z-index: 50;
    background: #d6479b;
    pointer-events: none;
}

.align-guide.vertical {
    top: 0;
    bottom: 0;
    width: 1px;
}

.align-guide.horizontal {
    left: 0;
    right: 0;
    height: 1px;
}

.building-shape {
    position: absolute;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px;
    border: 3px solid #15803d;
    background: #86efac;
    color: #14532d;
    text-align: center;
    font-size: 13px;
    font-weight: 800;
    overflow: hidden;
    cursor: grab;
    user-select: none;
    touch-action: none;
}

.building-shape.status-active { background: #86efac; border-color: #15803d; color: #14532d; }
.building-shape.status-inactive { background: #fca5a5; border-color: #b91c1c; color: #7f1d1d; }

.building-shape:active {
    cursor: grabbing;
}

.building-shape.rounded { border-radius: 18px; }

/* Spiked border wall: a movable/resizable gray line with spikes, not a filled rectangle. */
.building-shape.spiked-wall-shape,
.building-shape.spiked-wall-shape.status-active,
.building-shape.spiked-wall-shape.status-inactive {
    display: block;
    padding: 0;
    overflow: visible;
    background: transparent;
    border: none;
    border-radius: 0;
    color: transparent;
    box-shadow: none;
}
.building-shape.spiked-wall-shape::before {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    top: 14px;
    height: 4px;
    background: #808080;
    pointer-events: none;
}
.building-shape.spiked-wall-shape::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    height: 14px;
    background: repeating-linear-gradient(
        45deg,
        #808080 0px,
        #808080 4px,
        transparent 4px,
        transparent 12px
    );
    pointer-events: none;
}
.building-shape.spiked-wall-shape.selected {
    outline: 1px dashed #f97316;
    outline-offset: 2px;
}
.spiked-wall-shape .shape-label { display: none; }

/* Campus Ground: gray background layer only. It must never capture clicks or be selected. */
.building-shape.ground-shape,
.building-shape.ground-shape.status-active,
.building-shape.ground-shape.status-inactive {
    z-index: 0 !important;
    display: block;
    padding: 0;
    background: #808080 !important;
    border: none !important;
    border-radius: 0;
    color: transparent;
    box-shadow: none !important;
    outline: none !important;
    pointer-events: none !important;
    cursor: default !important;
}
.ground-shape .shape-label,
.ground-shape .resize-handle { display: none !important; }
.ground-shape.selected { outline: none !important; }

/* Basketball court: uses the user's actual 2D HTML/CSS court design. */
.building-shape.court-shape {
    display: block;
    padding: 0;
    background: #808080;
    border: 4px solid #ffffff;
    border-radius: 0;
    overflow: hidden;
    color: #fff;
}
.building-shape.court-shape.status-active,
.building-shape.court-shape.status-inactive { border-color: #ffffff; box-shadow: none; }
.court-graphics { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; }
.court-graphics .court-surface {
    position: absolute; inset: 0; width: 100%; height: 100%;
    background: #808080; border: 4px solid #ffffff; overflow: hidden;
}
.court-graphics .half-court-line {
    position: absolute; top: 0; bottom: 0; left: 50%; width: 4px;
    background: #ffffff; transform: translateX(-50%);
}
.court-graphics .center-circle {
    position: absolute; top: 50%; left: 50%; width: 24%; aspect-ratio: 1;
    border: 4px solid #ffffff; border-radius: 50%; transform: translate(-50%, -50%);
}
.court-graphics .key {
    position: absolute; top: 50%; width: 19%; height: 38%;
    border: 4px solid #ffffff; transform: translateY(-50%); overflow: hidden;
}
.court-graphics .left-key { left: 0; border-left: none; }
.court-graphics .right-key { right: 0; border-right: none; }
.court-graphics .free-throw-circle {
    position: absolute; top: 50%; width: 100%; aspect-ratio: 1;
    border: 4px solid #ffffff; border-radius: 50%;
}
.court-graphics .left-circle { right: -50%; transform: translateY(-50%); }
.court-graphics .right-circle { left: -50%; transform: translateY(-50%); }
.court-graphics .three-point-line {
    position: absolute; top: 50%; width: 35%; aspect-ratio: 1;
    border: 4px solid #ffffff; border-radius: 50%; transform: translateY(-50%);
}
.court-graphics .left-three { left: -12%; border-left: none; }
.court-graphics .right-three { right: -12%; border-right: none; }
.court-graphics .backboard {
    position: absolute; top: 50%; width: 4px; height: 28%;
    background: #ffffff; transform: translateY(-50%);
}
.court-graphics .left-backboard { left: 20%; }
.court-graphics .right-backboard { right: 20%; }
.court-graphics .hoop {
    position: absolute; top: 50%; width: 24px; height: 24px;
    border: 3.5px solid #ff4500; border-radius: 50%; transform: translateY(-50%);
}
.court-graphics .left-hoop { left: calc(20% + 4px); }
.court-graphics .right-hoop { right: calc(20% + 4px); }
.court-shape .shape-label { position: absolute; left: 50%; bottom: 3px; z-index: 2; width: max-content; max-width: 85%; padding: 2px 5px; border-radius: 3px; background: rgba(25,25,25,.78); color: #fff; font-size: 11px; transform: translateX(-50%); }

/* Parking lot: gray cement with two marked parking spaces and wheel stoppers. */
.building-shape.parking-shape {
    display: block; padding: 0; background: #808080; border: none;
    border-radius: 4px; overflow: hidden; color: #fff;
}
.building-shape.parking-shape.status-active,
.building-shape.parking-shape.status-inactive {
    background: #808080; border: none; box-shadow: none; color: #fff;
}
.parking-graphics { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; }
.parking-surface { position: absolute; inset: 0; background: #808080; }
.parking-area {
    position: absolute; left: 50%; bottom: 12%; transform: translateX(-50%);
    display: flex; width: 66%; height: 52%; min-width: 50px; min-height: 35px;
}
.parking-slot {
    position: relative; flex: 1; min-width: 0; height: 100%;
    border: 2px solid #fff; border-bottom: none;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: clamp(9px, 1.8vw, 18px); font-weight: 800;
}
.parking-slot:first-child { border-right-width: 1px; }
.parking-slot:last-child { border-left-width: 1px; }
.parking-slot::before {
    content: ''; position: absolute; top: 9%; left: 20%; width: 60%; height: 5px;
    background: #333; border-radius: 2px;
}
.parking-shape .shape-label { position: absolute; left: 50%; bottom: 2px; z-index: 2; width: max-content; max-width: 90%; padding: 1px 4px; border-radius: 3px; background: rgba(25,25,25,.78); color: #fff; font-size: 10px; transform: translateX(-50%); }

.building-shape.selected {
    outline: 3px solid #ffdf45;
    outline-offset: 2px;
    z-index: 5;
}

.shape-label {
    max-width: 100%;
    overflow-wrap: anywhere;
    pointer-events: none;
}

.resize-handle {
    position: absolute;
    width: 18px;
    height: 18px;
    right: 0;
    bottom: 0;
    background: #f97316;
    border: 2px solid #ffffff;
    border-radius: 4px 0 0 0;
    cursor: nwse-resize;
    z-index: 30;
    touch-action: none;
    box-shadow: 0 0 0 1px rgba(124, 45, 18, .35);
}

.field {
    margin-bottom: 15px;
}

.field label {
    display: block;
    margin-bottom: 7px;
    color: #43536b;
    font-size: 14px;
    font-weight: 700;
}

.field input,
.field textarea,
.field select {
    display: block;
    width: 100%;
    min-width: 0;
    padding: 12px;
    border: 1px solid #cbd6e5;
    border-radius: 7px;
    background: #fff;
    color: #172b4d;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 15px;
}

.field input:focus,
.field textarea:focus,
.field select:focus {
    outline: 2px solid #d6e5fb;
    border-color: #0b3b82;
}

.field textarea {
    min-height: 85px;
    resize: vertical;
}

.field-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 12px;
}

.building-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.details-delete-button {
    width: 100%;
    margin-top: 16px;
    padding: 11px 14px;
    border: 0;
    border-radius: 8px;
    background: #b42318;
    color: #fff;
    font-weight: 700;
    cursor: pointer;
}

.details-delete-button:hover { background: #912018; }

.layout-category-badge {
    display: inline-block;
    margin-bottom: 12px;
    padding: 5px 9px;
    border-radius: 999px;
    background: #eef2f7;
    color: #475467;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.building-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px;
    border: 1px solid #d8e1ee;
    border-radius: 8px;
    background: #fff;
}

.building-item-name {
    min-width: 0;
    overflow-wrap: anywhere;
    color: #0b3b82;
    font-size: 14px;
    font-weight: 700;
}

.building-item-main {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    min-width: 0;
}

.building-status-badge {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    line-height: 1.3;
}

.building-status-badge.status-active {
    color: #166534;
    background: #dcfce7;
    border: 1px solid #86efac;
}

.building-status-badge.status-inactive {
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fca5a5;
}

.building-item {
    cursor: pointer;
}

.building-item.selected {
    border-color: #0b3b82;
    background: #edf3fc;
}

.building-item-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}

.building-item button {
    padding: 9px 10px;
    font-size: 12px;
}

.save-button {
    width: 100%;
    padding: 15px;
    background: #0b3b82;
    color: #fff;
    font-size: 16px;
}

.save-button:hover {
    background: #174f9e;
}

.notice {
    padding: 15px 18px;
    margin-bottom: 22px;
    border: 1px solid #c8e8d0;
    border-radius: 8px;
    background: #eaf7ed;
    color: #176533;
    font-size: 14px;
    line-height: 1.6;
}

.notice.error {
    border-color: #f0c5c5;
    background: #fff0f0;
    color: #a32129;
}

.empty-text {
    margin: 0;
    color: #43536b;
    font-size: 14px;
    line-height: 1.7;
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

    .editor-grid {
        grid-template-columns: minmax(0, 1fr);
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

    .sidebar a {
        display: inline-block;
        margin: 2px;
        padding: 10px;
    }

    .dashboard-content {
        padding: 22px 16px;
    }

    .welcome h1 {
        font-size: 28px;
    }

    .panel {
        padding: 20px 16px;
    }

    .panel h2 {
        font-size: 21px;
    }
}

@media (max-width: 380px) {
    .logo h2 {
        font-size: 18px;
    }

    .field-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }
}

/* MODALS (unsaved changes + delete confirmation) */

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(23, 43, 77, 0.55);
}

.modal-backdrop.open {
    display: flex;
}

.modal-box {
    width: 100%;
    max-width: 440px;
    padding: 28px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 20px 50px rgba(11, 59, 130, 0.3);
}

.modal-icon {
    margin-bottom: 10px;
    font-size: 34px;
}

.modal-box h2 {
    margin: 0 0 10px;
    color: #0b3b82;
    font-size: 22px;
    font-weight: 800;
}

.modal-box p {
    margin: 0 0 22px;
    color: #43536b;
    font-size: 15px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.modal-box p.modal-extra {
    margin: -10px 0 22px;
    padding: 10px 12px;
    border-radius: 8px;
    background: #fffbea;
    border: 1px solid #f0dc8a;
    color: #684d00;
    font-size: 13px;
    line-height: 1.5;
}

.modal-box p.modal-extra[hidden] {
    display: none;
}

.modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 10px;
}
</style>
<link rel="stylesheet" href="../mobile.css">
</head>

<!-- data-no-bg: admin pages use solid backgrounds, so no slideshow here -->
<body data-no-bg>

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
        <a href="facilities.php">🗺️ Facilities</a>
    </aside>

    <main class="dashboard-content">
        <section class="welcome">
            <span class="eyebrow">ADMINISTRATION</span>
            <h1>Campus Layout Editor</h1>
            <p>Arrange the buildings of City of Malabon University on the campus layout.</p>
        </section>

        <?php if ($message !== ""): ?>
            <div class="notice">
                <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="notice error">
                <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="layoutForm">
            <input type="hidden" name="save_layout" value="1">

            <div class="editor-grid">
                <section>
                    <div class="panel">
                        <h2>Campus Layout</h2>
                        <p class="panel-help">
                            Drag buildings to move them. Drag the small square at the bottom-right corner to resize.
                            With Auto Align on, a building snaps to the edges and centers of other buildings and to the
                            map edges (hold Alt while dragging to move freely).
                            You can enter decimal values such as 149.21 in the details panel. Use Undo to go back one step.
                        </p>

                        <div class="toolbar">
                            <button type="button" class="btn-blue" onclick="addBuilding()">
                                Add Building
                            </button>
                            <button type="button" class="btn-light" id="elementToggleBtn" aria-expanded="false" aria-controls="elementsMenu">
                                Element
                            </button>
                            <div class="elements-menu" id="elementsMenu" hidden>
                                <button type="button" class="btn-light" onclick="addCampusElement('Court', 280, 150)">Court</button>
                                <button type="button" class="btn-light" onclick="addCampusElement('Parking Lot', 260, 160)">Parking Lot</button>
                                <button type="button" class="btn-light" onclick="addCampusElement('Campus Ground', 1200, 650)">Campus Ground</button>
                            </div>

                            <button type="button" class="btn-light" id="undoBtn"
                                    title="Undo the last change (Ctrl+Z)" disabled>
                                Undo
                            </button>

                            <label class="align-toggle" title="Snap buildings to each other while moving">
                                <input type="checkbox" id="autoAlign" checked>
                                Auto Align
                            </label>
                        </div>

                        <div class="canvas-wrap">
                            <div id="canvas"></div>
                        </div>
                    </div>

                    <div class="panel">
                        <h2>Buildings in Layout</h2>
                        <p class="panel-help">Only actual buildings appear here. Court, parking lot, ground, and border are campus layout elements; select them on the canvas to edit or delete them.</p>
                        <div id="buildingList" class="building-list"></div>
                    </div>
                </section>

                <aside>
                    <div class="panel">
                        <h2 id="detailsTitle">Building Details</h2>
                        <p class="panel-help" id="detailsHelp">Edit the selected building's information and dimensions.</p>
                        <div id="details">
                            <p class="empty-text">Select a building or add a new one.</p>
                        </div>
                    </div>

                    <div class="panel">
                        <h2>Save Changes</h2>
                        <p class="panel-help">Save the building positions and details to the database.</p>
                        <button type="submit" class="save-button">Save Campus Layout</button>
                    </div>
                </aside>
            </div>
        </form>

        <!-- Separate form (forms cannot be nested) used by the delete confirmation -->
        <form method="POST" id="deleteBuildingForm">
            <input type="hidden" name="delete_building" value="1">
            <input type="hidden" name="delete_building_id" id="deleteBuildingId" value="">
        </form>
    </main>
</div>

<footer>
    <p>© 2026 CMU Campus Navigator | City of Malabon University</p>
</footer>

<!-- DELETE CONFIRMATION MODAL -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="deleteTitle">
        <div class="modal-icon">🗑️</div>
        <h2 id="deleteTitle">Delete Campus Element?</h2>
        <p id="deleteMessage">Are you sure you want to delete this campus element?</p>
        <p class="modal-extra" id="deleteExtra" hidden>
            You also have other unsaved changes in the layout. They will be lost when the page reloads.
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-light" id="deleteCancelBtn">Cancel</button>
            <button type="button" class="btn-red" id="deleteConfirmBtn">Yes, Delete</button>
        </div>
    </div>
</div>

<!-- UNSAVED CHANGES MODAL -->
<div class="modal-backdrop" id="unsavedModal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="unsavedTitle">
        <div class="modal-icon">⚠️</div>
        <h2 id="unsavedTitle">Unsaved Changes</h2>
        <p>
            You have unsaved changes in the building editing section.
            If you leave this page now, your changes will be lost.
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-blue" id="stayBtn">Stay on Page</button>
            <button type="button" class="btn-red" id="leaveBtn">Leave Without Saving</button>
        </div>
    </div>
</div>

<script>
let buildings = <?= json_encode(
    $savedBuildings,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

let selectedId = null;
let editingStatusId = null;
let nextId = 1;

const CANVAS_W = 1200;
const CANVAS_H = 650;

/* Auto align: how close (in screen pixels) before a building snaps */
const SNAP_DISTANCE = 8;

buildings = buildings.map((building, index) => ({
    ...building,
    id: index + 1,
    databaseId: Number(building.databaseId || 0),
    status: building.status === "Inactive" ? "Inactive" : "Active",
    roomCount: Number(building.roomCount || 0),
    x: Number(building.x),
    y: Number(building.y),
    w: Number(building.w),
    h: Number(building.h),
    rotation: Number(building.rotation)
}));

if (buildings.length) {
    nextId = buildings.length + 1;
    selectedId = buildings[0].id;
}

// Snapshot of the saved state, used to detect unsaved changes
function layoutSnapshot() {
    return JSON.stringify(buildings.map(b => [
        b.databaseId, b.name, b.status, b.floors, b.shape,
        b.x, b.y, b.w, b.h, b.rotation
    ]));
}

const savedSnapshot = layoutSnapshot();

function hasUnsavedChanges() {
    return layoutSnapshot() !== savedSnapshot;
}

// Undo history: each entry is a snapshot taken before a change
const undoHistory = [];
const UNDO_LIMIT = 50;

function cloneState() {
    return JSON.stringify({ buildings: buildings, selectedId: selectedId });
}

function updateUndoButton() {
    const button = document.getElementById("undoBtn");
    if (button) button.disabled = undoHistory.length === 0;
}

function pushHistory(snapshot) {
    undoHistory.push(snapshot || cloneState());
    if (undoHistory.length > UNDO_LIMIT) undoHistory.shift();
    updateUndoButton();
}

function undo() {
    if (!undoHistory.length) return;

    const state = JSON.parse(undoHistory.pop());
    buildings = state.buildings;
    selectedId = state.selectedId;

    render();
    renderDetails();
    updateUndoButton();
}

// One undo step per edit of a details field (not one per keystroke)
function trackEdits(input) {
    if (!input) return;

    let before = null;
    let pushed = false;

    input.addEventListener("focus", () => {
        before = cloneState();
        pushed = false;
    });

    input.addEventListener("input", () => {
        if (pushed) return;
        pushHistory(before || cloneState());
        pushed = true;
    });
}

const canvas = document.getElementById("canvas");
const details = document.getElementById("details");
const buildingList = document.getElementById("buildingList");
const layoutForm = document.getElementById("layoutForm");
const autoAlign = document.getElementById("autoAlign");

function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, character => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    }[character]));
}

function selectedBuilding() {
    return buildings.find(building => building.id === selectedId);
}

/* ---------- AUTO ALIGN ---------- */

/* Lines a moving building can snap to: map edges/center + other buildings */
function snapTargets(movingId) {
    const xs = [0, CANVAS_W / 2, CANVAS_W];
    const ys = [0, CANVAS_H / 2, CANVAS_H];

    buildings.forEach(other => {
        if (other.id === movingId) return;

        xs.push(other.x, other.x + other.w / 2, other.x + other.w);
        ys.push(other.y, other.y + other.h / 2, other.y + other.h);
    });

    return { xs, ys };
}

/* The smallest move that lines a set of edges up with a target (or 0) */
function bestOffset(edges, targets, threshold) {
    let best = null;

    edges.forEach(edge => {
        targets.forEach(target => {
            const difference = target - edge;

            if (
                Math.abs(difference) <= threshold &&
                (best === null || Math.abs(difference) < Math.abs(best))
            ) {
                best = difference;
            }
        });
    });

    return best === null ? 0 : best;
}

function clampX(value, building) {
    return Math.max(0, Math.min(CANVAS_W - building.w, value));
}

function clampY(value, building) {
    return Math.max(0, Math.min(CANVAS_H - building.h, value));
}

/* Snap a proposed position and return the new one */
function snapPosition(building, x, y, scale) {
    const threshold = SNAP_DISTANCE / scale;
    const targets = snapTargets(building.id);

    const dx = bestOffset(
        [x, x + building.w / 2, x + building.w],
        targets.xs,
        threshold
    );

    const dy = bestOffset(
        [y, y + building.h / 2, y + building.h],
        targets.ys,
        threshold
    );

    return {
        x: clampX(x + dx, building),
        y: clampY(y + dy, building)
    };
}

function clearGuides() {
    canvas.querySelectorAll(".align-guide").forEach(guide => guide.remove());
}

/* Draw a pink line wherever the moving building is lined up with something */
function drawGuides(building) {
    clearGuides();

    const targets = snapTargets(building.id);
    const drawn = new Set();

    [building.x, building.x + building.w / 2, building.x + building.w].forEach(edge => {
        targets.xs.forEach(target => {
            if (Math.abs(target - edge) < 0.5 && !drawn.has("x" + target)) {
                drawn.add("x" + target);

                const guide = document.createElement("div");
                guide.className = "align-guide vertical";
                guide.style.left = target + "px";
                canvas.appendChild(guide);
            }
        });
    });

    [building.y, building.y + building.h / 2, building.y + building.h].forEach(edge => {
        targets.ys.forEach(target => {
            if (Math.abs(target - edge) < 0.5 && !drawn.has("y" + target)) {
                drawn.add("y" + target);

                const guide = document.createElement("div");
                guide.className = "align-guide horizontal";
                guide.style.top = target + "px";
                canvas.appendChild(guide);
            }
        });
    });
}

/* ---------- BUILDINGS ---------- */

function addBuilding(presetName = "New Building", presetShape = "rectangle", presetWidth = 140, presetHeight = 90) {
    pushHistory();
    const isGround = /ground/i.test(presetName || "");

    const building = {
        id: nextId++,
        databaseId: 0,
        roomCount: 0,
        name: presetName,
        status: "Active",
        floors: 1,
        shape: presetShape,
        x: isGround ? 0 : Math.min(50 + buildings.length * 20, 900),
        y: isGround ? 0 : Math.min(50 + buildings.length * 20, 450),
        w: isGround ? CANVAS_W : presetWidth,
        h: isGround ? CANVAS_H : presetHeight,
        rotation: 0
    };

    buildings.push(building);
    if (!isGround) selectedId = building.id;
    render();
    renderDetails();
}

function addCampusElement(name, width, height) {
    addBuilding(name, "rectangle", width, height);
    const menu = document.getElementById("elementsMenu");
    const toggle = document.getElementById("elementToggleBtn");
    if (menu) menu.hidden = true;
    if (toggle) toggle.setAttribute("aria-expanded", "false");
}

const elementToggleBtn = document.getElementById("elementToggleBtn");
const elementsMenu = document.getElementById("elementsMenu");
if (elementToggleBtn && elementsMenu) {
    elementToggleBtn.addEventListener("click", () => {
        elementsMenu.hidden = !elementsMenu.hidden;
        elementToggleBtn.setAttribute("aria-expanded", String(!elementsMenu.hidden));
    });
}

function selectBuilding(id) {
    selectedId = id;
    render();
    renderDetails();
}

function updateShape(building) {
    const element = canvas.querySelector('[data-id="' + building.id + '"]');
    if (!element) return;

    element.style.left = building.x + "px";
    element.style.top = building.y + "px";
    element.style.width = building.w + "px";
    element.style.height = building.h + "px";
    element.style.transform = "rotate(" + building.rotation + "deg)";
    const isCourt = /court/i.test(building.name || "");
    const isParking = /parking/i.test(building.name || "");
    const isGround = /ground/i.test(building.name || "");
    const isSpikedWall = /(spiked wall|spiked border|boundary wall|campus border)/i.test(building.name || "");
    element.className = "building-shape " + building.shape +
        (isCourt ? " court-shape" : "") +
        (isParking ? " parking-shape" : "") +
        (isGround ? " ground-shape" : "") +
        (isSpikedWall ? " spiked-wall-shape" : "") +
        (building.status === "Inactive" ? " status-inactive" : " status-active") +
        (building.id === selectedId ? " selected" : "");

    const label = element.querySelector(".shape-label");
    if (label) label.textContent = building.name || "Unnamed Building";
}

function updateSelectedStyles() {
    canvas.querySelectorAll(".building-shape").forEach(element => {
        const isSelected = Number(element.dataset.id) === Number(selectedId);
        element.classList.toggle("selected", isSelected);
    });
}

function render() {
    canvas.innerHTML = "";

    buildings.forEach(building => {
        const shape = document.createElement("div");
        shape.dataset.id = building.id;
        const isCourt = /court/i.test(building.name || "");
        const isParking = /parking/i.test(building.name || "");
        const isGround = /ground/i.test(building.name || "");
        const isSpikedWall = /(spiked wall|spiked border|boundary wall|campus border)/i.test(building.name || "");
        shape.className = "building-shape " + building.shape +
            (isCourt ? " court-shape" : "") +
            (isParking ? " parking-shape" : "") +
            (isGround ? " ground-shape" : "") +
            (isSpikedWall ? " spiked-wall-shape" : "") +
            (building.status === "Inactive" ? " status-inactive" : " status-active") +
            (building.id === selectedId ? " selected" : "");

        shape.style.left = building.x + "px";
        shape.style.top = building.y + "px";
        shape.style.width = building.w + "px";
        shape.style.height = building.h + "px";
        shape.style.transform = "rotate(" + building.rotation + "deg)";

        const label = document.createElement("span");
        label.className = "shape-label";
        label.textContent = building.name || "Unnamed Building";

        const handle = document.createElement("div");
        handle.className = "resize-handle";
        handle.title = "Drag to resize";

        if (isCourt) {
            const court = document.createElement("div");
            court.className = "court-graphics";
            court.innerHTML = `
                <div class="court-surface">
                    <div class="half-court-line"></div>
                    <div class="center-circle"></div>
                    <div class="key left-key">
                        <div class="free-throw-circle left-circle"></div>
                        <div class="hoop left-hoop"></div>
                        <div class="backboard left-backboard"></div>
                    </div>
                    <div class="three-point-line left-three"></div>
                    <div class="key right-key">
                        <div class="free-throw-circle right-circle"></div>
                        <div class="hoop right-hoop"></div>
                        <div class="backboard right-backboard"></div>
                    </div>
                    <div class="three-point-line right-three"></div>
                </div>
            `;
            shape.appendChild(court);
        }

        if (isParking) {
            const parking = document.createElement("div");
            parking.className = "parking-graphics";
            parking.innerHTML = `
                <div class="parking-surface">
                    <div class="parking-area">
                        <div class="parking-slot"></div>
                        <div class="parking-slot"></div>
                    </div>
                </div>
            `;
            shape.appendChild(parking);
        }

        // Keep the court and parking lot surfaces clean; their names remain in the details/list.
        if (!isCourt && !isParking && !isGround && !isSpikedWall) {
            shape.appendChild(label);
        }
        shape.appendChild(handle);
        canvas.appendChild(shape);

        shape.addEventListener("pointerdown", event => {
            if (isGround || event.target === handle) return;

            event.preventDefault();

            selectedId = building.id;
            updateSelectedStyles();
            renderList();
            renderDetails();

            const startX = event.clientX;
            const startY = event.clientY;
            const originalX = building.x;
            const originalY = building.y;
            const scale = canvas.getBoundingClientRect().width / CANVAS_W;

            shape.setPointerCapture(event.pointerId);

            const before = cloneState();
            let moved = false;

            function move(moveEvent) {
                if (!moved) {
                    pushHistory(before);
                    moved = true;
                }

                let newX = clampX(
                    originalX + (moveEvent.clientX - startX) / scale,
                    building
                );

                let newY = clampY(
                    originalY + (moveEvent.clientY - startY) / scale,
                    building
                );

                /* Auto align (hold Alt to move freely) */
                const snapping = autoAlign.checked && !moveEvent.altKey;

                if (snapping) {
                    const snapped = snapPosition(building, newX, newY, scale);
                    newX = snapped.x;
                    newY = snapped.y;
                }

                building.x = newX;
                building.y = newY;

                updateShape(building);

                if (snapping) {
                    drawGuides(building);
                } else {
                    clearGuides();
                }

                updatePositionFields(building);
                updateHiddenInputs();
            }

            function stop() {
                clearGuides();

                shape.removeEventListener("pointermove", move);
                shape.removeEventListener("pointerup", stop);
                shape.removeEventListener("pointercancel", stop);
            }

            shape.addEventListener("pointermove", move);
            shape.addEventListener("pointerup", stop);
            shape.addEventListener("pointercancel", stop);
        });

        handle.addEventListener("pointerdown", event => {
            event.preventDefault();
            event.stopPropagation();

            selectedId = building.id;
            updateSelectedStyles();
            renderList();
            renderDetails();

            const startX = event.clientX;
            const startY = event.clientY;
            const originalW = building.w;
            const originalH = building.h;
            const scale = canvas.getBoundingClientRect().width / CANVAS_W;

            handle.setPointerCapture(event.pointerId);

            const before = cloneState();
            let resized = false;

            function resize(moveEvent) {
                if (!resized) {
                    pushHistory(before);
                    resized = true;
                }

                building.w = Math.max(
                    40,
                    Math.min(CANVAS_W - building.x,
                        originalW + (moveEvent.clientX - startX) / scale)
                );

                building.h = Math.max(
                    40,
                    Math.min(CANVAS_H - building.y,
                        originalH + (moveEvent.clientY - startY) / scale)
                );

                updateShape(building);
                updateSizeFields(building);
                updateHiddenInputs();
            }

            function stopResize() {
                handle.removeEventListener("pointermove", resize);
                handle.removeEventListener("pointerup", stopResize);
                handle.removeEventListener("pointercancel", stopResize);
            }

            handle.addEventListener("pointermove", resize);
            handle.addEventListener("pointerup", stopResize);
            handle.addEventListener("pointercancel", stopResize);
        });
    });

    renderList();
    updateHiddenInputs();
}

function isCampusElement(building) {
    return /\b(court|parking lot|campus ground|ground|spiked wall|spiked border|boundary wall|campus border)\b/i.test(building.name || "");
}

function campusElementType(building) {
    const name = String(building.name || "").toLowerCase();
    if (/court/.test(name)) return "Court";
    if (/parking/.test(name)) return "Parking Lot";
    if (/ground/.test(name)) return "Campus Ground";
    if (/(spiked wall|spiked border|boundary wall|campus border)/.test(name)) return "Campus Border";
    return "Campus Element";
}

function renderList() {
    buildingList.innerHTML = "";
    const actualBuildings = buildings.filter(building => !isCampusElement(building));

    if (!actualBuildings.length) {
        buildingList.innerHTML = '<p class="empty-text">No buildings in the layout yet.</p>';
        return;
    }

    actualBuildings.forEach(building => {
        const item = document.createElement("div");
        item.className = "building-item" +
            (building.id === selectedId ? " selected" : "");
        item.addEventListener("click", () => selectBuilding(building.id));

        const name = document.createElement("span");
        name.className = "building-item-name";
        name.textContent = building.name || "Unnamed Building";

        const statusBadge = document.createElement("span");
        const currentStatus = building.status === "Inactive" ? "Inactive" : "Active";
        statusBadge.className = "building-status-badge " + (currentStatus === "Active" ? "status-active" : "status-inactive");
        statusBadge.textContent = currentStatus;

        const nameAndStatus = document.createElement("div");
        nameAndStatus.className = "building-item-main";
        nameAndStatus.appendChild(name);
        nameAndStatus.appendChild(statusBadge);

        const actions = document.createElement("div");
        actions.className = "building-item-actions";

        const edit = document.createElement("button");
        edit.type = "button";
        edit.className = "btn-light";
        edit.textContent = editingStatusId === building.id ? "Done" : "Edit";
        edit.addEventListener("click", event => {
            event.stopPropagation();
            editingStatusId = editingStatusId === building.id ? null : building.id;
            selectBuilding(building.id);
            renderList();
        });

        actions.appendChild(edit);
        item.appendChild(nameAndStatus);

        if (editingStatusId === building.id) {
            const statusLabel = document.createElement("label");
            statusLabel.className = "building-status-edit-label";
            statusLabel.textContent = "Status:";
            statusLabel.htmlFor = "buildingStatusEdit" + building.id;

            const statusSelect = document.createElement("select");
            statusSelect.id = "buildingStatusEdit" + building.id;
            statusSelect.className = "building-status-edit";
            statusSelect.innerHTML = `
                <option value="Active" ${building.status === "Active" ? "selected" : ""}>Active</option>
                <option value="Inactive" ${building.status === "Inactive" ? "selected" : ""}>Inactive</option>
            `;
            statusSelect.addEventListener("click", event => event.stopPropagation());
            statusSelect.addEventListener("change", event => {
                building.status = event.target.value === "Inactive" ? "Inactive" : "Active";
                render();
                if (selectedId === building.id) renderDetails();
            });

            item.appendChild(statusLabel);
            item.appendChild(statusSelect);
        }

        item.appendChild(actions);
        buildingList.appendChild(item);
    });
}

/* ---------- DELETE (with confirmation) ---------- */

const deleteModal = document.getElementById("deleteModal");
const deleteMessage = document.getElementById("deleteMessage");
const deleteExtra = document.getElementById("deleteExtra");
const deleteCancelBtn = document.getElementById("deleteCancelBtn");
const deleteConfirmBtn = document.getElementById("deleteConfirmBtn");
const deleteBuildingForm = document.getElementById("deleteBuildingForm");
const deleteBuildingId = document.getElementById("deleteBuildingId");

let pendingDeleteId = null;

/* Step 1: the Delete button opens the confirmation */
function requestDeleteBuilding(id) {
    const building = buildings.find(item => item.id === id);

    if (!building || !isCampusElement(building)) return;

    pendingDeleteId = id;

    const name = building.name || "Unnamed Building";

    if (building.databaseId > 0) {
        deleteMessage.textContent = "Delete campus element “" + name + "” from the layout? Actual buildings and their rooms will not be deleted.";
        deleteExtra.hidden = !hasUnsavedChanges();
    } else {
        deleteMessage.textContent = "Remove campus element “" + name + "” from the layout?";
        deleteExtra.hidden = true;
    }

    deleteConfirmBtn.disabled = false;
    deleteModal.classList.add("open");
    deleteCancelBtn.focus();
}

function closeDeleteModal() {
    deleteModal.classList.remove("open");
    pendingDeleteId = null;
}

/* Step 2: "Yes, Delete" really deletes */
function confirmDeleteBuilding() {
    const id = pendingDeleteId;
    const building = buildings.find(item => item.id === id);

    if (!building || !isCampusElement(building)) {
        closeDeleteModal();
        return;
    }

    if (building.databaseId > 0) {
        /* Only a recognized campus element can reach the server delete handler. */
        deleteConfirmBtn.disabled = true;
        deleteBuildingId.value = building.databaseId;
        deleteBuildingForm.submit();
        return;
    }

    /* New building that was never saved: only remove it from the editor */
    pushHistory();

    buildings = buildings.filter(item => item.id !== id);

    if (selectedId === id) {
        selectedId = buildings.length ? buildings[0].id : null;
    }

    closeDeleteModal();
    render();
    renderDetails();
}

deleteCancelBtn.addEventListener("click", closeDeleteModal);
deleteConfirmBtn.addEventListener("click", confirmDeleteBuilding);

deleteModal.addEventListener("click", event => {
    if (event.target === deleteModal) closeDeleteModal();
});

function renderDetails() {
    const building = selectedBuilding();

    if (!building) {
        details.innerHTML = '<p class="empty-text">Select a building or add a new one.</p>';
        return;
    }

    const isElement = isCampusElement(building);
    const title = document.getElementById("detailsTitle");
    const help = document.getElementById("detailsHelp");
    if (title) title.textContent = isElement ? campusElementType(building) + " Details" : "Building Details";
    if (help) help.textContent = isElement
        ? "Edit this campus element's name and dimensions, or remove it from the layout."
        : "Edit the selected building's information and dimensions.";

    details.innerHTML = `
        ${isElement ? `<div class="layout-category-badge">${escapeHtml(campusElementType(building))}</div>` : ""}
        <div class="field">
            <label for="detailName">${isElement ? "Element Name" : "Building Name"}</label>
            <input type="text" id="detailName" value="${escapeHtml(building.name)}">
        </div>

        ${isElement ? "" : `
        <div class="field">
            <label for="detailFloors">Total Floors</label>
            <input type="number" id="detailFloors" min="1" step="1" value="${building.floors}">
        </div>
        `}

        <div class="field">
            <label for="detailStatus">Building Status</label>
            <select id="detailStatus">
                <option value="Active" ${building.status === "Active" ? "selected" : ""}>Active</option>
                <option value="Inactive" ${building.status === "Inactive" ? "selected" : ""}>Inactive</option>
            </select>
        </div>

        <div class="field-grid">
            <div class="field">
                <label for="detailX">Position X</label>
                <input type="number" id="detailX" step="any" value="${building.x}">
            </div>
            <div class="field">
                <label for="detailY">Position Y</label>
                <input type="number" id="detailY" step="any" value="${building.y}">
            </div>
        </div>

        <div class="field-grid">
            <div class="field">
                <label for="detailWidth">Width</label>
                <input type="number" id="detailWidth" min="40" step="any" value="${building.w}">
            </div>
            <div class="field">
                <label for="detailHeight">Height</label>
                <input type="number" id="detailHeight" min="40" step="any" value="${building.h}">
            </div>
        </div>

        <div class="field">
            <label for="detailRotation">Rotation (degrees)</label>
            <input type="number" id="detailRotation" step="any" value="${building.rotation}">
        </div>

        ${isElement ? `<button type="button" class="details-delete-button" id="deleteSelectedElement">🗑 Delete Campus Element</button>` : ""}
    `;

    ["detailName", "detailFloors", "detailStatus", "detailX", "detailY",
     "detailWidth", "detailHeight", "detailRotation"].forEach(id => {
        trackEdits(document.getElementById(id));
    });

    const deleteElementButton = document.getElementById("deleteSelectedElement");
    if (deleteElementButton && isElement) {
        deleteElementButton.addEventListener("click", () => {
            requestDeleteBuilding(building.id);
        });
    }

    document.getElementById("detailName").addEventListener("input", event => {
        building.name = event.target.value;
        updateShape(building);
        renderList();
        updateHiddenInputs();
    });

    const floorsInput = document.getElementById("detailFloors");
    if (floorsInput) {
        floorsInput.addEventListener("input", event => {
            building.floors = Number(event.target.value || 1);
            updateHiddenInputs();
        });
    }

    document.getElementById("detailStatus").addEventListener("change", event => {
        building.status = event.target.value === "Inactive" ? "Inactive" : "Active";
        render();
        updateHiddenInputs();
    });

    ["X", "Y", "Width", "Height", "Rotation"].forEach(label => {
        const property = {
            X: "x",
            Y: "y",
            Width: "w",
            Height: "h",
            Rotation: "rotation"
        }[label];

        const input = document.getElementById("detail" + label);

        input.addEventListener("input", event => {
            const value = event.target.value;
            building[property] = value === "" ? 0 : Number(value);
            updateShape(building);
            updateHiddenInputs();
        });
    });
}

function updatePositionFields(building) {
    if (building.id !== selectedId) return;

    const x = document.getElementById("detailX");
    const y = document.getElementById("detailY");

    if (x && document.activeElement !== x) {
        x.value = building.x.toFixed(2).replace(/\.?0+$/, "");
    }

    if (y && document.activeElement !== y) {
        y.value = building.y.toFixed(2).replace(/\.?0+$/, "");
    }
}

function updateSizeFields(building) {
    if (building.id !== selectedId) return;

    const width = document.getElementById("detailWidth");
    const height = document.getElementById("detailHeight");

    if (width && document.activeElement !== width) {
        width.value = building.w.toFixed(2).replace(/\.?0+$/, "");
    }

    if (height && document.activeElement !== height) {
        height.value = building.h.toFixed(2).replace(/\.?0+$/, "");
    }
}

function updateHiddenInputs() {
    document.querySelectorAll(".generated-input").forEach(input => input.remove());

    buildings.forEach(building => {
        const fields = {
            building_id: building.databaseId || 0,
            building_name: building.name || "",
            status: building.status || "Active",
            total_floors: building.floors || 1,
            shape_type: building.shape || "rectangle",
            position_x: building.x,
            position_y: building.y,
            width: building.w,
            height: building.h,
            rotation: building.rotation
        };

        Object.entries(fields).forEach(([key, value]) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = key + "[]";
            input.value = value;
            input.className = "generated-input";
            layoutForm.appendChild(input);
        });
    });
}

layoutForm.addEventListener("submit", event => {
    if (!buildings.length) {
        event.preventDefault();
        alert("Magdagdag muna ng kahit isang building.");
        return;
    }

    for (const building of buildings) {
        if (!String(building.name || "").trim()) {
            event.preventDefault();
            alert("Pakilagay ang pangalan ng bawat building.");
            selectBuilding(building.id);
            return;
        }

        const values = [
            building.x,
            building.y,
            building.w,
            building.h,
            building.rotation
        ];

        if (!values.every(value => Number.isFinite(Number(value)))) {
            event.preventDefault();
            alert("Pakisuri ang position, width, height, at rotation values.");
            selectBuilding(building.id);
            return;
        }
    }

    updateHiddenInputs();
});

// Warn about unsaved changes when clicking a sidebar link
const unsavedModal = document.getElementById("unsavedModal");
const stayBtn = document.getElementById("stayBtn");
const leaveBtn = document.getElementById("leaveBtn");
let pendingLink = null;
let allowLeave = false;

function openUnsavedModal(link) {
    pendingLink = link;
    unsavedModal.classList.add("open");
    stayBtn.focus();
}

function closeUnsavedModal() {
    unsavedModal.classList.remove("open");
    pendingLink = null;
}

document.querySelectorAll(".sidebar a").forEach(link => {
    link.addEventListener("click", event => {
        if (allowLeave || !hasUnsavedChanges()) return;

        // Let new-tab clicks through
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;

        event.preventDefault();
        openUnsavedModal(link);
    });
});

stayBtn.addEventListener("click", closeUnsavedModal);

leaveBtn.addEventListener("click", () => {
    const link = pendingLink;
    allowLeave = true;
    unsavedModal.classList.remove("open");
    if (link) link.click(); // fade.js handles the page transition
});

unsavedModal.addEventListener("click", event => {
    if (event.target === unsavedModal) closeUnsavedModal();
});

document.addEventListener("keydown", event => {
    if (event.key !== "Escape") return;

    if (deleteModal.classList.contains("open")) {
        closeDeleteModal();
    } else if (unsavedModal.classList.contains("open")) {
        closeUnsavedModal();
    }
});

// Undo button and Ctrl+Z (Cmd+Z on Mac)
document.getElementById("undoBtn").addEventListener("click", undo);

document.addEventListener("keydown", event => {
    const tag = (event.target.tagName || "").toLowerCase();
    const typing = tag === "input" || tag === "textarea" || tag === "select";

    if ((event.ctrlKey || event.metaKey) && !event.shiftKey &&
        event.key.toLowerCase() === "z" && !typing &&
        !unsavedModal.classList.contains("open") &&
        !deleteModal.classList.contains("open")) {
        event.preventDefault();
        undo();
    }
});

render();
renderDetails();
updateUndoButton();
</script>

</body>
</html>