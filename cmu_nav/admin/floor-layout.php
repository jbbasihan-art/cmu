<?php
require_once __DIR__ . '/../config/database.php';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ordinalFloor($number) {
    if ($number === 1) return '1st';
    if ($number === 2) return '2nd';
    if ($number === 3) return '3rd';
    return $number . 'th';
}

function imageFromRow(array $row) {
    /*
     * Room and hallway uploads are stored in walk_links as a relative
     * uploads/room-attachments/... image path.
     */
    if (isset($row['walk_links']) && is_scalar($row['walk_links'])) {
        $walkPath = trim((string)$row['walk_links']);
        $imagePath = parse_url($walkPath, PHP_URL_PATH) ?: $walkPath;
        if ($walkPath !== '' && preg_match('/\.(?:jpe?g|png|gif|webp)$/i', $imagePath)) {
            if (preg_match('#^https?://#i', $walkPath)) {
                return $walkPath;
            }
            if (str_starts_with($walkPath, '/') ||
                str_starts_with($walkPath, './') ||
                str_starts_with($walkPath, '../')) {
                return $walkPath;
            }
            if (preg_match(
                '#^uploads/room-attachments/[a-f0-9]{32}\.(?:jpe?g|png|gif|webp)$#i',
                str_replace('\\', '/', $walkPath)
            )) {
                return '../' . ltrim(str_replace('\\', '/', $walkPath), '/');
            }
        }
    }

    $imageColumns = [
        'room_image', 'room_image_url', 'room_image_path',
        'office_image', 'office_image_url', 'office_image_path',
        'facility_image', 'facility_image_url', 'facility_image_path',
        'navigation_image', 'navigation_image_url', 'navigation_image_path',
        'location_image', 'location_image_url', 'location_image_path',
        'room_photo', 'office_photo', 'facility_photo', 'navigation_photo',
        'image_url', 'image_path', 'image',
        'photo_url', 'photo_path', 'photo',
        'picture_url', 'picture_path', 'picture', 'image_file', 'photo_file'
    ];

    foreach ($imageColumns as $column) {
        if (!isset($row[$column]) || !is_scalar($row[$column])) continue;
        $value = trim((string)$row[$column]);
        if ($value === '') continue;

        if (preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,#i', $value)) {
            return $value;
        }
        if (preg_match('#^https?://#i', $value) || str_starts_with($value, '/') ||
            str_starts_with($value, './') || str_starts_with($value, '../')) {
            return $value;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) continue;

        // Uploaded image paths are commonly stored relative to the app root.
        return '../' . ltrim(str_replace('\\', '/', $value), '/');
    }

    return '';
}

function visibleDetails(array $row) {
    $excluded = [
        'room_id', 'office_id', 'facility_id', 'navigation_point_id',
        'building_id', 'floor', 'floor_number',
        'room_number', 'room_name', 'office_name', 'facility_name', 'location_name',
        'position_x', 'position_y', 'width', 'height', 'shape_type', 'rotation',
        'walk_links', 'location_type', 'created_at', 'updated_at', 'deleted_at'
    ];
    $details = [];

    foreach ($row as $key => $value) {
        $key = (string)$key;
        if (in_array(strtolower($key), $excluded, true) ||
            preg_match('/(?:_id|_image|_photo|_picture|_blob)$/i', $key) ||
            preg_match('/^(?:image|photo|picture)(?:_|$)/i', $key) ||
            !is_scalar($value)) {
            continue;
        }

        $text = trim((string)$value);
        if ($text === '' || strlen($text) > 500) continue;

        $label = ucwords(str_replace('_', ' ', $key));
        $details[$label] = $text;
    }

    return $details;
}

function makeLayoutItem(array $row, $category, $idColumn, $numberColumn, $nameColumn, $floorColumn) {
    return [
        'category' => $category,
        'item_id' => (int)($row[$idColumn] ?? 0),
        'item_number' => (string)($numberColumn ? ($row[$numberColumn] ?? '') : ''),
        'item_name' => (string)($row[$nameColumn] ?? ''),
        'floor' => (string)($row[$floorColumn] ?? ''),
        'position_x' => (float)($row['position_x'] ?? 40),
        'position_y' => (float)($row['position_y'] ?? 65),
        'width' => (float)($row['width'] ?? 140),
        'height' => (float)($row['height'] ?? 90),
        'shape_type' => (string)($row['shape_type'] ?? 'rectangle'),
        'rotation' => (float)($row['rotation'] ?? 0),
        'location_type' => (string)($row['location_type'] ?? ''),
        'staircase_type' => (string)($row['staircase_type'] ?? ''),
        'sections' => is_array($row['_sections'] ?? null) ? $row['_sections'] : [],
        'walk_links' => is_scalar($row['walk_links'] ?? null) ? (string)$row['walk_links'] : '',
        'image' => imageFromRow($row),
        'details' => visibleDetails($row)
    ];
}

$categoryColors = [
    'Room' => '#0b3b82',
    'Offices' => '#2e9e5b',
    'Facilities' => '#e8772e'
];

$building_id = (int)($_GET['building_id'] ?? 0);
$floorInput = trim((string)($_GET['floor'] ?? '1'));
$floor = '1';

if (preg_match('/^(\d+)(?:st|nd|rd|th)?(?:\s+Floor)?$/i', $floorInput, $match)) {
    $floor = $match[1];
}

if ($building_id <= 0) {
    header('Location: ../campus-map.php');
    exit;
}

$stmt = $conn->prepare(
    'SELECT building_id, building_name, total_floors, status
     FROM buildings WHERE building_id = ? LIMIT 1'
);
$stmt->execute([$building_id]);
$building = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$building) {
    header('Location: ../campus-map.php');
    exit;
}

$totalFloors = max(1, (int)($building['total_floors'] ?? 1));
if (!ctype_digit($floor) || (int)$floor < 1 || (int)$floor > $totalFloors) {
    $floor = '1';
}

$floorNumber = (int)$floor;
$floorLabel = ordinalFloor($floorNumber);
$selectedFloorLabel = $floorLabel . ' Floor';
$items = [];

/*
 * Load each visible map object from its own table. The existing walk_links
 * column is read with each row and used as the uploaded image path when valid.
 */
$entityQueries = [
    [
        'category' => 'Room',
        'table' => 'rooms',
        'id' => 'room_id',
        'number' => 'room_number',
        'name' => 'room_name',
        'floor' => 'floor',
        'sql' => "SELECT * FROM rooms
                  WHERE building_id = :building_id
                    AND (TRIM(CAST(floor AS CHAR)) = :numeric_floor
                         OR LOWER(TRIM(CAST(floor AS CHAR))) = LOWER(:label_floor))"
    ],
    [
        'category' => 'Offices',
        'table' => 'offices',
        'id' => 'office_id',
        'number' => null,
        'name' => 'office_name',
        'floor' => 'floor',
        'sql' => "SELECT * FROM offices
                  WHERE building_id = :building_id
                    AND (TRIM(CAST(floor AS CHAR)) = :numeric_floor
                         OR LOWER(TRIM(CAST(floor AS CHAR))) = LOWER(:label_floor))"
    ],
    [
        'category' => 'Facilities',
        'table' => 'facilities',
        'id' => 'facility_id',
        'number' => null,
        'name' => 'facility_name',
        'floor' => 'floor',
        'sql' => "SELECT * FROM facilities
                  WHERE building_id = :building_id
                    AND (TRIM(CAST(floor AS CHAR)) = :numeric_floor
                         OR LOWER(TRIM(CAST(floor AS CHAR))) = LOWER(:label_floor))"
    ]
];

foreach ($entityQueries as $query) {
    $stmt = $conn->prepare($query['sql']);
    $stmt->execute([
        'building_id' => $building_id,
        'numeric_floor' => (string)$floorNumber,
        'label_floor' => $selectedFloorLabel
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = makeLayoutItem(
            $row,
            $query['category'],
            $query['id'],
            $query['number'],
            $query['name'],
            $query['floor']
        );
    }
}

$stmt = $conn->prepare(
    'SELECT * FROM navigation_points
     WHERE building_id = :building_id AND floor_number = :floor_number
     ORDER BY navigation_point_id ASC'
);
$stmt->execute([
    'building_id' => $building_id,
    'floor_number' => $floorNumber
]);
$navigationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$navigationIds = array_values(array_filter(array_map(
    static fn($row) => (int)($row['navigation_point_id'] ?? 0),
    $navigationRows
)));
$sectionsByNavigationId = [];
if ($navigationIds) {
    try {
        $sectionStmt = $conn->prepare(
            'SELECT navigation_point_id, section_number, section_name, photo_path, marker_x, marker_y
             FROM navigation_point_sections
             WHERE navigation_point_id = ANY(CAST(:navigation_ids AS BIGINT[]))
             ORDER BY navigation_point_id, section_number'
        );
        $sectionStmt->execute(['navigation_ids' => '{' . implode(',', $navigationIds) . '}']);
        foreach ($sectionStmt->fetchAll(PDO::FETCH_ASSOC) as $sectionRow) {
            $path = trim((string)($sectionRow['photo_path'] ?? ''));
            if ($path !== '' && !preg_match('#^https?://#i', $path) &&
                !str_starts_with($path, '/') && !str_starts_with($path, './') &&
                !str_starts_with($path, '../')) {
                $path = '../' . ltrim(str_replace('\\', '/', $path), '/');
            }
            $sectionRow['image'] = $path;
            $id = (int)$sectionRow['navigation_point_id'];
            $sectionsByNavigationId[$id][] = $sectionRow;
        }
    } catch (Throwable $sectionError) {
        // Older database installations may not have the hallway section table yet.
        error_log('Navigation point sections load error: ' . $sectionError->getMessage());
    }
}
foreach ($navigationRows as $row) {
    $row['floor'] = $row['floor_number'] ?? $floorNumber;
    $row['shape_type'] = 'rectangle';
    $row['_sections'] = $sectionsByNavigationId[(int)$row['navigation_point_id']] ?? [];
    if (strcasecmp((string)($row['location_type'] ?? ''), 'Hallway') === 0 &&
        !empty($row['_sections'])) {
        foreach ($row['_sections'] as $section) {
            if (!empty($section['image'])) {
                $row['walk_links'] = $section['image'];
                break;
            }
        }
    }
    $items[] = makeLayoutItem(
        $row,
        'Navigation',
        'navigation_point_id',
        null,
        'location_name',
        'floor'
    );
}

// Guard against duplicate rows returned by a source table while preserving
// distinct categories whose numeric IDs happen to match.
$uniqueItems = [];
$seenItems = [];
foreach ($items as $item) {
    $key = $item['category'] . ':' . $item['item_id'];
    if (isset($seenItems[$key])) continue;
    $seenItems[$key] = true;
    $uniqueItems[] = $item;
}
$items = $uniqueItems;

usort($items, function ($a, $b) {
    $categoryOrder = ['Navigation' => 0, 'Room' => 1, 'Offices' => 2, 'Facilities' => 3];
    $order = ($categoryOrder[$a['category']] ?? 99) <=> ($categoryOrder[$b['category']] ?? 99);
    return $order !== 0 ? $order : strnatcasecmp($a['item_number'], $b['item_number']);
});

$itemsJson = json_encode(
    $items,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
if ($itemsJson === false) $itemsJson = '[]';
$conn = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($building['building_name']) ?> - Floor Layout | CMU Campus Navigator</title>
    <script src="../floor.js"></script>
    <style>
        *{box-sizing:border-box}
        body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f3f6fb;color:#172b4d}
        .navbar{min-height:86px;padding:14px 4%;display:flex;align-items:center;justify-content:space-between;gap:20px;background:#fff;border-bottom:1px solid #dce4ef;box-shadow:0 2px 8px #142d5010}
        .brand{display:flex;align-items:center;gap:13px;color:#0b3b82;text-decoration:none}
        .brand img{width:54px;height:54px;object-fit:contain}
        .brand-text{display:flex;flex-direction:column;gap:5px}
        .brand-text strong{font-size:21px;color:#0b3b82}
        .brand-text span{font-size:13px;color:#52627a}
        .nav-links{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
        .nav-links a{padding:11px 16px;border-radius:7px;text-decoration:none;font-size:14px;font-weight:bold}
        .nav-home{color:#0b3b82;background:#edf3fc}
        .nav-admin{color:#fff;background:#0b3b82}
        .container{max-width:1600px;padding:30px;margin:auto}
        .back-link{display:inline-block;margin-bottom:20px;color:#0b3b82;text-decoration:none;font-size:14px;font-weight:bold}
        .page-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px}
        .eyebrow{color:#805900;font-size:12px;font-weight:800;letter-spacing:1.5px}
        .page-heading h1{margin:9px 0;color:#0b3b82;font-size:34px;overflow-wrap:anywhere}
        .page-heading p{margin:0;color:#52627a;line-height:1.7}
        .floor-badge{padding:10px 16px;border-radius:8px;background:#ffdf45;color:#172b4d;font-weight:bold}
        .floor-selector{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:22px}
        .floor-selector a{padding:11px 16px;border:1px solid #d8e1ee;border-radius:7px;color:#0b3b82;background:white;font-size:14px;font-weight:bold;text-decoration:none}
        .floor-selector a.active,.floor-selector a:hover{border-color:#0b3b82;background:#0b3b82;color:white}
        .panel{min-width:0;padding:22px;margin-bottom:20px;background:white;border:1px solid #d8e1ee;border-radius:12px;box-shadow:0 4px 14px #0b3b820d}
        .panel h2{margin:0 0 10px;color:#0b3b82;font-size:21px}
        .panel-description{margin:0 0 18px;color:#52627a;font-size:14px;line-height:1.6}
        .floor-layout-grid{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:18px;align-items:start}
        .floor-layout-column{min-width:0}
        .legend{display:flex;flex-wrap:wrap;gap:16px;margin:0 0 14px;color:#43536b;font-size:14px;font-weight:bold}
        .legend-item{display:inline-flex;align-items:center;gap:7px}
        .legend-dot{width:16px;height:16px;border-radius:4px;border:1px solid #0003}
        .layout-scroll{width:100%;overflow:auto;border:1px solid #d8e0ec;border-radius:8px;background:white}
        .layout-scroll.zoomed{overflow:hidden}
        .floor-plan{position:relative;width:1200px;height:650px;overflow:visible;background-color:#fbfcff;background-image:linear-gradient(#e7ecf4 1px,transparent 1px),linear-gradient(90deg,#e7ecf4 1px,transparent 1px);background-size:20px 20px;transform-origin:0 0;transition:transform .65s cubic-bezier(.22,.61,.36,1);will-change:transform}
        .canvas-label{position:absolute;top:12px;left:15px;color:#9aa5b5;font-size:13px;pointer-events:none}
        .room{position:absolute;min-width:60px;min-height:45px;display:flex;align-items:center;justify-content:center;padding:6px;margin:0;overflow:hidden;text-align:center;cursor:pointer;border:2px solid;border-radius:0;color:white;font-family:inherit;line-height:normal;transition:box-shadow .15s,filter .15s,opacity .3s}
        .room.nav-shape{min-width:20px;min-height:20px;padding:2px 4px}
        .room.staircase-shape{border:3px solid #646b73!important;background-color:#aeb4bb!important;background-image:repeating-linear-gradient(to bottom,transparent 0,transparent 12px,rgba(72,78,85,.72) 12px,rgba(72,78,85,.72) 14px),linear-gradient(135deg,#bfc4ca,#8d949c)!important;box-shadow:inset 7px 0 0 rgba(55,61,68,.16),inset -7px 0 0 rgba(55,61,68,.16)}
        .room.switchback-staircase{border:3px solid #454a50!important;border-radius:0!important;background:#a4a5a5!important;box-shadow:none!important;overflow:visible!important;color:#30363b!important}
        .switchback-diagram{position:absolute;inset:0;display:flex;flex-direction:column;background:#a4a5a5;pointer-events:none}
        .switchback-landing{height:35%;flex:0 0 35%;border-bottom:2px solid #454a50}
        .switchback-flights{height:65%;min-height:0;display:flex;position:relative;flex:1}
        .switchback-flight{flex:1;min-width:0;display:flex;flex-direction:column}
        .switchback-step{flex:1;min-height:0;border-bottom:1.5px solid #454a50;display:flex;align-items:center;justify-content:center}
        .switchback-step:last-child{border-bottom:0}
        .switchback-well{width:9%;flex:0 0 9%;margin-top:-2px;background:#a4a5a5;border-left:2px solid #454a50;border-right:2px solid #454a50;border-bottom:2px solid #454a50}
        .switchback-arrow{font-size:clamp(10px,1.8em,24px);line-height:1;font-weight:800;color:#30363b}
        .room.switchback-staircase .room-label{position:absolute;z-index:3;left:50%;top:50%;transform:translate(-50%,-50%);background:rgba(75,82,89,.9);color:#fff;padding:2px 4px;border-radius:3px;white-space:nowrap}
        .hallway-section-gallery{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:12px 0}
        .hallway-section-photo{display:block;writing-mode:horizontal-tb;direction:ltr;width:100%;min-width:0;min-height:42px;padding:11px 13px;border:1px solid #d7e0ed;border-radius:8px;background:#fff;cursor:pointer;text-align:left;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:600;line-height:1.4;transition:border-color .15s,background .15s,box-shadow .15s;color:#23344d;white-space:normal;overflow-wrap:break-word;word-break:normal}.hallway-section-photo:hover{border-color:#0b3b82;background:#f3f7fd}.hallway-section-photo.active{border-color:#f97316;background:#fff0e5;box-shadow:0 0 0 2px #f9731626}.hallway-section-photo span{display:block;writing-mode:horizontal-tb;min-width:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:600;line-height:1.4;color:#0b3b82;white-space:normal;overflow-wrap:break-word;word-break:normal}.hallway-section-photo.active span{color:#c2410c}

        .room:hover,.room.selected,.room:focus-visible{z-index:5;box-shadow:0 0 0 3px #d9a900;outline:none}
        .floor-plan.has-selection .room:not(.selected){opacity:.45}
        .room.selected{z-index:30;opacity:1}
        .room-label{font-size:12px;font-weight:bold;white-space:pre-line;overflow-wrap:anywhere;pointer-events:none}
        .legend-hint{margin-top:12px;color:#52627a;font-size:12px}
        .room-details-panel{position:sticky;top:20px;min-width:0;padding:18px;background:white;border:1px solid #d8e1ee;border-radius:10px;box-shadow:0 4px 14px #0b3b820a}
        .room-details-panel.has-room{border-color:#0b3b82;border-width:2px}
        .room-details-panel h2{margin:0 0 6px;color:#0b3b82;font-size:19px}
        .details-hint{margin:0 0 18px;color:#52627a;font-size:13px;line-height:1.6}
        .detail-item{padding:13px 0;border-bottom:1px solid #e5ebf4}
        .detail-label{display:block;margin-bottom:6px;color:#52627a;font-size:12px;font-weight:bold}
        .detail-value{display:block;color:#0b3b82;font-size:15px;font-weight:bold;overflow-wrap:anywhere;line-height:1.5}
        .room-info-placeholder{padding:16px 12px;color:#52627a;background:#f8faff;border:1px dashed #cbd6e5;border-radius:8px;font-size:13px;line-height:1.6;text-align:center}
        .room-selected-label{display:inline-block;padding:5px 9px;margin-bottom:12px;color:#146c43;background:#d1e7dd;border-radius:20px;font-size:11px;font-weight:bold}
        .hallway-sequence{margin:14px 0;padding:12px;border:1px solid #d8e1ee;border-radius:8px;background:#f8faff}
        .image-viewer:not([hidden]){display:block;width:100%;min-height:180px}
        .detail-image{display:block;width:100%;max-height:360px;object-fit:contain;background:#eef2f7;border-radius:8px}
        .hallway-sequence h3{margin:0 0 8px;color:#0b3b82;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;line-height:1.35;letter-spacing:.1px}
        .hallway-sequence p{margin:0 0 12px;color:#52627a;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.55}
        .hallway-thumbnails{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-items:stretch}
        @media(max-width:520px){.hallway-thumbnails{grid-template-columns:minmax(0,1fr)}.hallway-section-photo,.hallway-section-photo span{writing-mode:horizontal-tb;}}
        .hallway-thumb{min-width:0;padding:4px;border:1px solid #d8e1ee;border-radius:7px;background:#fff;color:#172b4d;text-align:left;cursor:pointer}
        .hallway-thumb:hover,.hallway-thumb.active{border-color:#0b3b82;box-shadow:0 0 0 2px #0b3b821a}
        .hallway-thumb img{display:block;width:100%;height:58px;object-fit:cover;border-radius:4px;background:#e8edf5}
        .hallway-thumb span{display:block;margin-top:4px;font-size:10px;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .hallway-thumb small{display:block;margin-top:2px;color:#64748b;font-size:9px}
        .image-viewer{margin:14px 0 8px}
        .image-viewer.fullscreen{position:fixed;inset:0;z-index:99999;display:block;margin:0;padding:0;background:rgba(0,0,0,.78);backdrop-filter:blur(1px)}
        .image-viewer.fullscreen[hidden]{display:none}
        .image-viewer.fullscreen .image-viewport{position:absolute;inset:0;width:100%;height:100%;border-radius:0;border:0;background:#000}
        .image-viewer.fullscreen .detail-image{width:100%;height:100%;max-height:none;object-fit:contain;border-radius:0;transition:transform .02s linear}
        .photo-empty-state{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:28px;color:#fff;background:#000;text-align:center;font-size:clamp(16px,2.4vw,25px);font-weight:600;line-height:1.5;z-index:2}
        .photo-empty-state[hidden]{display:none}
        .photo-viewer-close{display:none;position:fixed;z-index:100001;top:14px;right:18px;width:42px;height:42px;border:1px solid #ffffff66;border-radius:50%;background:#0b3b82;color:white;font-size:27px;cursor:pointer}
        .image-viewer.fullscreen .photo-viewer-close{display:block}
        .image-viewport{position:relative;width:100%;height:300px;overflow:hidden;border:1px solid #d8e1ee;border-radius:7px;background:#000;touch-action:none;cursor:grab}
        .image-viewport:active{cursor:grabbing}
        .detail-image{display:block;width:100%;height:100%;margin:0;object-fit:contain;transform-origin:center center;transition:transform .02s linear;user-select:none;-webkit-user-drag:none;pointer-events:auto;cursor:zoom-in}
        .photo-nav-arrow{position:absolute;z-index:4;display:none;align-items:center;justify-content:center;width:42px;height:42px;padding:0;border:1px solid #ffffff88;border-radius:50%;background:#0b3b8270;color:#fff;font-size:24px;font-weight:bold;line-height:1;cursor:pointer;opacity:.82;box-shadow:0 2px 10px #0005;backdrop-filter:blur(3px);transition:background .15s,opacity .15s,transform .15s}
        .image-viewer.fullscreen .photo-nav-arrow{display:flex}
        .photo-nav-arrow:hover{background:#d9a900cc;color:#172b4d;opacity:1;transform:scale(1.06)}
        .photo-nav-arrow:disabled{opacity:.3;cursor:not-allowed}
        .photo-nav-forward{left:50%;bottom:12px;transform:translateX(-50%)}
        .photo-nav-forward:hover{transform:translateX(-50%) scale(1.06)}
        .photo-nav-back{left:50%;top:12px;transform:translateX(-50%)}
        .photo-nav-back:hover{transform:translateX(-50%) scale(1.06)}
        .photo-nav-left{left:12px;top:50%;transform:translateY(-50%)}
        .photo-nav-left:hover{transform:translateY(-50%) scale(1.06)}
        .photo-nav-right{right:12px;top:50%;transform:translateY(-50%)}
        .photo-nav-right:hover{transform:translateY(-50%) scale(1.06)}
        .photo-nav-caption{display:none;position:absolute;z-index:3;left:50%;bottom:62px;transform:translateX(-50%);max-width:75%;padding:5px 9px;border-radius:5px;background:#101827bb;color:#fff;font-size:11px;text-align:center;pointer-events:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .image-viewer.fullscreen .photo-nav-caption{display:block}
        .image-link{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;margin:12px 0 8px;padding:13px 18px;border:1px solid #d9a900;border-radius:10px;background:linear-gradient(135deg,#0b3b82 0%,#1455a4 100%);color:#fff;font-family:inherit;font-size:14px;font-weight:700;letter-spacing:.2px;cursor:pointer;box-shadow:0 5px 14px #0b3b8226;transition:transform .18s ease,box-shadow .18s ease,background .18s ease}
        .image-link:hover{background:linear-gradient(135deg,#082d63 0%,#0b3b82 100%);box-shadow:0 8px 18px #0b3b8238;transform:translateY(-1px)}
        .image-link:active{transform:translateY(0)}
        .image-link .image-link-icon{display:inline-flex;align-items:center;justify-content:center;width:25px;height:25px;border-radius:7px;background:#ffffff20;font-size:17px;line-height:1}
        .image-link .image-link-copy{display:flex;flex-direction:column;align-items:flex-start;gap:2px}
        .image-link .image-link-title{font-size:14px}
        .image-link .image-link-subtitle{font-size:11px;font-weight:400;color:#e2ebff}
        .back-btn{display:block;width:100%;margin-top:14px;padding:12px 16px;border:0;border-radius:7px;background:#0b3b82;color:white;font-family:inherit;font-size:14px;font-weight:bold;cursor:pointer}
        .empty-state{padding:40px 20px;color:#52627a;text-align:center;font-size:14px;line-height:1.7}
        .empty-state strong{display:block;margin-bottom:8px;color:#0b3b82;font-size:18px}
        .image-view-btn{display:inline-block;margin:12px 0 8px;padding:10px 16px;border:0;border-radius:7px;background:#0b3b82;color:#fff;font-family:inherit;font-size:14px;font-weight:bold;cursor:pointer}
        .image-view-btn:hover{background:#082d63}
        /* Full-screen photo viewer: black side panels, centered photo, subtle CMU-style controls. */
        .image-viewer.fullscreen{position:fixed!important;inset:0!important;width:100vw!important;height:100dvh!important;min-height:0!important;margin:0!important;padding:0!important;overflow:hidden!important;background:rgba(5,10,20,.34)!important;backdrop-filter:blur(1px)!important}
        .image-viewer.fullscreen .image-viewport{position:absolute!important;inset:0!important;width:100%!important;height:100%!important;border:0!important;border-radius:0!important;background:transparent!important;}
        .image-viewer.fullscreen .detail-image{width:100%!important;height:100%!important;max-height:none!important;object-fit:contain!important;background:transparent!important;}
        .image-viewer.fullscreen .photo-nav-arrow{position:absolute;display:flex;opacity:.58;background:rgba(12,22,38,.78);border:1px solid rgba(255,255,255,.22);color:#fff;pointer-events:auto;z-index:100000;box-shadow:0 4px 18px rgba(0,0,0,.32);backdrop-filter:blur(8px)}
        .image-viewer.fullscreen .photo-nav-forward{left:50%;top:auto;right:auto;bottom:18px;transform:translateX(-50%)}
        .image-viewer.fullscreen .photo-nav-back{left:50%;top:18px;right:auto;bottom:auto;transform:translateX(-50%)}
        .image-viewer.fullscreen .photo-nav-left{left:30%;top:50%;right:auto;bottom:auto;transform:translate(-50%,-50%)}
        .image-viewer.fullscreen .photo-nav-right{left:70%;top:50%;right:auto;bottom:auto;transform:translate(-50%,-50%)}
        .image-viewer.fullscreen .photo-nav-arrow:hover{opacity:.92;background:rgba(11,59,130,.78);border-color:#d9a900;color:#fff;transform:translate(-50%,-50%) scale(1.05)}
        .image-viewer.fullscreen .photo-nav-arrow.is-cursor-hidden{opacity:.42;pointer-events:auto}
        .image-viewer.fullscreen .photo-nav-caption{bottom:72px;background:rgba(8,11,18,.78);border:1px solid rgba(255,255,255,.14);padding:7px 12px;border-radius:8px;}
        .image-viewer.fullscreen .photo-viewer-close{top:16px;right:18px;background:rgba(11,59,130,.92);border:1px solid rgba(255,255,255,.3);box-shadow:0 4px 16px rgba(0,0,0,.28)}
        .image-viewer.fullscreen .photo-viewer-close:hover{background:#b42318;border-color:#fff}
        .photo-nav-arrow{transition:opacity .2s,background .15s,color .15s,transform .15s}
        .detail-image.dolly-out{animation:dollyOut .26s ease-in forwards}
        .detail-image.dolly-in{animation:dollyIn .42s ease-out both}
        @keyframes dollyOut{to{transform:scale(1.16);opacity:.72;filter:blur(1px)}}
        @keyframes dollyIn{from{transform:scale(1.13);opacity:.72;filter:blur(1px)}to{transform:scale(1);opacity:1;filter:blur(0)}}
        .site-footer{background:#fff;color:#52627a;text-align:center;padding:20px 16px;border-top:1px solid #dce4ef;font-size:13px}
        @media(max-width:1100px){.floor-layout-grid{grid-template-columns:minmax(0,1fr)}.room-details-panel{position:static}}
        @media(max-width:650px){.navbar{align-items:flex-start;flex-direction:column;padding:16px}.brand-text strong{font-size:18px}.nav-links a{padding:10px 12px}.container{padding:18px 12px}.page-heading h1{font-size:28px}.panel{padding:17px}}
        @media(prefers-reduced-motion:reduce){.floor-plan{transition:none}}
    </style>
<link rel="stylesheet" href="../mobile.css">
</head>
<body data-no-bg>
<header class="navbar">
    <a class="brand" href="../campus-map.php">
        <img src="../cmu%20logo.jpg" alt="CMU Logo">
        <div class="brand-text"><strong>CMU Campus Navigator</strong><span>City of Malabon University</span></div>
    </a>
    <nav class="nav-links">
        <a href="../index.php" class="nav-home">Home</a>
        <a href="../campus-map.php" class="nav-home">Campus Map</a>
        <a href="login.php" class="nav-admin">Admin Login</a>
    </nav>
</header>

<main class="container">
    <a class="back-link" href="../campus-map.php">&larr; Back to Campus Map</a>
    <div class="page-heading">
        <div>
            <span class="eyebrow">BUILDING INFORMATION</span>
            <h1><?= h($building['building_name']) ?></h1>
            <p>View rooms, offices, facilities, and navigation points. Select a floor and click an item to see its database details and image.</p>
        </div>
        <div class="floor-badge"><?= h($selectedFloorLabel) ?></div>
    </div>

    <nav class="floor-selector" aria-label="Choose floor">
        <?php for ($i = 1; $i <= $totalFloors; $i++): ?>
            <a class="<?= $i === $floorNumber ? 'active' : '' ?>"
               href="floor-layout.php?building_id=<?= $building_id ?>&amp;floor=<?= $i ?>">
                <?= h(ordinalFloor($i)) ?> Floor
            </a>
        <?php endfor; ?>
    </nav>

    <section class="panel">
        <h2>Floor Layout</h2>
        <p class="panel-description">Items use their saved database positions. Click an item to zoom in and view its details on the right.</p>

        <?php if (empty($items)): ?>
            <div class="empty-state">
                <strong>No layout items found for this floor.</strong>
                No rooms, offices, facilities, or navigation points were found for this building and floor.
                Check that the records are assigned to this building and floor in the admin layout editor.
            </div>
        <?php else: ?>
            <div class="legend">
                <?php foreach ($categoryColors as $name => $color): ?>
                    <span class="legend-item"><span class="legend-dot" style="background:<?= h($color) ?>"></span><?= h($name) ?></span>
                <?php endforeach; ?>
            </div>

            <div class="floor-layout-grid">
                <div class="floor-layout-column">
                    <div class="layout-scroll" id="layoutScroll">
                        <div class="floor-plan" id="floorPlan">
                            <div class="canvas-label"><?= h($building['building_name']) ?> — <?= h($selectedFloorLabel) ?></div>
                            <?php foreach ($items as $index => $item):
                                $x = max(0, min(1200, $item['position_x']));
                                $y = max(0, min(650, $item['position_y']));
                                $rotation = max(-360, min(360, $item['rotation']));
                                $isNavigation = $item['category'] === 'Navigation';
                                $isStaircase = $isNavigation && strcasecmp($item['location_type'], 'Staircase') === 0;
                                $isSwitchback = $isStaircase && strcasecmp($item['staircase_type'], 'Switchback') === 0;
                                $w = $isNavigation ? max(20, min(1200, $item['width'])) : max(60, min(600, $item['width']));
                                $height = $isNavigation ? max(20, min(650, $item['height'])) : max(45, min(400, $item['height']));
                                $number = trim($item['item_number']);
                                $name = trim($item['item_name']);
                                $label = implode("\n", array_filter([$number, $name], fn($v) => $v !== ''));
                                $color = $isNavigation ? '#ffffff' : ($categoryColors[$item['category']] ?? '#0b3b82');
                                $textColor = $color === '#ffffff' ? '#334155' : '#ffffff';
                                $borderColor = sprintf('#%02x%02x%02x',
                                    (int)round(hexdec(substr(ltrim($color, '#'), 0, 2)) * 0.7),
                                    (int)round(hexdec(substr(ltrim($color, '#'), 2, 2)) * 0.7),
                                    (int)round(hexdec(substr(ltrim($color, '#'), 4, 2)) * 0.7)
                                );
                            ?>
                                <button type="button"
                                    class="room<?= $isNavigation ? ' nav-shape' : '' ?><?= $isStaircase ? ' staircase-shape' : '' ?><?= $isSwitchback ? ' switchback-staircase' : '' ?>"
                                    style="left:<?= $x ?>px;top:<?= $y ?>px;width:<?= $w ?>px;height:<?= $height ?>px;transform:rotate(<?= $rotation ?>deg);background:<?= h($color) ?>;border-color:<?= h($borderColor) ?>;color:<?= h($textColor) ?>"
                                    data-index="<?= (int)$index ?>"
                                    data-x="<?= $x ?>" data-y="<?= $y ?>" data-w="<?= $w ?>" data-h="<?= $height ?>"
                                    title="Click to view item details"
                                    aria-label="<?= h(trim($number . ' ' . $name)) ?>">
                                    <?php if ($isSwitchback): ?>
                                        <span class="switchback-diagram" aria-hidden="true">
                                            <span class="switchback-landing"></span>
                                            <span class="switchback-flights">
                                                <span class="switchback-flight"><?php for ($step = 0; $step < 6; $step++): ?><span class="switchback-step"><?php if ($step === 0): ?><span class="switchback-arrow">↑</span><?php endif; ?></span><?php endfor; ?></span>
                                                <span class="switchback-well"></span>
                                                <span class="switchback-flight"><?php for ($step = 0; $step < 6; $step++): ?><span class="switchback-step"><?php if ($step === 5): ?><span class="switchback-arrow">↓</span><?php endif; ?></span><?php endfor; ?></span>
                                            </span>
                                        </span>
                                    <?php endif; ?>
                                    <span class="room-label"><?= h($label !== '' ? $label : $item['category']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="legend-hint">Click an item to zoom in. Click an empty area, press Esc, or use Back to zoom out.</div>
                </div>

                <aside class="room-details-panel" id="roomDetailsPanel">
                    <h2 id="detailsHeading">Room Details</h2>
                    <p class="details-hint">Select a room, office, facility, or navigation point to see its saved information.</p>
                    <div class="room-info-placeholder" id="itemPlaceholder">No item selected yet.</div>
                    <div id="itemDetails" hidden>
                        <span class="room-selected-label" id="selectedLabel">SELECTED ITEM</span>
                        <div class="detail-item"><span class="detail-label">Category</span><span class="detail-value" id="detailType">—</span></div>
                        <div class="detail-item"><span class="detail-label">Number / ID</span><span class="detail-value" id="detailNumber">—</span></div>
                        <div class="detail-item"><span class="detail-label">Name</span><span class="detail-value" id="detailName">—</span></div>
                        <div class="detail-item"><span class="detail-label">Floor</span><span class="detail-value" id="detailFloor">—</span></div>
                        <div id="extraDetails"></div>
                        <div class="image-viewer" id="imageViewer" hidden>
                            <button type="button" class="photo-viewer-close" id="closePhotoViewer" aria-label="Close image viewer">×</button>
                            <div class="image-viewport" id="imageViewport">
                                <img class="detail-image" id="detailImage" alt="">
                                <div class="photo-empty-state" id="photoEmptyState" hidden>No room photo is available for this room.</div>
                                <button type="button" class="photo-nav-arrow photo-nav-back" data-photo-nav="back" aria-label="Go to previous viewpoint" title="Back">⌃</button>
                                <button type="button" class="photo-nav-arrow photo-nav-forward" data-photo-nav="forward" aria-label="Go to next viewpoint" title="Forward">⌄</button>
                                <button type="button" class="photo-nav-arrow photo-nav-left" data-photo-nav="left" aria-label="Go to viewpoint on the left" title="Left">‹</button>
                                <button type="button" class="photo-nav-arrow photo-nav-right" data-photo-nav="right" aria-label="Go to viewpoint on the right" title="Right">›</button>
                                <span class="photo-nav-caption" id="photoNavCaption">Choose an arrow to move to a nearby photo</span>
                            </div>
                        </div>
                        <button type="button" class="image-link" id="detailImageLink" hidden>
                            <span class="image-link-icon" aria-hidden="true">⛶</span>
                            <span class="image-link-copy"><span class="image-link-title">View Image in Full Screen</span><span class="image-link-subtitle">Open photo and use arrows to explore</span></span>
                        </button>
                        <div class="room-info-placeholder" id="imagePlaceholder" hidden>No room or item image is saved for this record.</div>
                        <section class="hallway-sequence" id="hallwaySequence" hidden>
                            <h3>Hallway Areas</h3>
                            <p>Select an area name to preview its photo. Images are loaded only when selected to keep the page responsive.</p>
                            <div class="hallway-thumbnails" id="hallwayThumbnails"></div>
                        </section>
                        <button type="button" class="back-btn" id="resetViewBtn">&larr; Back / Reset View</button>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </section>
</main>

<footer class="site-footer">© 2026 CMU Campus Navigator | City of Malabon University</footer>

<script>
const items = <?= $itemsJson ?>;
const floorPlan = document.getElementById('floorPlan');
const layoutScroll = document.getElementById('layoutScroll');
const detailsPanel = document.getElementById('roomDetailsPanel');
const placeholder = document.getElementById('itemPlaceholder');
const details = document.getElementById('itemDetails');
const resetViewBtn = document.getElementById('resetViewBtn');
let selectedButton = null;
let resetTimer = null;
const detailImage = document.getElementById('detailImage');
const photoEmptyState = document.getElementById('photoEmptyState');

function showNavigationDestination(button) {
    showItem(button);
    const item = items[Number(button.dataset.index)] || {};
    const hasImage = !!item.image;
    if (photoEmptyState) {
        photoEmptyState.hidden = hasImage;
        photoEmptyState.textContent = hasImage ? '' : 'No room photo is available for this room.';
    }
    if (!hasImage) {
        detailImage.removeAttribute('src');
        detailImage.hidden = true;
        imageViewer.hidden = false;
        imageViewer.classList.add('fullscreen');
        document.body.style.overflow = 'hidden';
        if (detailImageLink) { detailImageLink.hidden = true; detailImageLink.disabled = true; }
        const caption = document.getElementById('photoNavCaption');
        if (caption) caption.textContent = (item.item_name || item.category || 'Room') + ' · No photo available';
    } else {
        detailImage.hidden = false;
        imageViewer.hidden = false;
        imageViewer.classList.add('fullscreen');
        document.body.style.overflow = 'hidden';
    }
}
let activeHallwaySections = [];
let activeHallwayIndex = -1;
const detailImageLink = document.getElementById('detailImageLink');
const imageViewer = document.getElementById('imageViewer');
const imageViewport = document.getElementById('imageViewport');
let imageScale = 1;
let imageOffsetX = 0;
let imageOffsetY = 0;
let imageDragging = false;
let dragStartX = 0;
let dragStartY = 0;
let dragOriginX = 0;
let dragOriginY = 0;

function updateImageTransform() {
    detailImage.style.transform =
        'translate(' + imageOffsetX + 'px,' + imageOffsetY + 'px) scale(' + imageScale + ')';
}

function resetImageTransform() {
    imageScale = 1;
    imageOffsetX = 0;
    imageOffsetY = 0;
    updateImageTransform();
}

function zoomToItem(button) {
    clearTimeout(resetTimer);
    const scrollLeft = layoutScroll.scrollLeft;
    const scrollTop = layoutScroll.scrollTop;
    layoutScroll.classList.add('zoomed');
    const viewWidth = layoutScroll.clientWidth;
    const viewHeight = layoutScroll.clientHeight;
    const x = parseFloat(button.dataset.x) || 0;
    const y = parseFloat(button.dataset.y) || 0;
    const w = parseFloat(button.dataset.w) || 140;
    const h = parseFloat(button.dataset.h) || 90;
    const scale = Math.max(1.2, Math.min(3.5,
        Math.min((viewWidth * 0.5) / Math.max(w, 1), (viewHeight * 0.5) / Math.max(h, 1))
    ));
    const translateX = viewWidth / 2 + scrollLeft - (x + w / 2) * scale;
    const translateY = viewHeight / 2 + scrollTop - (y + h / 2) * scale;
    floorPlan.style.transform = 'translate(' + translateX + 'px,' + translateY + 'px) scale(' + scale + ')';
}

function addDetail(label, value) {
    const row = document.createElement('div');
    row.className = 'detail-item';
    const heading = document.createElement('span');
    heading.className = 'detail-label';
    heading.textContent = label;
    const content = document.createElement('span');
    content.className = 'detail-value';
    content.textContent = value;
    row.append(heading, content);
    document.getElementById('extraDetails').appendChild(row);
}

function isHallwayItem(item) {
    return item && item.category === 'Navigation' &&
        (String(item.location_type || '').trim().toLowerCase() === 'hallway' ||
         /hallway|area\s*[1-7]/i.test(String(item.item_name || '')));
}

function renderHallwaySequence(currentItem) {
    const section = document.getElementById('hallwaySequence');
    const list = document.getElementById('hallwayThumbnails');
    if (!section || !list) return;
    list.replaceChildren();
    if (!isHallwayItem(currentItem)) {
        section.hidden = true;
        return;
    }
    const sections = (currentItem.sections || [])
        .filter(part => part && part.image)
        .sort((a, b) => {
            // Display order is based only on saved yellow-dot coordinates.
            // In this floor plan, marker_y=88 is the first/top position and smaller values are farther down.
            const ay = Number(a.marker_y);
            const by = Number(b.marker_y);
            const ax = Number(a.marker_x);
            const bx = Number(b.marker_x);
            const aValid = Number.isFinite(ax) && Number.isFinite(ay);
            const bValid = Number.isFinite(bx) && Number.isFinite(by);
            if (aValid && bValid) return (ay - by) || (ax - bx);
            if (aValid) return -1;
            if (bValid) return 1;
            return 0;
        });
    if (!sections.length) {
        section.hidden = true;
        return;
    }
    sections.forEach(part => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'hallway-section-photo';
        button.dataset.sectionNumber = String(part.section_number);
        button.title = 'View ' + (part.section_name || ('Area ' + part.section_number));
        const label = document.createElement('span');
        label.textContent = part.section_name || ('Area ' + part.section_number);
        button.append(label);
        button.addEventListener('click', event => {
            event.stopPropagation();
            activeHallwaySections = sections;
            activeHallwayIndex = activeHallwaySections.findIndex(entry => entry.image === part.image);
            list.querySelectorAll('.hallway-section-photo').forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            detailImage.src = part.image;
            detailImage.alt = label.textContent;
            // Selecting a thumbnail only previews it; fullscreen is opened by the button.
            if (detailImageLink) {
                detailImageLink.hidden = false;
                detailImageLink.disabled = false;
            }
            const caption = document.getElementById('photoNavCaption');
            if (caption) caption.textContent = label.textContent + ' (' + (activeHallwayIndex + 1) + ' of ' + activeHallwaySections.length + ')';
            imagePlaceholder.hidden = true;
            imageViewer.classList.remove('fullscreen');
            imageViewer.hidden = false;
            document.body.style.overflow = '';
            resetImageTransform();
        });
        list.appendChild(button);
    });
    section.hidden = list.children.length === 0;
}

function showItem(button, event) {
    if (event) event.stopPropagation();
    document.querySelectorAll('.room').forEach(function(item) {
        item.classList.remove('selected');
        item.setAttribute('aria-pressed', 'false');
    });
    button.classList.add('selected');
    button.setAttribute('aria-pressed', 'true');
    selectedButton = button;

    const item = items[Number(button.dataset.index)] || {};
    const category = item.category || 'Item';
    const headingNames = {
        Room: 'Room Details',
        Offices: 'Office Details',
        Facilities: 'Facility Details',
        Navigation: isHallwayItem(item) ? 'Hallway Details' : 'Location Details'
    };
    document.getElementById('detailsHeading').textContent = headingNames[category] || 'Item Details';
    const hallwaySelected = isHallwayItem(item);
    document.getElementById('detailType').closest('.detail-item').hidden = hallwaySelected;
    document.getElementById('detailNumber').closest('.detail-item').hidden = hallwaySelected;
    document.getElementById('detailName').closest('.detail-item').hidden = false;
    document.getElementById('detailFloor').closest('.detail-item').hidden = false;
    document.getElementById('detailType').textContent = category;
    document.getElementById('detailNumber').textContent = item.item_number || 'N/A';
    document.getElementById('detailName').textContent = item.item_name || 'No name provided';
    document.getElementById('detailFloor').textContent = item.floor || 'N/A';
    document.getElementById('selectedLabel').textContent = hallwaySelected ? 'SELECTED HALLWAY' : 'SELECTED ' + category.toUpperCase();
    const photoCaption = document.getElementById('photoNavCaption');
    if (photoCaption) photoCaption.textContent = (item.item_name || category) + ' · Use arrows to move';

    const extraDetails = document.getElementById('extraDetails');
    extraDetails.replaceChildren();
    Object.entries(item.details || {}).forEach(function(entry) {
        addDetail(entry[0], String(entry[1]));
    });
    renderHallwaySequence(item);

    const imagePlaceholder = document.getElementById('imagePlaceholder');
    detailImage.hidden = false;
    if (photoEmptyState) photoEmptyState.hidden = true;
    if (hallwaySelected) imagePlaceholder.hidden = true;
    detailImage.onerror = function() {
        imageViewer.hidden = true;
        imagePlaceholder.hidden = false;
        if (detailImageLink) { detailImageLink.hidden = true; detailImageLink.disabled = true; }
    };
    if (item.image && !hallwaySelected) {
        activeHallwaySections = [];
        activeHallwayIndex = -1;
        detailImage.onload = function() {
            imageViewer.hidden = false;
            imagePlaceholder.hidden = true;
            if (detailImageLink) { detailImageLink.hidden = false; detailImageLink.disabled = false; }
        };
        resetImageTransform();
        detailImage.src = item.image;
        if (detailImageLink) {
            detailImageLink.hidden = false;
            detailImageLink.disabled = false;
            detailImageLink.setAttribute('aria-label', 'Open ' + (item.item_name || category) + ' image in fullscreen');
        }
        detailImage.alt = (item.item_name || category) + ' image';
        imageViewer.hidden = false;
        imagePlaceholder.hidden = true;
    } else {
        detailImage.onload = null;
        detailImage.onerror = null;
        if (hallwaySelected) {
            activeHallwaySections = (item.sections || []).filter(part => part && part.image).sort((a, b) => {
                // Keep the same coordinate-only order as the thumbnail gallery.
                const ay = Number(a.marker_y), by = Number(b.marker_y);
                const ax = Number(a.marker_x), bx = Number(b.marker_x);
                const aValid = Number.isFinite(ax) && Number.isFinite(ay);
                const bValid = Number.isFinite(bx) && Number.isFinite(by);
                if (aValid && bValid) return (ay - by) || (ax - bx);
                if (aValid) return -1;
                if (bValid) return 1;
                return 0;
            });
            activeHallwayIndex = -1;
        }
        detailImage.removeAttribute('src');
        if (detailImageLink) { detailImageLink.hidden = true; detailImageLink.disabled = true; }
        imageViewer.hidden = true;
        // Hallways use the area-photo gallery; only show this message for other items.
        imagePlaceholder.hidden = hallwaySelected || !!(item.sections && item.sections.some(part => part && part.image));
    }

    placeholder.hidden = true;
    details.hidden = false;
    detailsPanel.classList.add('has-room');
    floorPlan.classList.add('has-selection');
    zoomToItem(button);
    layoutScroll.scrollIntoView({behavior:'smooth', block:'nearest'});
}

// Keep the item-selection handler accessible to the browser and inline integrations.
window.showItem = showItem;

function navigatePhoto(direction) {
    if (!selectedButton || !imageViewer.classList.contains('fullscreen')) return;

    // Hallway navigation is calculated from marker_x/marker_y saved by add-room.php.
    // It does NOT use section_number, labels, or the gallery's ordering to choose a destination.
    // This floor plan uses marker_y=88 near the top/first position; y decreases as you move downward. x grows to the right.
    if (activeHallwaySections.length > 0 && activeHallwayIndex >= 0) {
        const currentPart = activeHallwaySections[activeHallwayIndex];
        const cx = Number(currentPart.marker_x);
        const cy = Number(currentPart.marker_y);
        const hasCurrentCoords = Number.isFinite(cx) && Number.isFinite(cy);
        const candidates = activeHallwaySections.map((part, index) => {
            if (index === activeHallwayIndex) return null;
            const x = Number(part.marker_x);
            const y = Number(part.marker_y);
            if (!hasCurrentCoords || !Number.isFinite(x) || !Number.isFinite(y)) return null;
            const dx = x - cx;
            const dy = y - cy;
            let primary;
            let secondary;
            if (direction === 'forward' || direction === 'up') {
                if (dy <= 0.5) return null;
                primary = Math.abs(dy);
                secondary = Math.abs(dx);
            } else if (direction === 'back' || direction === 'down') {
                if (dy >= -0.5) return null;
                primary = Math.abs(dy);
                secondary = Math.abs(dx);
            } else if (direction === 'left') {
                if (dx >= -0.5) return null;
                primary = Math.abs(dx);
                secondary = Math.abs(dy);
            } else if (direction === 'right') {
                if (dx <= 0.5) return null;
                primary = Math.abs(dx);
                secondary = Math.abs(dy);
            } else {
                return null;
            }
            // Prioritize movement in the requested direction, then prefer the most aligned dot.
            return { index, score: primary + secondary * 1.35 };
        }).filter(Boolean).sort((a, b) => a.score - b.score);

        if (candidates.length) {
            activeHallwayIndex = candidates[0].index;
            const part = activeHallwaySections[activeHallwayIndex];
            detailImage.hidden = false;
            if (photoEmptyState) photoEmptyState.hidden = true;
            detailImage.src = part.image;
            detailImage.alt = part.section_name || 'Hallway photo';
            const caption = document.getElementById('photoNavCaption');
            if (caption) caption.textContent = part.section_name || 'Hallway photo';
            const thumbList = document.getElementById('hallwayThumbnails');
            if (thumbList) {
                thumbList.querySelectorAll('.hallway-section-photo').forEach(btn => btn.classList.remove('active'));
                const selectedThumb = Array.from(thumbList.querySelectorAll('.hallway-section-photo'))
                    .find(btn => btn.dataset.sectionNumber === String(part.section_number));
                if (selectedThumb) selectedThumb.classList.add('active');
            }
            resetImageTransform();
            return;
        }
        // No hallway-section marker in this direction: continue below and
        // navigate to the nearest mapped room/office/facility/navigation point.
    }

    const current = items[Number(selectedButton.dataset.index)] || {};
    const currentX = Number(current.position_x ?? selectedButton.dataset.x ?? 0);
    const currentY = Number(current.position_y ?? selectedButton.dataset.y ?? 0);
    const candidates = [];

    document.querySelectorAll('.room').forEach(function(button) {
        if (button === selectedButton) return;
        const item = items[Number(button.dataset.index)] || {};
        const dx = Number(item.position_x ?? button.dataset.x ?? 0) - currentX;
        const dy = Number(item.position_y ?? button.dataset.y ?? 0) - currentY;
        let primary = 0;
        let secondary = 0;

        if (direction === 'left') {
            if (dx >= -1) return;
            primary = Math.abs(dx);
            secondary = Math.abs(dy);
        } else if (direction === 'right') {
            if (dx <= 1) return;
            primary = Math.abs(dx);
            secondary = Math.abs(dy);
        } else if (direction === 'forward') {
            if (dy <= 1) return;
            primary = Math.abs(dy);
            secondary = Math.abs(dx);
        } else if (direction === 'back') {
            if (dy >= -1) return;
            primary = Math.abs(dy);
            secondary = Math.abs(dx);
        }

        // Prefer a nearby item aligned with the requested direction.
        candidates.push({button: button, score: primary + secondary * 1.6});
    });

    candidates.sort(function(a, b) { return a.score - b.score; });
    if (candidates.length) {
        showNavigationDestination(candidates[0].button);
        return;
    }

    const caption = document.getElementById('photoNavCaption');
    if (caption) caption.textContent = 'No room or location in that direction';
}

function resetView() {
    if (!selectedButton) return;
    selectedButton.classList.remove('selected');
    selectedButton.setAttribute('aria-pressed', 'false');
    selectedButton = null;
    ['detailType', 'detailNumber', 'detailName', 'detailFloor'].forEach(function(id) {
        const element = document.getElementById(id);
        if (element) element.closest('.detail-item').hidden = false;
    });
    const hallwaySequence = document.getElementById('hallwaySequence');
    if (hallwaySequence) hallwaySequence.hidden = true;
    floorPlan.style.transform = '';
    floorPlan.classList.remove('has-selection');
    details.hidden = true;
    placeholder.hidden = false;
    detailsPanel.classList.remove('has-room');
    clearTimeout(resetTimer);
    resetTimer = setTimeout(function() {
        layoutScroll.classList.remove('zoomed');
    }, 700);
}

if (floorPlan && layoutScroll) {
    // Event delegation ensures every room, office, facility, and navigation point selects reliably.
    floorPlan.addEventListener('click', function(event) {
        const itemButton = event.target.closest('.room');
        if (itemButton && floorPlan.contains(itemButton)) {
            showItem(itemButton, event);
            return;
        }
        if (event.target === floorPlan || event.target.classList.contains('canvas-label')) resetView();
    });
    if (resetViewBtn) resetViewBtn.addEventListener('click', resetView);
    // Mouse wheel / trackpad zoom; drag the photo to pan when zoomed.
    if (imageViewport) {
        imageViewport.addEventListener('wheel', function(event) {
            if (imageViewer.hidden) return;
            event.preventDefault();
            imageScale = Math.max(1, Math.min(5, imageScale + (event.deltaY < 0 ? 0.2 : -0.2)));
            if (imageScale === 1) { imageOffsetX = 0; imageOffsetY = 0; }
            updateImageTransform();
        }, { passive: false });
        imageViewport.addEventListener('pointerdown', function(event) {
            if (imageViewer.hidden || event.target.closest('[data-photo-nav]')) return;
            imageDragging = true;
            dragStartX = event.clientX;
            dragStartY = event.clientY;
            dragOriginX = imageOffsetX;
            dragOriginY = imageOffsetY;
            imageViewport.setPointerCapture(event.pointerId);
        });
        imageViewport.addEventListener('pointermove', function(event) {
            if (!imageDragging) return;
            imageOffsetX = dragOriginX + event.clientX - dragStartX;
            imageOffsetY = dragOriginY + event.clientY - dragStartY;
            updateImageTransform();
        });
        function stopImageDrag() { imageDragging = false; }
        imageViewport.addEventListener('pointerup', stopImageDrag);
        imageViewport.addEventListener('pointercancel', stopImageDrag);
        imageViewport.addEventListener('dblclick', resetImageTransform);
    }
    document.querySelectorAll('[data-photo-nav]').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            navigatePhoto(button.dataset.photoNav);
        });
    });
    const closePhotoViewer = document.getElementById('closePhotoViewer');
    // Photo click only selects/previews the image. The button opens fullscreen.
    detailImage.style.cursor = 'default';
    detailImage.title = 'Select a hallway area below, then use the fullscreen button';
    function openFullscreenPhoto(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const currentSrc = detailImage.currentSrc || detailImage.getAttribute('src');
        if (!currentSrc || detailImage.hidden) return;
        imageViewer.hidden = false;
        imageViewer.classList.add('fullscreen');
        resetImageTransform();
        document.body.style.overflow = 'hidden';
    }
    // Only the explicit 'View Image in Full Screen' button opens fullscreen.
    if (detailImageLink) detailImageLink.addEventListener('click', openFullscreenPhoto);
    function closeImageViewer() {
        imageViewer.classList.remove('fullscreen');
        document.body.style.overflow = '';
        const currentItem = selectedButton ? (items[Number(selectedButton.dataset.index)] || {}) : {};
        if (isHallwayItem(currentItem)) imageViewer.hidden = true;
        resetImageTransform();
    }
    if (closePhotoViewer) closePhotoViewer.addEventListener('click', closeImageViewer);
    imageViewer.addEventListener('click', function(event) {
        if (imageViewer.classList.contains('fullscreen') && event.target === imageViewer) closeImageViewer();
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            if (imageViewer.classList.contains('fullscreen')) {
                imageViewer.classList.remove('fullscreen');
                document.body.style.overflow = '';
                resetImageTransform();
            } else resetView();
        }
    });
    window.addEventListener('resize', function() {
        if (selectedButton) zoomToItem(selectedButton);
    });
}
</script>
<script src="floor-layout.js?v=20261026" defer></script>
</body>
</html>
