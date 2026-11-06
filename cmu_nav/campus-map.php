<?php
require_once __DIR__ . "/config/database.php";

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$sql = "
    SELECT b.building_id, b.building_name, b.total_floors, b.image, b.status,
           l.shape_type, l.position_x, l.position_y, l.width, l.height, l.rotation,
           (SELECT COUNT(*) FROM rooms r WHERE r.building_id=b.building_id
             AND (r.room_type IS NULL OR TRIM(r.room_type)='' OR LOWER(TRIM(r.room_type)) IN ('room','rooms'))) AS room_count,
           (SELECT COUNT(*) FROM offices o WHERE o.building_id=b.building_id) AS office_count,
           (SELECT COUNT(*) FROM facilities f WHERE f.building_id=b.building_id) AS facility_count
    FROM buildings b
    LEFT JOIN building_layouts l ON l.building_id=b.building_id
    ORDER BY b.building_id ASC
";

try {
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log("Campus map query error: " . $ex->getMessage());
    die("Hindi ma-load ang campus map. Pakitingnan ang database tables at column names.");
}

$buildings = [];
foreach ($rows as $row) {
    $shape = $row["shape_type"] ?? "rectangle";
    if (!in_array($shape, ["rectangle", "square", "rounded"], true)) $shape = "rectangle";
    $name = trim((string)($row["building_name"] ?? "Unnamed Building"));
    // Grounds are map backgrounds: keep them behind all other elements and non-interactive.
    $isGround = (bool)preg_match('/ground|grounds|campus\s*ground/i', $name);
    $buildings[] = [
        "id" => (int)$row["building_id"], "name" => $name,
        "floors" => max(1, (int)($row["total_floors"] ?? 1)),
        "image" => $row["image"] ?? "", "status" => $row["status"] ?? "Active",
        "shape" => $shape, "x" => (float)($row["position_x"] ?? 50),
        "y" => (float)($row["position_y"] ?? 50), "w" => (float)($row["width"] ?? 140),
        "h" => (float)($row["height"] ?? 90), "rotation" => (float)($row["rotation"] ?? 0),
        "rooms" => (int)$row["room_count"], "offices" => (int)$row["office_count"],
        "facilities" => (int)$row["facility_count"], "isGround" => $isGround
    ];
}

// Render grounds first, then every other map element above it.
usort($buildings, function ($a, $b) {
    if ($a["isGround"] === $b["isGround"]) return $a["id"] <=> $b["id"];
    return $a["isGround"] ? -1 : 1;
});

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$buildingsJson = json_encode($buildings, $jsonFlags) ?: "[]";

$searchRooms = [];
try {
    $roomResult = $conn->query("SELECT room_id, building_id, room_number, room_name, floor FROM rooms ORDER BY room_name ASC");
    foreach ($roomResult->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $searchRooms[] = ["id" => (int)$row["room_id"], "buildingId" => (int)$row["building_id"], "number" => trim((string)($row["room_number"] ?? "")), "name" => trim((string)($row["room_name"] ?? "")), "type" => "Room", "floor" => trim((string)($row["floor"] ?? ""))];
    }
    $officeResult = $conn->query("SELECT office_id, building_id, office_name FROM offices ORDER BY office_name ASC");
    foreach ($officeResult->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $searchRooms[] = ["id" => (int)$row["office_id"], "buildingId" => (int)$row["building_id"], "number" => "", "name" => trim((string)($row["office_name"] ?? "")), "type" => "Offices", "floor" => ""];
    }
    $facilityResult = $conn->query("SELECT facility_id, building_id, facility_name, category FROM facilities ORDER BY facility_name ASC");
    foreach ($facilityResult->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $searchRooms[] = ["id" => (int)$row["facility_id"], "buildingId" => (int)$row["building_id"], "number" => "", "name" => trim((string)($row["facility_name"] ?? "")), "type" => "Facilities", "floor" => trim((string)($row["category"] ?? ""))];
    }
} catch (PDOException $ex) {
    error_log("Campus map search query error: " . $ex->getMessage());
    $searchRooms = [];
}
$searchRoomsJson = json_encode($searchRooms, $jsonFlags) ?: "[]";

/* ---------- Navigation data readiness (navigation_points / navigation_point_sections) ----------
   Only table existence and a per-building count of navigation_points are read here.
   No column names beyond building_id are assumed. */
$navInfo = ["pointsTable" => false, "sectionsTable" => false, "pointsByBuilding" => new stdClass()];
try {
    $isTrue = function ($v) { return $v === true || $v === "t" || $v === "true" || $v === 1 || $v === "1"; };
    $chk = $conn->query("SELECT to_regclass('public.navigation_points') IS NOT NULL AS p,
                                to_regclass('public.navigation_point_sections') IS NOT NULL AS s")->fetch(PDO::FETCH_ASSOC);
    $navInfo["pointsTable"] = $isTrue($chk["p"] ?? false);
    $navInfo["sectionsTable"] = $isTrue($chk["s"] ?? false);
    if ($navInfo["pointsTable"]) {
        $byBuilding = [];
        $cnt = $conn->query("SELECT building_id, COUNT(*) AS c FROM navigation_points WHERE building_id IS NOT NULL GROUP BY building_id");
        foreach ($cnt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byBuilding[(int)$r["building_id"]] = (int)$r["c"];
        }
        $navInfo["pointsByBuilding"] = (object)$byBuilding;
    }
} catch (Throwable $ex) {
    error_log("Campus map navigation info error: " . $ex->getMessage());
}
$navInfoJson = json_encode($navInfo, $jsonFlags) ?: "{}";

/* ---------- Optional GPS calibration (config/campus_geo.php) ----------
   GPS can only be shown on the map when the real-world latitude/longitude of the map's
   top-left and bottom-right corners are known. Nothing is guessed: without this file the
   GPS button still works but explains that the map is not calibrated. */
$campusGeo = null;
$geoFile = __DIR__ . "/config/campus_geo.php";
if (is_file($geoFile)) {
    $lvl = ob_get_level();
    ob_start();
    try {
        $cfg = include $geoFile;
    } catch (Throwable $ex) {
        $cfg = null;
        error_log("campus_geo.php error: " . $ex->getMessage());
    }
    while (ob_get_level() > $lvl) ob_end_clean();

    $validPoint = function ($p) {
        return is_array($p) && count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1])
            && abs((float)$p[0]) <= 90 && abs((float)$p[1]) <= 180;
    };
    if (is_array($cfg) && $validPoint($cfg["top_left"] ?? null) && $validPoint($cfg["bottom_right"] ?? null)) {
        $tl = [(float)$cfg["top_left"][0], (float)$cfg["top_left"][1]];
        $br = [(float)$cfg["bottom_right"][0], (float)$cfg["bottom_right"][1]];
        // North-up map: top-left must be north-west of bottom-right.
        if ($tl[0] > $br[0] && $tl[1] < $br[1]) {
            $campusGeo = [
                "top_left" => $tl,
                "bottom_right" => $br,
                "max_accuracy_m" => max(5, min(100, (float)($cfg["max_accuracy_m"] ?? 40)))
            ];
        }
    }
}
$campusGeoJson = json_encode($campusGeo, $jsonFlags);
if ($campusGeoJson === false) $campusGeoJson = "null";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><meta name="theme-color" content="#ffffff">
<title>Campus Map | CMU Campus Navigator</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f3f6fb;color:#172b4d}
.navbar{min-height:80px;padding:14px 4%;display:flex;align-items:center;justify-content:space-between;gap:20px;background:#fff;border-bottom:1px solid #dce4ef}
.logo{display:flex;align-items:center;gap:12px}.logo img{width:52px;height:52px;object-fit:contain}.logo h2{margin:0 0 4px;color:#0b3b82;font-size:21px}.logo span{font-size:13px;color:#52627a}
.nav-links{display:flex;gap:10px;flex-wrap:wrap}.nav-links a{padding:11px 15px;border-radius:7px;text-decoration:none;font-size:14px;font-weight:bold}.nav-home{background:#edf3fc;color:#0b3b82}.nav-admin{background:#0b3b82;color:#fff}
main{max-width:1600px;margin:auto;padding:28px}.page-heading{margin-bottom:22px}.eyebrow{color:#805900;font-size:12px;font-weight:800;letter-spacing:1.5px}.page-heading h1{margin:8px 0;color:#0b3b82;font-size:34px}.page-heading p,.panel-description{color:#52627a;line-height:1.6}
.map-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:20px;align-items:start}.panel{min-width:0;padding:20px;margin-bottom:20px;background:#fff;border:1px solid #d8e1ee;border-radius:12px;box-shadow:0 4px 14px #0b3b820a}.panel h2{margin:0 0 10px;color:#0b3b82;font-size:21px}.map-scroll{width:100%;overflow:auto;border:1px solid #d8e1ee;border-radius:9px;background:#f8faff}
#campusCanvas{position:relative;width:1200px;height:650px;overflow:hidden;background:#808080;transform-origin:0 0;transition:transform .45s}
.building{position:absolute;display:flex;align-items:center;justify-content:center;padding:7px;border:2px solid #0b3b82;background:#dce8fa;color:#0b3b82;text-align:center;font-size:13px;font-weight:800;cursor:pointer;user-select:none;transition:box-shadow .15s,filter .15s,opacity .2s}
.building.active{border-color:#15803d;background:#86efac;color:#14532d}.building.inactive{border-color:#b91c1c;background:#fca5a5;color:#7f1d1d}
.building.rectangle,.building.square{border-radius:2px}.building.rounded{border-radius:18px}.building:hover,.building:focus-visible{filter:brightness(.97);box-shadow:0 0 0 4px #ffdf45;outline:none}

/* Reuse the exact special campus-element designs from Add Building. */
/* Campus Ground: gray background layer only. It must never capture clicks or be selected. */
.building.ground-element,
.building.ground-element.status-active,
.building.ground-element.status-inactive {
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
.ground-element .shape-label,
.ground-element .resize-handle { display: none !important; }
.ground-element.selected { outline: none !important; }

/* Basketball court: uses the user's actual 2D HTML/CSS court design. */
.building.court-shape {
    display: block;
    padding: 0;
    background: #808080;
    border: 4px solid #ffffff;
    border-radius: 0;
    overflow: hidden;
    color: #fff;
}
.building.court-shape.status-active,
.building.court-shape.status-inactive { border-color: #ffffff; box-shadow: none; }
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
.building.parking-shape {
    display: block; padding: 0; background: #808080; border: none;
    border-radius: 4px; overflow: hidden; color: #fff;
}
.building.parking-shape.status-active,
.building.parking-shape.status-inactive {
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


.building.court-shape,.building.parking-shape{cursor:default!important}.court-label{position:absolute;left:50%;bottom:3px;z-index:2;width:max-content;max-width:85%;padding:2px 5px;border-radius:3px;background:rgba(25,25,25,.78);color:#fff;font-size:11px;transform:translateX(-50%)}.parking-label{position:absolute;left:50%;bottom:2px;z-index:2;width:max-content;max-width:90%;padding:1px 4px;border-radius:3px;background:rgba(25,25,25,.78);color:#fff;font-size:10px;transform:translateX(-50%)}
.building.ground-element{background:#808080!important;border:0!important;color:transparent!important;pointer-events:none!important;z-index:0!important}
/* Grounds are decorative map backgrounds: no status color, no pointer events, always behind other objects. */
.building.ground-element{background:#808080!important;border:0!important;color:transparent!important;z-index:0!important;pointer-events:none!important;cursor:default;box-shadow:none!important;opacity:1!important}
.building.selected{box-shadow:0 0 0 4px #ffdf45;z-index:50!important}.building-label{max-width:100%;overflow-wrap:anywhere;pointer-events:none}
#campusCanvas.has-selection .building:not(.selected):not(.ground-element){opacity:.45}
.building-list{display:flex;flex-direction:column;gap:8px}.building-list-item{display:block;width:100%;padding:12px;text-align:left;border:1px solid #d8e1ee;border-radius:7px;background:#fff;color:#0b3b82;cursor:pointer;font-size:13px;font-weight:700;overflow-wrap:anywhere}.building-list-item:hover{background:#edf3fc}.building-list-item.selected{border-color:#d9a900;background:#fff8d8}.list-status{display:inline-block;margin-top:5px;font-size:11px;font-weight:700}.list-status.active{color:#198754}.list-status.inactive{color:#dc3545}.list-status.ground{color:#667766}.legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:15px;color:#52627a;font-size:12px}.legend-item{display:inline-flex;align-items:center;gap:6px}.legend-color{width:15px;height:15px;border:2px solid;border-radius:3px}.legend-active{background:#86efac;border-color:#15803d}.legend-inactive{background:#fca5a5;border-color:#b91c1c}.legend-ground{background:#e8efe6;border-color:#b8c8b8}
/* Search bar restored; colors and special-element designs match Add Building. */
.map-search{display:flex;gap:10px;align-items:center;margin:0 0 14px}
.map-search input{flex:1;min-width:0;padding:12px 14px;border:1px solid #cbd6e5;border-radius:8px;background:#fff;color:#172b4d;font:inherit;outline:none}
.map-search input:focus{border-color:#0b3b82;box-shadow:0 0 0 3px #0b3b821a}
.map-search button{padding:12px 16px;border:0;border-radius:8px;background:#0b3b82;color:#fff;font-weight:700;cursor:pointer}
.search-count{margin:0 0 12px;color:#52627a;font-size:12px}
.building.search-dim{opacity:.18!important;filter:grayscale(.35)}
.building.search-match{outline:3px solid #ffdf45;outline-offset:2px}
.info-panel[hidden]{display:none}.detail-name{color:#0b3b82;overflow-wrap:anywhere}.status{display:inline-block;padding:5px 9px;margin-bottom:10px;border-radius:20px;font-size:12px;font-weight:700}.status.active{background:#86efac;color:#14532d}.status.inactive{background:#fca5a5;color:#7f1d1d}.stats-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}.stat-card{padding:12px;border:1px solid #d8e1ee;border-radius:8px;background:#f8faff}.stat-card strong{display:block;color:#0b3b82;font-size:23px}.stat-card span{display:block;margin-top:4px;color:#52627a;font-size:12px}.back-btn{width:100%;margin-top:14px;padding:12px;border:0;border-radius:7px;background:#0b3b82;color:#fff;font-weight:bold;cursor:pointer}.empty-message{padding:20px;color:#52627a;text-align:center;line-height:1.6}footer{padding:22px;text-align:center;background:#fff;border-top:1px solid #dce4ef;color:#52627a;font-size:13px}
@media(max-width:1000px){main{padding:20px}.map-layout{grid-template-columns:1fr}}@media(max-width:650px){.navbar{align-items:flex-start;flex-direction:column;padding:16px}main{padding:16px 12px}.page-heading h1{font-size:28px}.panel{padding:16px}}
/* Original campus search bar */
.search-panel{position:relative;margin-bottom:18px}.search-box{display:flex;align-items:center;gap:10px;padding:0 14px;border:1px solid #cbd6e5;border-radius:9px;background:#fff}.search-box:focus-within{border-color:#0b3b82;box-shadow:0 0 0 3px #d6e5fb}.search-box .search-icon{font-size:18px}.search-box input{flex:1;min-width:0;padding:14px 0;border:0;outline:none;background:transparent;color:#172b4d;font:inherit;font-size:15px}.search-clear{padding:6px 10px;border:0;border-radius:6px;background:#edf3fc;color:#0b3b82;font-size:12px;font-weight:700;cursor:pointer}.search-results{display:none;max-height:360px;margin-top:10px;overflow-y:auto;border:1px solid #d8e1ee;border-radius:9px;background:#fff;box-shadow:0 8px 22px #0b3b8214}.search-results.open{display:block}.search-group-title{padding:9px 14px;background:#edf3fc;color:#0b3b82;font-size:12px;font-weight:800;letter-spacing:1px}.result-row{display:flex;align-items:center;gap:10px;border-bottom:1px solid #e5eaf2}.result-row:last-child{border-bottom:0}.result-main{flex:1;min-width:0;padding:12px 14px;border:0;background:transparent;text-align:left;cursor:pointer;font-family:inherit}.result-main:hover,.result-main:focus-visible{background:#f8faff;outline:none}.result-title{display:block;color:#0b3b82;font-size:14px;font-weight:700;overflow-wrap:anywhere}.result-meta{display:block;margin-top:3px;color:#52627a;font-size:12px}.result-link{flex-shrink:0;margin-right:12px;padding:7px 10px;border-radius:6px;background:#0b3b82;color:#fff;text-decoration:none;font-size:12px;font-weight:700;white-space:nowrap}.no-results{padding:18px 14px;color:#52627a;font-size:14px;text-align:center}

/* ===== Fit campus layout to the screen (phone friendly) ===== */
.map-scroll{touch-action:manipulation}
.building:not(.court-shape):not(.parking-shape) .building-label{font-size:calc(13px * var(--lbl, 1));line-height:1.1}
@media(max-width:650px){
  .navbar{min-height:0}
  .nav-links{width:100%}
  .nav-links a{flex:1;text-align:center;padding:10px 8px}
  .page-heading p{font-size:13px}
  .panel{padding:12px}
  .search-results{max-height:260px}
  .legend{gap:10px}
}

/* ===== App look: same navigation design as index.php ===== */
:root{--navy:#0b3b82;--navy-dark:#072a5c;--yellow:#ffd23f;--ice:#edf3fc;--muted:#5f6f89;--line:#e3eaf5;--nav-h:66px}
.map-wrap{position:relative}
.map-scroll{touch-action:none;overflow:hidden;cursor:grab}
.map-scroll.dragging{cursor:grabbing}
#campusCanvas.dragging{transition:none}
.zoom-ctrl{position:absolute;right:10px;bottom:10px;z-index:80;display:flex;flex-direction:column;gap:6px}
.zoom-ctrl button{width:40px;height:40px;border:0;border-radius:10px;background:#fff;color:var(--navy);font-size:20px;font-weight:800;line-height:1;box-shadow:0 3px 10px rgba(11,59,130,.25);cursor:pointer}
.zoom-ctrl button.fit{font-size:11px}
.bottom-nav{display:none}
@media(max-width:768px){
  body{padding-bottom:calc(var(--nav-h) + env(safe-area-inset-bottom,0px) + 12px)}
  .navbar{position:sticky;top:0;z-index:50;min-height:0;flex-direction:row;align-items:center;padding:calc(10px + env(safe-area-inset-top,0px)) 18px 10px}
  .nav-links{display:none}
  .logo{gap:12px;min-width:0}
  .logo img{width:46px;height:46px;flex:0 0 46px}
  .logo h2{margin:0;font-size:19px;line-height:1.15;font-weight:800}
  .logo span{display:block;margin-top:3px;font-size:11.5px;color:var(--muted)}
  main{padding:14px 10px}
  .map-layout .panel{padding:10px}
  .bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:60;display:grid;grid-template-columns:repeat(4,1fr);height:calc(var(--nav-h) + env(safe-area-inset-bottom,0px));padding-bottom:env(safe-area-inset-bottom,0px);background:#fff;border-top:1px solid var(--line);box-shadow:0 -6px 18px rgba(11,59,130,.07)}
  .bottom-nav a{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;font-size:11px;font-weight:700;color:#4a5b78;text-decoration:none;-webkit-tap-highlight-color:transparent}
  .bottom-nav svg{width:24px;height:24px}
  .bottom-nav a.active{color:var(--navy)}
  .bottom-nav a.active::after{content:"";position:absolute;bottom:7px;width:22px;height:3px;border-radius:3px;background:var(--yellow)}
}

/* ===== Nicer heading ===== */
.page-heading{position:relative;overflow:hidden;padding:22px 20px;margin-bottom:18px;border-radius:22px;color:#fff;background:linear-gradient(135deg,#0b3b82 0%,#072a5c 100%);box-shadow:0 12px 26px rgba(11,59,130,.25)}
.page-heading::after{content:"";position:absolute;right:-50px;top:-60px;width:190px;height:190px;border-radius:50%;background:radial-gradient(circle,rgba(255,210,63,.45) 0%,rgba(255,210,63,0) 70%);pointer-events:none}
.page-heading .eyebrow{display:inline-block;padding:6px 13px;border-radius:999px;background:var(--yellow);color:var(--navy-dark);font-size:11px;font-weight:800;letter-spacing:.6px}
.page-heading h1{position:relative;margin:14px 0 8px;color:#fff;font-size:32px;font-weight:900;letter-spacing:-.4px}
.page-heading p{position:relative;margin:0;max-width:60ch;color:#dbe6f8;font-size:14px;line-height:1.55}
@media(max-width:650px){.page-heading{padding:20px 18px;border-radius:20px}.page-heading h1{font-size:28px}}

/* ===== Building information bottom sheet (app style) ===== */
.map-layout{display:block}
.info-panel[hidden]{display:none!important}
.sheet-handle{width:42px;height:4px;margin:2px auto 12px;border-radius:4px;background:#d3dcea}
.info-close{position:absolute;right:14px;top:16px;width:34px;height:34px;border:0;border-radius:50%;background:var(--ice);color:var(--navy);font-size:20px;line-height:1;font-weight:800;cursor:pointer}
.sheet-label{display:block;font-size:11px;font-weight:800;letter-spacing:.6px;color:#805900}
.sheet-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:6px 40px 14px 0}
.sheet-head h2{margin:0;font-size:21px;line-height:1.15;color:var(--navy);overflow-wrap:anywhere}
.sheet-head .status{margin:0;flex:0 0 auto}
.info-panel .stats-grid{grid-template-columns:repeat(4,1fr);gap:8px;margin-top:0}
.info-panel .stat-card{padding:10px 4px;text-align:center;border-radius:12px;font:inherit}
.info-panel .stat-card strong{font-size:20px}
.info-panel .stat-card span{font-size:11px}
button.stat-card.tappable{cursor:pointer;border:1px solid #d8e1ee;background:#f8faff}
.stat-card.on{border-color:#d9a900!important;background:#fff8d8!important}
.sheet-list{margin-top:10px;max-height:190px;overflow-y:auto;border:1px solid #d8e1ee;border-radius:12px}
.sheet-list[hidden]{display:none}
.sheet-item{display:block;width:100%;padding:10px 12px;border:0;border-bottom:1px solid #e5eaf2;background:#fff;text-align:left;font:inherit;cursor:pointer}
.sheet-item:last-child{border-bottom:0}
.sheet-item b{display:block;font-size:14px;color:var(--navy);overflow-wrap:anywhere}
.sheet-item small{display:block;margin-top:2px;font-size:12px;color:#52627a}
.sheet-empty{padding:14px;text-align:center;font-size:13px;color:#52627a}
.sheet-actions{display:flex;gap:10px;margin-top:14px}
.sheet-actions button{flex:1;padding:13px;border:0;border-radius:12px;font-weight:800;font-size:14px;cursor:pointer}
.sheet-actions .primary{background:var(--navy);color:#fff}
.sheet-actions .secondary{background:var(--ice);color:var(--navy)}

/* Larger, centered building information pop-up */
.sheet-handle{display:none}
.info-close{width:38px;height:38px;top:14px;right:14px;font-size:22px}
.sheet-label{font-size:12px}
.sheet-head{margin:8px 46px 18px 0}
.sheet-head h2{font-size:26px}
.info-panel .stats-grid{gap:10px}
.info-panel .stat-card{padding:16px 4px}
.info-panel .stat-card strong{font-size:28px}
.info-panel .stat-card span{font-size:13px;margin-top:6px}
.sheet-list{max-height:220px}
.sheet-item b{font-size:15px}
.sheet-actions{margin-top:18px}
.sheet-actions button{padding:16px;font-size:15px}

/* ===== Building information pop-up: centered, always fits the screen ===== */
.info-backdrop{position:fixed;top:0;right:0;bottom:0;left:0;z-index:90;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(7,26,62,.5)}
.info-backdrop[hidden]{display:none}
.info-panel{position:relative!important;left:auto!important;top:auto!important;transform:none!important;display:flex;flex-direction:column;width:100%;max-width:440px;max-height:100%;margin:0!important;padding:0!important;overflow:hidden;border-radius:24px!important;background:#fff;border:0!important;box-shadow:0 24px 60px rgba(7,26,62,.4)!important;animation:popUp .2s ease}
.info-panel[hidden]{display:none!important}
@keyframes popUp{from{opacity:0}to{opacity:1}}
.info-panel .info-close{position:absolute;top:14px;right:14px;z-index:2;width:36px;height:36px;font-size:22px}
.sheet-body{flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:20px 18px 6px}
.sheet-label{display:block;font-size:12px}
.sheet-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin:8px 44px 16px 0}
.sheet-head h2{flex:1 1 auto;min-width:0;margin:0;font-size:22px;line-height:1.2}
.sheet-head .status{margin:0}
.info-panel .stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:0}
.info-panel .stat-card{padding:12px 2px;text-align:center}
.info-panel .stat-card strong{font-size:24px}
.info-panel .stat-card span{margin-top:4px;font-size:12px}
.sheet-list{margin-top:12px;max-height:180px}
.sheet-actions{flex:0 0 auto;display:flex;gap:10px;margin:0;padding:12px 18px calc(18px + env(safe-area-inset-bottom,0px));background:#fff;border-top:1px solid #eef2f8}
.sheet-actions button{flex:1;min-width:0;padding:14px 8px;font-size:14.5px;border-radius:12px}
@media(max-width:340px){.info-panel .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}

/* ===== Pin location & navigation ===== */
:root{--pin-start:#1d4ed8;--pin-dest:#c2410c;--route:#7c3aed}

/* Navigation panel (above the map) */
.nav-panel{margin:0 0 16px;padding:14px;border:1px solid #d8e1ee;border-radius:12px;background:#f8faff}
.nav-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.nav-head h3{margin:0;color:var(--navy);font-size:17px}
.nav-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.nav-field{min-width:0}
.nav-field label{display:flex;align-items:center;gap:7px;margin-bottom:6px;color:#43536b;font-size:13px;font-weight:700}
.nav-field select{width:100%;min-height:46px;padding:10px;border:1px solid #cbd6e5;border-radius:8px;background:#fff;color:#172b4d;font:inherit;font-size:15px}
.nav-field select:focus{outline:2px solid #d6e5fb;border-color:var(--navy)}
.nav-btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.nav-btn{flex:1 1 auto;display:inline-block;min-height:42px;padding:10px 12px;border:0;border-radius:8px;background:var(--navy);color:#fff;text-align:center;text-decoration:none;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.nav-btn.light{background:var(--ice);color:var(--navy)}
.nav-btn.on{background:#d9a900;color:#3b2c00}
.nav-btn:disabled{opacity:.6;cursor:wait}
.nav-hint{margin:8px 0 0;color:#68758a;font-size:12px;line-height:1.5}
.nav-msg{margin:10px 0 0;color:#52627a;font-size:13px;line-height:1.5}
.nav-msg:empty{display:none}
.nav-msg.warn{color:#8a5a00}
.dot{display:inline-block;flex:0 0 auto;width:13px;height:13px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px #94a3b8}
.dot.start{background:var(--pin-start)}
.dot.dest{background:var(--pin-dest)}
.nav-card{margin-top:12px;padding:12px;border:1px solid #d8e1ee;border-radius:10px;background:#fff}
.nav-row{display:flex;align-items:flex-start;gap:10px;padding:6px 0}
.nav-row+.nav-row{border-top:1px solid #eef2f8}
.nav-row .dot{margin-top:4px}
.nav-row-main{flex:1;min-width:0}
.nav-row-main b{display:block;color:var(--navy);font-size:14px;overflow-wrap:anywhere}
.nav-row-main small{display:block;margin-top:2px;color:#52627a;font-size:12px;line-height:1.4}
.nav-row-main .muted{color:#7a879c}
.nav-badge{flex:0 0 auto;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}
.nav-badge.gps{background:#dbeafe;color:#1e3a8a}
.nav-badge.manual{background:#eef2f8;color:#43536b}
.nav-badge.low{background:#fff3cd;color:#8a5a00}
.nav-note{margin:8px 0 0;color:#52627a;font-size:12.5px;line-height:1.55}
.nav-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}

.pick-banner{position:absolute;left:50%;top:10px;z-index:85;display:flex;align-items:center;gap:10px;max-width:calc(100% - 20px);padding:7px 8px 7px 14px;border-radius:999px;background:var(--pin-start);color:#fff;font-size:13px;font-weight:700;box-shadow:0 6px 16px rgba(7,26,62,.35);transform:translateX(-50%)}
.pick-banner[hidden]{display:none}
.pick-banner button{padding:6px 12px;border:0;border-radius:999px;background:#fff;color:var(--pin-start);font:inherit;font-size:12px;font-weight:800;cursor:pointer}
.map-scroll.picking,.map-scroll.picking.dragging{cursor:crosshair}

/* Pins live inside the canvas, so they pan and zoom with the map. --pin keeps them a steady on-screen size. */
.route-layer{position:absolute;left:0;top:0;z-index:65;pointer-events:none;overflow:visible}
#pinLayer{position:absolute;left:0;top:0;width:100%;height:100%;z-index:70;pointer-events:none}
.acc-ring{position:absolute;border-radius:50%;background:rgba(29,78,216,.14);border:2px solid rgba(29,78,216,.55);transform:translate(-50%,-50%);pointer-events:none}
.pin{position:absolute;width:30px;height:40px;pointer-events:auto;cursor:pointer;outline:none;transform-origin:50% 100%;transform:translate(-50%,-100%) scale(var(--pin,1));filter:drop-shadow(0 3px 3px rgba(0,0,0,.45))}
.pin.start{z-index:2}.pin.dest{z-index:3}
.pin.open{z-index:6}
.pin.near.start{margin-left:calc(-17px * var(--pin,1))}
.pin.near.dest{margin-left:calc(17px * var(--pin,1))}
.pin-art{display:block;width:100%;height:100%}
.pin-art svg{display:block;width:100%;height:100%}
.pin:focus-visible .pin-art{outline:3px solid #ffdf45;outline-offset:2px;border-radius:8px}
.pin.gps::after{content:"";position:absolute;left:50%;bottom:-6px;width:18px;height:18px;margin-left:-9px;border:3px solid var(--pin-start);border-radius:50%;animation:pinPulse 1.8s ease-out infinite;pointer-events:none}
@keyframes pinPulse{from{transform:scale(.3);opacity:.95}to{transform:scale(1.9);opacity:0}}
@media(prefers-reduced-motion:reduce){.pin.gps::after{animation:none;opacity:.5}}
.pin-chip{position:absolute;left:100%;top:4px;margin-left:3px;padding:2px 7px;border:1px solid #fff;border-radius:999px;color:#fff;font:700 11px/1.3 Arial,Helvetica,sans-serif;white-space:nowrap;pointer-events:none}
.pin.start .pin-chip{background:var(--pin-start)}
.pin.dest .pin-chip{background:var(--pin-dest)}
.pin-tip{display:none;position:absolute;bottom:calc(100% + 8px);left:50%;z-index:5;width:max-content;max-width:230px;padding:9px 11px;border-radius:10px;background:#fff;color:#172b4d;text-align:left;font:13px/1.4 Arial,Helvetica,sans-serif;box-shadow:0 6px 18px rgba(7,26,62,.35);transform:translateX(-50%);cursor:default}
.pin.open .pin-tip{display:block}
.pin-tip.below{bottom:auto;top:calc(100% + 8px)}
.pin-tip.al-left{left:0;transform:none}
.pin-tip.al-right{left:auto;right:0;transform:none}
.pin-tip b{display:block;color:var(--navy);overflow-wrap:anywhere}
.pin-tip small{display:block;margin-top:2px;color:#52627a;font-size:12px}
.pin-tip .tip-warn{margin-top:5px;color:#8a5a00;font-size:12px}
.pin-tip a{display:inline-block;margin-top:7px;padding:6px 10px;border-radius:6px;background:var(--navy);color:#fff;text-decoration:none;font-size:12px;font-weight:700}

.legend-pin{width:13px;height:13px;border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 1px #94a3b8}
.legend-pin.start{background:var(--pin-start)}
.legend-pin.dest{background:var(--pin-dest)}
.legend-line{width:20px;height:0;border-top:3px dashed var(--route)}

/* "Go here" / "Start here" buttons in search results and the building pop-up */
.result-link.go{margin-left:-12px;border:0;background:var(--pin-dest);font-family:inherit;cursor:pointer}
.sheet-nav{display:flex;gap:8px;margin-top:12px}
.sheet-nav button{flex:1;min-width:0;padding:12px 6px;border:0;border-radius:10px;color:#fff;font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.sheet-nav .to{background:var(--pin-dest)}
.sheet-nav .from{background:var(--pin-start)}
.sheet-row{display:flex;align-items:stretch;border-bottom:1px solid #e5eaf2}
.sheet-row:last-child{border-bottom:0}
.sheet-row .sheet-item{flex:1;min-width:0;border-bottom:0}
.sheet-go{flex:0 0 auto;margin:6px 8px 6px 0;padding:0 10px;border:0;border-radius:8px;background:var(--pin-dest);color:#fff;font:inherit;font-size:12px;font-weight:700;cursor:pointer}

@media(max-width:650px){
  .nav-grid{grid-template-columns:1fr}
  .nav-panel{padding:12px}
  .nav-btn{min-height:46px}
  .result-link.go{margin-left:0}
}

</style>
<link rel="stylesheet" href="mobile.css">
</head>
<body>
<header class="navbar"><div class="logo"><img src="cmu%20logo.jpg" alt="CMU Logo"><div><h2>CMU Campus Navigator</h2><span>City of Malabon University</span></div></div><nav class="nav-links"><a href="index.php#home" class="nav-home">Home</a><a href="campus-map.php" class="nav-home">Campus Map</a><a href="admin/University-info.php" class="nav-home">University Info</a><a href="update_request.php" class="nav-home">Feedback</a><a href="admin/login.php" class="nav-admin">Admin Login</a></nav></header>
<main><section class="page-heading"><span class="eyebrow">EXPLORE OUR CAMPUS</span><h1>CMU Campus Map</h1><p>Tap a building to see its details, then open its floor layout. Pinch or use + / − to zoom.</p></section>
<div class="map-layout"><section class="panel"><h2>Campus Layout</h2><p class="panel-description">Building positions and dimensions come from the saved Campus Layout Editor.</p><section class="search-panel"><div class="search-box"><span class="search-icon" aria-hidden="true">🔎</span><input type="search" id="campusSearch" placeholder="Search a building, room, office, or facility (e.g. Library, 101, Registrar)" autocomplete="off" aria-label="Search buildings, rooms, offices, and facilities"><button type="button" class="search-clear" id="searchClear" hidden>Clear</button></div><div class="search-results" id="searchResults" role="listbox"></div></section>

<section class="nav-panel" id="navPanel" aria-label="Pin your location and choose a destination">
  <div class="nav-head"><h3>Find your way</h3><button type="button" class="search-clear" id="navClear" hidden>Clear pins</button></div>
  <div class="nav-grid">
    <div class="nav-field">
      <label for="startSelect"><span class="dot start" aria-hidden="true"></span>Where are you? (starting point)</label>
      <select id="startSelect" aria-label="Starting point"></select>
      <div class="nav-btns">
        <button type="button" class="nav-btn" id="gpsBtn">📍 Use my location</button>
        <button type="button" class="nav-btn light" id="pickBtn">👆 Tap on map</button>
      </div>
    </div>
    <div class="nav-field">
      <label for="destSelect"><span class="dot dest" aria-hidden="true"></span>Where to? (destination)</label>
      <select id="destSelect" aria-label="Destination"></select>
      <p class="nav-hint">Pick a building, room, office, or facility. You can also use “Go here” in the search results or in a building’s pop-up.</p>
    </div>
  </div>
  <p class="nav-msg" id="navMsg" role="status" aria-live="polite"></p>
  <div id="navSummary" hidden></div>
</section>

<div class="map-wrap"><div class="map-scroll" id="mapScroll"><div id="campusCanvas"></div></div>
<div class="pick-banner" id="pickBanner" hidden><span>Tap the map where you are</span><button type="button" id="pickCancel">Cancel</button></div>
<div class="zoom-ctrl"><button type="button" id="zoomIn" aria-label="Zoom in">+</button><button type="button" id="zoomOut" aria-label="Zoom out">−</button><button type="button" class="fit" id="zoomFit" aria-label="Fit map to screen">Fit</button></div></div><div class="legend"><span class="legend-item"><span class="legend-color legend-active"></span>Active</span><span class="legend-item"><span class="legend-color legend-inactive"></span>Inactive</span><span class="legend-item"><span class="legend-pin start"></span>Your location</span><span class="legend-item"><span class="legend-pin dest"></span>Destination</span><span class="legend-item"><span class="legend-line"></span>Route preview</span></div></section>
<aside><section class="panel info-panel" id="infoPanel" hidden></section><div id="buildingList" hidden></div></aside></div></main>
<footer>© <?= date("Y") ?> CMU Campus Navigator | City of Malabon University</footer>
<nav class="bottom-nav" aria-label="Main">
<a href="index.php#home"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11l8-7 8 7"/><path d="M6 10v10h12V10"/></svg>Home</a>
<a href="campus-map.php" class="active" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><path d="M3 6.5l6-2.5 6 2.5 6-2.5v13.5l-6 2.5-6-2.5-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/></svg>Map</a>
<a href="admin/University-info.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/></svg>Info</a>
<a href="update_request.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/></svg>Feedback</a>
</nav>
<script>
const buildings = <?= $buildingsJson ?>;
const searchRooms = <?= $searchRoomsJson ?>;
const geoCfg = <?= $campusGeoJson ?>;
const navInfo = <?= $navInfoJson ?>;
const canvas=document.getElementById('campusCanvas'), mapScroll=document.getElementById('mapScroll'), buildingList=document.getElementById('buildingList'), infoPanel=document.getElementById('infoPanel');
let selectedId=null, selectedAt=0;
function el(tag,cls,text){const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n}
function isGround(b){return Boolean(b.isGround)||/ground/i.test(b.name||'')}
function isCourt(b){return /court/i.test(b.name||'')}
function isParking(b){return /parking/i.test(b.name||'')}
function isCampusElement(b){return isGround(b)||isCourt(b)||isParking(b)||/(spiked border|spiked wall|campus border|boundary wall)/i.test(b.name||'')}
function statusClass(status){const s=String(status??'').trim().toLowerCase();if(['inactive','0','disabled','deactivated','not active'].includes(s))return 'inactive';return 'active'}
function statusLabel(status){return statusClass(status)==='inactive'?'Inactive':'Active'}
function byId(id){return buildings.find(b=>b.id===Number(id))}
function floorUrl(b){return 'admin/floor-layout.php?building_id='+encodeURIComponent(b.id)+'&floor=1'}

/* ===== Pan / zoom view: bigger map on phones ===== */
let CANVAS_W=1200, CANVAS_H=650, OX=0, OY=0; const MAX_Z=4; let view={s:1,x:0,y:0}, minZ=.3, initZ=1;
/* Size the map canvas to the real campus layout (ground + buildings): no empty margin, nothing cropped */
function computeBounds(){
  let x1=Infinity,y1=Infinity,x2=-Infinity,y2=-Infinity;
  buildings.forEach(b=>{const t=(b.rotation||0)*Math.PI/180,c=Math.abs(Math.cos(t)),n=Math.abs(Math.sin(t)),bw=b.w*c+b.h*n,bh=b.w*n+b.h*c,cx=b.x+b.w/2,cy=b.y+b.h/2;
    x1=Math.min(x1,cx-bw/2);y1=Math.min(y1,cy-bh/2);x2=Math.max(x2,cx+bw/2);y2=Math.max(y2,cy+bh/2)});
  if(isFinite(x1)&&x2>x1&&y2>y1){OX=Math.floor(x1);OY=Math.floor(y1);CANVAS_W=Math.ceil(x2-OX);CANVAS_H=Math.ceil(y2-OY)}
  canvas.style.width=CANVAS_W+'px';canvas.style.height=CANVAS_H+'px';
}
function boxSize(){const w=mapScroll.parentElement.clientWidth;const h=w>=1200?CANVAS_H:Math.round(Math.max(280,Math.min(520,w*CANVAS_H/CANVAS_W+90)));return[w,h]}
function setView(s,x,y){
  const w=mapScroll.clientWidth,h=mapScroll.clientHeight;
  s=Math.max(minZ,Math.min(MAX_Z,s));
  const cw=CANVAS_W*s,ch=CANVAS_H*s;
  x=cw<=w?(w-cw)/2:Math.min(0,Math.max(w-cw,x));
  y=ch<=h?(h-ch)/2:Math.min(0,Math.max(h-ch,y));
  view={s,x,y};
  canvas.style.transform='translate('+x+'px,'+y+'px) scale('+s+')';
  canvas.style.setProperty('--lbl',Math.max(1,Math.min(3.2,.9/s)).toFixed(2));
  /* pins and the route preview keep a steady on-screen size while the map zooms */
  const pv=Math.max(.25,Math.min(4,1/s));
  canvas.style.setProperty('--pin',pv.toFixed(3));
  styleRoute(pv);updateNear();refreshTips();
}
const backdrop=el('div','info-backdrop');backdrop.hidden=true;backdrop.appendChild(infoPanel);document.body.appendChild(backdrop);
function hideInfo(){infoPanel.hidden=true;backdrop.hidden=true}
backdrop.addEventListener('click',e=>{if(e.target===backdrop)hideInfo()});
function zoomAt(s,mx,my){const k=Math.max(minZ,Math.min(MAX_Z,s));const cx=(mx-view.x)/view.s,cy=(my-view.y)/view.s;setView(k,mx-cx*k,my-cy*k)}
function resetCanvasView(){const w=mapScroll.clientWidth;setView(initZ,(w-CANVAS_W*initZ)/2,0)}
function applyFit(){
  const w=mapScroll.parentElement.clientWidth,maxH=Math.max(260,window.innerHeight*.7);
  initZ=Math.min(w/CANVAS_W,maxH/CANVAS_H);
  minZ=initZ;
  mapScroll.style.height=Math.round(CANVAS_H*initZ)+2+'px';
  const b=selectedId!==null?byId(selectedId):null;
  if(b)select(b,true);else resetCanvasView();
}
function resetView(){selectedId=null;resetCanvasView();canvas.classList.remove('has-selection');canvas.querySelectorAll('.selected').forEach(n=>n.classList.remove('selected'));buildingList.querySelectorAll('.selected').forEach(n=>n.classList.remove('selected'));infoPanel.hidden=true;backdrop.hidden=true}
/* drag to pan, pinch to zoom */
const pts=new Map();let drag=null,moved=false,pinch=null;
mapScroll.addEventListener('pointerdown',e=>{pts.set(e.pointerId,{x:e.clientX,y:e.clientY});moved=false;if(pts.size===1)drag={x:e.clientX,y:e.clientY,vx:view.x,vy:view.y};if(pts.size===2){const[a,b]=[...pts.values()];pinch={d:Math.hypot(a.x-b.x,a.y-b.y),s:view.s};drag=null}});
mapScroll.addEventListener('pointermove',e=>{
  if(!pts.has(e.pointerId))return;pts.set(e.pointerId,{x:e.clientX,y:e.clientY});
  if(pts.size===2&&pinch){const[a,b]=[...pts.values()];const d=Math.hypot(a.x-b.x,a.y-b.y),r=mapScroll.getBoundingClientRect();moved=true;canvas.classList.add('dragging');zoomAt(pinch.s*d/pinch.d,(a.x+b.x)/2-r.left,(a.y+b.y)/2-r.top);return}
  if(drag&&pts.size===1){const dx=e.clientX-drag.x,dy=e.clientY-drag.y;if(!moved&&Math.hypot(dx,dy)<6)return;if(!moved){moved=true;canvas.classList.add('dragging');mapScroll.classList.add('dragging');try{mapScroll.setPointerCapture(e.pointerId)}catch(_){}}setView(view.s,drag.vx+dx,drag.vy+dy)}
});
function endPtr(e){pts.delete(e.pointerId);if(pts.size<2)pinch=null;if(pts.size===0){drag=null;canvas.classList.remove('dragging');mapScroll.classList.remove('dragging')}else if(pts.size===1){const p=[...pts.values()][0];drag={x:p.x,y:p.y,vx:view.x,vy:view.y}}}
['pointerup','pointercancel'].forEach(t=>mapScroll.addEventListener(t,endPtr));
mapScroll.addEventListener('wheel',e=>{e.preventDefault();const r=mapScroll.getBoundingClientRect();zoomAt(view.s*(e.deltaY<0?1.15:1/1.15),e.clientX-r.left,e.clientY-r.top)},{passive:false});
/* + / - / Fit buttons: zoom around the center of the map */
function zoomBtn(f){zoomAt(view.s*f,mapScroll.clientWidth/2,mapScroll.clientHeight/2)}
document.getElementById('zoomIn').addEventListener('click',()=>zoomBtn(1.35));
document.getElementById('zoomOut').addEventListener('click',()=>zoomBtn(1/1.35));
document.getElementById('zoomFit').addEventListener('click',resetView);
/* A drag must never count as a click (stopImmediatePropagation also keeps the "tap to set my location" handler from firing) */
mapScroll.addEventListener('click',e=>{if(moved){e.stopImmediatePropagation();e.preventDefault();moved=false}},true);

function buildingBox(b){const t=(b.rotation||0)*Math.PI/180,c=Math.abs(Math.cos(t)),n=Math.abs(Math.sin(t));return{w:b.w*c+b.h*n,h:b.w*n+b.h*c}}
function fillList(list,b,type,label){
 list.replaceChildren();
 const items=searchRooms.filter(r=>r.buildingId===b.id&&r.type===type);
 if(!items.length)list.appendChild(el('div','sheet-empty','No '+label.toLowerCase()+' listed for this building.'));
 items.forEach(r=>{const row=el('div','sheet-row');const it=el('button','sheet-item');it.type='button';it.appendChild(el('b','',r.number!==''?r.number+' \u2014 '+r.name:r.name));if(r.floor!=='')it.appendChild(el('small','',r.floor));
  it.onclick=()=>{location.href='admin/floor-layout.php?building_id='+encodeURIComponent(b.id)+'&floor='+encodeURIComponent(floorNumber(r.floor))};
  const go=el('button','sheet-go','Go here');go.type='button';go.title='Set as destination';go.onclick=ev=>{ev.stopPropagation();goToPlace(placeKeyForRoom(r))};
  row.appendChild(it);row.appendChild(go);list.appendChild(row)});
 list.hidden=false;
}
function buildInfo(b){
 infoPanel.replaceChildren();
 const xb=el('button','info-close','\u00d7');xb.type='button';xb.setAttribute('aria-label','Close');xb.onclick=hideInfo;infoPanel.appendChild(xb);
 const body=el('div','sheet-body');
 body.appendChild(el('span','sheet-label','BUILDING INFORMATION'));
 const head=el('div','sheet-head');head.appendChild(el('h2','detail-name',b.name));head.appendChild(el('span','status '+statusClass(b.status),statusLabel(b.status)));body.appendChild(head);
 const stats=el('div','stats-grid'),list=el('div','sheet-list');list.hidden=true;let openType=null;
 [['Floors',b.floors,null],['Rooms',b.rooms,'Room'],['Offices',b.offices,'Offices'],['Facilities',b.facilities,'Facilities']].forEach(([label,val,type])=>{
  const c=el(type?'button':'div','stat-card'+(type?' tappable':''));if(type)c.type='button';
  c.appendChild(el('strong','',Number(val||0).toLocaleString()));c.appendChild(el('span','',label));
  if(type)c.onclick=()=>{stats.querySelectorAll('.tappable').forEach(n=>n.classList.remove('on'));if(openType===type){openType=null;list.hidden=true;return}openType=type;c.classList.add('on');fillList(list,b,type,label)};
  stats.appendChild(c)});
 body.appendChild(stats);body.appendChild(list);
 const navRow=el('div','sheet-nav');
 const from=el('button','from','\ud83d\udccd Start here');from.type='button';from.onclick=()=>startAtBuilding(b.id);
 const to=el('button','to','\ud83c\udfaf Go here');to.type='button';to.onclick=()=>goToBuilding(b.id);
 navRow.appendChild(from);navRow.appendChild(to);body.appendChild(navRow);
 infoPanel.appendChild(body);
 const act=el('div','sheet-actions');
 const open=el('button','primary','Open Floor Layout');open.type='button';open.onclick=()=>location.href=floorUrl(b);
 const back=el('button','secondary','Reset View');back.type='button';back.onclick=resetView;
 act.appendChild(open);act.appendChild(back);infoPanel.appendChild(act);
}
/* Zoom so the whole building fits with breathing room: not too far out, never cut off */
function select(b,quiet){
 if(isCampusElement(b))return;
 selectedId=b.id;if(!quiet)selectedAt=performance.now();
 canvas.classList.add('has-selection');
 canvas.querySelectorAll('.building').forEach(n=>n.classList.toggle('selected',Number(n.dataset.id)===b.id));
 buildInfo(b);
 if(!quiet){infoPanel.hidden=false;backdrop.hidden=false}
 const vw=mapScroll.clientWidth,vh=mapScroll.clientHeight,bx=buildingBox(b),cx=b.x-OX+b.w/2,cy=b.y-OY+b.h/2;
 const sc=Math.max(initZ,Math.min(3.2,Math.min(vw/(Math.max(bx.w,1)*1.6),vh/(Math.max(bx.h,1)*1.6))));
 setView(sc,vw/2-cx*sc,vh/2-cy*sc);
}
function handleClick(b){if(isCampusElement(b))return;if(selectedId===b.id&&!infoPanel.hidden&&performance.now()-selectedAt>400){location.href=floorUrl(b);return}select(b)}

/* ===================================================================
   Pin location & navigation preparation
   - Start and destination pins are placed from real map data:
     buildings use their saved layout position (center of the building);
     rooms / offices / facilities are anchored to the building that contains them.
   - GPS is only shown when config/campus_geo.php calibrates the map to real
     latitude/longitude. Without it, GPS is never faked: the student picks manually.
   - The dashed line is a straight-line PREVIEW only, not a walking route.
   =================================================================== */
const NS='http://www.w3.org/2000/svg';
const GPS_MAX_ACCURACY_HARD=200;
const startSelect=document.getElementById('startSelect'), destSelect=document.getElementById('destSelect');
const gpsBtn=document.getElementById('gpsBtn'), pickBtn=document.getElementById('pickBtn');
const navMsg=document.getElementById('navMsg'), navSummary=document.getElementById('navSummary'), navClear=document.getElementById('navClear');
const pickBanner=document.getElementById('pickBanner'), pickCancel=document.getElementById('pickCancel');
const nav={start:null,dest:null,picking:false};
const places=[], placeByKey=new Map(), pinEls={};
const KIND={Room:'Room',Offices:'Office',Facilities:'Facility'}, PREFIX={Room:'r',Offices:'o',Facilities:'f'};
let ring=null;

const pinLayer=el('div');pinLayer.id='pinLayer';
const routeSvg=document.createElementNS(NS,'svg');routeSvg.setAttribute('class','route-layer');routeSvg.setAttribute('aria-hidden','true');
const routeLine=document.createElementNS(NS,'line');
routeLine.setAttribute('stroke','#7c3aed');routeLine.setAttribute('stroke-linecap','round');routeLine.setAttribute('visibility','hidden');
routeSvg.appendChild(routeLine);

function attachNavLayers(){
 routeSvg.setAttribute('width',CANVAS_W);routeSvg.setAttribute('height',CANVAS_H);routeSvg.setAttribute('viewBox','0 0 '+CANVAS_W+' '+CANVAS_H);
 canvas.appendChild(routeSvg);canvas.appendChild(pinLayer);
}
function styleRoute(p){routeLine.setAttribute('stroke-width',(4*p).toFixed(2));routeLine.setAttribute('stroke-dasharray',(10*p).toFixed(2)+' '+(8*p).toFixed(2))}
function clamp(v,a,b){return Math.max(a,Math.min(b,v))}
function msg(text,cls){navMsg.textContent=text||'';navMsg.className='nav-msg'+(cls?' '+cls:'')}
function reveal(){const r=mapScroll.getBoundingClientRect();if(r.top<0||r.bottom>window.innerHeight)mapScroll.scrollIntoView({block:'center',behavior:'smooth'})}

/* ---- places (all come from the buildings / rooms / offices / facilities data already loaded) ---- */
function centerOf(b){return{x:b.x-OX+b.w/2,y:b.y-OY+b.h/2}}
function placeKeyForRoom(r){return PREFIX[r.type]+':'+r.id}
function placeTitle(p){return p.number?p.number+' \u2014 '+p.name:p.name}
function placeSub(p){
 const b=byId(p.buildingId);
 if(p.kind==='Building')return 'Building \u00b7 '+b.floors+(b.floors===1?' floor':' floors');
 if(p.kind==='Facility')return 'Facility \u00b7 '+b.name+(p.detail?' \u00b7 '+p.detail:'');
 return p.kind+' \u00b7 '+b.name+(p.detail?' \u00b7 '+p.detail:'');
}
function buildPlaces(){
 places.length=0;placeByKey.clear();
 buildings.filter(b=>!isCampusElement(b)).forEach(b=>{const p={key:'b:'+b.id,kind:'Building',buildingId:b.id,name:b.name,number:'',detail:'',floorNo:1};places.push(p)});
 searchRooms.forEach(r=>{
  const b=byId(r.buildingId);if(!b||isCampusElement(b)||!r.name&&!r.number)return;
  places.push({key:placeKeyForRoom(r),kind:KIND[r.type]||'Room',buildingId:b.id,name:r.name||r.number,number:r.number,detail:r.floor,floorNo:r.type==='Facilities'?1:floorNumber(r.floor)});
 });
 places.forEach(p=>placeByKey.set(p.key,p));
}
function fillSelects(){
 const bs=buildings.filter(b=>!isCampusElement(b)).sort((a,b)=>a.name.localeCompare(b.name));
 const order={Room:1,Office:2,Facility:3};
 [startSelect,destSelect].forEach(sel=>{
  sel.replaceChildren();
  const first=el('option','',sel===startSelect?'Choose your starting point\u2026':'Choose a destination\u2026');first.value='';sel.appendChild(first);
  if(sel===startSelect){const c=el('option','','');c.value='__custom';c.id='startCustom';c.hidden=true;sel.appendChild(c)}
  bs.forEach(b=>{
   const g=document.createElement('optgroup');g.label=b.name;
   const w=el('option','','\ud83c\udfe2 Whole building');w.value='b:'+b.id;g.appendChild(w);
   places.filter(p=>p.buildingId===b.id&&p.kind!=='Building').sort((a,c)=>(order[a.kind]-order[c.kind])||placeTitle(a).localeCompare(placeTitle(c))).forEach(p=>{
    const o=el('option','',placeTitle(p)+' \u00b7 '+p.kind+(p.detail?' \u00b7 '+p.detail:''));o.value=p.key;g.appendChild(o)});
   sel.appendChild(g);
  });
 });
}

/* ---- geometry helpers ---- */
function buildingAt(x,y){
 for(const b of [...buildings].reverse()){
  if(isCampusElement(b))continue;
  const c=centerOf(b),t=-(b.rotation||0)*Math.PI/180,dx=x-c.x,dy=y-c.y;
  const lx=dx*Math.cos(t)-dy*Math.sin(t),ly=dx*Math.sin(t)+dy*Math.cos(t);
  if(Math.abs(lx)<=b.w/2&&Math.abs(ly)<=b.h/2)return b;
 }
 return null;
}
function geoScale(){
 if(!geoCfg)return null;
 const[lat1,lng1]=geoCfg.top_left,[lat2,lng2]=geoCfg.bottom_right,midLat=(lat1+lat2)/2*Math.PI/180;
 const wM=Math.abs(lng2-lng1)*111320*Math.cos(midLat),hM=Math.abs(lat1-lat2)*110574;
 if(!(wM>0&&hM>0))return null;
 return{pxPerMeter:(CANVAS_W/wM+CANVAS_H/hM)/2};
}
function geoToCanvas(lat,lng){
 const[lat1,lng1]=geoCfg.top_left,[lat2,lng2]=geoCfg.bottom_right;
 const fx=(lng-lng1)/(lng2-lng1),fy=(lat1-lat)/(lat1-lat2);
 return{fx,fy,x:fx*CANVAS_W,y:fy*CANVAS_H};
}
function distanceMeters(){
 const s=nav.start,d=nav.dest,sc=geoScale();
 if(!s||!d||!sc)return null;
 return Math.hypot(s.x-d.x,s.y-d.y)/sc.pxPerMeter;
}

/* ---- state changes ---- */
function setStart(s,fit){nav.start=s;renderNav(fit)}
function setStartPlace(p){
 if(!p){setStart(null,false);return}
 const b=byId(p.buildingId),c=centerOf(b);
 setStart({source:'manual-place',key:p.key,title:placeTitle(p),sub:'Starting point \u00b7 selected manually \u00b7 '+placeSub(p),x:c.x,y:c.y,buildingId:b.id},true);
}
function setDestination(p){
 if(!p){nav.dest=null;renderNav(false);return}
 const b=byId(p.buildingId),c=centerOf(b);
 nav.dest={key:p.key,kind:p.kind,title:placeTitle(p),sub:placeSub(p),x:c.x,y:c.y,buildingId:b.id,floorNo:p.floorNo};
 renderNav(true);
}
function goToBuilding(id){resetView();closeResults();setDestination(placeByKey.get('b:'+id));reveal()}
function goToPlace(key){resetView();closeResults();setDestination(placeByKey.get(key));reveal()}
function startAtBuilding(id){resetView();setStartPlace(placeByKey.get('b:'+id));reveal()}
function clearNav(){stopPicking();nav.start=null;nav.dest=null;msg('');renderNav(false);resetView()}

/* ---- pins ---- */
function pinSvg(fill,glyph){return '<svg viewBox="0 0 30 40" aria-hidden="true"><path d="M15 1.5C7.6 1.5 1.5 7.5 1.5 14.9 1.5 25 15 38.5 15 38.5S28.5 25 28.5 14.9C28.5 7.5 22.4 1.5 15 1.5z" fill="'+fill+'" stroke="#fff" stroke-width="2.2"/>'+glyph+'</svg>'}
const GLYPH_GPS='<circle cx="15" cy="15" r="5.5" fill="#fff"/>';
const GLYPH_MANUAL='<circle cx="15" cy="15" r="6.8" fill="#fff"/><circle cx="15" cy="15" r="2.6" fill="#1d4ed8"/>';
const GLYPH_DEST='<polygon points="15,6.2 17.6,11.9 23.8,12.6 19.2,16.8 20.5,23 15,19.8 9.5,23 10.8,16.8 6.2,12.6 12.4,11.9" fill="#fff"/>';
function pinInfo(kind){
 if(kind==='start'&&nav.start){
  const s=nav.start,gps=s.source==='gps';
  return{x:s.x,y:s.y,title:s.title,sub:s.sub,cls:gps?'gps':'manual',chip:gps?'You \u00b7 GPS':'You \u00b7 manual',svg:pinSvg('#1d4ed8',gps?GLYPH_GPS:GLYPH_MANUAL),warn:s.lowAccuracy?'GPS accuracy is low. Confirm this spot or choose your location manually.':'',link:null};
 }
 if(kind==='dest'&&nav.dest){
  const d=nav.dest,b=byId(d.buildingId);
  return{x:d.x,y:d.y,title:d.title,sub:d.sub,cls:'',chip:'Destination',svg:pinSvg('#c2410c',GLYPH_DEST),warn:'',link:'admin/floor-layout.php?building_id='+encodeURIComponent(b.id)+'&floor='+encodeURIComponent(d.floorNo||1)};
 }
 return null;
}
function upsertPin(kind){
 const d=pinInfo(kind);let p=pinEls[kind];
 if(!d){if(p){p.remove();delete pinEls[kind]}return}
 if(!p){
  p=el('div','pin '+kind);p.tabIndex=0;p.setAttribute('role','button');
  p.addEventListener('click',ev=>{ev.stopPropagation();if(ev.target.closest('a'))return;togglePin(kind)});
  p.addEventListener('keydown',ev=>{if(ev.key==='Enter'||ev.key===' '){ev.preventDefault();togglePin(kind)}});
  pinLayer.appendChild(p);pinEls[kind]=p;
 }
 const wasOpen=p.classList.contains('open');
 p.className='pin '+kind+(d.cls?' '+d.cls:'')+(wasOpen?' open':'');
 p.style.left=d.x+'px';p.style.top=d.y+'px';p._x=d.x;p._y=d.y;
 p.setAttribute('aria-label',d.title+'. '+d.sub);
 const art=el('span','pin-art');art.innerHTML=d.svg;
 const chip=el('span','pin-chip',d.chip);
 const tip=el('div','pin-tip');tip.appendChild(el('b','',d.title));tip.appendChild(el('small','',d.sub));
 if(d.warn)tip.appendChild(el('div','tip-warn',d.warn));
 if(d.link){const a=el('a','','Open floor layout');a.href=d.link;tip.appendChild(a)}
 p.replaceChildren(art,chip,tip);
 placeTip(p);
}
function placeTip(p){
 const tip=p.querySelector('.pin-tip');if(!tip)return;
 const sx=view.x+p._x*view.s,sy=view.y+p._y*view.s,w=mapScroll.clientWidth;
 tip.classList.toggle('below',sy<170);tip.classList.toggle('al-left',sx<140);tip.classList.toggle('al-right',sx>w-140);
}
function refreshTips(){Object.values(pinEls).forEach(p=>{if(p.classList.contains('open'))placeTip(p)})}
function closePinTips(){Object.values(pinEls).forEach(p=>p.classList.remove('open'))}
function togglePin(kind){const p=pinEls[kind];if(!p)return;const open=!p.classList.contains('open');closePinTips();if(open){p.classList.add('open');placeTip(p)}}
/* If both pins land on (almost) the same spot, nudge them apart so both stay visible */
function updateNear(){const a=pinEls.start,b=pinEls.dest;const near=!!(a&&b&&Math.hypot(a._x-b._x,a._y-b._y)*view.s<26);[a,b].forEach(p=>{if(p)p.classList.toggle('near',near)})}
function drawRing(){
 const s=nav.start,sc=geoScale();
 if(!s||s.source!=='gps'||!sc||!s.accuracy){if(ring){ring.remove();ring=null}return}
 if(!ring){ring=el('div','acc-ring');pinLayer.insertBefore(ring,pinLayer.firstChild)}
 const d=Math.max(10,s.accuracy*sc.pxPerMeter*2);
 ring.style.left=s.x+'px';ring.style.top=s.y+'px';ring.style.width=d+'px';ring.style.height=d+'px';
}
function drawRoute(){
 const s=nav.start,d=nav.dest;
 if(!s||!d||Math.hypot(s.x-d.x,s.y-d.y)<1){routeLine.setAttribute('visibility','hidden');return}
 routeLine.setAttribute('x1',s.x);routeLine.setAttribute('y1',s.y);routeLine.setAttribute('x2',d.x);routeLine.setAttribute('y2',d.y);
 routeLine.setAttribute('visibility','visible');
}
function fitToPins(){
 const list=[nav.start,nav.dest].filter(Boolean);if(!list.length)return;
 const vw=mapScroll.clientWidth,vh=mapScroll.clientHeight;
 const x1=Math.min(...list.map(p=>p.x)),x2=Math.max(...list.map(p=>p.x)),y1=Math.min(...list.map(p=>p.y)),y2=Math.max(...list.map(p=>p.y));
 const cx=(x1+x2)/2,cy=(y1+y2)/2;let s;
 if(list.length===1||(x2-x1<4&&y2-y1<4))s=Math.min(MAX_Z,Math.max(initZ,initZ*2.2));
 else s=Math.min(2.5,Math.max(initZ,Math.min(vw/((x2-x1)+260),vh/((y2-y1)+260))));
 setView(s,vw/2-cx*s,vh/2-cy*s+18);
}

/* ---- panel (selects, summary, plan) ---- */
function syncSelects(){
 const s=nav.start,custom=document.getElementById('startCustom');
 if(custom){
  custom.hidden=!(s&&!s.key);
  if(s&&!s.key)custom.textContent=s.source==='gps'?'\ud83d\udccd My location (GPS)':'\ud83d\udc46 Spot I chose on the map';
 }
 startSelect.value=s?(s.key||'__custom'):'';
 destSelect.value=nav.dest?nav.dest.key:'';
}
function sumRow(kind,title,sub,badge,badgeCls){
 const r=el('div','nav-row');r.appendChild(el('span','dot '+kind));
 const m=el('div','nav-row-main');
 if(title){m.appendChild(el('b','',title));if(sub)m.appendChild(el('small','',sub))}
 else m.appendChild(el('small','muted',kind==='start'?'Starting point not set yet':'Destination not set yet'));
 r.appendChild(m);
 if(badge)r.appendChild(el('span','nav-badge '+badgeCls,badge));
 return r;
}
function renderSummary(){
 navSummary.replaceChildren();
 const s=nav.start,d=nav.dest;
 if(!s&&!d){navSummary.hidden=true;return}
 navSummary.hidden=false;
 const card=el('div','nav-card');
 card.appendChild(sumRow('start',s&&s.title,s&&s.sub,s?(s.source==='gps'?(s.lowAccuracy?'GPS \u00b7 low accuracy':'GPS'):'Manual'):'',s?(s.source==='gps'?(s.lowAccuracy?'low':'gps'):'manual'):''));
 card.appendChild(sumRow('dest',d&&d.title,d&&d.sub,'',''));
 let note='';
 if(s&&d){
  const sb=byId(s.buildingId),db=byId(d.buildingId);
  if(s.buildingId&&s.buildingId===d.buildingId){
   note='Both points are in '+db.name+'. Use its floor layout for indoor directions.';
  }else{
   const m=distanceMeters();
   note=(m!==null?'About '+Math.round(m)+' m in a straight line. ':'')+'The dashed line is only a preview, not a walking route.';
  }
  if(navInfo.pointsTable){
   const n=Number((navInfo.pointsByBuilding||{})[d.buildingId]||0);
   note+=' '+(n>0?n+' navigation point'+(n===1?'':'s')+' mapped in '+db.name+'.':'No navigation points are mapped yet for '+db.name+'.');
  }
 }else if(s){note='Now choose a destination.'}
 else{note='Now set your starting point: use your location, tap the map, or pick it from the list.'}
 card.appendChild(el('p','nav-note',note));
 if(d){
  const act=el('div','nav-actions');
  const a=el('a','nav-btn','Open floor layout');a.href='admin/floor-layout.php?building_id='+encodeURIComponent(d.buildingId)+'&floor='+encodeURIComponent(d.floorNo||1);
  act.appendChild(a);card.appendChild(act);
 }
 navSummary.appendChild(card);
}
/* Single object the route step will read: start, destination, and which data it can connect to */
function getPlan(){
 const s=nav.start,d=nav.dest;
 return{
  ready:!!(s&&d),
  start:s?{source:s.source,label:s.title,x:s.x,y:s.y,buildingId:s.buildingId||null,placeKey:s.key||null,lat:s.lat??null,lng:s.lng??null,accuracyMeters:s.accuracy??null,lowAccuracy:!!s.lowAccuracy}:null,
  destination:d?{kind:d.kind,placeKey:d.key,label:d.title,buildingId:d.buildingId,floor:d.kind==='Facility'?null:(d.floorNo||1),x:d.x,y:d.y}:null,
  sameBuilding:!!(s&&d&&s.buildingId&&s.buildingId===d.buildingId),
  straightLineMeters:distanceMeters(),
  mapSize:{width:CANVAS_W,height:CANVAS_H},
  navigationData:navInfo
 };
}
function renderNav(fit){
 syncSelects();upsertPin('start');upsertPin('dest');drawRing();drawRoute();updateNear();renderSummary();
 navClear.hidden=!(nav.start||nav.dest);
 if(fit)fitToPins();
 document.dispatchEvent(new CustomEvent('cmu:navigation-plan',{detail:getPlan()}));
}
window.CMUNav={getPlan,setDestinationByKey:k=>goToPlace(k),clear:clearNav};

/* ---- GPS (real device location; never simulated) ---- */
function locateMe(){
 stopPicking();
 if(!('geolocation' in navigator)){msg('This browser does not support GPS. Please choose your location manually.','warn');return}
 if(!window.isSecureContext){msg('GPS only works on a secure (https) page. Please choose your location manually.','warn');return}
 gpsBtn.disabled=true;gpsBtn.textContent='Locating\u2026';msg('Waiting for a GPS fix\u2026');
 navigator.geolocation.getCurrentPosition(onFix,onGpsError,{enableHighAccuracy:true,timeout:15000,maximumAge:0});
}
function gpsDone(){gpsBtn.disabled=false;gpsBtn.textContent='\ud83d\udccd Use my location'}
function onFix(pos){
 gpsDone();
 const c=pos.coords,acc=Math.round(c.accuracy||0);
 if(!geoCfg){msg('Your device found a GPS position (about \u00b1'+acc+' m), but the campus map has not been calibrated for GPS yet, so it cannot be shown on the map. Please choose your location manually.','warn');return}
 const g=geoToCanvas(c.latitude,c.longitude);
 if(g.fx<-.05||g.fx>1.05||g.fy<-.05||g.fy>1.05){msg('Your GPS position is outside the campus map area. If you are on campus, please choose your location manually.','warn');return}
 if(acc>GPS_MAX_ACCURACY_HARD){msg('GPS accuracy is too low (about \u00b1'+acc+' m) to place you on the map. This is common indoors. Please choose your location manually.','warn');return}
 const x=clamp(g.x,0,CANVAS_W),y=clamp(g.y,0,CANVAS_H),low=acc>geoCfg.max_accuracy_m,b=buildingAt(x,y);
 setStart({source:'gps',title:'Your location',sub:'Detected by GPS \u00b7 accuracy about \u00b1'+acc+' m'+(b?' \u00b7 near '+b.name:''),x,y,buildingId:b?b.id:null,lat:c.latitude,lng:c.longitude,accuracy:acc,lowAccuracy:low},true);
 msg(low?'GPS accuracy is low (about \u00b1'+acc+' m), which is common indoors. Check the pin, or choose your location manually.':'GPS location found (about \u00b1'+acc+' m). If the pin looks wrong, choose your location manually.',low?'warn':'');
}
function onGpsError(err){
 gpsDone();
 const m={1:'Location permission was denied. Allow location access in your browser, or choose your location manually.',2:'Your location is unavailable right now (common indoors). Please choose your location manually.',3:'Finding your location timed out. Try again, or choose your location manually.'};
 msg(m[err&&err.code]||'Could not get your location. Please choose it manually.','warn');
}

/* ---- manual: tap the map ---- */
function startPicking(){
 if(nav.picking){stopPicking();return}
 nav.picking=true;hideInfo();closePinTips();
 pickBtn.classList.add('on');pickBtn.textContent='\ud83d\udc46 Tap the map\u2026';
 mapScroll.classList.add('picking');pickBanner.hidden=false;
 msg('Tap the spot on the map where you are standing.');reveal();
}
function stopPicking(){
 if(!nav.picking)return;
 nav.picking=false;pickBtn.classList.remove('on');pickBtn.textContent='\ud83d\udc46 Tap on map';
 mapScroll.classList.remove('picking');pickBanner.hidden=true;
}
/* Capture phase: while picking, a tap sets the start instead of selecting a building. Drags are filtered out above. */
mapScroll.addEventListener('click',e=>{
 if(!nav.picking)return;
 e.stopPropagation();e.preventDefault();
 const r=canvas.getBoundingClientRect(),x=(e.clientX-r.left)/view.s,y=(e.clientY-r.top)/view.s;
 if(x<0||y<0||x>CANVAS_W||y>CANVAS_H)return;
 const b=buildingAt(x,y);
 stopPicking();
 setStart({source:'manual-map',title:'Your location',sub:'Set manually on the map'+(b?' \u00b7 inside '+b.name:''),x,y,buildingId:b?b.id:null},!!nav.dest);
 msg('Location set manually. Tap the pin to see its details.');
},true);
mapScroll.addEventListener('click',e=>{if(!e.target.closest('.pin'))closePinTips()});

gpsBtn.addEventListener('click',locateMe);
pickBtn.addEventListener('click',startPicking);
pickCancel.addEventListener('click',()=>{stopPicking();msg('')});
navClear.addEventListener('click',clearNav);
startSelect.addEventListener('change',()=>{const v=startSelect.value;if(v==='__custom')return;stopPicking();if(v==='')setStart(null,false);else setStartPlace(placeByKey.get(v))});
destSelect.addEventListener('change',()=>{const v=destSelect.value;setDestination(v===''?null:placeByKey.get(v))});
function initNavigation(){buildPlaces();fillSelects();renderNav(false)}

/* Search buildings, rooms, offices, and facilities — original search behavior */
const searchInput=document.getElementById('campusSearch'), searchResults=document.getElementById('searchResults'), searchClear=document.getElementById('searchClear');
const MAX_RESULTS=30, SEARCH_ZOOM_WAIT=1100;
let searchNavTimer=null;
function floorNumber(text){const n=parseInt(String(text||''),10);return Number.isFinite(n)&&n>0?n:1}
function closeResults(){searchResults.classList.remove('open');searchResults.replaceChildren()}
function makeResultRow(title,meta,onPick,href,onGo){const row=el('div','result-row');const main=el('button','result-main');main.type='button';main.appendChild(el('span','result-title',title));main.appendChild(el('span','result-meta',meta));main.addEventListener('click',onPick);row.appendChild(main);if(href){const link=el('a','result-link','View floor');link.href=href;link.addEventListener('click',ev=>{ev.preventDefault();onPick()});row.appendChild(link)}if(onGo){const go=el('button','result-link go','Go here');go.type='button';go.addEventListener('click',ev=>{ev.preventDefault();onGo()});row.appendChild(go)}return row}
function zoomThenOpenFloor(buildingId,floor){const b=byId(buildingId);if(!b||isCampusElement(b))return;clearTimeout(searchNavTimer);closeResults();select(b,true);const node=canvas.querySelector('.building[data-id="'+Number(b.id)+'"]');if(node){node.classList.remove('found');void node.offsetWidth;node.classList.add('found')}searchNavTimer=setTimeout(()=>{location.href='admin/floor-layout.php?building_id='+encodeURIComponent(b.id)+'&floor='+encodeURIComponent(floor||1)},window.matchMedia('(prefers-reduced-motion: reduce)').matches?150:SEARCH_ZOOM_WAIT)}
function runSearch(){const q=searchInput.value.trim().toLowerCase();searchClear.hidden=q==='';if(!q){closeResults();return}const bMatches=buildings.filter(b=>!isCampusElement(b)&&String(b.name||'').toLowerCase().includes(q));const rMatches=searchRooms.filter(r=>{const b=byId(r.buildingId);return b&&!isCampusElement(b)&&[r.number,r.name,r.type,r.floor,b.name].join(' ').toLowerCase().includes(q)});searchResults.replaceChildren();if(!bMatches.length&&!rMatches.length){searchResults.appendChild(el('div','no-results','No buildings, rooms, offices, or facilities found for “'+searchInput.value.trim()+'”.'));searchResults.classList.add('open');return}if(bMatches.length){searchResults.appendChild(el('div','search-group-title','BUILDINGS'));bMatches.slice(0,MAX_RESULTS).forEach(b=>searchResults.appendChild(makeResultRow(b.name,'Building · '+b.floors+(b.floors===1?' floor':' floors')+' · Zooms in, then opens its floor layout',()=>zoomThenOpenFloor(b.id,1),floorUrl(b),()=>goToBuilding(b.id))))}if(rMatches.length){searchResults.appendChild(el('div','search-group-title','ROOMS, OFFICES & FACILITIES'));rMatches.slice(0,MAX_RESULTS).forEach(r=>{const b=byId(r.buildingId),f=floorNumber(r.floor),title=r.number!==''?r.number+' — '+r.name:r.name,meta=r.type+' · '+b.name+(r.floor!==''?' · '+r.floor:'');searchResults.appendChild(makeResultRow(title,meta,()=>zoomThenOpenFloor(b.id,f),'admin/floor-layout.php?building_id='+encodeURIComponent(b.id)+'&floor='+encodeURIComponent(f),()=>goToPlace(placeKeyForRoom(r))))})}searchResults.classList.add('open')}
searchInput.addEventListener('input',runSearch);searchInput.addEventListener('focus',()=>{if(searchInput.value.trim()!=='')runSearch()});searchInput.addEventListener('keydown',ev=>{if(ev.key==='Enter'){ev.preventDefault();const first=searchResults.querySelector('.result-main');if(first)first.click()}if(ev.key==='Escape')closeResults()});searchClear.addEventListener('click',()=>{searchInput.value='';searchClear.hidden=true;closeResults();searchInput.focus()});document.addEventListener('click',ev=>{if(!ev.target.closest('.search-panel'))closeResults()});
function render(){
 canvas.replaceChildren();buildingList.replaceChildren();
 if(!buildings.length){buildingList.appendChild(el('div','empty-message','Wala pang buildings sa database.'));return}
 // Grounds are rendered first so they remain beneath all other map objects.
 const ordered=[...buildings].sort((a,b)=>Number(isGround(b))-Number(isGround(a)));
 ordered.forEach(b=>{
  const ground=isGround(b),court=isCourt(b),parking=isParking(b),campusElement=isCampusElement(b),sc=statusClass(b.status);
  const shape=el('div','building '+b.shape+(ground?' ground-element':court?' court-shape':parking?' parking-shape':' '+sc));
  shape.dataset.id=b.id;shape.style.left=(b.x-OX)+'px';shape.style.top=(b.y-OY)+'px';shape.style.width=b.w+'px';shape.style.height=b.h+'px';shape.style.transform='rotate('+b.rotation+'deg)';
  if(ground){shape.style.zIndex='0';shape.tabIndex=-1;shape.setAttribute('aria-label',b.name+' background');shape.title=b.name+' background';}
  else if(campusElement){shape.style.zIndex='1';shape.tabIndex=-1;shape.setAttribute('aria-label',b.name);shape.title=b.name;}
  else {shape.style.zIndex='2';shape.tabIndex=0;shape.setAttribute('role','button');shape.setAttribute('aria-label',b.name+', '+statusLabel(b.status));shape.title=b.name+' — '+statusLabel(b.status);shape.addEventListener('click',ev=>{ev.stopPropagation();handleClick(b)});shape.addEventListener('keydown',ev=>{if(ev.key==='Enter'||ev.key===' '){ev.preventDefault();handleClick(b)}})}
  if(court){
   const courtGraphics=el('div','court-graphics');
   courtGraphics.innerHTML=`<div class="court-surface"><div class="half-court-line"></div><div class="center-circle"></div><div class="key left-key"><div class="free-throw-circle left-circle"></div><div class="hoop left-hoop"></div><div class="backboard left-backboard"></div></div><div class="three-point-line left-three"></div><div class="key right-key"><div class="free-throw-circle right-circle"></div><div class="hoop right-hoop"></div><div class="backboard right-backboard"></div></div><div class="three-point-line right-three"></div></div>`;
   shape.appendChild(courtGraphics);
   
  } else if(parking){
   const parkingGraphics=el('div','parking-graphics');
   parkingGraphics.innerHTML=`<div class="parking-surface"><div class="parking-area"><div class="parking-slot"></div><div class="parking-slot"></div></div></div>`;
   shape.appendChild(parkingGraphics);
  } else if(!ground&&!campusElement){shape.appendChild(el('span','building-label',b.name));}
  canvas.appendChild(shape);
  if(!campusElement){const btn=el('button','building-list-item');btn.type='button';btn.dataset.id=b.id;btn.appendChild(el('div','',b.name));btn.appendChild(el('span','list-status '+sc,statusLabel(b.status)));btn.onclick=()=>handleClick(b);buildingList.appendChild(btn)}
 });
 if(!buildingList.children.length)buildingList.appendChild(el('div','empty-message','Walang clickable buildings na available.'));
 attachNavLayers();
}
canvas.addEventListener('click',e=>{if(e.target===canvas)resetView()});document.addEventListener('keydown',e=>{if(e.key==='Escape'){hideInfo();closeResults();stopPicking();closePinTips()}});
computeBounds();render();applyFit();initNavigation();window.addEventListener('resize',applyFit);
/* Search sent from the Home page (campus-map.php?search=...) */
(function(){const q=(new URLSearchParams(location.search).get('search')||'').trim();if(!q)return;searchInput.value=q;runSearch();const sp=document.querySelector('.search-panel');if(sp)sp.scrollIntoView({block:'start'})})();
</script>
</body></html>
