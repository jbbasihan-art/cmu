
<?php
session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

require_once "../config/database.php";

$hallwaySectionStorageAvailable = true;
try {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS navigation_point_sections (
            navigation_point_id BIGINT NOT NULL,
            section_number INTEGER NOT NULL,
            section_name VARCHAR(120) NOT NULL DEFAULT '',
            photo_path TEXT NOT NULL,
            marker_x NUMERIC(5,2) NOT NULL DEFAULT 50,
            marker_y NUMERIC(5,2) NOT NULL DEFAULT 50,
            PRIMARY KEY (navigation_point_id, section_number)
        )"
    );
    $conn->exec(
        "ALTER TABLE navigation_point_sections
         DROP CONSTRAINT IF EXISTS navigation_point_sections_section_number_check"
    );
    $conn->exec(
        "ALTER TABLE navigation_point_sections
         ALTER COLUMN section_number TYPE INTEGER"
    );
} catch (Throwable $ex) {
    $hallwaySectionStorageAvailable = false;
    error_log("Hallway section table setup error: " . $ex->getMessage());
}

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function getUploadAt($field, $index = null) {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }

    if ($index === null) {
        return $_FILES[$field];
    }

    $upload = $_FILES[$field];
    if (!isset($upload["error"][$index])) {
        return null;
    }

    return [
        "name" => $upload["name"][$index] ?? "",
        "type" => $upload["type"][$index] ?? "",
        "tmp_name" => $upload["tmp_name"][$index] ?? "",
        "error" => $upload["error"][$index] ?? UPLOAD_ERR_NO_FILE,
        "size" => $upload["size"][$index] ?? 0
    ];
}

function storeRoomAttachment($upload) {
    if (!$upload || (int)($upload["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int)($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("The attachment upload did not complete.");
    }

    $maxBytes = 10 * 1024 * 1024;
    if ((int)($upload["size"] ?? 0) <= 0 || (int)$upload["size"] > $maxBytes) {
        throw new RuntimeException("JPG/JPEG images must be smaller than 10 MB.");
    }

    $extension = strtolower(pathinfo((string)($upload["name"] ?? ""), PATHINFO_EXTENSION));
    $allowedTypes = [
        "jpg"  => ["image/jpeg"],
        "jpeg" => ["image/jpeg"]
    ];

    if (!isset($allowedTypes[$extension])) {
        throw new RuntimeException("Choose a JPG or JPEG image only.");
    }

    $temporaryPath = (string)($upload["tmp_name"] ?? "");
    if ($temporaryPath === "" || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException("The uploaded file could not be verified.");
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    if (!in_array($mimeType, $allowedTypes[$extension], true)) {
        throw new RuntimeException("The file contents do not match the selected file type.");
    }

    $directory = __DIR__ . "/../uploads/room-attachments";
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("The attachment folder could not be created.");
    }

    $filename = bin2hex(random_bytes(16)) . "." . $extension;
    if (!move_uploaded_file($temporaryPath, $directory . "/" . $filename)) {
        throw new RuntimeException("The uploaded file could not be saved.");
    }

    return "uploads/room-attachments/" . $filename;
}

function storeHallwaySectionPhoto($upload) {
    if (!$upload || (int)($upload["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $extension = strtolower(pathinfo((string)($upload["name"] ?? ""), PATHINFO_EXTENSION));
    if (!in_array($extension, ["jpg", "jpeg", "png", "gif", "webp"], true)) {
        throw new RuntimeException("Hallway sections only accept image files.");
    }

    return storeRoomAttachment($upload);
}

function deleteStoredAttachment($relativePath) {
    $relativePath = str_replace("\\", "/", (string)$relativePath);
    if (!preg_match('#^uploads/room-attachments/[a-f0-9]{32}\.[a-z0-9]{2,5}$#i', $relativePath)) {
        return;
    }

    $path = __DIR__ . "/../" . $relativePath;
    if (is_file($path)) {
        @unlink($path);
    }
}

function cleanupUnusedAttachment(PDO $conn, $relativePath) {
    $relativePath = (string)$relativePath;
    if ($relativePath === "") return;

    $roomStmt = $conn->prepare("SELECT COUNT(*) FROM rooms WHERE walk_links = :path");
    $roomStmt->execute(["path" => $relativePath]);
    $roomReferences = (int)$roomStmt->fetchColumn();

    $hallwayStmt = $conn->prepare(
        "SELECT COUNT(*) FROM navigation_points
         WHERE (walk_links = :walk_path AND location_type = 'Hallway') OR image_url = :image_path"
    );
    $hallwayStmt->execute(["walk_path" => $relativePath, "image_path" => $relativePath]);
    $hallwayReferences = (int)$hallwayStmt->fetchColumn();

    $officeStmt = $conn->prepare("SELECT COUNT(*) FROM offices WHERE room_image_url = :path OR walk_links = :path");
    $officeStmt->execute(["path" => $relativePath]);
    $officeReferences = (int)$officeStmt->fetchColumn();

    $facilityStmt = $conn->prepare("SELECT COUNT(*) FROM facilities WHERE image = :path OR walk_links = :path");
    $facilityStmt->execute(["path" => $relativePath]);
    $facilityReferences = (int)$facilityStmt->fetchColumn();

    $sectionReferences = 0;
    if (!empty($GLOBALS["hallwaySectionStorageAvailable"])) {
        $sectionStmt = $conn->prepare(
            "SELECT COUNT(*) FROM navigation_point_sections WHERE photo_path = :path"
        );
        $sectionStmt->execute(["path" => $relativePath]);
        $sectionReferences = (int)$sectionStmt->fetchColumn();
    }

    if (
        $roomReferences + $hallwayReferences + $officeReferences +
        $facilityReferences + $sectionReferences === 0
    ) {
        deleteStoredAttachment($relativePath);
    }
}

function replaceEntityAttachment(PDO $conn, $kind, $id, $buildingId, $upload, $remove) {
    if (!$upload || (int)($upload["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if (!$remove) return [null, null];
    }

    $sectionPhoto = false;
    if ($kind === "r") {
        $table = "rooms"; $idColumn = "room_id"; $pathColumn = "walk_links"; $extraWhere = "";
    } elseif ($kind === "o") {
        $table = "offices"; $idColumn = "office_id"; $pathColumn = "room_image_url"; $extraWhere = "";
    } elseif ($kind === "f") {
        $table = "facilities"; $idColumn = "facility_id"; $pathColumn = "image"; $extraWhere = "";
    } elseif ($kind === "n") {
        $table = "navigation_points"; $idColumn = "navigation_point_id"; $pathColumn = "image_url"; $extraWhere = "";
    } else {
        return [null, null];
    }

    if ($sectionPhoto) {
        $lookup = $conn->prepare("SELECT photo_path FROM navigation_point_sections WHERE navigation_point_id = :id AND section_number = 0");
        $lookup->execute(["id" => $id]);
        $oldPath = $lookup->fetchColumn();
        if ($oldPath === false) $oldPath = "";
    } else {
        $lookup = $conn->prepare("SELECT {$pathColumn} FROM {$table} WHERE {$idColumn} = :id AND building_id = :building_id{$extraWhere}");
        $lookup->execute(["id" => $id, "building_id" => $buildingId]);
        $oldPath = $lookup->fetchColumn();
        if ($oldPath === false) throw new RuntimeException("The selected item's image record could not be found.");
    }

    $newPath = $upload && (int)($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ? storeRoomAttachment($upload) : null;

    try {
        if ($sectionPhoto) {
            if ($newPath === null) {
                $delete = $conn->prepare("DELETE FROM navigation_point_sections WHERE navigation_point_id = :id AND section_number = 0");
                $delete->execute(["id" => $id]);
            } else {
                $save = $conn->prepare("INSERT INTO navigation_point_sections (navigation_point_id, section_number, section_name, photo_path, marker_x, marker_y) VALUES (:id, 0, 'Main Photo', :path, 50, 50) ON CONFLICT (navigation_point_id, section_number) DO UPDATE SET section_name = EXCLUDED.section_name, photo_path = EXCLUDED.photo_path");
                $save->execute(["id" => $id, "path" => $newPath]);
            }
        } else {
            $update = $conn->prepare("UPDATE {$table} SET {$pathColumn} = :path WHERE {$idColumn} = :id AND building_id = :building_id{$extraWhere}");
            $update->execute(["path" => $newPath, "id" => $id, "building_id" => $buildingId]);
        }
    } catch (Throwable $ex) {
        if ($newPath !== null) deleteStoredAttachment($newPath);
        throw $ex;
    }

    return [$newPath, (string)($oldPath ?: "")];
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

        $floors[] = $i . $suffix . " Floor";
    }

    return $floors;
}

/* "3rd Floor" -> 3 */
function floorNumber($label) {
    return preg_match('/\d+/', (string)$label, $m) ? (int)$m[0] : 0;
}

/* 3 -> "3rd Floor" */
function floorLabel($number) {
    $labels = getFloorOptions($number);
    return $labels ? end($labels) : "";
}

/* Fixed colors for each category */
$categoryColors = [
    "Room"       => "#0b3b82",
    "Offices"    => "#2e9e5b",
    "Facilities" => "#e8772e"
];

/* Room name options depend on the selected Type */
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
        "Male Comfort Room (CR)",
        "Female Comfort Room (CR)",
        "Guardhouse"
    ]
];

$typeOptions = array_keys($roomNameOptions);

/* Suggested category for each preset facility */
$facilityCategoryDefaults = [
    "Library"           => "Academic",
    "Clinic"            => "Health",
    "Canteen"           => "Food",
    "Comfort Room (CR)"        => "Sanitation",
    "Male Comfort Room (CR)"   => "Sanitation",
    "Female Comfort Room (CR)" => "Sanitation",
    "Guardhouse"        => "Security"
];

/* Navigation point types */
$navTypeOptions = [
    "Hallway",
    "Corridor",
    "Staircase",
    "Elevator",
    "Walkway",
    "Other"
];

$staircaseTypeOptions = [
    "Straight",
    "Switchback",
    "Spiral",
    "Other"
];

/* Starting size on floor plan: [width, height] */
$navDefaultSize = [
    "Hallway"   => [400, 40],
    "Corridor"  => [400, 40],
    "Staircase" => [80, 120],
    "Elevator"  => [60, 60],
    "Walkway"   => [300, 30],
    "Other"     => [140, 90]
];

$buildingId = (int)($_GET["building_id"] ?? $_POST["building_id"] ?? 0);
$selectedFloor = trim((string)($_GET["floor"] ?? $_POST["floor"] ?? ""));

/* Type passed from offices.php / facilities.php */
$presetType = trim((string)($_GET["type"] ?? $_POST["type"] ?? ""));

if (!in_array($presetType, $typeOptions, true)) {
    $presetType = "";
}

$presetQuery = $presetType !== "" ? "&type=" . urlencode($presetType) : "";

$message = "";
$error = "";

/* ---------- Buildings ---------- */
$buildings = [];

try {
    // Exclude campus layout elements from the building selector.
    // These records remain in the database; they are only hidden from this list.
    $buildings = $conn->query(
        "SELECT building_id, building_name, total_floors
         FROM buildings
         WHERE NOT (
             LOWER(TRIM(building_name)) LIKE '%court%'
             OR LOWER(TRIM(building_name)) LIKE '%parking lot%'
             OR LOWER(TRIM(building_name)) LIKE '%campus ground%'
             OR LOWER(TRIM(building_name)) LIKE '%spiked border%'
         )
         ORDER BY building_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log("Buildings load error: " . $ex->getMessage());
    $error = "Unable to load buildings.";
}

$selectedBuilding = null;

if ($buildingId > 0) {
    try {
        $stmt = $conn->prepare(
            "SELECT building_id, building_name, total_floors
             FROM buildings
             WHERE building_id = :id
               AND NOT (
                   LOWER(TRIM(building_name)) LIKE '%court%'
                   OR LOWER(TRIM(building_name)) LIKE '%parking lot%'
                   OR LOWER(TRIM(building_name)) LIKE '%campus ground%'
                   OR LOWER(TRIM(building_name)) LIKE '%spiked border%'
               )"
        );
        $stmt->execute(["id" => $buildingId]);
        $selectedBuilding = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $ex) {
        error_log("Building load error: " . $ex->getMessage());
    }

    if (!$selectedBuilding) {
        $buildingId = 0;
        $selectedFloor = "";
    }
}

$floors = [];

if ($buildingId > 0 && $selectedBuilding) {
    $floors = getFloorOptions($selectedBuilding["total_floors"]);

    if (
        $selectedFloor !== "" &&
        !in_array($selectedFloor, $floors, true)
    ) {
        $selectedFloor = "";
    }
}

/* ---------- Add a room / office / facility ---------- */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["add_room"])
) {
    $roomType = trim($_POST["new_room_type"] ?? "");
    $roomNumber = trim($_POST["new_room_number"] ?? "");
    $roomNameChoice = trim($_POST["new_room_name"] ?? "");
    $roomNameOther = trim($_POST["new_room_name_other"] ?? "");
    $selectedFloor = trim($_POST["floor"] ?? "");

    $width = 140;
    $height = 90;
    $shape = "rectangle";
    $rotation = 0;
    $x = 40;
    $y = 65;

    /* Use custom name when "Others" is selected */
    $roomName = ($roomNameChoice === "Others")
        ? $roomNameOther
        : $roomNameChoice;
    $facilityCategory = $facilityCategoryDefaults[$roomName] ?? "Other";

    if (!$selectedBuilding || $buildingId <= 0) {
        $error = "Please select a building first.";
    } elseif (
        $selectedFloor === "" ||
        !in_array($selectedFloor, $floors, true)
    ) {
        $error = "Please select a valid floor.";
    } elseif (!in_array($roomType, $typeOptions, true)) {
        $error = "Please select a valid type (Room, Offices, or Facilities).";
    } elseif ($presetType !== "" && $roomType !== $presetType) {
        $error = "Type must be " . $presetType . ".";
    } elseif ($roomType === "Room" && $roomNumber === "") {
        $error = "Room Number is required.";
    } elseif (
        $roomType === "Room" &&
        (!ctype_digit($roomNumber) || strlen($roomNumber) > 50)
    ) {
        $error = "Room Number must contain numbers only.";
    } elseif ($roomNameChoice === "") {
        $error = "Please select a Room Name.";
    } elseif (
        $roomNameChoice !== "Others" &&
        !in_array($roomNameChoice, $roomNameOptions[$roomType], true)
    ) {
        $error = "Please select a valid Room Name for the chosen type.";
    } elseif ($roomNameChoice === "Others" && $roomNameOther === "") {
        $error = "Please type the custom Room Name.";
    } elseif (mb_strlen($roomName) > 150) {
        $error = "Room Name must not exceed 150 characters.";
        } else {
        $floorNum = floorNumber($selectedFloor);

        try {
            $attachmentPath = storeRoomAttachment(getUploadAt("room_attachment"));

            if ($roomType === "Offices") {
                /* Offices are saved in the offices table */
                $stmt = $conn->prepare(
                    "INSERT INTO offices
                        (building_id, office_name, floor, room_image_url,
                         position_x, position_y, width, height,
                         shape_type, rotation)
                     VALUES
                        (:building_id, :office_name, :floor, :room_image_url,
                         :x, :y, :w, :h, :shape, :rotation)"
                );

                $stmt->execute([
                    "building_id" => $buildingId,
                    "office_name" => $roomName,
                    "floor"       => $floorNum,
                    "room_image_url" => $attachmentPath,
                    "x"           => $x,
                    "y"           => $y,
                    "w"           => $width,
                    "h"           => $height,
                    "shape"       => $shape,
                    "rotation"    => $rotation
                ]);

            } elseif ($roomType === "Facilities") {
                /*
                 * Facilities table has no description column.
                 * Do not insert a description value.
                 */
                $stmt = $conn->prepare(
                    "INSERT INTO facilities
                        (building_id, facility_name, category, image,
                         floor, position_x, position_y, width, height,
                         shape_type, rotation)
                     VALUES
                        (:building_id, :facility_name, :category, :image,
                         :floor, :x, :y, :w, :h, :shape, :rotation)"
                );

                $stmt->execute([
                    "building_id"   => $buildingId,
                    "facility_name" => $roomName,
                    "category"      => $facilityCategory,
                    "image"         => $attachmentPath,
                    "floor"         => $floorNum,
                    "x"             => $x,
                    "y"             => $y,
                    "w"             => $width,
                    "h"             => $height,
                    "shape"         => $shape,
                    "rotation"      => $rotation
                ]);

            } else {
                /* Rooms are saved in the rooms table */
                $stmt = $conn->prepare(
                    "INSERT INTO rooms
                        (building_id, room_number, room_name, room_type, floor, walk_links,
                         position_x, position_y, width, height,
                         shape_type, rotation)
                     VALUES
                        (:building_id, :room_number, :room_name, :room_type, :floor, :walk_links,
                         :x, :y, :w, :h, :shape, :rotation)"
                );

                $stmt->execute([
                    "building_id" => $buildingId,
                    "room_number" => $roomNumber,
                    "room_name"   => $roomName,
                    "room_type"   => $roomType,
                    "floor"       => $floorNum,
                    "walk_links" => $attachmentPath,
                    "x"           => $x,
                    "y"           => $y,
                    "w"           => $width,
                    "h"           => $height,
                    "shape"       => $shape,
                    "rotation"    => $rotation
                ]);
            }

            header(
                "Location: add-room.php?building_id=" . $buildingId .
                "&floor=" . urlencode($selectedFloor) .
                "&added=1" . $presetQuery
            );
            exit;

        } catch (Throwable $ex) {
            if (!empty($attachmentPath)) {
                deleteStoredAttachment($attachmentPath);
            }
            error_log("Add room error: " . $ex->getMessage());
            $error = "Unable to add. Please check your database columns. (" .
                $ex->getMessage() . ")";
        }
    }
}

/* ---------- Add a navigation point ---------- */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["add_navigation_point"])
) {
    $navType = trim((string)($_POST["nav_type"] ?? ""));
    $navStairType = trim((string)($_POST["nav_staircase_type"] ?? ""));
    $selectedFloor = trim((string)($_POST["floor"] ?? ""));

    $isVertical = in_array($navType, ["Staircase", "Elevator"], true);

    $totalFloors = $selectedBuilding
        ? (int)$selectedBuilding["total_floors"]
        : 0;

    $navFloors = [floorNumber($selectedFloor)];
    $floorError = "";

    if ($isVertical) {
        $postedFloors = $_POST["nav_floors"] ?? [];

        if (!is_array($postedFloors)) {
            $postedFloors = [];
        }

        foreach ($postedFloors as $value) {
            if (!is_scalar($value) || !ctype_digit(trim((string)$value))) {
                $floorError = "Invalid floor selection.";
                break;
            }

            $navFloors[] = (int)trim((string)$value);
        }

        $navFloors = array_values(array_unique($navFloors));
        sort($navFloors);
    }

    foreach ($navFloors as $floorToCheck) {
        if ($floorToCheck < 1 || $floorToCheck > $totalFloors) {
            $floorError = "Floor " . $floorToCheck .
                " does not exist. This building has " . $totalFloors .
                ($totalFloors === 1 ? " floor." : " floors.");
            break;
        }
    }

    if (!$selectedBuilding || $buildingId <= 0) {
        $error = "Please select a building first.";
    } elseif (
        $selectedFloor === "" ||
        !in_array($selectedFloor, $floors, true)
    ) {
        $error = "Please select a valid floor.";
    } elseif (!in_array($navType, $navTypeOptions, true)) {
        $error = "Please select a valid location type.";
    } elseif (
        $navType === "Staircase" &&
        !in_array($navStairType, $staircaseTypeOptions, true)
    ) {
        $error = "Please select a staircase type.";
    } elseif ($navType !== "Staircase" && $navStairType !== "") {
        $error = "Staircase type can only be used for staircases.";
    } elseif ($floorError !== "") {
        $error = $floorError;
    } else {
        $attachmentPath = null;
        try {
            if ($navType === "Hallway") {
                $attachmentPath = storeRoomAttachment(getUploadAt("hallway_attachment"));
            }

            $conn->beginTransaction();

            $connectionGroup = null;

            if ($isVertical) {
                $connectionGroup = (string)$conn
                    ->query("SELECT gen_random_uuid()")
                    ->fetchColumn();
            }

            [$navWidth, $navHeight] = $navDefaultSize[$navType];

            $stmt = $conn->prepare(
                "INSERT INTO navigation_points
                    (building_id, location_name, location_type, staircase_type,
                     floor_number, connection_group, walk_links,
                     position_x, position_y, width, height, rotation)
                 VALUES
                    (:building_id, :location_name, :location_type, :staircase_type,
                     :floor_number, :connection_group, :walk_links,
                     :x, :y, :w, :h, 0)"
            );

            foreach ($navFloors as $floorToSave) {
                $stmt->execute([
                    "building_id"      => $buildingId,
                    "location_name"    => $navType,
                    "location_type"    => $navType,
                    "staircase_type"   => $navType === "Staircase" ? $navStairType : null,
                    "floor_number"     => $floorToSave,
                    "connection_group" => $connectionGroup,
                    "walk_links"       => $attachmentPath,
                    "x"                => 40,
                    "y"                => 65,
                    "w"                => $navWidth,
                    "h"                => $navHeight
                ]);
            }

            $conn->commit();

            header(
                "Location: add-room.php?building_id=" . $buildingId .
                "&floor=" . urlencode($selectedFloor) .
                "&nav_added=1"
            );
            exit;

        } catch (Throwable $ex) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            if (!empty($attachmentPath)) {
                deleteStoredAttachment($attachmentPath);
            }

            error_log("Add navigation point error: " . $ex->getMessage());
            $error = "Unable to add navigation point. (" .
                $ex->getMessage() . ")";
        }
    }
}

/* ---------- Delete one hallway area and its saved photo ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_hallway_section"])) {
    $sectionNumber = filter_var($_POST["delete_hallway_section"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $navId = filter_var($_POST["hallway_section_point_id"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $selectedFloor = trim((string)($_POST["floor"] ?? ""));
    if ($sectionNumber !== false && $navId !== false && $buildingId > 0 && $selectedBuilding && in_array($selectedFloor, $floors, true)) {
        try {
            $findPhoto = $conn->prepare("SELECT s.photo_path FROM navigation_point_sections s JOIN navigation_points n ON n.navigation_point_id = s.navigation_point_id WHERE s.navigation_point_id = :id AND s.section_number = :section_number AND n.building_id = :building_id AND n.floor_number = :floor_num AND n.location_type = 'Hallway'");
            $findPhoto->execute(["id" => $navId, "section_number" => $sectionNumber, "building_id" => $buildingId, "floor_num" => floorNumber($selectedFloor)]);
            $photoPath = (string)($findPhoto->fetchColumn() ?: "");
            $deleteArea = $conn->prepare("DELETE FROM navigation_point_sections WHERE navigation_point_id = :id AND section_number = :section_number");
            $deleteArea->execute(["id" => $navId, "section_number" => $sectionNumber]);
            if ($photoPath !== "") cleanupUnusedAttachment($conn, $photoPath);
            header("Location: add-room.php?building_id=" . $buildingId . "&floor=" . urlencode($selectedFloor) . "&selected_item=n" . $navId . "&area_deleted=1" . $presetQuery);
            exit;
        } catch (Throwable $ex) {
            error_log("Delete hallway area error: " . $ex->getMessage());
            $error = "Unable to delete this hallway area.";
        }
    } else {
        $error = "Invalid hallway area.";
    }
}

/* ---------- Delete one hallway area photo ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_hallway_section_photo"])) {
    $sectionNumber = filter_var($_POST["delete_hallway_section_photo"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $navId = filter_var($_POST["hallway_section_point_id"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $selectedFloor = trim((string)($_POST["floor"] ?? ""));
    if ($sectionNumber !== false && $navId !== false && $buildingId > 0 && $selectedBuilding && in_array($selectedFloor, $floors, true)) {
        try {
            $findPhoto = $conn->prepare("SELECT s.photo_path FROM navigation_point_sections s JOIN navigation_points n ON n.navigation_point_id = s.navigation_point_id WHERE s.navigation_point_id = :id AND s.section_number = :section_number AND n.building_id = :building_id AND n.floor_number = :floor_num AND n.location_type = 'Hallway'");
            $findPhoto->execute(["id" => $navId, "section_number" => $sectionNumber, "building_id" => $buildingId, "floor_num" => floorNumber($selectedFloor)]);
            $photoPath = (string)($findPhoto->fetchColumn() ?: "");
            if ($photoPath !== "") {
                $deletePhoto = $conn->prepare("UPDATE navigation_point_sections SET photo_path = '' WHERE navigation_point_id = :id AND section_number = :section_number");
                $deletePhoto->execute(["id" => $navId, "section_number" => $sectionNumber]);
                cleanupUnusedAttachment($conn, $photoPath);
            }
            header("Location: add-room.php?building_id=" . $buildingId . "&floor=" . urlencode($selectedFloor) . "&selected_item=n" . $navId . "&photo_deleted=1" . $presetQuery);
            exit;
        } catch (Throwable $ex) {
            error_log("Delete hallway area photo error: " . $ex->getMessage());
            $error = "Unable to delete this area photo.";
        }
    } else {
        $error = "Invalid hallway area photo.";
    }
}

/* ---------- Save one hallway area photo and its marker ---------- */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["save_hallway_section"])
) {
    $sectionNumber = filter_var(
        $_POST["save_hallway_section"],
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );
    $navId = filter_var(
        $_POST["hallway_section_point_id"] ?? null,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );
    $selectedFloor = trim((string)($_POST["floor"] ?? ""));

    if (!$hallwaySectionStorageAvailable) {
        $error = "Hallway section storage is unavailable. Check database permissions.";
    } elseif (!$selectedBuilding || $buildingId <= 0) {
        $error = "Please select a building first.";
    } elseif (
        $selectedFloor === "" ||
        !in_array($selectedFloor, $floors, true)
    ) {
        $error = "Please select a valid floor.";
    } elseif ($sectionNumber === false || $navId === false) {
        $error = "Invalid hallway section.";
    } else {
        $floorNum = floorNumber($selectedFloor);
        $labels = is_array($_POST["hallway_section_name"] ?? null)
            ? $_POST["hallway_section_name"]
            : [];
        $markerXs = is_array($_POST["hallway_section_marker_x"] ?? null)
            ? $_POST["hallway_section_marker_x"]
            : [];
        $markerYs = is_array($_POST["hallway_section_marker_y"] ?? null)
            ? $_POST["hallway_section_marker_y"]
            : [];
        $markerPlacedFlags = is_array($_POST["hallway_section_marker_placed"] ?? null)
            ? $_POST["hallway_section_marker_placed"]
            : [];
        $rawSectionName = $labels[$sectionNumber] ?? "";
        $sectionName = is_scalar($rawSectionName)
            ? trim((string)$rawSectionName)
            : "";
        $markerXInput = $markerXs[$sectionNumber] ?? null;
        $markerYInput = $markerYs[$sectionNumber] ?? null;

        if ($sectionName === "") {
            $sectionName = "Area " . $sectionNumber;
        }
        $sectionName = mb_substr($sectionName, 0, 120);
        $markerX = is_numeric($markerXInput)
            ? max(0, min(100, (float)$markerXInput))
            : null;
        $markerY = is_numeric($markerYInput)
            ? max(0, min(100, (float)$markerYInput))
            : null;
        $markerWasPlaced = (string)($markerPlacedFlags[$sectionNumber] ?? "") === "1";

        if (!$markerWasPlaced || $markerX === null || $markerY === null) {
            $error = "Click the hallway to place this area's marker before saving.";
        } else {
            $newPhotoPath = null;
            try {
                $hallwayCheck = $conn->prepare(
                    "SELECT navigation_point_id
                     FROM navigation_points
                     WHERE navigation_point_id = :id
                       AND building_id = :building_id
                       AND floor_number = :floor_num
                       AND location_type = 'Hallway'"
                );
                $hallwayCheck->execute([
                    "id" => $navId,
                    "building_id" => $buildingId,
                    "floor_num" => $floorNum
                ]);
                if (!$hallwayCheck->fetchColumn()) {
                    throw new RuntimeException("The selected hallway was not found.");
                }

                $existingStmt = $conn->prepare(
                    "SELECT photo_path
                     FROM navigation_point_sections
                     WHERE navigation_point_id = :id
                       AND section_number = :section_number"
                );
                $existingStmt->execute([
                    "id" => $navId,
                    "section_number" => $sectionNumber
                ]);
                $oldPhotoPath = (string)($existingStmt->fetchColumn() ?: "");

                $newPhotoPath = storeHallwaySectionPhoto(
                    getUploadAt("hallway_section_photo", $sectionNumber)
                );
                $photoPath = $newPhotoPath ?? $oldPhotoPath;
                if ($photoPath === "") {
                    throw new RuntimeException(
                        "Choose a picture for this area before saving."
                    );
                }

                $conn->beginTransaction();
                $saveSection = $conn->prepare(
                    "INSERT INTO navigation_point_sections
                        (navigation_point_id, section_number, section_name,
                         photo_path, marker_x, marker_y)
                     VALUES
                        (:navigation_point_id, :section_number, :section_name,
                         :photo_path, :marker_x, :marker_y)
                     ON CONFLICT (navigation_point_id, section_number)
                     DO UPDATE SET
                        section_name = EXCLUDED.section_name,
                        photo_path = EXCLUDED.photo_path,
                        marker_x = EXCLUDED.marker_x,
                        marker_y = EXCLUDED.marker_y"
                );
                $saveSection->execute([
                    "navigation_point_id" => $navId,
                    "section_number" => $sectionNumber,
                    "section_name" => $sectionName,
                    "photo_path" => $photoPath,
                    "marker_x" => $markerX,
                    "marker_y" => $markerY
                ]);
                $conn->commit();

                if ($newPhotoPath !== null && $oldPhotoPath !== "") {
                    try {
                        cleanupUnusedAttachment($conn, $oldPhotoPath);
                    } catch (Throwable $cleanupError) {
                        error_log(
                            "Hallway section replacement cleanup error: " .
                            $cleanupError->getMessage()
                        );
                    }
                }

                if (!isset($_POST["save_layout"])) {
                    header(
                        "Location: add-room.php?building_id=" . $buildingId .
                        "&floor=" . urlencode($selectedFloor) .
                        "&section_saved=1&selected_item=n" . $navId . $presetQuery
                    );
                    exit;
                }
            } catch (Throwable $ex) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                if ($newPhotoPath !== null) {
                    deleteStoredAttachment($newPhotoPath);
                }
                error_log("Save hallway section error: " . $ex->getMessage());
                $error = $ex instanceof RuntimeException
                    ? $ex->getMessage()
                    : "Unable to save this hallway section.";
            }
        }
    }
}
if (isset($_POST["save_hallway_section"]) && $error !== "") {
    unset($_POST["save_layout"]);
}

/* ---------- Delete selected items (including connected stair/elevator points) ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_item"])) {
    $selectedFloor = trim((string)($_POST["floor"] ?? ""));
    $rawItems = $_POST["delete_items"] ?? [];
    if (!is_array($rawItems)) $rawItems = [];
    // Backward compatibility with the old single-item form.
    if (!$rawItems && isset($_POST["delete_kind"], $_POST["delete_id"])) {
        $rawItems[] = (string)$_POST["delete_kind"] . (string)$_POST["delete_id"];
    }
    $deleteItems = [];
    foreach ($rawItems as $rawItem) {
        if (preg_match('/^([rofn])(\d+)$/', (string)$rawItem, $match)) {
            $deleteItems[$match[1] . (int)$match[2]] = [$match[1], (int)$match[2]];
        }
    }

    if (!$selectedBuilding || $buildingId <= 0) {
        $error = "Please select a building first.";
    } elseif ($selectedFloor === "" || !in_array($selectedFloor, $floors, true)) {
        $error = "Please select a valid floor.";
    } elseif (!$deleteItems) {
        $error = "Select at least one valid item to delete.";
    } else {
        $floorNum = floorNumber($selectedFloor);
        $deleteQueries = [
            "r" => "DELETE FROM rooms WHERE room_id = :id AND building_id = :building_id AND CAST(floor AS TEXT) IN (:floor_num, :floor_label) AND (room_type IS NULL OR room_type <> 'Facilities')",
            "o" => "DELETE FROM offices WHERE office_id = :id AND building_id = :building_id AND floor = :floor_num",
            "f" => "DELETE FROM facilities WHERE facility_id = :id AND building_id = :building_id AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)",
            "n" => "DELETE FROM navigation_points WHERE navigation_point_id = :id AND building_id = :building_id"
        ];
        $attachments = [];
        $sectionPaths = [];
        $deletedCount = 0;
        try {
            // Deleting one connected stair/elevator removes every navigation point in its connection group.
            $expanded = $deleteItems;
            foreach ($deleteItems as [$kind, $id]) {
                if ($kind !== "n") continue;
                $groupStmt = $conn->prepare("SELECT connection_group::text FROM navigation_points WHERE navigation_point_id = :id AND building_id = :building_id");
                $groupStmt->execute(["id" => $id, "building_id" => $buildingId]);
                $group = $groupStmt->fetchColumn();
                if ($group !== false && $group !== null && $group !== "") {
                    $connectedStmt = $conn->prepare("SELECT navigation_point_id FROM navigation_points WHERE building_id = :building_id AND connection_group::text = :grp");
                    $connectedStmt->execute(["building_id" => $buildingId, "grp" => (string)$group]);
                    foreach ($connectedStmt->fetchAll(PDO::FETCH_COLUMN) as $connectedId) {
                        $expanded["n" . (int)$connectedId] = ["n", (int)$connectedId];
                    }
                }
            }
            $conn->beginTransaction();
            foreach ($expanded as [$kind, $id]) {
                if ($kind === "r" || $kind === "n") {
                    $table = $kind === "r" ? "rooms" : "navigation_points";
                    $column = $kind === "r" ? "room_id" : "navigation_point_id";
                    $lookupColumns = $kind === "n" ? "walk_links, image_url" : "walk_links";
                    $lookup = $conn->prepare("SELECT {$lookupColumns} FROM {$table} WHERE {$column} = :id AND building_id = :building_id");
                    $lookup->execute(["id" => $id, "building_id" => $buildingId]);
                    $attachmentRow = $lookup->fetch(PDO::FETCH_ASSOC) ?: [];
                    foreach ($attachmentRow as $pathValue) {
                        $path = (string)($pathValue ?: "");
                        if ($path !== "") $attachments[] = $path;
                    }
                }
                if ($kind === "n" && $hallwaySectionStorageAvailable) {
                    $sectionLookup = $conn->prepare("SELECT photo_path FROM navigation_point_sections WHERE navigation_point_id = :id");
                    $sectionLookup->execute(["id" => $id]);
                    foreach ($sectionLookup->fetchAll(PDO::FETCH_COLUMN) as $path) if ((string)$path !== "") $sectionPaths[] = (string)$path;
                }
                $params = ["id" => $id, "building_id" => $buildingId, "floor_num" => $floorNum];
                if ($kind === "r" || $kind === "f") {
                    $params["floor_num"] = (string)$floorNum;
                    $params["floor_label"] = $selectedFloor;
                } elseif ($kind === "n") {
                    unset($params["floor_num"]);
                }
                $stmt = $conn->prepare($deleteQueries[$kind]);
                $stmt->execute($params);
                $deletedCount += $stmt->rowCount();
                if ($kind === "n" && $hallwaySectionStorageAvailable) {
                    $removeSections = $conn->prepare("DELETE FROM navigation_point_sections WHERE navigation_point_id = :id");
                    $removeSections->execute(["id" => $id]);
                }
            }
            $conn->commit();
            foreach (array_unique($attachments) as $path) {
                try { cleanupUnusedAttachment($conn, $path); } catch (Throwable $cleanupError) { error_log("Attachment cleanup error: " . $cleanupError->getMessage()); }
            }
            foreach (array_unique($sectionPaths) as $path) {
                try { cleanupUnusedAttachment($conn, $path); } catch (Throwable $cleanupError) { error_log("Hallway section cleanup error: " . $cleanupError->getMessage()); }
            }
            if ($deletedCount > 0) {
                header("Location: add-room.php?building_id=" . $buildingId . "&floor=" . urlencode($selectedFloor) . "&deleted=1" . $presetQuery);
                exit;
            }
            $error = "The selected items could not be found on this floor.";
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log("Bulk delete room information error: " . $ex->getMessage());
            $error = "Unable to delete the selected items.";
        }
    }
}

/* ---------- Save the layout ---------- */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["save_layout"])
) {
    $ids = $_POST["room_id"] ?? [];
    $xs = $_POST["position_x"] ?? [];
    $ys = $_POST["position_y"] ?? [];
    $widths = $_POST["width"] ?? [];
    $heights = $_POST["height"] ?? [];
    $rotations = $_POST["rotation"] ?? [];
    $names = $_POST["room_name"] ?? [];
    $numbers = $_POST["room_number"] ?? [];
    $roomTypes = $_POST["room_type"] ?? [];
    $navTypes = $_POST["nav_type"] ?? [];
    $hallwayCopySources = $_POST["hallway_copy_source_id"] ?? [];
    $removeAttachmentFlags = $_POST["remove_attachment"] ?? [];
    if (!is_array($removeAttachmentFlags)) {
        $removeAttachmentFlags = [];
    }

    if (!$selectedBuilding || $selectedFloor === "") {
        $error = "Please select a building and floor.";
    } elseif (!in_array($selectedFloor, $floors, true)) {
        $error = "Please select a valid floor.";
    } elseif (!is_array($ids) || count($ids) > 500) {
        $error = "Invalid layout data.";
    } else {
        $floorNum = floorNumber($selectedFloor);
        $newAttachmentPaths = [];
        $oldAttachmentPaths = [];

        try {
            $conn->beginTransaction();

            $roomStmt = $conn->prepare(
                "UPDATE rooms
                 SET room_number = :number,
                     position_x = :x, position_y = :y,
                     width = :w, height = :h,
                     shape_type = :shape, rotation = :rotation,
                     room_name = :name
                 WHERE room_id = :id
                   AND building_id = :building_id
                   AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)"
            );

            $officeStmt = $conn->prepare(
                "UPDATE offices
                 SET position_x = :x, position_y = :y,
                     width = :w, height = :h,
                     shape_type = :shape, rotation = :rotation,
                     office_name = :name
                 WHERE office_id = :id
                   AND building_id = :building_id
                   AND floor = :floor_num"
            );

            $facilityStmt = $conn->prepare(
                "UPDATE facilities
                 SET position_x = :x, position_y = :y,
                     width = :w, height = :h,
                     shape_type = :shape, rotation = :rotation,
                     facility_name = :name
                 WHERE facility_id = :id
                   AND building_id = :building_id
                   AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)"
            );

            $navStmt = $conn->prepare(
                "UPDATE navigation_points
                 SET position_x = :x, position_y = :y,
                     width = :w, height = :h,
                     rotation = :rotation,
                     location_name = :name
                 WHERE navigation_point_id = :id
                   AND building_id = :building_id
                   AND floor_number = :floor_num"
            );

            $navRenameStmt = $conn->prepare(
                "UPDATE navigation_points
                 SET location_name = :name
                 WHERE building_id = :building_id
                   AND connection_group IS NOT NULL
                   AND connection_group = (
                       SELECT connection_group
                       FROM navigation_points
                       WHERE navigation_point_id = :id
                         AND building_id = :sub_building_id
                   )"
            );

            $newRoomStmt = $conn->prepare(
                "INSERT INTO rooms
                    (building_id, room_number, room_name, room_type, floor, walk_links,
                     position_x, position_y, width, height, shape_type, rotation)
                 VALUES
                    (:building_id, :room_number, :room_name, :room_type, :floor, :walk_links,
                     :x, :y, :w, :h, :shape, :rotation)"
            );

            $newOfficeStmt = $conn->prepare(
                "INSERT INTO offices
                    (building_id, office_name, floor, position_x, position_y,
                     width, height, shape_type, rotation)
                 VALUES
                    (:building_id, :office_name, :floor, :x, :y,
                     :w, :h, :shape, :rotation)"
            );

            $newHallwayStmt = $conn->prepare(
                "INSERT INTO navigation_points
                    (building_id, location_name, location_type, staircase_type,
                     floor_number, connection_group, walk_links, position_x, position_y,
                     width, height, rotation)
                 VALUES
                    (:building_id, :location_name, :location_type, NULL,
                     :floor_number, NULL, :walk_links, :x, :y, :w, :h, :rotation)
                 RETURNING navigation_point_id"
            );

            foreach ($ids as $i => $uidValue) {
                $uid = (string)$uidValue;
                $kind = substr($uid, 0, 1);
                $isNew = preg_match('/^[ron]new\d+$/', $uid) === 1;

                if ($isNew) {
                    $num = 0;
                } elseif (!in_array($kind, ["r", "o", "f", "n"], true)) {
                    $kind = "r";
                    $num = (int)$uid;
                } else {
                    $num = (int)substr($uid, 1);
                }

                if (!$isNew && $num <= 0) {
                    continue;
                }

                if ($kind === "n") {
                    $width = max(20, min(1200, (float)($widths[$i] ?? 140)));
                    $height = max(20, min(650, (float)($heights[$i] ?? 90)));
                } else {
                    $width = max(60, min(600, (float)($widths[$i] ?? 140)));
                    $height = max(45, min(400, (float)($heights[$i] ?? 90)));
                }

                $rotation = max(-360, min(360, (float)($rotations[$i] ?? 0)));

                /*
                 * Clamp using the rotated visible bounds, not only the
                 * unrotated width/height. The editor rotates each shape around
                 * its center, so the saved x/y must allow for the extra space
                 * occupied by the rotated rectangle. This keeps a staircase
                 * at the bottom of the canvas from being repositioned on save.
                 */
                $angle = fmod($rotation, 360) * M_PI / 180;
                $absCos = abs(cos($angle));
                $absSin = abs(sin($angle));
                $visualWidth = $width * $absCos + $height * $absSin;
                $visualHeight = $width * $absSin + $height * $absCos;
                $minX = ($visualWidth - $width) / 2;
                $minY = ($visualHeight - $height) / 2;
                $maxX = 1200 - ($visualWidth + $width) / 2;
                $maxY = 650 - ($visualHeight + $height) / 2;
                $x = max($minX, min($maxX, (float)($xs[$i] ?? 40)));
                $y = max($minY, min($maxY, (float)($ys[$i] ?? 65)));
                $roomName = mb_substr(trim((string)($names[$i] ?? "")), 0, 150);
                $roomNumber = trim((string)($numbers[$i] ?? ""));

                if ($roomName === "") {
                    throw new InvalidArgumentException("Room Name cannot be empty.");
                }

                if (
                    $kind === "r" &&
                    $roomNumber !== "" &&
                    (
                        strlen($roomNumber) > 50 ||
                        !ctype_digit($roomNumber)
                    )
                ) {
                    throw new InvalidArgumentException(
                        "Room Number must contain numbers only."
                    );
                }

                if ($isNew) {
                    if ($kind === "r") {
                        $roomType = trim((string)($roomTypes[$i] ?? ""));
                        if ($roomType !== "Room") {
                            throw new InvalidArgumentException(
                                "The copied room has an invalid type."
                            );
                        }

                        $newAttachmentPath = storeRoomAttachment(getUploadAt("attachment", $i));
                        if ($newAttachmentPath !== null) $newAttachmentPaths[] = $newAttachmentPath;
                        $newRoomStmt->execute([
                            "building_id" => $buildingId,
                            "room_number" => $roomNumber,
                            "room_name"   => $roomName,
                            "room_type"   => $roomType,
                            "floor"       => $floorNum,
                            "walk_links" => $newAttachmentPath,
                            "x"           => $x,
                            "y"           => $y,
                            "w"           => $width,
                            "h"           => $height,
                            "shape"       => "rectangle",
                            "rotation"    => $rotation
                        ]);
                    } elseif ($kind === "o") {
                        $newOfficeStmt->execute([
                            "building_id" => $buildingId,
                            "office_name" => $roomName,
                            "floor"       => $floorNum,
                            "x"           => $x,
                            "y"           => $y,
                            "w"           => $width,
                            "h"           => $height,
                            "shape"       => "rectangle",
                            "rotation"    => $rotation
                        ]);
                    } elseif (
                        $kind === "n" &&
                        trim((string)($navTypes[$i] ?? "")) === "Hallway"
                    ) {
                        $newAttachmentPath = storeRoomAttachment(getUploadAt("attachment", $i));
                        if ($newAttachmentPath !== null) $newAttachmentPaths[] = $newAttachmentPath;
                        $newHallwayStmt->execute([
                            "building_id"   => $buildingId,
                            "location_name" => $roomName,
                            "location_type" => "Hallway",
                            "floor_number"  => $floorNum,
                            "walk_links" => $newAttachmentPath,
                            "x"             => $x,
                            "y"             => $y,
                            "w"             => $width,
                            "h"             => $height,
                            "rotation"      => $rotation
                        ]);
                        $newHallwayId = (int)$newHallwayStmt->fetchColumn();
                        $copySource = trim((string)($hallwayCopySources[$i] ?? ""));
                        if (
                            $newHallwayId > 0 &&
                            $hallwaySectionStorageAvailable &&
                            preg_match('/^n([1-9]\d*)$/', $copySource, $sourceMatch)
                        ) {
                            $copySections = $conn->prepare(
                                "INSERT INTO navigation_point_sections
                                    (navigation_point_id, section_number, section_name,
                                     photo_path, marker_x, marker_y)
                                 SELECT :new_id, s.section_number, s.section_name,
                                        s.photo_path, s.marker_x, s.marker_y
                                 FROM navigation_point_sections s
                                 INNER JOIN navigation_points source_point
                                    ON source_point.navigation_point_id = s.navigation_point_id
                                 WHERE s.navigation_point_id = :source_id
                                   AND source_point.building_id = :building_id
                                   AND source_point.floor_number = :floor_number
                                   AND source_point.location_type = 'Hallway'
                                 ON CONFLICT (navigation_point_id, section_number)
                                 DO NOTHING"
                            );
                            $copySections->execute([
                                "new_id" => $newHallwayId,
                                "source_id" => (int)$sourceMatch[1],
                                "building_id" => $buildingId,
                                "floor_number" => $floorNum
                            ]);
                        }
                    } else {
                        throw new InvalidArgumentException(
                            "Only rooms, offices, and Hallways can be pasted."
                        );
                    }

                    continue;
                }

                if ($kind === "n") {
                    $navStmt->execute([
                        "x"           => $x,
                        "y"           => $y,
                        "w"           => $width,
                        "h"           => $height,
                        "rotation"    => $rotation,
                        "name"        => $roomName,
                        "id"          => $num,
                        "building_id" => $buildingId,
                        "floor_num"   => $floorNum
                    ]);

                    $navRenameStmt->execute([
                        "name"            => $roomName,
                        "building_id"     => $buildingId,
                        "id"              => $num,
                        "sub_building_id" => $buildingId
                    ]);

                    [$newPath, $oldPath] = replaceEntityAttachment(
                        $conn, "n", $num, $buildingId,
                        getUploadAt("attachment", $i),
                        isset($removeAttachmentFlags[$i])
                    );
                    if ($newPath !== null) $newAttachmentPaths[] = $newPath;
                    if ($oldPath !== null && $oldPath !== "") $oldAttachmentPaths[] = $oldPath;
                    continue;
                }

                $common = [
                    "x"           => $x,
                    "y"           => $y,
                    "w"           => $width,
                    "h"           => $height,
                    "shape"       => "rectangle",
                    "rotation"    => $rotation,
                    "name"        => $roomName,
                    "id"          => $num,
                    "building_id" => $buildingId
                ];

                if ($kind === "o") {
                    $officeStmt->execute($common + ["floor_num" => $floorNum]);
                    [$newPath, $oldPath] = replaceEntityAttachment($conn, "o", $num, $buildingId, getUploadAt("attachment", $i), isset($removeAttachmentFlags[$i]));
                    if ($newPath !== null) $newAttachmentPaths[] = $newPath;
                    if ($oldPath !== null && $oldPath !== "") $oldAttachmentPaths[] = $oldPath;
                } elseif ($kind === "f") {
                    $facilityStmt->execute($common + [
                        "floor_num"   => (string)$floorNum,
                        "floor_label" => $selectedFloor
                    ]);
                    [$newPath, $oldPath] = replaceEntityAttachment($conn, "f", $num, $buildingId, getUploadAt("attachment", $i), isset($removeAttachmentFlags[$i]));
                    if ($newPath !== null) $newAttachmentPaths[] = $newPath;
                    if ($oldPath !== null && $oldPath !== "") $oldAttachmentPaths[] = $oldPath;
                } else {
                    $roomStmt->execute($common + [
                        "number"      => $roomNumber,
                        "floor_num"   => (string)$floorNum,
                        "floor_label" => $selectedFloor
                    ]);
                    [$newPath, $oldPath] = replaceEntityAttachment(
                        $conn,
                        "r",
                        $num,
                        $buildingId,
                        getUploadAt("attachment", $i),
                        isset($removeAttachmentFlags[$i])
                    );
                    if ($newPath !== null) $newAttachmentPaths[] = $newPath;
                    if ($oldPath !== null && $oldPath !== "") $oldAttachmentPaths[] = $oldPath;
                }
            }

            // Persist hallway area marker coordinates during Save Layout too.
            // Without this, dragged marker positions only existed in JavaScript and
            // reverted to the older database coordinates after redirect/reload.
            $selectedPointIdForMarkers = filter_var(
                $_POST["hallway_section_point_id"] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            );
            if ($selectedPointIdForMarkers !== false) {
                $validHallway = $conn->prepare(
                    "SELECT navigation_point_id FROM navigation_points
                     WHERE navigation_point_id = :id
                       AND building_id = :building_id
                       AND floor_number = :floor_num
                       AND location_type = 'Hallway'"
                );
                $validHallway->execute([
                    "id" => $selectedPointIdForMarkers,
                    "building_id" => $buildingId,
                    "floor_num" => $floorNum
                ]);
                if ($validHallway->fetchColumn()) {
                    $markerXsToSave = is_array($_POST["hallway_section_marker_x"] ?? null)
                        ? $_POST["hallway_section_marker_x"] : [];
                    $markerYsToSave = is_array($_POST["hallway_section_marker_y"] ?? null)
                        ? $_POST["hallway_section_marker_y"] : [];
                    $markerFlagsToSave = is_array($_POST["hallway_section_marker_placed"] ?? null)
                        ? $_POST["hallway_section_marker_placed"] : [];
                    $updateMarker = $conn->prepare(
                        "UPDATE navigation_point_sections
                         SET marker_x = :marker_x, marker_y = :marker_y
                         WHERE navigation_point_id = :navigation_point_id
                           AND section_number = :section_number"
                    );
                    foreach ($markerXsToSave as $sectionNo => $markerXValue) {
                        $sectionNo = filter_var($sectionNo, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
                        $markerYValue = $markerYsToSave[$sectionNo] ?? null;
                        $placed = (string)($markerFlagsToSave[$sectionNo] ?? "") === "1";
                        if ($sectionNo === false || !$placed || !is_numeric($markerXValue) || !is_numeric($markerYValue)) {
                            continue;
                        }
                        $updateMarker->execute([
                            "marker_x" => max(0, min(100, (float)$markerXValue)),
                            "marker_y" => max(0, min(100, (float)$markerYValue)),
                            "navigation_point_id" => $selectedPointIdForMarkers,
                            "section_number" => $sectionNo
                        ]);
                    }
                }
            }

            $conn->commit();
            foreach (array_unique($oldAttachmentPaths) as $oldAttachmentPath) {
                try {
                    cleanupUnusedAttachment($conn, $oldAttachmentPath);
                } catch (Throwable $cleanupError) {
                    error_log("Attachment cleanup error: " . $cleanupError->getMessage());
                }
            }

            $selectedPointId = filter_var(
                $_POST["hallway_section_point_id"] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            );
            $selectedItemQuery = $selectedPointId !== false
                ? "&selected_item=n" . $selectedPointId
                : "";
            $sectionSavedQuery = isset($_POST["save_hallway_section"])
                ? "&section_saved=1"
                : "";
            header(
                "Location: add-room.php?building_id=" . $buildingId .
                "&floor=" . urlencode($selectedFloor) .
                "&saved=1" . $selectedItemQuery . $sectionSavedQuery . $presetQuery
            );
            exit;

        } catch (InvalidArgumentException $ex) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            foreach (array_unique($newAttachmentPaths) as $newAttachmentPath) {
                deleteStoredAttachment($newAttachmentPath);
            }
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            foreach (array_unique($newAttachmentPaths) as $newAttachmentPath) {
                deleteStoredAttachment($newAttachmentPath);
            }
            error_log("Save layout error: " . $ex->getMessage());
            $error = "Unable to save layout. Check your database room layout columns.";
        }
    }
}

if (isset($_GET["added"])) {
    $message = "Added successfully!";
}

if (isset($_GET["nav_added"])) {
    $message = "Navigation point added successfully!";
}

if (isset($_GET["saved"])) {
    $message = "Room layout saved successfully!";
}

if (isset($_GET["deleted"])) {
    $message = "Item deleted successfully!";
}

if (isset($_GET["section_saved"])) {
    $message = "Hallway section saved successfully!";
}

/* ---------- Load everything on the selected floor ---------- */
$rooms = [];
$referenceRooms = [];
$referenceFloorNum = 0;

if (
    $buildingId > 0 &&
    $selectedBuilding &&
    $selectedFloor !== "" &&
    in_array($selectedFloor, $floors, true)
) {
    $floorNum = floorNumber($selectedFloor);
    $referenceFloorNum = $floorNum - 1;

    try {
        /* Rooms */
        $stmt = $conn->prepare(
            "SELECT room_id, room_number, room_name, room_type, floor, walk_links,
                    position_x, position_y, width, height,
                    shape_type, rotation
             FROM rooms
             WHERE building_id = :building_id
               AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)
               AND (room_type IS NULL OR room_type <> 'Facilities')
             ORDER BY room_number"
        );

        $stmt->execute([
            "building_id" => $buildingId,
            "floor_num"   => (string)$floorNum,
            "floor_label" => $selectedFloor
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row["uid"] = "r" . $row["room_id"];
            $rooms[] = $row;
        }

    } catch (Throwable $ex) {
        error_log("Rooms load error: " . $ex->getMessage());
        $error = $error !== "" ? $error : "Unable to load rooms.";
    }

    try {
        /* Offices */
        $stmt = $conn->prepare(
            "SELECT office_id, office_name, floor, walk_links, room_image_url,
                    position_x, position_y, width, height,
                    shape_type, rotation
             FROM offices
             WHERE building_id = :building_id
               AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)
             ORDER BY office_id"
        );

        $stmt->execute([
            "building_id" => $buildingId,
            "floor_num"   => (string)$floorNum,
            "floor_label" => $selectedFloor
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rooms[] = [
                "uid"         => "o" . $row["office_id"],
                "room_id"     => $row["office_id"],
                "room_number" => "",
                "room_name"   => $row["office_name"],
                "room_type"   => "Offices",
                "floor"       => $row["floor"],
                "position_x"  => $row["position_x"],
                "position_y"  => $row["position_y"],
                "width"       => $row["width"],
                "height"      => $row["height"],
                "shape_type"  => $row["shape_type"],
                "rotation"    => $row["rotation"],
                "walk_links" => $row["walk_links"] ?? "",
                "attachment_path" => $row["room_image_url"] ?? ""
            ];
        }

    } catch (Throwable $ex) {
        error_log("Offices load error: " . $ex->getMessage());
        $error = $error !== ""
            ? $error
            : "Unable to load offices. Did you run the ALTER TABLE offices SQL?";
    }

    try {
        /*
         * Facilities: description is intentionally omitted because
         * the facilities table does not have that column.
         */
        $stmt = $conn->prepare(
            "SELECT facility_id, facility_name, category, floor, walk_links, image,
                    position_x, position_y, width, height,
                    shape_type, rotation
             FROM facilities
             WHERE building_id = :building_id
               AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)
             ORDER BY facility_id"
        );

        $stmt->execute([
            "building_id" => $buildingId,
            "floor_num"   => (string)$floorNum,
            "floor_label" => $selectedFloor
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rooms[] = [
                "uid"         => "f" . $row["facility_id"],
                "room_id"     => $row["facility_id"],
                "room_number" => "",
                "room_name"   => $row["facility_name"],
                "room_type"   => "Facilities",
                "category"    => $row["category"] ?? "",
                "floor"       => $row["floor"],
                "position_x"  => $row["position_x"],
                "position_y"  => $row["position_y"],
                "width"       => $row["width"],
                "height"      => $row["height"],
                "shape_type"  => $row["shape_type"],
                "rotation"    => $row["rotation"],
                "walk_links" => $row["walk_links"] ?? "",
                "attachment_path" => $row["image"] ?? ""
            ];
        }

    } catch (Throwable $ex) {
        error_log("Facilities load error: " . $ex->getMessage());
        $error = $error !== ""
            ? $error
            : "Unable to load facilities. (" . $ex->getMessage() . ")";
    }

    try {
        /* Navigation points */

        /* Which floors does each staircase / elevator connect? */
        $groupFloors = [];

        $stmt = $conn->prepare(
            "SELECT connection_group::text AS grp, floor_number
             FROM navigation_points
             WHERE building_id = :building_id
               AND connection_group IS NOT NULL
             ORDER BY floor_number"
        );

        $stmt->execute(["building_id" => $buildingId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $groupFloors[$link["grp"]][] = floorLabel((int)$link["floor_number"]);
        }

        $stmt = $conn->prepare(
            "SELECT navigation_point_id, location_name, location_type,
                    staircase_type, floor_number,
                    connection_group::text AS connection_group,
                    position_x, position_y, width, height, rotation, walk_links, image_url
             FROM navigation_points
             WHERE building_id = :building_id
               AND floor_number = :floor_num
             ORDER BY navigation_point_id"
        );

        $stmt->execute([
            "building_id" => $buildingId,
            "floor_num"   => $floorNum
        ]);

        $hallwaySectionsById = [];
        if ($hallwaySectionStorageAvailable) {
            $sectionStmt = $conn->prepare(
                "SELECT s.navigation_point_id, s.section_number, s.section_name,
                        s.photo_path, s.marker_x, s.marker_y, n.location_type
                 FROM navigation_point_sections s
                 INNER JOIN navigation_points n
                    ON n.navigation_point_id = s.navigation_point_id
                 WHERE n.building_id = :building_id
                   AND n.floor_number = :floor_num
                 ORDER BY s.navigation_point_id, s.section_number"
            );
            $sectionStmt->execute([
                "building_id" => $buildingId,
                "floor_num" => $floorNum
            ]);
            foreach ($sectionStmt->fetchAll(PDO::FETCH_ASSOC) as $sectionRow) {
                if ((int)$sectionRow["section_number"] === 0) continue;
                if (($sectionRow["location_type"] ?? "") !== "Hallway") continue;
                $hallwaySectionsById[(string)$sectionRow["navigation_point_id"]][] = [
                    "section_number" => (int)$sectionRow["section_number"],
                    "section_name" => (string)$sectionRow["section_name"],
                    "photo_path" => (string)$sectionRow["photo_path"],
                    "marker_x" => (float)$sectionRow["marker_x"],
                    "marker_y" => (float)$sectionRow["marker_y"]
                ];
            }
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $group = $row["connection_group"];

            $rooms[] = [
                "uid"            => "n" . $row["navigation_point_id"],
                "room_id"        => $row["navigation_point_id"],
                "room_number"    => "",
                "room_name"      => $row["location_name"],
                "room_type"      => "Navigation",
                "floor"          => $row["floor_number"],
                "position_x"     => $row["position_x"],
                "position_y"     => $row["position_y"],
                "width"          => $row["width"],
                "height"         => $row["height"],
                "shape_type"     => "rectangle",
                "rotation"       => $row["rotation"],
                "nav_type"       => $row["location_type"],
                "staircase_type" => $row["staircase_type"] ?? "",
                "links"          => (
                    $group !== null && isset($groupFloors[$group])
                ) ? implode(", ", $groupFloors[$group]) : "",
                "walk_links" => $row["walk_links"] ?? "",
                "attachment_path" => $row["image_url"] ?? "",
                "hallway_sections" => $hallwaySectionsById[
                    (string)$row["navigation_point_id"]
                ] ?? []
            ];
        }

    } catch (Throwable $ex) {
        error_log("Navigation points load error: " . $ex->getMessage());
        $error = $error !== ""
            ? $error
            : "Unable to load navigation points.";
    }

    if ($referenceFloorNum > 0) {
        $referenceFloorLabel = floorLabel($referenceFloorNum);

        try {
            $stmt = $conn->prepare(
                "SELECT room_id, room_number, room_name, room_type, floor,
                        position_x, position_y, width, height,
                        shape_type, rotation
                 FROM rooms
                 WHERE building_id = :building_id
                   AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)
                   AND (room_type IS NULL OR room_type <> 'Facilities')
                 ORDER BY room_number"
            );
            $stmt->execute([
                "building_id" => $buildingId,
                "floor_num"   => (string)$referenceFloorNum,
                "floor_label" => $referenceFloorLabel
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row["uid"] = "r" . $row["room_id"];
                $referenceRooms[] = $row;
            }
        } catch (Throwable $ex) {
            error_log("Previous-floor rooms reference load error: " . $ex->getMessage());
        }

        try {
            $stmt = $conn->prepare(
                "SELECT office_id, office_name, floor,
                        position_x, position_y, width, height,
                        shape_type, rotation
                 FROM offices
                 WHERE building_id = :building_id
                   AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)
                 ORDER BY office_id"
            );
            $stmt->execute([
                "building_id" => $buildingId,
                "floor_num"   => (string)$referenceFloorNum,
                "floor_label" => $referenceFloorLabel
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $referenceRooms[] = [
                    "uid"         => "o" . $row["office_id"],
                    "room_number" => "",
                    "room_name"   => $row["office_name"],
                    "room_type"   => "Offices",
                    "position_x"  => $row["position_x"],
                    "position_y"  => $row["position_y"],
                    "width"       => $row["width"],
                    "height"      => $row["height"],
                    "rotation"    => $row["rotation"]
                ];
            }
        } catch (Throwable $ex) {
            error_log("Previous-floor offices reference load error: " . $ex->getMessage());
        }

        try {
            $stmt = $conn->prepare(
                "SELECT facility_id, facility_name, category, floor,
                        position_x, position_y, width, height,
                        shape_type, rotation
                 FROM facilities
                 WHERE building_id = :building_id
                   AND CAST(floor AS TEXT) IN (:floor_num, :floor_label)
                 ORDER BY facility_id"
            );
            $stmt->execute([
                "building_id" => $buildingId,
                "floor_num"   => (string)$referenceFloorNum,
                "floor_label" => $referenceFloorLabel
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $referenceRooms[] = [
                    "uid"         => "f" . $row["facility_id"],
                    "room_number" => "",
                    "room_name"   => $row["facility_name"],
                    "room_type"   => "Facilities",
                    "category"    => $row["category"] ?? "",
                    "position_x"  => $row["position_x"],
                    "position_y"  => $row["position_y"],
                    "width"       => $row["width"],
                    "height"      => $row["height"],
                    "rotation"    => $row["rotation"]
                ];
            }
        } catch (Throwable $ex) {
            error_log("Previous-floor facilities reference load error: " . $ex->getMessage());
        }

        try {
            $stmt = $conn->prepare(
                "SELECT navigation_point_id, location_name, location_type,
                        staircase_type, position_x, position_y,
                        width, height, rotation
                 FROM navigation_points
                 WHERE building_id = :building_id
                   AND floor_number = :floor_num
                 ORDER BY navigation_point_id"
            );
            $stmt->execute([
                "building_id" => $buildingId,
                "floor_num"   => (string)$referenceFloorNum,
                "floor_label" => $referenceFloorLabel
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $referenceRooms[] = [
                    "uid"            => "n" . $row["navigation_point_id"],
                    "room_number"    => "",
                    "room_name"      => $row["location_name"],
                    "room_type"      => "Navigation",
                    "position_x"     => $row["position_x"],
                    "position_y"     => $row["position_y"],
                    "width"          => $row["width"],
                    "height"         => $row["height"],
                    "rotation"       => $row["rotation"],
                    "nav_type"       => $row["location_type"],
                    "staircase_type" => $row["staircase_type"] ?? ""
                ];
            }
        } catch (Throwable $ex) {
            error_log("Previous-floor navigation reference load error: " . $ex->getMessage());
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Room Layout Editor | CMU Campus Navigator</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f3f6fb;
    color: #172b4d;
}

.navbar {
    min-height: 86px;
    padding: 15px 4%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: #fff;
    border-bottom: 1px solid #dce4ef;
}

.logo {
    display: flex;
    align-items: center;
    gap: 14px;
}

.logo img {
    width: 56px;
    height: 56px;
    object-fit: contain;
}

.logo h2 {
    margin: 0 0 5px;
    color: #0b3b82;
    font-size: 21px;
}

.logo span {
    color: #4d5f78;
    font-size: 14px;
    font-weight: bold;
}

.navbar nav {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.navbar nav a {
    padding: 12px 18px;
    border-radius: 7px;
    text-decoration: none;
    font-size: 14px;
    font-weight: bold;
}

.view-btn {
    background: #edf3fc;
    color: #0b3b82;
}

.logout-btn {
    background: #b42318;
    color: white;
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
    color: white;
}

.sidebar h3 {
    margin: 0 12px 22px;
    color: #ffdf45;
    font-size: 14px;
    letter-spacing: 1.2px;
}

.sidebar a {
    display: block;
    margin-bottom: 8px;
    padding: 15px 14px;
    border-radius: 7px;
    color: white;
    text-decoration: none;
    font-weight: bold;
}

.sidebar a:hover,
.sidebar a.active {
    background: #24569b;
}

.content {
    flex: 1;
    min-width: 0;
    padding: 35px;
}

.eyebrow {
    color: #805900;
    font-size: 13px;
    font-weight: bold;
    letter-spacing: 1.4px;
}

h1 {
    margin: 10px 0;
    color: #0b3b82;
    font-size: 32px;
}

h2,
h3 {
    color: #0b3b82;
}

.subtitle {
    color: #52627a;
    line-height: 1.6;
    margin: 0 0 20px;
}

.panel {
    background: white;
    border: 1px solid #d8e1ee;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 20px;
    box-shadow: 0 4px 14px rgba(11, 59, 130, 0.05);
}

.panel h2,
.panel h3 {
    margin-top: 0;
}

.picker {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 15px;
    align-items: end;
}

.add-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
    align-items: end;
}

.field label {
    display: block;
    margin-bottom: 7px;
    color: #43536b;
    font-size: 14px;
    font-weight: bold;
}

.field input,
.field select {
    width: 100%;
    min-height: 42px;
    padding: 10px;
    border: 1px solid #d8e1ee;
    border-radius: 7px;
    background: white;
    color: #172b4d;
    font: inherit;
}

.field textarea {
    width: 100%;
    min-height: 80px;
    padding: 10px;
    border: 1px solid #d8e1ee;
    border-radius: 7px;
    background: white;
    color: #172b4d;
    font: inherit;
    resize: vertical;
}

.field input[readonly] {
    background: #f6f8fc;
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

/* Floors checklist (navigation point form) */
.floor-checks {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 8px;
    padding: 12px;
    border: 1px solid #d8e1ee;
    border-radius: 7px;
    background: #f8faff;
}

.field .check-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: #172b4d;
    font-weight: normal;
    cursor: pointer;
}

.field .check-item input[type="checkbox"] {
    width: 16px;
    min-height: 0;
    height: 16px;
    margin: 0;
    padding: 0;
    cursor: pointer;
}

.field-hint {
    margin: 8px 0 0;
    color: #52627a;
    font-size: 13px;
    line-height: 1.5;
}

/* Category color legend */
.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin: 0 0 14px;
    color: #43536b;
    font-size: 14px;
    font-weight: bold;
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}

.legend-dot {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    border: 1px solid rgba(0, 0, 0, 0.2);
}

button,
.btn {
    display: inline-block;
    padding: 12px 16px;
    border: 0;
    border-radius: 7px;
    background: #0b3b82;
    color: white;
    text-decoration: none;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

button:hover,
.btn:hover {
    filter: brightness(.94);
}

button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    filter: none;
}

.gold {
    background: #d9a900;
    color: #172b4d;
}

.light {
    background: #edf3fc;
    color: #0b3b82;
}

.btn-blue {
    background: #0b3b82;
    color: #fff;
}

.btn-red {
    background: #b42318;
    color: #fff;
}

.notice {
    padding: 13px 16px;
    border-radius: 7px;
    margin-bottom: 18px;
}

.success {
    background: #edf8ef;
    border: 1px solid #b9e2c0;
    color: #216e39;
}

.error {
    background: #fff0ee;
    border: 1px solid #f2c3bd;
    color: #a1261c;
}

.undo-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
    color: #52627a;
    font-size: 13px;
    line-height: 1.5;
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
    font-weight: bold;
    cursor: pointer;
    user-select: none;
}

.align-toggle input {
    width: 16px;
    height: 16px;
    margin: 0;
    cursor: pointer;
}

.editor-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 270px;
    gap: 18px;
    align-items: start;
}

.layout-column {
    min-width: 0;
}

.canvas-scroll {
    width: 100%;
    overflow: auto;
    border: 1px solid #d8e0ec;
    border-radius: 8px;
    background: white;
}

#roomCanvas {
    position: relative;
    width: 1200px;
    height: 650px;
    overflow: hidden;
    background-color: #fbfcff;
    background-image:
        linear-gradient(#e7ecf4 1px, transparent 1px),
        linear-gradient(90deg, #e7ecf4 1px, transparent 1px);
    background-size: 20px 20px;
}

#previousFloorLayer {
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
}

.canvas-label {
    position: absolute;
    top: 12px;
    left: 15px;
    color: #9aa5b5;
    font-size: 13px;
    pointer-events: none;
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

.room-shape {
    position: absolute;
    z-index: 1;
    min-width: 60px;
    min-height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 6px;
    border: 2px solid #0b3b82;
    border-radius: 0;
    background: #0b3b82;
    color: #ffffff;
    text-align: center;
    cursor: move;
    user-select: none;
    touch-action: none;
}

.previous-floor-shape {
    opacity: 0.15;
    cursor: default;
    pointer-events: none;
    user-select: none;
    touch-action: auto;
}

/* Hallways, corridors and walkways can be long and thin */
.room-shape.nav-shape {
    min-width: 20px;
    min-height: 20px;
    padding: 2px 4px;
    overflow: hidden;
}

.room-shape.nav-shape .resize-handle {
    right: -2px;
    bottom: -2px;
}

.room-shape.staircase-shape {
    border: 3px solid #646b73 !important;
    background-color: #aeb4bb !important;
    background-image:
        repeating-linear-gradient(
            to bottom,
            transparent 0,
            transparent 12px,
            rgba(72, 78, 85, 0.72) 12px,
            rgba(72, 78, 85, 0.72) 14px
        ),
        linear-gradient(135deg, #bfc4ca, #8d949c) !important;
    box-shadow: inset 7px 0 0 rgba(55, 61, 68, 0.16),
                inset -7px 0 0 rgba(55, 61, 68, 0.16);
    transform-origin: center center;
}

/* CSS-drawn switchback staircase. No image file is used. */
.room-shape.switchback-staircase {
    border: 3px solid #454a50 !important;
    border-radius: 0 !important;
    background: #a4a5a5 !important;
    box-shadow: none !important;
    overflow: visible !important;
    color: #30363b !important;
}
.switchback-diagram { position:absolute; inset:0; display:flex; flex-direction:column; background:#a4a5a5; pointer-events:none; }
.switchback-landing { height:35%; flex:0 0 35%; border-bottom:2px solid #454a50; }
.switchback-flights { height:65%; min-height:0; display:flex; position:relative; flex:1; }
.switchback-flight { flex:1; min-width:0; display:flex; flex-direction:column; }
.switchback-step { flex:1; min-height:0; border-bottom:1.5px solid #454a50; display:flex; align-items:center; justify-content:center; }
.switchback-step:last-child { border-bottom:0; }
.switchback-well { width:9%; flex:0 0 9%; margin-top:-2px; background:#a4a5a5; border-left:2px solid #454a50; border-right:2px solid #454a50; border-bottom:2px solid #454a50; }
.switchback-arrow { font-size:clamp(10px, 1.8em, 24px); line-height:1; font-weight:800; color:#30363b; }
.room-shape.switchback-staircase .room-label { position:absolute; z-index:3; left:50%; top:50%; transform:translate(-50%,-50%); background:rgba(75,82,89,.9); color:#fff; padding:2px 4px; border-radius:3px; white-space:nowrap; pointer-events:none; }
.room-shape.switchback-staircase.selected { outline:4px solid #f59e0b !important; outline-offset:2px; z-index:20; }

.room-shape.staircase-shape::before,
.room-shape.staircase-shape::after {
    content: "";
    position: absolute;
    top: 3px;
    bottom: 3px;
    width: 3px;
    background: rgba(72, 78, 85, 0.72);
    pointer-events: none;
}

.room-shape.staircase-shape::before { left: 7px; }
.room-shape.staircase-shape::after { right: 7px; }
.room-shape.staircase-shape .resize-handle {
    z-index: 30;
    transform: rotate(0deg);
    transform-origin: center center;
}

.room-shape.staircase-shape .room-label {
    position: relative;
    z-index: 2;
    padding: 2px 4px;
    border-radius: 3px;
    background: rgba(75, 82, 89, 0.88);
    color: #fff;
}

.hallway-marker-layer {
    position: absolute;
    z-index: 8;
    inset: 0;
    pointer-events: none;
}

.hallway-section-marker {
    position: absolute;
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 2px solid #fff;
    border-radius: 50%;
    background: rgba(217, 169, 0, 0.58);
    color: #172b4d;
    font-size: 0;
    font-weight: 800;
    opacity: 0.58;
    line-height: 1;
    cursor: pointer;
    pointer-events: auto;
    box-shadow: 0 1px 5px rgba(0, 0, 0, 0.42);
}

.hallway-section-marker:hover,
.hallway-section-marker:focus-visible {
    outline: 3px solid #fff;
    outline-offset: 2px;
    background: rgba(240, 187, 25, 0.72);
    opacity: 0.78;
}

.room-shape.selected {
    box-shadow: 0 0 0 3px #d9a900;
    z-index: 5;
}

.room-label {
    font-size: 12px;
    font-weight: bold;
    white-space: pre-line;
    overflow-wrap: anywhere;
    pointer-events: none;
}

.resize-handle {
    position: absolute;
    left: 0;
    top: 0;
    width: 13px;
    height: 13px;
    border: 2px solid white;
    border-radius: 3px;
    background: #d9a900;
    cursor: nwse-resize;
    z-index: 20;
}

.details .field {
    margin-bottom: 12px;
}

.details h3 {
    margin-bottom: 18px;
}

.hallway-sections-editor {
    display: grid;
    gap: 10px;
}

.hallway-sections-editor.is-collapsed {
    display: none;
}
#hallwayAreaSelector { padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
.hallway-section-marker { touch-action: none; cursor: grab; }
.hallway-section-marker.dragging { cursor: grabbing; outline: 3px solid #d9a900; }

.hallway-section-row {
    padding: 12px;
    border: 1px solid #d8e1ee;
    border-radius: 8px;
    background: #f8faff;
}

.hallway-section-row h4 {
    margin: 0 0 8px;
    color: #0b3b82;
    font-size: 14px;
}

.hallway-section-row .field {
    margin-bottom: 8px;
}

.hallway-section-row .section-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.hallway-section-row .section-controls button {
    min-height: 36px;
    padding: 8px 10px;
    font-size: 12px;
}

.hallway-section-row .place-hallway-marker.active {
    background: #d09b00;
    color: #172b4d;
}

.hallway-marker-status {
    display: block;
    min-height: 18px;
    margin-top: 8px;
    color: #6b5200;
    font-size: 12px;
    font-weight: 700;
}

.room-list-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.room-list-heading h3 { margin:0; }
.room-list-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.room-list-toggle { border:1px solid #c8d5e7; background:#f3f7fc; color:#123d78; font-weight:700; border-radius:9px; padding:9px 13px; cursor:pointer; transition:background .18s ease, transform .18s ease; }
.room-list-toggle:hover { background:#e4edf9; transform:translateY(-1px); }
#roomListWrap { overflow:hidden; transition:opacity .18s ease; }
#roomListWrap[hidden] { display:none; }

.add-form-toggle-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin:18px 0 10px; }
.add-form-toggle-row h3 { margin:0; }
.add-form-toggle { border:1px solid #c8d5e7; border-radius:9px; padding:10px 15px; background:#123f7b; color:#fff; font-weight:700; cursor:pointer; transition:background .18s ease, transform .18s ease; }
.add-form-toggle:hover { background:#0b2e5d; transform:translateY(-1px); }
#addRoomForm[hidden], #addNavForm[hidden] { display:none !important; }

input[type="file"] { display:block; width:100%; max-width:100%; box-sizing:border-box; padding:10px; border:1px dashed #9db2ce; border-radius:12px; background:linear-gradient(135deg,#f8fbff,#edf4fc); color:#344966; font:inherit; cursor:pointer; }
input[type="file"]::file-selector-button { margin-right:12px; padding:9px 13px; border:0; border-radius:8px; background:#123f7b; color:#fff; font-weight:700; cursor:pointer; transition:background .18s ease; }
input[type="file"]::file-selector-button:hover { background:#0b2e5d; }
.existing-photo-hint { padding:8px 10px; border-radius:8px; background:#fff8df; color:#745600; }
.room-list-hint { margin:6px 0 10px; color:#667085; font-size:12px; }
.room-list {
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
}

.room-list-item {
    display: flex;
    align-items: stretch;
    gap: 6px;
    min-width: 0;
}

.room-list .room-list-delete {
    flex: 0 0 auto;
    padding: 8px 10px;
}

.room-list .room-list-select {
    flex: 1;
    min-width: 0;
    text-align: left;
    background: #edf3fc;
    color: #172b4d;
    border-left: 6px solid #0b3b82;
}

.room-list .room-list-select.active {
    background: #fff8d8;
    outline: 1px solid #d9a900;
}

footer {
    padding: 20px;
    text-align: center;
    background: white;
    border-top: 1px solid #dce4ef;
    color: #43536b;
    font-size: 14px;
}

@media (max-width: 1100px) {
    .editor-grid {
        grid-template-columns: 1fr;
    }

    .picker {
        grid-template-columns: 1fr 1fr;
    }

    .picker button {
        grid-column: 1 / -1;
    }

    .add-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 800px) {
    .admin-layout {
        flex-direction: column;
    }

    .sidebar {
        width: 100%;
        padding: 18px;
    }

    .content {
        padding: 22px 15px;
    }

    .navbar {
        flex-direction: column;
        align-items: flex-start;
    }

    .picker,
    .add-grid {
        grid-template-columns: 1fr;
    }
}

/* UNSAVED CHANGES MODAL */

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
}

.modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 10px;
}

.upload-image-preview { margin-top: 12px; }
.upload-image-preview img { display:block; max-width:100%; max-height:220px; border:1px solid #d8e1ee; border-radius:8px; cursor:zoom-in; }
.upload-image-preview .field-hint { margin-top:6px; }
.image-viewer-overlay { display:none; position:fixed; inset:0; z-index:9999; background:rgba(5,15,35,.92); padding:18px; }
.image-viewer-overlay.open { display:flex; flex-direction:column; }
.image-viewer-toolbar { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:8px; padding-bottom:12px; }
.image-viewer-toolbar button, .image-viewer-toolbar a { border:0; border-radius:6px; padding:10px 14px; background:#fff; color:#0b3b82; font-weight:bold; cursor:pointer; text-decoration:none; }
.image-viewer-toolbar .viewer-close { background:#b42318; color:#fff; }
.image-viewer-stage { flex:1; min-height:0; overflow:hidden; display:flex; align-items:center; justify-content:center; cursor:grab; touch-action:none; }
.image-viewer-stage.dragging { cursor:grabbing; }
.image-viewer-stage img { max-width:none; max-height:none; transform-origin:center center; user-select:none; -webkit-user-drag:none; }
.viewer-direction-hint { position:absolute; left:50%; bottom:22px; transform:translateX(-50%); max-width:calc(100% - 32px); padding:9px 13px; border-radius:20px; background:rgba(0,0,0,.62); color:#fff; font-size:12px; text-align:center; pointer-events:none; }
#viewerStage { position:relative; }
.image-viewer-toolbar button { min-height:38px; }
</style>
<link rel="stylesheet" href="../mobile.css">
</head>

<body>

<header class="navbar">
    <div class="logo">
        <img src="../cmu logo.jpg" alt="CMU Logo">

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

    <main class="content">

        <span class="eyebrow">ADMINISTRATION</span>
        <h1>Room Layout Editor</h1>

        <p class="subtitle">
            Add rooms and arrange their positions on the selected building floor.
        </p>

        <?php if ($message): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="notice error"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="panel">
            <h2>Select Building and Floor</h2>

            <form method="GET" action="add-room.php" class="picker">

                <?php if ($presetType !== ""): ?>
                    <input type="hidden" name="type" value="<?= e($presetType) ?>">
                <?php endif; ?>

                <div class="field">
                    <label for="building_id">Building *</label>

                    <select name="building_id" id="building_id" required>
                        <option value="">Choose a building</option>

                        <?php foreach ($buildings as $building): ?>
                            <option
                                value="<?= (int)$building["building_id"] ?>"
                                data-floors="<?= (int)$building["total_floors"] ?>"
                                <?= $buildingId === (int)$building["building_id"] ? "selected" : "" ?>
                            >
                                <?= e($building["building_name"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="floor">Floor *</label>

                    <select
                        name="floor"
                        id="floor"
                        required
                        <?= $buildingId ? "" : "disabled" ?>
                    >
                        <option value="">Choose a floor</option>

                        <?php foreach ($floors as $floor): ?>
                            <option
                                value="<?= e($floor) ?>"
                                <?= $selectedFloor === $floor ? "selected" : "" ?>
                            >
                                <?= e($floor) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">Open Layout</button>
            </form>
        </section>

        <?php if (
            $selectedBuilding &&
            $selectedFloor !== "" &&
            in_array($selectedFloor, $floors, true)
        ): ?>

        <section class="panel">

            <h2>
                <?= e($selectedBuilding["building_name"]) ?>
                — <?= e($selectedFloor) ?>
            </h2>

            <p class="subtitle">
                Add room details and arrange the rooms directly on the layout.
            </p>

            <div class="add-form-toggle-row" style="display:flex;flex-direction:column;align-items:flex-start;gap:10px">
                <h3>Add New Room</h3>
                <button type="button" class="add-form-toggle" id="toggleAddRoomForm" aria-expanded="false" aria-controls="addRoomForm">Add New Room</button>
            </div>

            <form method="POST" action="add-room.php" class="panel" id="addRoomForm" enctype="multipart/form-data" hidden>

                <input type="hidden" name="building_id" value="<?= $buildingId ?>">
                <input type="hidden" name="floor" value="<?= e($selectedFloor) ?>">

                <?php if ($presetType !== ""): ?>
                    <input type="hidden" name="type" value="<?= e($presetType) ?>">
                <?php endif; ?>

                <div class="add-grid">

                    <div class="field">
                        <label for="new_room_type">Type *</label>
                        <select id="new_room_type" name="new_room_type" required>
                            <?php if ($presetType === ""): ?>
                                <option value="">Choose a type</option>
                            <?php endif; ?>

                            <?php foreach ($typeOptions as $type): ?>
                                <?php if ($presetType === "" || $presetType === $type): ?>
                                    <option value="<?= e($type) ?>" <?= $presetType === $type ? "selected" : "" ?>>
                                        <?= e($type) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field hidden" id="roomNumberField">
                        <label for="new_room_number">Room Number *</label>
                        <input
                            id="new_room_number"
                            name="new_room_number"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="50"
                            placeholder="Example: 101"
                            title="Numbers only"
                        >
                    </div>

                    <div class="field">
                        <label for="new_room_name">Room Name *</label>
                        <select
                            id="new_room_name"
                            name="new_room_name"
                            required
                            disabled
                        >
                            <option value="">Choose a type first</option>
                        </select>
                    </div>

                    <div class="field full hidden" id="otherRoomNameField">
                        <label for="new_room_name_other">Custom Room Name *</label>
                        <input
                            id="new_room_name_other"
                            name="new_room_name_other"
                            type="text"
                            maxlength="150"
                            placeholder="Type the room name"
                        >
                    </div>

                </div>

                <button
                    type="submit"
                    name="add_room"
                    value="1"
                    class="gold"
                    style="margin-top:15px"
                >
                    Add Room
                </button>

            </form>

            <?php if ($presetType === ""): ?>

            <div class="add-form-toggle-row" style="display:flex;flex-direction:column;align-items:flex-start;gap:10px">
                <h3>Add Navigation Point</h3>
                <button type="button" class="add-form-toggle" id="toggleAddNavForm" aria-expanded="false" aria-controls="addNavForm">Add Navigation Point</button>
            </div>

            <p class="subtitle">
                Hallways, corridors, staircases, elevators and walkways, so
                visitors can locate them on the floor plan.
            </p>

            <form method="POST" action="add-room.php" class="panel" id="addNavForm" enctype="multipart/form-data" hidden>

                <input type="hidden" name="building_id" value="<?= $buildingId ?>">
                <input type="hidden" name="floor" value="<?= e($selectedFloor) ?>">

                <div class="add-grid">

                    <div class="field">
                        <label for="nav_type">Location Type *</label>
                        <select id="nav_type" name="nav_type" required>
                            <option value="">Choose a location type</option>

                            <?php foreach ($navTypeOptions as $navOption): ?>
                                <option value="<?= e($navOption) ?>">
                                    <?= e($navOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field hidden" id="navStairField">
                        <label for="nav_staircase_type">Staircase Type *</label>
                        <select id="nav_staircase_type" name="nav_staircase_type" disabled>
                            <option value="">Choose a staircase type</option>

                            <?php foreach ($staircaseTypeOptions as $stairOption): ?>
                                <option value="<?= e($stairOption) ?>">
                                    <?= e($stairOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field full hidden" id="navFloorsField">
                        <label>Floors Connected *</label>

                        <div class="floor-checks">
                            <?php foreach ($floors as $floorOption): ?>
                                <?php $optionNumber = floorNumber($floorOption); ?>

                                <label class="check-item">
                                    <input
                                        type="checkbox"
                                        class="nav-floor-check"
                                        name="nav_floors[]"
                                        value="<?= $optionNumber ?>"
                                        <?= $optionNumber === floorNumber($selectedFloor) ? "checked" : "" ?>
                                        disabled
                                    >
                                    <?= e($floorOption) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <p class="field-hint">
                            Tick every floor this staircase or elevator reaches.
                            It is saved once per floor and linked together, and the
                            floor you are editing is always included.
                        </p>
                    </div>

                </div>

                <button
                    type="submit"
                    name="add_navigation_point"
                    value="1"
                    class="gold"
                    style="margin-top:15px"
                >
                    Add Navigation Point
                </button>

            </form>

            <?php endif; ?>

        </section>

        <section class="panel layout-editor-panel">
            <h2>Layout Editor — <?= e($selectedBuilding["building_name"]) ?>, <?= e($selectedFloor) ?></h2>
            <p class="subtitle">Arrange rooms and navigation points on this floor plan.</p>
            <h3>Room Layout</h3>

            <div class="legend">
                <?php foreach ($categoryColors as $categoryName => $categoryColor): ?>
                    <span class="legend-item">
                        <span
                            class="legend-dot"
                            style="background: <?= e($categoryColor) ?>"
                        ></span>
                        <?= e($categoryName) ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <form method="POST" action="add-room.php" id="deleteItemForm" hidden>
                <input type="hidden" name="building_id" value="<?= $buildingId ?>">
                <input type="hidden" name="floor" value="<?= e($selectedFloor) ?>">
                <input type="hidden" name="delete_item" value="1">
                <div id="deleteItemsInputs"></div>
                <?php if ($presetType !== ""): ?>
                    <input type="hidden" name="type" value="<?= e($presetType) ?>">
                <?php endif; ?>
            </form>

            <form method="POST" action="add-room.php" id="layoutForm" enctype="multipart/form-data">

                <input type="hidden" name="building_id" value="<?= $buildingId ?>">
                <input type="hidden" name="floor" value="<?= e($selectedFloor) ?>">

                <?php if ($presetType !== ""): ?>
                    <input type="hidden" name="type" value="<?= e($presetType) ?>">
                <?php endif; ?>

                <div class="undo-bar">
                    <button type="button" class="light" id="undoBtn"
                            title="Undo the last change (Ctrl+Z)" disabled>
                        Undo
                    </button>

                    <button type="button" class="light" id="copyBtn"
                            title="Copy the selected room, office, or Hallway (Ctrl+C)" disabled>
                        Copy
                    </button>

                    <button type="button" class="light" id="pasteBtn"
                            title="Paste a copied room, office, or Hallway (Ctrl+V)" disabled>
                        Paste
                    </button>

                    <label class="align-toggle" title="Snap rooms to each other while moving">
                        <input type="checkbox" id="autoAlign" checked>
                        Auto Align
                    </label>

                    <span>
                        Move, resize, rename, or copy and paste a room, then click Undo to go back one step.
                        With Auto Align on, a room snaps to other rooms and the layout edges
                        (hold Alt while dragging to move freely). Hold Ctrl/Command or Shift and click multiple objects, then drag one selected object to move them together.
                    </span>
                </div>

                <div class="editor-grid">

                    <div class="layout-column">
                    <div class="canvas-scroll">
                        <div id="roomCanvas">
                            <div id="previousFloorLayer" aria-hidden="true"></div>
                            <div class="canvas-label">
                                <?= e($selectedBuilding["building_name"]) ?>
                                — <?= e($selectedFloor) ?>
                            </div>
                        </div>
                    </div>

                    <div class="panel" style="margin-top:14px">
                        <div class="room-list-heading">
                            <h3>Room List</h3>
                            <div class="room-list-actions">
                                <button type="button" class="light room-list-toggle" id="toggleRoomListBtn" aria-expanded="false" aria-controls="roomListWrap">Show List</button>
                                <button type="button" class="room-list-delete light" id="deleteSelectedBtn" disabled>Delete Selected</button>
                            </div>
                        </div>
                        <div id="roomListWrap" hidden>
                            <p class="room-list-hint">Ctrl-click (or Command-click on Mac) to select multiple items.</p>
                            <div class="room-list" id="roomList"></div>
                        </div>
                    </div>
                    </div>

                    <aside class="panel details">

                        <h3>Room Information</h3>

                        <div class="field">
                            <label for="detailType">Type</label>
                            <input
                                id="detailType"
                                type="text"
                                readonly
                                placeholder="Select a room"
                            >
                        </div>

                        <div class="field">
                            <label for="detailNumber">Room Number</label>
                            <input
                                id="detailNumber"
                                type="text"
                                inputmode="numeric"
                                maxlength="50"
                                placeholder="Select a room"
                            >
                        </div>


                        <div class="field hidden" id="detailLinksField">
                            <label for="detailLinks">Connects Floors</label>
                            <input
                                id="detailLinks"
                                type="text"
                                readonly
                            >
                        </div>

                        <div class="field">
                            <label for="detailNameSelect">Room Name</label>
                            <select id="detailNameSelect" disabled>
                                <option value="">Select a room</option>
                            </select>
                        </div>

                        <div class="field hidden" id="detailNameCustomField">
                            <label for="detailName">Custom Room Name</label>
                            <input
                                id="detailName"
                                type="text"
                                maxlength="150"
                                placeholder="Type the room name"
                            >
                        </div>

                        <div class="field">
                            <label for="detailX">Position X</label>
                            <input id="detailX" type="number" readonly>
                        </div>

                        <div class="field">
                            <label for="detailY">Position Y</label>
                            <input id="detailY" type="number" readonly>
                        </div>

                        <div class="field">
                            <label for="detailWidth">Width (px)</label>
                            <input
                                id="detailWidth"
                                type="number"
                                step="any"
                                disabled
                            >
                        </div>

                        <div class="field">
                            <label for="detailHeight">Height (px)</label>
                            <input
                                id="detailHeight"
                                type="number"
                                step="any"
                                disabled
                            >
                        </div>
                        <div class="field hidden" id="detailStairRotationField">
                            <label for="detailStairRotation">Switchback rotation (degrees)</label>
                            <div style="display:flex;gap:6px;align-items:center">
                                <input id="detailStairRotation" type="number" min="-360" max="360" step="15" value="0" style="flex:1;min-width:0">
                                <button type="button" class="light" id="rotateStairLeft" title="Rotate left 15 degrees">↶ 15°</button>
                                <button type="button" class="light" id="rotateStairRight" title="Rotate right 15 degrees">↷ 15°</button>
                            </div>
                            <p class="field-hint">Only rotates a selected Switchback staircase. Save Layout to keep the angle.</p>
                        </div>

                         <div class="field hidden" id="detailAttachmentField">
                            <label>Current Attachment</label>
                            <div class="field-hint" id="currentAttachment">No attachment saved.</div>
                            <div id="attachmentUploadControl">
                                <label for="detailAttachment">Image (JPG/JPEG, optional)</label>
                                <input
                                    id="detailAttachment"
                                    type="file"
                                    accept=".jpg,.jpeg,image/jpeg"
                                    disabled
                                >
                            </div>
                            <button type="button" class="light" id="removeAttachmentButton" hidden>Remove Image</button>
                            <input id="removeAttachment" type="checkbox" disabled hidden>
                            <p class="field-hint">JPG/JPEG images only; maximum 10 MB.</p>
                        </div>

                        <div class="field hidden" id="hallwaySectionsField">
                            <label>Hallway Areas</label>
                            <p class="field-hint">
                                Add an area, enter its name, and choose a picture. Click
                                “Place marker,” then click the desired point inside the
                                hallway. Save each area separately.
                            </p>
                            <?php if (!$hallwaySectionStorageAvailable): ?>
                                <p class="error">
                                    Hallway section storage could not be initialized.
                                    Check that the database user can create tables.
                                </p>
                            <?php endif; ?>
                            <input
                                type="hidden"
                                id="hallwaySectionPointId"
                                name="hallway_section_point_id"
                                value=""
                            >
                            <div class="hallway-area-toolbar" style="display:flex;gap:8px;align-items:center;margin:0 0 10px;flex-wrap:wrap">
                                <button type="button" class="gold" id="addHallwaySection">+ Add Area</button>
                                <label for="hallwayAreaSelector" style="margin:0">Open area:</label><select id="hallwayAreaSelector" style="max-width:180px"><option value="">Select an area</option></select>
                            </div>
                            <div class="hallway-sections-editor" id="hallwaySectionsEditor"></div>
                            <span class="hallway-marker-status" id="hallwayMarkerStatus" aria-live="polite"></span>
                        </div>

                    </aside>

                </div>

                <button type="submit" name="save_layout" value="1">
                    Save Layout
                </button>

                <a href="rooms.php" class="btn light">
                    Manage Room Details
                </a>

            </form>

        </section>

        <?php endif; ?>

    </main>
</div>

<footer>
    © <?= date("Y") ?> CMU Campus Navigator | City of Malabon University
</footer>

<div class="modal-backdrop" id="unsavedModal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="unsavedTitle">
        <div class="modal-icon">⚠️</div>
        <h2 id="unsavedTitle">Unsaved Changes</h2>
        <p>
            You have unsaved changes in the room editing section.
            If you leave this page now, your changes will be lost.
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-blue" id="stayBtn">Stay on Page</button>
            <button type="button" class="btn-red" id="leaveBtn">Leave Without Saving</button>
        </div>
    </div>
</div>

<script>
const PRESET_TYPE = <?= json_encode($presetType) ?>;
const HALLWAY_SECTION_STORAGE_AVAILABLE = <?= $hallwaySectionStorageAvailable ? "true" : "false" ?>;

/* Floor being edited (used by the navigation point form) */
const NAV_CURRENT_FLOOR = <?= (int)floorNumber($selectedFloor) ?>;

/* Set below once the navigation point form is ready (unsaved-changes warning) */
let navFormHasChanges = () => false;

const buildingSelect = document.getElementById("building_id");
const floorSelect = document.getElementById("floor");

if (buildingSelect && floorSelect) {
    buildingSelect.addEventListener("change", () => {
        floorSelect.value = "";
        floorSelect.disabled = true;

        const selectedOption =
            buildingSelect.options[buildingSelect.selectedIndex];

        const totalFloors = parseInt(
            selectedOption?.dataset.floors || "0",
            10
        );

        floorSelect.innerHTML =
            '<option value="">Choose a floor</option>';

        for (let i = 1; i <= totalFloors; i++) {
            let suffix;

            if (i % 100 >= 11 && i % 100 <= 13) {
                suffix = "th";
            } else {
                switch (i % 10) {
                    case 1: suffix = "st"; break;
                    case 2: suffix = "nd"; break;
                    case 3: suffix = "rd"; break;
                    default: suffix = "th";
                }
            }

            const floorName = i + suffix + " Floor";
            const option = document.createElement("option");

            option.value = floorName;
            option.textContent = floorName;

            floorSelect.appendChild(option);
        }

        floorSelect.disabled = !selectedOption?.value || totalFloors <= 0;
    });
}

/* Add New Room: Type controls Room Number and Room Name */
const roomNameOptions = <?= json_encode(
    $roomNameOptions,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const CATEGORY_COLORS = <?= json_encode(
    $categoryColors,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const typeSelect = document.getElementById("new_room_type");
const numberField = document.getElementById("roomNumberField");
const numberInput = document.getElementById("new_room_number");
const nameSelect = document.getElementById("new_room_name");
const otherField = document.getElementById("otherRoomNameField");
const otherInput = document.getElementById("new_room_name_other");

if (typeSelect && numberField && numberInput && nameSelect && otherField && otherInput) {

    function toggleOtherName() {
        const isOther = nameSelect.value === "Others";

        otherField.classList.toggle("hidden", !isOther);
        otherInput.required = isOther;

        if (isOther) {
            otherInput.focus();
        } else {
            otherInput.value = "";
        }
    }

    function updateTypeFields() {
        const type = typeSelect.value;

        /* Room Number: only for Room, and numbers only */
        const showNumber = type === "Room";

        numberField.classList.toggle("hidden", !showNumber);
        numberInput.required = showNumber;
        numberInput.disabled = !showNumber;
        if (!showNumber) {
            numberInput.value = "";
        }

        /* Room Name options based on Type */
        nameSelect.innerHTML = "";

        if (!type || !roomNameOptions[type]) {
            nameSelect.innerHTML =
                '<option value="">Choose a type first</option>';
            nameSelect.disabled = true;
        } else {
            const placeholder = document.createElement("option");
            placeholder.value = "";
            placeholder.textContent = "Choose a room name";
            nameSelect.appendChild(placeholder);

            roomNameOptions[type].forEach(name => {
                const option = document.createElement("option");
                option.value = name;
                option.textContent = name;
                nameSelect.appendChild(option);
            });

            const other = document.createElement("option");
            other.value = "Others";
            other.textContent = "Others";
            nameSelect.appendChild(other);

            nameSelect.disabled = false;
        }

        toggleOtherName();
    }

    /* Keep only digits in Room Number */
    numberInput.addEventListener("input", () => {
        numberInput.value = numberInput.value.replace(/\D/g, "");
    });

    typeSelect.addEventListener("change", updateTypeFields);

    nameSelect.addEventListener("change", () => {
        toggleOtherName();
    });

    updateTypeFields();
}

/* Add Navigation Point: type controls the staircase type and floors list */
const navForm = document.getElementById("addNavForm");

if (navForm) {
    const navType = document.getElementById("nav_type");
    const navStairField = document.getElementById("navStairField");
    const navStairType = document.getElementById("nav_staircase_type");
    const navFloorsField = document.getElementById("navFloorsField");
    const navFloorChecks = Array.from(navForm.querySelectorAll(".nav-floor-check"));

    function updateNavFields() {
        const type = navType.value;
        const isStair = type === "Staircase";
        const isVertical = isStair || type === "Elevator";
        const isHallway = type === "Hallway";

        /* Staircase type: only for staircases */
        navStairField.classList.toggle("hidden", !isStair);
        navStairType.required = isStair;
        navStairType.disabled = !isStair;

        if (!isStair) {
            navStairType.value = "";
        }

        /* Floors connected: only for staircases and elevators */
        navFloorsField.classList.toggle("hidden", !isVertical);

        navFloorChecks.forEach(box => {
            box.disabled = !isVertical;
        });
    }

    /* The floor being edited always stays ticked */
    navFloorChecks.forEach(box => {
        box.addEventListener("click", () => {
            if (Number(box.value) === NAV_CURRENT_FLOOR) {
                box.checked = true;
            }
        });
    });

    navType.addEventListener("change", updateNavFields);

    navFormHasChanges = () => navType.value !== "";

    updateNavFields();
}

const initialRooms = <?= json_encode(
    $rooms,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const previousFloorRooms = <?= json_encode(
    $referenceRooms,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;


function wireAddFormToggle(buttonId, formId, openText, closeText) {
    const toggle = document.getElementById(buttonId);
    const target = document.getElementById(formId);
    if (!toggle || !target) return;
    toggle.addEventListener("click", () => {
        const willOpen = target.hidden;
        target.hidden = !willOpen;
        toggle.setAttribute("aria-expanded", String(willOpen));
        toggle.textContent = willOpen ? closeText : openText;
        if (willOpen) target.scrollIntoView({ behavior: "smooth", block: "nearest" });
    });
}
wireAddFormToggle("toggleAddRoomForm", "addRoomForm", "Add New Room", "Hide Room Form");
wireAddFormToggle("toggleAddNavForm", "addNavForm", "Add Navigation Point", "Hide Navigation Form");

const canvas = document.getElementById("roomCanvas");
const form = document.getElementById("layoutForm");
const list = document.getElementById("roomList");
const previousFloorLayer = document.getElementById("previousFloorLayer");
const deleteForm = document.getElementById("deleteItemForm");
const deleteItemsInputs = document.getElementById("deleteItemsInputs");
const deleteSelectedBtn = document.getElementById("deleteSelectedBtn");
const toggleRoomListBtn = document.getElementById("toggleRoomListBtn");
const roomListWrap = document.getElementById("roomListWrap");
if (toggleRoomListBtn && roomListWrap) {
    toggleRoomListBtn.addEventListener("click", () => {
        const willHide = !roomListWrap.hidden;
        roomListWrap.hidden = willHide;
        toggleRoomListBtn.textContent = willHide ? "Show List" : "Hide List";
        toggleRoomListBtn.setAttribute("aria-expanded", String(!willHide));
    });
}

/* Set below once the layout editor is ready; used by the unsaved-changes warning */
let layoutHasChanges = () => false;

if (canvas && form && list) {

    const CANVAS_W = 1200;
    const CANVAS_H = 650;

    /* Auto align: how close (in pixels) before a room snaps */
    const SNAP_DISTANCE = 8;

    const detailType = document.getElementById("detailType");
    const detailNumber = document.getElementById("detailNumber");
    const detailLinksField = document.getElementById("detailLinksField");
    const detailLinks = document.getElementById("detailLinks");
    const detailNameSelect = document.getElementById("detailNameSelect");
    const detailNameCustomField = document.getElementById("detailNameCustomField");
    const detailName = document.getElementById("detailName");
    const detailX = document.getElementById("detailX");
    const detailY = document.getElementById("detailY");
    const detailWidth = document.getElementById("detailWidth");
    const detailHeight = document.getElementById("detailHeight");
    const detailStairRotationField = document.getElementById("detailStairRotationField");
    const detailStairRotation = document.getElementById("detailStairRotation");
    const rotateStairLeft = document.getElementById("rotateStairLeft");
    const rotateStairRight = document.getElementById("rotateStairRight");
    const detailAttachmentField = document.getElementById("detailAttachmentField");
    const detailAttachment = document.getElementById("detailAttachment");
    const attachmentUploadControl = document.getElementById("attachmentUploadControl");
    const currentAttachment = document.getElementById("currentAttachment");
    const removeAttachment = document.getElementById("removeAttachment");
    const removeAttachmentButton = document.getElementById("removeAttachmentButton");
    const hallwaySectionsField = document.getElementById("hallwaySectionsField");
    const hallwaySectionsEditor = document.getElementById("hallwaySectionsEditor");
    const addHallwaySectionButton = document.getElementById("addHallwaySection");
    const hallwayAreaSelector = document.getElementById("hallwayAreaSelector");
    const hallwaySectionPointId = document.getElementById("hallwaySectionPointId");
    const hallwayMarkerStatus = document.getElementById("hallwayMarkerStatus");
    const autoAlign = document.getElementById("autoAlign");
    const copyBtn = document.getElementById("copyBtn");
    const pasteBtn = document.getElementById("pasteBtn");
    let activeMarkerSection = null;
    let sectionEditorFor = null;

    let openedHallwayArea = "";
    hallwayAreaSelector.addEventListener("change", () => {
        openedHallwayArea = hallwayAreaSelector.value;
        hallwaySectionsEditor.querySelectorAll(".hallway-section-row").forEach(row => {
            row.hidden = openedHallwayArea !== row.dataset.sectionNumber;
        });
    });

    /* ---------- color helpers ---------- */

    /* Color comes from the room's Type (rooms with no type use the Room color) */
    function colorFor(r) {
        if (r.kind === "n" && r.nav_type === "Hallway") {
            return "#ffffff";
        }

        if (r.kind === "n") {
            return r.nav_type === "Staircase" ? "#aeb4bb" : "#8b929b";
        }

        return CATEGORY_COLORS[r.room_type] || CATEGORY_COLORS["Room"];
    }

    function hexToRgb(hex) {
        return [
            parseInt(hex.slice(1, 3), 16),
            parseInt(hex.slice(3, 5), 16),
            parseInt(hex.slice(5, 7), 16)
        ];
    }

    function darken(hex, factor) {
        const [r, g, b] = hexToRgb(hex);
        const toHex = n => Math.round(n * factor).toString(16).padStart(2, "0");
        return "#" + toHex(r) + toHex(g) + toHex(b);
    }

    function textColorFor(hex) {
        const [r, g, b] = hexToRgb(hex);
        const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
        return luminance > 150 ? "#172b4d" : "#ffffff";
    }

    function setValue(el, value) {
        const text = String(value);
        if (el.value !== text) {
            el.value = text;
        }
    }

    /* ---------- room data ---------- */

    function nameChoicesFor(r) {
        /* Navigation points use free-text names only */
        if (r.kind === "n") {
            return [];
        }

        if (r.room_type && roomNameOptions[r.room_type]) {
            return roomNameOptions[r.room_type];
        }

        return Object.values(roomNameOptions).flat();
    }

    /* Hallways and corridors can be thinner and longer than rooms */
    function sizeLimits(r) {
        return r.kind === "n"
            ? { minW: 20, maxW: 1200, minH: 20, maxH: 650 }
            : { minW: 60, maxW: 600, minH: 45, maxH: 400 };
    }

    /* "Staircase (Switchback)" */
    function navLabel(r) {
        return r.nav_type + (r.staircase_type ? " (" + r.staircase_type + ")" : "");
    }

    /*
     * Each item has a text id ("r12" = rooms table, "o5" = offices table,
     * "f2" = facilities table, "n3" = navigation_points table)
     * so ids from different tables never clash.
     */
    let rooms = initialRooms.map((r, index) => ({
        id: String(r.uid),
        kind: String(r.uid).charAt(0),
        room_number: r.room_number || "",
        room_name: r.room_name || "",
        original_name: r.room_name || "",
        room_type: r.room_type || "",
        category: r.category || "",
        nav_type: r.nav_type || "",
        staircase_type: r.staircase_type || "",
        links: r.links || "",
        walk_links: r.walk_links || "",
        attachment_path: r.attachment_path || (String(r.uid).charAt(0) === "r" ? (r.walk_links || "") : ""),
        copy_source_id: r.copy_source_id || "",
        hallway_sections: Array.isArray(r.hallway_sections)
            ? r.hallway_sections.map(section => ({
                section_number: Number(section.section_number),
                section_name: String(section.section_name || ""),
                photo_path: String(section.photo_path || ""),
                marker_x: Number(section.marker_x ?? 50),
                marker_y: Number(section.marker_y ?? 50),
                marker_placed: true
            }))
            : [],
        custom: false,
        x: Number(r.position_x ?? (40 + (index % 5) * 190)),
        y: Number(r.position_y ?? (65 + Math.floor(index / 5) * 125)),
        width: Number(r.width || 140),
        height: Number(r.height || 90),
        rotation: Number(r.rotation || 0)
    }));

    /* A name that is not in the preset list is a custom ("Others") name */
    rooms.forEach(r => {
        r.custom = r.room_name !== "" && !nameChoicesFor(r).includes(r.room_name);
    });

    /* Snapshot of the saved state, used to detect unsaved changes */
    function roomsSnapshot() {
        return JSON.stringify(rooms.map(r => [
            r.id, r.room_number, r.room_name, r.x, r.y,
            r.width, r.height, r.rotation
        ]));
    }

    const savedRoomsSnapshot = roomsSnapshot();
    let attachmentPending = false;
    let sectionPending = false;

    layoutHasChanges = () =>
        roomsSnapshot() !== savedRoomsSnapshot || attachmentPending || sectionPending;

    const requestedSelectedId = new URLSearchParams(window.location.search)
        .get("selected_item");
    let selectedId = rooms.some(r => r.id === requestedSelectedId)
        ? requestedSelectedId
        : (rooms.length ? rooms[0].id : null);
    // Ctrl/Command-click or Shift-click allows selecting multiple layout objects.
    const selectedIds = new Set(selectedId ? [selectedId] : []);
    let interaction = null;
    let builtFor = undefined;

    function selectedRoom() {
        return rooms.find(r => r.id === selectedId) || null;
    }

    let clipboardItem = null;
    let pasteSequence = 0;

    function isTemporaryId(id) {
        return /^[ron]new\d+$/.test(String(id));
    }

    function canCopyItem(r) {
        return !!r && (
            r.kind === "r" ||
            r.kind === "o" ||
            (r.kind === "n" && r.nav_type === "Hallway")
        );
    }

    function updateCopyPasteButtons() {
        if (copyBtn) {
            copyBtn.disabled = !canCopyItem(selectedRoom());
        }

        if (pasteBtn) {
            pasteBtn.disabled = !clipboardItem;
        }
    }

    function copySelectedItem() {
        const r = selectedRoom();
        if (!canCopyItem(r)) return false;

        clipboardItem = JSON.parse(JSON.stringify(r));
        updateCopyPasteButtons();
        return true;
    }

    function pasteCopiedItem() {
        if (!clipboardItem) return false;

        const pasted = JSON.parse(JSON.stringify(clipboardItem));
        pasted.copy_source_id = pasted.copy_source_id ||
            (/^n\d+$/.test(pasted.id) ? pasted.id : "");
        pasteSequence += 1;
        pasted.id = pasted.kind + "new" + pasteSequence;
        pasted.x = Math.max(
            0,
            Math.min(CANVAS_W - pasted.width, pasted.x + 24 * pasteSequence)
        );
        pasted.y = Math.max(
            0,
            Math.min(CANVAS_H - pasted.height, pasted.y + 24 * pasteSequence)
        );
        pasted.original_name = pasted.room_name;

        pushHistory();
        rooms.push(pasted);
        selectedId = pasted.id;
        render();
        return true;
    }

    if (copyBtn) {
        copyBtn.addEventListener("click", copySelectedItem);
    }

    if (pasteBtn) {
        pasteBtn.addEventListener("click", pasteCopiedItem);
    }

    /* ---------- AUTO ALIGN ---------- */

    /* Lines a moving room can snap to: layout edges/center + other rooms */
    function snapTargets(movingId) {
        const xs = [0, CANVAS_W / 2, CANVAS_W];
        const ys = [0, CANVAS_H / 2, CANVAS_H];

        rooms.forEach(other => {
            if (other.id === movingId) return;

            xs.push(other.x, other.x + other.width / 2, other.x + other.width);
            ys.push(other.y, other.y + other.height / 2, other.y + other.height);
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

    /* Keep rotated room shapes inside the canvas using their visible bounds. */
    function clampRoomPosition(room, x, y) {
        const angle = ((Number(room.rotation) || 0) % 360) * Math.PI / 180;
        const absCos = Math.abs(Math.cos(angle));
        const absSin = Math.abs(Math.sin(angle));
        const visualWidth = room.width * absCos + room.height * absSin;
        const visualHeight = room.width * absSin + room.height * absCos;
        const minX = (visualWidth - room.width) / 2;
        const minY = (visualHeight - room.height) / 2;
        const maxX = CANVAS_W - (visualWidth + room.width) / 2;
        const maxY = CANVAS_H - (visualHeight + room.height) / 2;
        return {
            x: Math.max(minX, Math.min(maxX, x)),
            y: Math.max(minY, Math.min(maxY, y))
        };
    }

    /* Snap a proposed position and return the new one */
    function snapPosition(room, x, y) {
        const targets = snapTargets(room.id);

        const dx = bestOffset(
            [x, x + room.width / 2, x + room.width],
            targets.xs,
            SNAP_DISTANCE
        );

        const dy = bestOffset(
            [y, y + room.height / 2, y + room.height],
            targets.ys,
            SNAP_DISTANCE
        );

        return clampRoomPosition(room, x + dx, y + dy);
    }

    function clearGuides() {
        canvas.querySelectorAll(".align-guide").forEach(guide => guide.remove());
    }

    /* Draw a pink line wherever the moving room is lined up with something */
    function drawGuides(room) {
        clearGuides();

        const targets = snapTargets(room.id);
        const drawn = new Set();

        [room.x, room.x + room.width / 2, room.x + room.width].forEach(edge => {
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

        [room.y, room.y + room.height / 2, room.y + room.height].forEach(edge => {
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

    /* ---------- Undo history (a snapshot is saved before each change) ---------- */

    const undoHistory = [];
    const UNDO_LIMIT = 50;
    const undoBtn = document.getElementById("undoBtn");

    function cloneState() {
        return JSON.stringify({ rooms: rooms, selectedId: selectedId });
    }

    function updateUndoButton() {
        if (undoBtn) {
            undoBtn.disabled = undoHistory.length === 0;
        }
    }

    function pushHistory(snapshot) {
        undoHistory.push(snapshot || cloneState());

        if (undoHistory.length > UNDO_LIMIT) {
            undoHistory.shift();
        }

        updateUndoButton();
    }

    function undo() {
        if (!undoHistory.length) return;

        const state = JSON.parse(undoHistory.pop());

        rooms = state.rooms;
        selectedId = state.selectedId;
        interaction = null;
        builtFor = undefined;      /* rebuild the Room Name list */

        clearGuides();
        render();
        updateUndoButton();
    }

    /* One undo step per edit of a field (not one per keystroke) */
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

    function roomText(r, separator) {
        /* Navigation points: name on the plan, "Type | name" in the list */
        if (r.kind === "n") {
            return separator === "\n"
                ? r.room_name
                : [r.nav_type, r.room_name].filter(part => part !== "").join(separator);
        }

        return [r.room_number, r.room_name]
            .filter(part => part !== "")
            .join(separator);
    }

    function drawPreviousFloor() {
        if (!previousFloorLayer || !Array.isArray(previousFloorRooms)) {
            return;
        }

        previousFloorRooms.forEach((data, index) => {
            const room = {
                id: String(data.uid || ("previous-" + index)),
                kind: String(data.uid || "").charAt(0),
                room_number: data.room_number || "",
                room_name: data.room_name || "",
                room_type: data.room_type || "",
                nav_type: data.nav_type || "",
                staircase_type: data.staircase_type || "",
                x: Number(data.position_x ?? 40),
                y: Number(data.position_y ?? 65),
                width: Number(data.width || 140),
                height: Number(data.height || 90),
                rotation: Number(data.rotation || 0)
            };

            const shape = document.createElement("div");
            shape.className = "room-shape previous-floor-shape" +
                (room.kind === "n" ? " nav-shape" : "") +
                (room.kind === "n" && room.nav_type === "Staircase"
                    ? " staircase-shape"
                    : "") +
                (room.kind === "n" && room.nav_type === "Staircase" && room.staircase_type === "Switchback"
                    ? " switchback-staircase"
                    : "");
            shape.style.left = room.x + "px";
            shape.style.top = room.y + "px";
            shape.style.width = room.width + "px";
            shape.style.height = room.height + "px";
            shape.style.transform = "rotate(" + room.rotation + "deg)";
            shape.style.borderRadius = "0";

            const color = colorFor(room);
            if (room.kind === "n" && room.nav_type === "Staircase") {
                shape.style.background = "";
                shape.style.borderColor = "";
                shape.style.color = "#30363b";
            } else {
                shape.style.background = color;
                shape.style.borderColor = darken(color, 0.7);
                shape.style.color = textColorFor(color);
            }

            const label = document.createElement("span");
            label.className = "room-label";
            label.textContent = roomText(room, "\n");
            shape.appendChild(label);
            previousFloorLayer.appendChild(shape);
        });
    }

    /* ---------- Room Information panel ---------- */

    function buildNameSelect(r) {
        detailNameSelect.innerHTML = "";

        if (!r) {
            detailNameSelect.innerHTML = '<option value="">Select a room</option>';
            detailNameSelect.disabled = true;
            return;
        }

        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Choose a room name";
        detailNameSelect.appendChild(placeholder);

        nameChoicesFor(r).forEach(name => {
            const option = document.createElement("option");
            option.value = name;
            option.textContent = name;
            detailNameSelect.appendChild(option);
        });

        const other = document.createElement("option");
        other.value = "Others";
        other.textContent = "Others";
        detailNameSelect.appendChild(other);

        detailNameSelect.disabled = false;
    }

    /* Text shown in the Type box of the Room Information panel */
    function typeLabel(r) {
        if (r.kind === "n") {
            return navLabel(r);
        }

        if (r.kind === "f") {
            return "Facilities" + (r.category ? " — " + r.category : "");
        }

        return r.room_type || "Room";
    }

    function hallwaySectionFor(room, number) {
        if (!Array.isArray(room.hallway_sections)) {
            room.hallway_sections = [];
        }

        let section = room.hallway_sections.find(
            item => Number(item.section_number) === Number(number)
        );
        if (!section) {
            section = {
                section_number: Number(number),
                section_name: "Area " + number,
                photo_path: "",
                marker_x: 50,
                marker_y: 50,
                marker_placed: false
            };
            room.hallway_sections.push(section);
        }
        return section;
    }

    function nextHallwaySectionNumber(room) {
        return (room.hallway_sections || []).reduce(
            (highest, section) =>
                Math.max(highest, Number(section.section_number) || 0),
            0
        ) + 1;
    }

    function sectionPhotoStops(room) {
        return (room.hallway_sections || [])
            .filter(section =>
                /^uploads\/room-attachments\/[a-f0-9]{32}\.(jpe?g|png|gif|webp)$/i
                    .test(String(section.photo_path || ""))
            )
            .sort((a, b) => Number(a.section_number) - Number(b.section_number))
            .map(section => ({
                src: "../" + section.photo_path,
                label: section.section_name || ("Area " + section.section_number),
                sectionNumber: Number(section.section_number)
            }));
    }

    function createHallwaySectionRow(room, section) {
        const number = Number(section.section_number);
        const row = document.createElement("div");
        row.className = "hallway-section-row";
        row.dataset.sectionNumber = String(number);

        const heading = document.createElement("h4");
        heading.textContent = section.section_name || ("Area " + number);

        const nameField = document.createElement("div");
        nameField.className = "field";
        const nameLabel = document.createElement("label");
        nameLabel.textContent = "Area name";
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.name = "hallway_section_name[" + number + "]";
        nameInput.maxLength = 120;
        nameInput.value = section.section_name || ("Area " + number);
        nameInput.addEventListener("input", () => {
            section.section_name = nameInput.value;
            heading.textContent = nameInput.value || ("Area " + number);
            sectionPending = true;
        });
        nameField.append(nameLabel, nameInput);

        const photoField = document.createElement("div");
        photoField.className = "field";
        const photoLabel = document.createElement("label");
        photoLabel.textContent = "Area picture";
        const photoInput = document.createElement("input");
        photoInput.type = "file";
        photoInput.name = "hallway_section_photo[" + number + "]";
        photoInput.accept = ".jpg,.jpeg,.png,.gif,.webp";
        photoInput.addEventListener("change", () => {
            sectionPending = sectionPending || photoInput.files.length > 0;
            updateSaveButton();
        });
        photoField.append(photoLabel, photoInput);
        // Keep the upload control visible so an existing area photo can be replaced.
        photoField.hidden = false;
        if (section.photo_path) {
            const existingHint = document.createElement("p");
            existingHint.className = "field-hint existing-photo-hint";
            existingHint.textContent = "A picture is already saved. Choose a new file to replace it.";
            photoField.appendChild(existingHint);
        }

        const markerField = document.createElement("div");
        markerField.className = "field";
        const markerLabel = document.createElement("label");
        markerLabel.textContent = "Marker location";
        const markerStatus = document.createElement("span");
        markerStatus.className = "field-hint hallway-section-position";
        const updateMarkerStatus = () => {
            markerStatus.textContent = section.marker_placed
                ? "Marker placed on hallway."
                : "Click Place marker, then click the hallway.";
        };
        updateMarkerStatus();

        const xInput = document.createElement("input");
        xInput.type = "hidden";
        xInput.name = "hallway_section_marker_x[" + number + "]";
        xInput.value = Number(section.marker_x ?? 50).toFixed(2);
        xInput.className = "hallway-marker-x";

        const yInput = document.createElement("input");
        yInput.type = "hidden";
        yInput.name = "hallway_section_marker_y[" + number + "]";
        yInput.value = Number(section.marker_y ?? 50).toFixed(2);
        yInput.className = "hallway-marker-y";

        const placedInput = document.createElement("input");
        placedInput.type = "hidden";
        placedInput.name = "hallway_section_marker_placed[" + number + "]";
        placedInput.value = section.marker_placed ? "1" : "0";
        placedInput.className = "hallway-marker-placed";
        markerField.append(markerLabel, markerStatus, xInput, yInput, placedInput);

        const controls = document.createElement("div");
        controls.className = "section-controls";
        const placeButton = document.createElement("button");
        placeButton.type = "button";
        placeButton.className = "light place-hallway-marker";
        placeButton.textContent = section.marker_placed
            ? "Place / move marker"
            : "Place marker";
        placeButton.addEventListener("click", () => {
            activeMarkerSection = number;
            hallwayMarkerStatus.textContent =
                "Click the desired point inside the hallway for " +
                (section.section_name || ("Area " + number)) + ".";
            hallwaySectionsEditor
                .querySelectorAll(".place-hallway-marker")
                .forEach(button => button.classList.remove("active"));
            placeButton.classList.add("active");
        });

        const saveButton = document.createElement("button");
        saveButton.type = "submit";
        saveButton.name = "save_hallway_section";
        saveButton.value = String(number);
        saveButton.className = "gold";
        saveButton.textContent = "Save area";
        saveButton.disabled =
            !HALLWAY_SECTION_STORAGE_AVAILABLE ||
            !/^n\d+$/.test(room.id) ||
            !section.marker_placed ||
            (!section.photo_path && photoInput.files.length === 0);

        function updateSaveButton() {
            saveButton.disabled =
                !HALLWAY_SECTION_STORAGE_AVAILABLE ||
                !/^n\d+$/.test(room.id) ||
                !section.marker_placed ||
                (!section.photo_path && photoInput.files.length === 0);
        }

        const deleteAreaButton = document.createElement("button");
        deleteAreaButton.type = "submit";
        deleteAreaButton.name = "delete_hallway_section";
        deleteAreaButton.value = String(number);
        deleteAreaButton.className = "light delete-hallway-area";
        deleteAreaButton.textContent = "Delete area";
        deleteAreaButton.addEventListener("click", event => {
            if (!confirm("Delete this hallway area and its saved photo?")) event.preventDefault();
        });

        const deletePhotoButton = document.createElement("button");
        deletePhotoButton.type = "submit";
        deletePhotoButton.name = "delete_hallway_section_photo";
        deletePhotoButton.value = String(number);
        deletePhotoButton.className = "light delete-hallway-photo";
        deletePhotoButton.textContent = "Delete photo";
        deletePhotoButton.hidden = !section.photo_path;
        deletePhotoButton.addEventListener("click", event => {
            if (!confirm("Delete the saved photo for this area?")) event.preventDefault();
        });

        photoInput.addEventListener("change", () => {
            deletePhotoButton.hidden = !(section.photo_path || photoInput.files.length);
        });

        row.append(heading, nameField, photoField, markerField, controls);
        controls.append(placeButton, saveButton, deletePhotoButton, deleteAreaButton);
        row.updateMarkerStatus = updateMarkerStatus;
        row.updateSaveButton = updateSaveButton;
        return row;
    }

    function refreshHallwayAreaSelector(room) {
        const previous = openedHallwayArea;
        hallwayAreaSelector.replaceChildren();
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Select an area";
        hallwayAreaSelector.appendChild(placeholder);
        (room.hallway_sections || []).slice().sort((a,b) => Number(a.section_number)-Number(b.section_number)).forEach(section => {
            const option = document.createElement("option");
            option.value = String(section.section_number);
            option.textContent = section.section_name || ("Area " + section.section_number);
            hallwayAreaSelector.appendChild(option);
        });
        openedHallwayArea = previous || (room.hallway_sections?.length ? String(room.hallway_sections[0].section_number) : "");
        hallwayAreaSelector.value = openedHallwayArea;
        hallwaySectionsEditor.querySelectorAll(".hallway-section-row").forEach(row => {
            row.hidden = openedHallwayArea !== row.dataset.sectionNumber;
        });
    }

    function updateHallwaySections(room) {
        const isHallway = !!room && room.kind === "n" && room.nav_type === "Hallway";
        hallwaySectionsField.classList.toggle("hidden", !isHallway);

        if (!isHallway) {
            activeMarkerSection = null;
            hallwayMarkerStatus.textContent = "";
            hallwaySectionPointId.value = "";
            return;
        }

        hallwaySectionPointId.value = /^n\d+$/.test(room.id)
            ? room.id.slice(1)
            : "";

        if (sectionEditorFor !== room.id) {
            activeMarkerSection = null;
            sectionEditorFor = room.id;
            hallwaySectionsEditor.replaceChildren();
            hallwayMarkerStatus.textContent = "";

            (room.hallway_sections || [])
                .slice()
                .sort((a, b) => Number(a.section_number) - Number(b.section_number))
                .forEach(section => {
                    hallwaySectionsEditor.appendChild(
                        createHallwaySectionRow(room, section)
                    );
                });
            refreshHallwayAreaSelector(room);
        } else {
            room.hallway_sections.forEach(section => {
                const row = hallwaySectionsEditor.querySelector(
                    '[data-section-number="' + Number(section.section_number) + '"]'
                );
                if (!row) return;
                const xInput = row.querySelector(".hallway-marker-x");
                const yInput = row.querySelector(".hallway-marker-y");
                const placedInput = row.querySelector(".hallway-marker-placed");
                if (xInput) {
                    xInput.value = Number(section.marker_x ?? 50).toFixed(1);
                }
                if (yInput) {
                    yInput.value = Number(section.marker_y ?? 50).toFixed(1);
                }
                if (placedInput) {
                    placedInput.value = section.marker_placed ? "1" : "0";
                }
                if (row.updateMarkerStatus) row.updateMarkerStatus();
                if (row.updateSaveButton) row.updateSaveButton();
            });
        }
    }

    addHallwaySectionButton.addEventListener("click", () => {
        const room = selectedRoom();
        if (!room || room.kind !== "n" || room.nav_type !== "Hallway") return;
        if (!HALLWAY_SECTION_STORAGE_AVAILABLE) {
            hallwayMarkerStatus.textContent =
                "Hallway area storage is unavailable. Check the database permissions.";
            return;
        }
        if (!/^n\d+$/.test(room.id)) {
            hallwayMarkerStatus.textContent =
                "Save this hallway to the layout before adding area pictures.";
            return;
        }

        const number = nextHallwaySectionNumber(room);
        const section = hallwaySectionFor(room, number);
        section.section_name = "Area " + number;
        section.marker_placed = false;
        sectionPending = true;

        const row = createHallwaySectionRow(room, section);
        hallwaySectionsEditor.appendChild(row);
        refreshHallwayAreaSelector(room);
        openedHallwayArea = String(number);
        hallwayAreaSelector.value = openedHallwayArea;
        hallwaySectionsEditor.querySelectorAll(".hallway-section-row").forEach(item => item.hidden = item.dataset.sectionNumber !== openedHallwayArea);
        activeMarkerSection = number;
        hallwayMarkerStatus.textContent =
            "Area " + number + " added. Click the hallway to place its marker.";
        hallwaySectionsEditor
            .querySelectorAll(".place-hallway-marker")
            .forEach(button => button.classList.remove("active"));
        const placeButton = row.querySelector(".place-hallway-marker");
        if (placeButton) placeButton.classList.add("active");
        render();
    });

    function updateInfo() {
        const r = selectedRoom();
        const id = r ? r.id : null;

        if (builtFor !== id) {
            buildNameSelect(r);
            builtFor = id;
        }

        setValue(detailType, r ? typeLabel(r) : "");
        setValue(detailNumber, r ? r.room_number : "");
        detailNumber.disabled = !r || r.kind !== "r";

        /* Staircases and elevators: which floors they connect */
        detailLinksField.classList.toggle("hidden", !(r && r.links));
        setValue(detailLinks, r ? r.links : "");

        if (r) {
            const choices = nameChoicesFor(r);

            detailNameSelect.value = r.custom
                ? "Others"
                : (choices.includes(r.room_name) ? r.room_name : "");
        }

        /* Custom name box is editable only when "Others" is selected */
        detailNameCustomField.classList.toggle("hidden", !(r && r.custom));
        setValue(detailName, r ? r.room_name : "");

        setValue(detailX, r ? Math.round(r.x) : "");
        setValue(detailY, r ? Math.round(r.y) : "");
        setValue(detailWidth, r ? r.width : "");
        setValue(detailHeight, r ? r.height : "");
        const isSwitchback = !!r && r.kind === "n" && r.nav_type === "Staircase" && r.staircase_type === "Switchback";
        detailStairRotationField.classList.toggle("hidden", !isSwitchback);
        detailStairRotation.disabled = !isSwitchback;
        setValue(detailStairRotation, r ? (Number(r.rotation) || 0) : 0);

        detailWidth.disabled = !r;
        detailHeight.disabled = !r;

        /* Images are edited only after selecting an existing room, office, facility, or navigation point. Hallway Areas keep their own photos too. */
        const canAttach = !!r && ["r", "o", "f", "n"].includes(r.kind);
        const attachmentPathRaw = String(r ? (r.attachment_path || (r.kind === "r" ? r.walk_links : "" ) || "") : "");
        // Normalize paths returned by older records (some may include ../).
        const attachmentPath = attachmentPathRaw.replace(/^(?:\.\.\/)+/, "");
        const safeAttachmentPath =
            /^uploads\/room-attachments\/[a-f0-9]{32}\.(?:jpe?g)$/i.test(attachmentPath);
        detailAttachmentField.classList.toggle("hidden", !canAttach);
        detailAttachment.disabled = !canAttach;
        removeAttachment.disabled = !canAttach;
        removeAttachmentButton.disabled = !canAttach;

        if (canAttach) {
            if (detailAttachment.dataset.roomId !== r.id) {
                detailAttachment.value = "";
                removeAttachment.checked = false;
                attachmentPending = false;
                detailAttachment.dataset.roomId = r.id;
            }

            const index = rooms.indexOf(r);
            detailAttachment.name = "attachment[" + index + "]";
            removeAttachment.name = "remove_attachment[" + index + "]";
        } else {
            detailAttachment.removeAttribute("name");
            removeAttachment.removeAttribute("name");
        }
        currentAttachment.replaceChildren();
        if (safeAttachmentPath) {
            const isImage = /\.(jpe?g)$/i.test(attachmentPath);
            const link = document.createElement("button");
            link.type = "button";
            link.className = "btn light";
            link.textContent = isImage ? "View Image (Zoom & Pan)" : "View File";
            link.addEventListener("click", () => {
                if (isImage) openImageViewer("../" + attachmentPath);
                else window.open("../" + attachmentPath, "_blank", "noopener,noreferrer");
            });
            currentAttachment.appendChild(link);
        } else {
            currentAttachment.textContent = canAttach
                ? "No attachment saved."
                : "No file linked.";
        }
        if (!safeAttachmentPath) removeAttachment.checked = false;
        const hasSavedImage = canAttach && safeAttachmentPath && !removeAttachment.checked;
        attachmentUploadControl.hidden = hasSavedImage;
        removeAttachmentButton.hidden = !hasSavedImage;
        removeAttachmentButton.textContent = "Remove Image";
        updateHallwaySections(r);

        if (r) {
            const limits = sizeLimits(r);
            detailWidth.min = limits.minW;
            detailWidth.max = Math.min(limits.maxW, CANVAS_W - r.x);
            detailHeight.min = limits.minH;
            detailHeight.max = Math.min(limits.maxH, CANVAS_H - r.y);
        } else {
            detailWidth.removeAttribute("min");
            detailWidth.removeAttribute("max");
            detailHeight.removeAttribute("min");
            detailHeight.removeAttribute("max");
        }
    }

    detailAttachment.addEventListener("change", () => {
        attachmentPending = !!detailAttachment.files.length || removeAttachment.checked;
    });
    removeAttachmentButton.addEventListener("click", () => {
        removeAttachment.checked = true;
        attachmentPending = true;
        updateInfo();
    });
    removeAttachment.addEventListener("change", () => {
        attachmentPending = !!detailAttachment.files.length || removeAttachment.checked;
    });

    detailNumber.addEventListener("input", () => {
        const r = selectedRoom();
        if (!r || r.kind !== "r") return;

        const digits = detailNumber.value.replace(/\D/g, "").slice(0, 50);
        if (detailNumber.value !== digits) {
            detailNumber.value = digits;
        }

        r.room_number = digits;
        render();
    });

    trackEdits(detailNumber);

    function applyDimensionEdit(input, dimension) {
        const r = selectedRoom();
        if (!r) return;

        const requested = Number(input.value);
        if (!Number.isFinite(requested)) {
            setValue(input, r[dimension]);
            return;
        }

        const limits = sizeLimits(r);
        const isWidth = dimension === "width";
        const min = isWidth ? limits.minW : limits.minH;
        const max = isWidth
            ? Math.min(limits.maxW, CANVAS_W - r.x)
            : Math.min(limits.maxH, CANVAS_H - r.y);

        r[dimension] = Math.max(min, Math.min(max, requested));
        render();
    }

    detailWidth.addEventListener("change", () => {
        applyDimensionEdit(detailWidth, "width");
    });

    detailHeight.addEventListener("change", () => {
        applyDimensionEdit(detailHeight, "height");
    });

    function applySwitchbackRotation(value) {
        const r = selectedRoom();
        if (!r || r.kind !== "n" || r.nav_type !== "Staircase" || r.staircase_type !== "Switchback") return;
        const angle = Number(value);
        if (!Number.isFinite(angle)) return;
        pushHistory(cloneState());
        r.rotation = Math.max(-360, Math.min(360, angle));

        /*
         * Rotation changes the visible bounding box. Re-clamp the position
         * immediately so a staircase rotated near the canvas bottom/right
         * remains inside the canvas before Save Layout submits position_x/y.
         * Otherwise the server-side unrotated bounds can adjust its position
         * during save, making it appear to jump after the page reloads.
         */
        const clampedPosition = clampRoomPosition(r, r.x, r.y);
        r.x = clampedPosition.x;
        r.y = clampedPosition.y;

        setValue(detailStairRotation, r.rotation);
        render();
    }
    detailStairRotation.addEventListener("change", () => applySwitchbackRotation(detailStairRotation.value));
    rotateStairLeft.addEventListener("click", () => applySwitchbackRotation((Number(selectedRoom()?.rotation) || 0) - 15));
    rotateStairRight.addEventListener("click", () => applySwitchbackRotation((Number(selectedRoom()?.rotation) || 0) + 15));

    trackEdits(detailWidth);
    trackEdits(detailHeight);

    detailNameSelect.addEventListener("change", () => {
        const r = selectedRoom();
        if (!r) return;

        const value = detailNameSelect.value;

        if (value !== "") {
            pushHistory();
        }

        if (value === "Others") {
            r.custom = true;
            render();
            detailName.focus();
            detailName.select();
        } else if (value !== "") {
            r.custom = false;
            r.room_name = value;
            render();
        } else {
            render();
        }
    });

    detailName.addEventListener("input", () => {
        const r = selectedRoom();
        if (!r || !r.custom) return;

        r.room_name = detailName.value;
        render();
    });

    trackEdits(detailName);

    /* ---------- canvas ---------- */

    function drawRoom(r) {
        let el = document.getElementById("room-" + r.id);

        if (!el) {
            el = document.createElement("div");
            el.id = "room-" + r.id;
            el.className = "room-shape";

            const label = document.createElement("span");
            label.className = "room-label";

            const handle = document.createElement("span");
            handle.className = "resize-handle";

            const markerLayer = document.createElement("div");
            markerLayer.className = "hallway-marker-layer";

            const initialRoom = rooms.find(item => item.id === r.id);
            if (initialRoom && initialRoom.kind === "n" && initialRoom.nav_type === "Staircase" && initialRoom.staircase_type === "Switchback") {
                const diagram = document.createElement("div");
                diagram.className = "switchback-diagram";
                const landing = document.createElement("div"); landing.className = "switchback-landing";
                diagram.appendChild(landing);
                const flights = document.createElement("div"); flights.className = "switchback-flights";
                for (let flightIndex = 0; flightIndex < 2; flightIndex++) {
                    const flight = document.createElement("div"); flight.className = "switchback-flight";
                    for (let stepIndex = 0; stepIndex < 6; stepIndex++) {
                        const step = document.createElement("div"); step.className = "switchback-step";
                        if ((flightIndex === 0 && stepIndex === 0) || (flightIndex === 1 && stepIndex === 5)) {
                            const arrow = document.createElement("span"); arrow.className = "switchback-arrow"; arrow.textContent = flightIndex === 0 ? "↑" : "↓"; step.appendChild(arrow);
                        }
                        flight.appendChild(step);
                    }
                    flights.appendChild(flight);
                    if (flightIndex === 0) { const well = document.createElement("div"); well.className = "switchback-well"; flights.appendChild(well); }
                }
                diagram.appendChild(flights);
                el.appendChild(diagram);
            }
            el.append(label, handle, markerLayer);
            canvas.appendChild(el);

            el.addEventListener("pointerdown", event => {
                const id = el.dataset.id;
                const room = rooms.find(item => item.id === id);

                if (!room) return;

                if (activeMarkerSection !== null) {
                    if (
                        room.kind === "n" &&
                        room.nav_type === "Hallway"
                    ) {
                        const bounds = el.getBoundingClientRect();
                        const angle = Number(room.rotation || 0) * Math.PI / 180;

                        /*
                         * Use the visual center of the rotated hallway, then
                         * convert the click back into the hallway's unrotated
                         * coordinate space. This keeps placement accurate when
                         * the hallway is rotated or the canvas is scrolled.
                         */
                        const dx = event.clientX - (bounds.left + bounds.width / 2);
                        const dy = event.clientY - (bounds.top + bounds.height / 2);
                        const localX =
                            dx * Math.cos(angle) + dy * Math.sin(angle) + Number(room.width) / 2;
                        const localY =
                            -dx * Math.sin(angle) + dy * Math.cos(angle) + Number(room.height) / 2;
                        const markerX = Math.max(
                            0,
                            Math.min(100, (localX / Number(room.width)) * 100)
                        );
                        const markerY = Math.max(
                            0,
                            Math.min(100, (localY / Number(room.height)) * 100)
                        );
                        const section = hallwaySectionFor(room, activeMarkerSection);
                        section.marker_x = markerX;
                        section.marker_y = markerY;
                        section.marker_placed = true;
                        const sectionRow = hallwaySectionsEditor.querySelector(
                            '[data-section-number="' +
                            Number(section.section_number) + '"]'
                        );
                        if (sectionRow) {
                            const xInput = sectionRow.querySelector(".hallway-marker-x");
                            const yInput = sectionRow.querySelector(".hallway-marker-y");
                            const placedInput = sectionRow.querySelector(
                                ".hallway-marker-placed"
                            );
                            if (xInput) xInput.value = markerX.toFixed(2);
                            if (yInput) yInput.value = markerY.toFixed(2);
                            if (placedInput) placedInput.value = "1";
                            if (sectionRow.updateMarkerStatus) {
                                sectionRow.updateMarkerStatus();
                            }
                            if (sectionRow.updateSaveButton) {
                                sectionRow.updateSaveButton();
                            }
                        }
                        sectionPending = true;
                        hallwayMarkerStatus.textContent =
                            (section.section_name || ("Area " + activeMarkerSection)) +
                            " marker placed. Save this area to keep it.";
                        activeMarkerSection = null;
                        hallwaySectionsEditor
                            .querySelectorAll(".place-hallway-marker")
                            .forEach(button => button.classList.remove("active"));
                        render();
                    } else {
                        hallwayMarkerStatus.textContent =
                            "Place the marker inside the selected hallway.";
                    }
                    event.preventDefault();
                    return;
                }

                const additiveSelect = event.shiftKey || event.ctrlKey || event.metaKey;
                if (additiveSelect) {
                    if (selectedIds.has(id)) {
                        selectedIds.delete(id);
                    } else {
                        selectedIds.add(id);
                    }
                    selectedId = selectedIds.has(id) ? id : (Array.from(selectedIds).slice(-1)[0] || null);
                    render();
                    // Clicking to toggle selection should not start a drag.
                    event.preventDefault();
                    return;
                }

                if (!selectedIds.has(id)) {
                    selectedIds.clear();
                    selectedIds.add(id);
                }
                selectedId = id;
                render();

                const isResize = event.target.classList.contains("resize-handle");
                const groupItems = !isResize && selectedIds.size > 1
                    ? rooms.filter(item => selectedIds.has(item.id)).map(item => ({ id: item.id, x: item.x, y: item.y }))
                    : [];
                interaction = {
                    id,
                    type: isResize ? "resize" : (groupItems.length ? "moveGroup" : "move"),
                    groupItems,
                    startX: event.clientX,
                    startY: event.clientY,
                    x: room.x,
                    y: room.y,
                    width: room.width,
                    height: room.height,
                    before: cloneState(),
                    recorded: false
                };

                el.setPointerCapture(event.pointerId);
                event.preventDefault();
            });

            el.addEventListener("pointermove", event => {
                if (!interaction ||
                    interaction.id !== el.dataset.id) {
                    return;
                }

                const room = rooms.find(item => item.id === interaction.id);
                if (!room) return;

                const dx = event.clientX - interaction.startX;
                const dy = event.clientY - interaction.startY;

                /* Save one undo step when the drag really starts */
                if (!interaction.recorded) {
                    if (dx === 0 && dy === 0) return;

                    pushHistory(interaction.before);
                    interaction.recorded = true;
                }

                if (interaction.type === "moveGroup") {
                    if (interaction.groupItems.length && interaction.groupItems.some(item => item.id === interaction.id)) {
                        const anchor = interaction.groupItems.find(item => item.id === interaction.id);
                        let dxGroup = dx;
                        let dyGroup = dy;
                        const movedAnchor = rooms.find(item => item.id === interaction.id);
                        if (movedAnchor) {
                            const clampedAnchor = clampRoomPosition(movedAnchor, anchor.x + dxGroup, anchor.y + dyGroup);
                            let targetX = clampedAnchor.x;
                            let targetY = clampedAnchor.y;
                            if (autoAlign && autoAlign.checked && !event.altKey) {
                                const snapped = snapPosition(movedAnchor, targetX, targetY);
                                targetX = snapped.x; targetY = snapped.y;
                            }
                            dxGroup = targetX - anchor.x;
                            dyGroup = targetY - anchor.y;
                        }
                        interaction.groupItems.forEach(item => {
                            const target = rooms.find(candidate => candidate.id === item.id);
                            if (!target) return;
                            const clampedTarget = clampRoomPosition(target, item.x + dxGroup, item.y + dyGroup);
                            target.x = clampedTarget.x;
                            target.y = clampedTarget.y;
                        });
                    }
                    render();
                    clearGuides();
                } else if (interaction.type === "move") {
                    const clampedPosition = clampRoomPosition(room, interaction.x + dx, interaction.y + dy);
                    let newX = clampedPosition.x;
                    let newY = clampedPosition.y;

                    /* Auto align (hold Alt to move freely) */
                    const snapping = autoAlign && autoAlign.checked && !event.altKey;

                    if (snapping) {
                        const snapped = snapPosition(room, newX, newY);
                        newX = snapped.x;
                        newY = snapped.y;
                    }

                    room.x = newX;
                    room.y = newY;

                    render();

                    if (snapping) {
                        drawGuides(room);
                    } else {
                        clearGuides();
                    }
                } else {
                    const limits = sizeLimits(room);
                    /*
                     * Resize in the staircase's local coordinate system.
                     * Without this inverse rotation, dragging the handle after
                     * rotating a switchback can feel blocked or change the wrong
                     * dimension even when there is room on the canvas.
                     */
                    const angle = (Number(room.rotation || 0) * Math.PI) / 180;
                    const localDx = dx * Math.cos(angle) + dy * Math.sin(angle);
                    const localDy = -dx * Math.sin(angle) + dy * Math.cos(angle);
                    const resizeDx = room.rotation ? localDx : dx;
                    const resizeDy = room.rotation ? localDy : dy;

                    /* Keep the existing generous navigation-point limits. */
                    const maxW = room.kind === "n"
                        ? limits.maxW
                        : limits.maxW;
                    const maxH = room.kind === "n"
                        ? limits.maxH
                        : limits.maxH;

                    room.width = Math.max(
                        limits.minW,
                        Math.min(maxW, interaction.width + resizeDx)
                    );
                    room.height = Math.max(
                        limits.minH,
                        Math.min(maxH, interaction.height + resizeDy)
                    );
                    render();
                }
            });

            el.addEventListener("pointerup", () => {
                interaction = null;
                clearGuides();
            });

            el.addEventListener("pointercancel", () => {
                interaction = null;
                clearGuides();
            });
        }

        const color = colorFor(r);

        el.dataset.id = r.id;
        el.className = "room-shape" +
            (r.kind === "n" ? " nav-shape" : "") +
            (r.kind === "n" && r.nav_type === "Staircase" ? " staircase-shape" : "") +
            (r.kind === "n" && r.nav_type === "Staircase" && r.staircase_type === "Switchback" ? " switchback-staircase" : "") +
            (selectedIds.has(r.id) ? " selected" : "");

        el.style.left = r.x + "px";
        el.style.top = r.y + "px";
        el.style.width = r.width + "px";
        el.style.height = r.height + "px";
        el.style.transformOrigin = "50% 50%";
        el.style.transform = "rotate(" + r.rotation + "deg)";
        el.style.borderRadius = "0";

        /* Category color */
        if (r.kind === "n" && r.nav_type === "Staircase") {
            el.style.background = "";
            el.style.borderColor = "";
            el.style.color = "#30363b";
        } else {
            el.style.background = color;
            el.style.borderColor = darken(color, 0.7);
            el.style.color = textColorFor(color);
        }

        el.querySelector(".room-label").textContent =
            roomText(r, "\n");

        const resizeHandle = el.querySelector(".resize-handle");
        resizeHandle.style.display =
            selectedIds.size === 1 && r.id === selectedId ? "block" : "none";
        /*
         * Keep the orange resize grip at the LOWER-RIGHT of the staircase's
         * visible rotated bounding box, not at the original unrotated corner.
         * The handle remains upright and follows the edge when rotation changes.
         */
        const rotationDegrees = Number(r.rotation || 0);
        const rotationRadians = rotationDegrees * Math.PI / 180;
        const absCos = Math.abs(Math.cos(rotationRadians));
        const absSin = Math.abs(Math.sin(rotationRadians));
        const visualHalfWidth = (r.width * absCos + r.height * absSin) / 2;
        const visualHalfHeight = (r.width * absSin + r.height * absCos) / 2;
        const localX = Math.cos(rotationRadians) * visualHalfWidth + Math.sin(rotationRadians) * visualHalfHeight;
        const localY = -Math.sin(rotationRadians) * visualHalfWidth + Math.cos(rotationRadians) * visualHalfHeight;
        const handleSize = 13;
        resizeHandle.style.left = (r.width / 2 + localX - handleSize / 2) + "px";
        resizeHandle.style.top = (r.height / 2 + localY - handleSize / 2) + "px";
        resizeHandle.style.right = "auto";
        resizeHandle.style.bottom = "auto";
        resizeHandle.style.transform = "none";

        const markerLayer = el.querySelector(".hallway-marker-layer");
        markerLayer.replaceChildren();
        if (r.kind === "n" && r.nav_type === "Hallway" && r.id === selectedId) {
            sectionPhotoStops(r).forEach(stop => {
                const section = r.hallway_sections.find(
                    item => Number(item.section_number) === stop.sectionNumber
                );
                if (!section) return;

                const marker = document.createElement("button");
                marker.type = "button";
                marker.className = "hallway-section-marker";
                marker.textContent = "";
                marker.title = stop.label + " — click to view";
                marker.setAttribute("aria-label", "View hallway " + stop.label);
                marker.style.left = Number(section.marker_x ?? 50) + "%";
                marker.style.top = Number(section.marker_y ?? 50) + "%";
                /* Center the marker on the exact saved click position. */
                marker.style.transform = "translate(-50%, -50%)";
                let markerDragging = false;
                let markerMoved = false;
                marker.addEventListener("pointerdown", event => {
                    event.stopPropagation();
                    markerDragging = true;
                    markerMoved = false;
                    marker.setPointerCapture(event.pointerId);
                    marker.classList.add("dragging");
                });
                marker.addEventListener("pointermove", event => {
                    if (!markerDragging) return;
                    const bounds = markerLayer.getBoundingClientRect();
                    if (!bounds.width || !bounds.height) return;
                    markerMoved = true;
                    // Snap marker position to 2% increments for easier alignment.
                    const x = Math.max(0, Math.min(100, Math.round(((event.clientX - bounds.left) / bounds.width * 100) / 2) * 2));
                    const y = Math.max(0, Math.min(100, Math.round(((event.clientY - bounds.top) / bounds.height * 100) / 2) * 2));
                    section.marker_x = x;
                    section.marker_y = y;
                    marker.style.left = x + "%";
                    marker.style.top = y + "%";
                    const row = hallwaySectionsEditor.querySelector('[data-section-number="' + Number(section.section_number) + '"]');
                    if (row) {
                        const xi = row.querySelector(".hallway-marker-x"), yi = row.querySelector(".hallway-marker-y"), pi = row.querySelector(".hallway-marker-placed");
                        if (xi) xi.value = x.toFixed(2);
                        if (yi) yi.value = y.toFixed(2);
                        if (pi) pi.value = "1";
                        if (row.updateSaveButton) row.updateSaveButton();
                    }
                    section.marker_placed = true;
                    sectionPending = true;
                });
                const finishMarkerDrag = event => {
                    if (!markerDragging) return;
                    markerDragging = false;
                    marker.classList.remove("dragging");
                    if (markerMoved) {
                        event.stopPropagation();
                        hallwayMarkerStatus.textContent = (section.section_name || stop.label) + " marker moved. Save this area to keep the position.";
                    }
                };
                marker.addEventListener("pointerup", finishMarkerDrag);
                marker.addEventListener("pointercancel", finishMarkerDrag);
                marker.addEventListener("click", event => {
                    event.stopPropagation();
                    if (markerMoved) { markerMoved = false; return; }
                    window.openImageViewer(stop.src, sectionPhotoStops(r));
                });
                markerLayer.appendChild(marker);
            });
        }
    }

    function render() {
        rooms.forEach(drawRoom);
        list.innerHTML = "";

        rooms.forEach(r => {
            const item = document.createElement("div");
            item.className = "room-list-item";
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "room-list-select" + (selectedIds.has(r.id) ? " active" : "");
            btn.textContent = roomText(r, " | ");
            btn.style.borderLeftColor = colorFor(r);
            btn.title = "Click to select; Ctrl/Command-click to select multiple";
            btn.addEventListener("click", event => {
                if (event.ctrlKey || event.metaKey) {
                    if (selectedIds.has(r.id)) selectedIds.delete(r.id);
                    else selectedIds.add(r.id);
                    selectedId = selectedIds.has(r.id) ? r.id : (Array.from(selectedIds).slice(-1)[0] || null);
                } else {
                    selectedId = r.id;
                    selectedIds.clear();
                    selectedIds.add(r.id);
                }
                render();
            });
            item.append(btn);
            list.appendChild(item);
        });
        if (deleteSelectedBtn) {
            const count = selectedIds.size;
            deleteSelectedBtn.disabled = count === 0;
            deleteSelectedBtn.textContent = count > 1 ? `Delete Selected (${count})` : "Delete Selected";
        }

        updateInfo();
        updateCopyPasteButtons();
    }

    if (deleteSelectedBtn) {
        deleteSelectedBtn.addEventListener("click", () => {
            const chosen = rooms.filter(r => selectedIds.has(r.id));
            if (!chosen.length) return;
            const temporary = chosen.filter(r => isTemporaryId(r.id));
            const persistent = chosen.filter(r => !isTemporaryId(r.id));
            const connectedStairsNote = persistent.some(r => r.id.charAt(0) === "n")
                ? " Connected stair/elevator points that share the same connection group will also be deleted."
                : "";
            const message = `Delete ${chosen.length} selected item(s)?${connectedStairsNote} This action cannot be undone.`;
            if (!window.confirm(message)) return;
            if (temporary.length) {
                pushHistory();
                const tempIds = new Set(temporary.map(r => r.id));
                rooms = rooms.filter(r => !tempIds.has(r.id));
                tempIds.forEach(id => selectedIds.delete(id));
            }
            if (persistent.length) {
                if (!deleteForm || !deleteItemsInputs) return;
                deleteItemsInputs.innerHTML = "";
                persistent.forEach(r => {
                    const input = document.createElement("input");
                    input.type = "hidden";
                    input.name = "delete_items[]";
                    input.value = r.id;
                    deleteItemsInputs.appendChild(input);
                });
                deleteForm.requestSubmit();
                return;
            }
            selectedId = Array.from(selectedIds)[0] || (rooms[0] ? rooms[0].id : null);
            if (!selectedIds.size && selectedId) selectedIds.add(selectedId);
            render();
        });
    }

    form.addEventListener("submit", event => {
        form.querySelectorAll(".generated-input").forEach(el => el.remove());

        if (event.submitter && event.submitter.name === "save_hallway_section") {
            const saveLayoutInput = document.createElement("input");
            saveLayoutInput.type = "hidden";
            saveLayoutInput.name = "save_layout";
            saveLayoutInput.value = "1";
            saveLayoutInput.className = "generated-input";
            form.appendChild(saveLayoutInput);
        }

        rooms.forEach(r => {
            const values = {
                room_id: r.id,
                room_number: r.room_number || "",
                room_name: r.room_name.trim() || r.original_name,
                room_type: r.room_type || "",
                nav_type: r.nav_type || "",
                hallway_copy_source_id: r.copy_source_id || "",
                position_x: r.x,
                position_y: r.y,
                width: r.width,
                height: r.height,
                shape_type: "rectangle",
                rotation: r.rotation
            };

            Object.entries(values).forEach(([key, value]) => {
                const input = document.createElement("input");

                input.type = "hidden";
                input.name = key + "[]";
                input.value = value;
                input.className = "generated-input";

                form.appendChild(input);
            });
        });
    });

    /* Undo button and Ctrl+Z (Cmd+Z on Mac) */
    if (undoBtn) {
        undoBtn.addEventListener("click", undo);
    }

    document.addEventListener("keydown", event => {
        const tag = (event.target.tagName || "").toLowerCase();
        const typing = tag === "input" || tag === "textarea" || tag === "select";
        const modal = document.getElementById("unsavedModal");
        const modalOpen = modal && modal.classList.contains("open");
        const modifier = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        if (!modifier || event.shiftKey || typing || modalOpen) return;

        if (key === "z") {
            event.preventDefault();
            undo();
        } else if (key === "c" && copySelectedItem()) {
            event.preventDefault();
        } else if (key === "v" && clipboardItem) {
            event.preventDefault();
            pasteCopiedItem();
        }
    });

    drawPreviousFloor();
    render();
    updateUndoButton();
}

/* ---------- Unsaved changes warning (sidebar links) ---------- */

/* The "Add New Room" and "Add Navigation Point" forms count as an edit once
   anything is filled in (a Type pre-selected from offices.php / facilities.php
   doesn't count) */
function addFormHasChanges() {
    let roomFormChanged = false;

    if (typeSelect && nameSelect && numberInput && otherInput) {
        roomFormChanged =
            (typeSelect.value !== "" && typeSelect.value !== PRESET_TYPE) ||
            nameSelect.value !== "" ||
            numberInput.value.trim() !== "" ||
            otherInput.value.trim() !== "";
    }

    return roomFormChanged || navFormHasChanges();
}

function hasUnsavedChanges() {
    return layoutHasChanges() || addFormHasChanges();
}

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

        /* Let new-tab clicks through */
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
    if (link) link.click();
});

unsavedModal.addEventListener("click", event => {
    if (event.target === unsavedModal) closeUnsavedModal();
});

document.addEventListener("keydown", event => {
    if (event.key === "Escape" && unsavedModal.classList.contains("open")) {
        closeUnsavedModal();
    }
});
</script>

<div class="image-viewer-overlay" id="imageViewer" role="dialog" aria-modal="true" aria-label="Image viewer">
    <div class="image-viewer-toolbar">
        <button type="button" class="viewer-close" id="viewerClose">Close ✕</button>
    </div>
    <div class="image-viewer-stage" id="viewerStage">
        <img id="viewerImage" alt="Room or hallway image">
    </div>
</div>
<script>
(() => {
    const overlay = document.getElementById('imageViewer');
    const stage = document.getElementById('viewerStage');
    const image = document.getElementById('viewerImage');
    if (!overlay || !stage || !image) return;
    let scale = 1, x = 0, y = 0, dragging = false, startX = 0, startY = 0, originX = 0, originY = 0;
    let photoStops = [], currentStop = 0;
    function paint() { image.style.transform = `translate(${x}px, ${y}px) scale(${scale})`; }
    function reset() { scale = 1; x = 0; y = 0; paint(); }
    function showStop(index) {
        if (!photoStops.length) return;
        currentStop = (index + photoStops.length) % photoStops.length;
        image.onload = fitImage;
        image.src = photoStops[currentStop].src;
        image.alt = photoStops[currentStop].label;
        if (image.complete && image.naturalWidth) fitImage();
    }
    window.openImageViewer = function(src, stops) {
        photoStops = Array.isArray(stops) && stops.length ? stops : [{src, label:'Current photo'}];
        let match = photoStops.findIndex(stop => stop.src === src);
        showStop(match >= 0 ? match : 0);
        overlay.classList.add('open'); document.body.style.overflow = 'hidden';
    };
    function close() { overlay.classList.remove('open'); document.body.style.overflow = ''; image.src = ''; }
    document.getElementById('viewerClose').addEventListener('click', close);
    function fitImage() {
        if (!image.naturalWidth || !image.naturalHeight) return;
        const fit = Math.min((stage.clientWidth - 24) / image.naturalWidth, (stage.clientHeight - 24) / image.naturalHeight);
        scale = Math.max(.05, fit); x = 0; y = 0; paint();
    }
    window.addEventListener('resize', () => {
        if (overlay.classList.contains('open')) fitImage();
    });
    stage.addEventListener('pointerdown', event => { if (event.target !== image) return; dragging = true; startX = event.clientX; startY = event.clientY; originX = x; originY = y; stage.classList.add('dragging'); stage.setPointerCapture(event.pointerId); });
    stage.addEventListener('pointermove', event => { if (!dragging) return; x = originX + event.clientX - startX; y = originY + event.clientY - startY; paint(); });
    function endDrag() { dragging = false; stage.classList.remove('dragging'); }
    stage.addEventListener('pointerup', endDrag); stage.addEventListener('pointercancel', endDrag);
    overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && overlay.classList.contains('open')) close(); });
    function previewInput(inputId, previewId) {
        const input = document.getElementById(inputId), preview = document.getElementById(previewId);
        if (!input || !preview) return;
        input.addEventListener('change', () => {
            preview.replaceChildren();
            const file = input.files && input.files[0];
            if (!file || !file.type.startsWith('image/')) { preview.hidden = true; return; }
            const url = URL.createObjectURL(file);
            const img = document.createElement('img'); img.src = url; img.alt = 'Selected image preview';
            img.addEventListener('click', () => {
                const stops = [];
                [['room_attachment', 'Room photo'], ['hallway_attachment', 'Hallway photo']].forEach(([id, label]) => {
                    const selected = document.getElementById(id)?.files?.[0];
                    if (selected && selected.type.startsWith('image/')) {
                        let previewImg = document.querySelector(`#${id === 'room_attachment' ? 'roomImagePreview' : 'hallwayImagePreview'} img`);
                        stops.push({src: previewImg?.src || URL.createObjectURL(selected), label});
                    }
                });
                window.openImageViewer(url, stops);
            });
            const hint = document.createElement('p'); hint.className = 'field-hint'; hint.textContent = 'Click preview to open photo navigation, zoom, and pan.';
            preview.append(img, hint); preview.hidden = false;
        });
    }
    previewInput('room_attachment', 'roomImagePreview');
    previewInput('hallway_attachment', 'hallwayImagePreview');
})();
</script>

</body>
</html>