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

</style>
<link rel="stylesheet" href="mobile.css">
</head>
<body>
<header class="navbar"><div class="logo"><img src="cmu%20logo.jpg" alt="CMU Logo"><div><h2>CMU Campus Navigator</h2><span>City of Malabon University</span></div></div><nav class="nav-links"><a href="index.php#home" class="nav-home">Home</a><a href="campus-map.php" class="nav-home">Campus Map</a><a href="admin/University-info.php" class="nav-home">University Info</a><a href="update_request.php" class="nav-home">Feedback</a><a href="admin/login.php" class="nav-admin">Admin Login</a></nav></header>
<main><section class="page-heading"><span class="eyebrow">EXPLORE OUR CAMPUS</span><h1>CMU Campus Map</h1><p>Tap a building to see its details, then open its floor layout. Pinch or use + / − to zoom.</p></section>
<div class="map-layout"><section class="panel"><h2>Campus Layout</h2><p class="panel-description">Building positions and dimensions come from the saved Campus Layout Editor.</p><section class="search-panel"><div class="search-box"><span class="search-icon" aria-hidden="true">🔎</span><input type="search" id="campusSearch" placeholder="Search a building, room, office, or facility (e.g. Library, 101, Registrar)" autocomplete="off" aria-label="Search buildings, rooms, offices, and facilities"><button type="button" class="search-clear" id="searchClear" hidden>Clear</button></div><div class="search-results" id="searchResults" role="listbox"></div></section><div class="map-wrap"><div class="map-scroll" id="mapScroll"><div id="campusCanvas"></div></div>
<div class="zoom-ctrl"><button type="button" id="zoomIn" aria-label="Zoom in">+</button><button type="button" id="zoomOut" aria-label="Zoom out">−</button><button type="button" class="fit" id="zoomFit" aria-label="Fit map to screen">Fit</button></div></div><div class="legend"><span class="legend-item"><span class="legend-color legend-active"></span>Active</span><span class="legend-item"><span class="legend-color legend-inactive"></span>Inactive</span></div></section>
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
mapScroll.addEventListener('click',e=>{if(moved){e.stopPropagation();e.preventDefault();moved=false}},true);

function buildingBox(b){const t=(b.rotation||0)*Math.PI/180,c=Math.abs(Math.cos(t)),n=Math.abs(Math.sin(t));return{w:b.w*c+b.h*n,h:b.w*n+b.h*c}}
function fillList(list,b,type,label){
 list.replaceChildren();
 const items=searchRooms.filter(r=>r.buildingId===b.id&&r.type===type);
 if(!items.length)list.appendChild(el('div','sheet-empty','No '+label.toLowerCase()+' listed for this building.'));
 items.forEach(r=>{const it=el('button','sheet-item');it.type='button';it.appendChild(el('b','',r.number!==''?r.number+' \u2014 '+r.name:r.name));if(r.floor!=='')it.appendChild(el('small','',r.floor));
  it.onclick=()=>{location.href='admin/floor-layout.php?building_id='+encodeURIComponent(b.id)+'&floor='+encodeURIComponent(floorNumber(r.floor))};list.appendChild(it)});
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
 body.appendChild(stats);body.appendChild(list);infoPanel.appendChild(body);
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
/* Search buildings, rooms, offices, and facilities — original search behavior */
const searchInput=document.getElementById('campusSearch'), searchResults=document.getElementById('searchResults'), searchClear=document.getElementById('searchClear');
const MAX_RESULTS=30, SEARCH_ZOOM_WAIT=1100;
let searchNavTimer=null;
function floorNumber(text){const n=parseInt(String(text||''),10);return Number.isFinite(n)&&n>0?n:1}
function closeResults(){searchResults.classList.remove('open');searchResults.replaceChildren()}
function makeResultRow(title,meta,onPick,href){const row=el('div','result-row');const main=el('button','result-main');main.type='button';main.appendChild(el('span','result-title',title));main.appendChild(el('span','result-meta',meta));main.addEventListener('click',onPick);row.appendChild(main);if(href){const link=el('a','result-link','View floor');link.href=href;link.addEventListener('click',ev=>{ev.preventDefault();onPick()});row.appendChild(link)}return row}
function zoomThenOpenFloor(buildingId,floor){const b=byId(buildingId);if(!b||isCampusElement(b))return;clearTimeout(searchNavTimer);closeResults();select(b,true);const node=canvas.querySelector('.building[data-id="'+Number(b.id)+'"]');if(node){node.classList.remove('found');void node.offsetWidth;node.classList.add('found')}searchNavTimer=setTimeout(()=>{location.href='admin/floor-layout.php?building_id='+encodeURIComponent(b.id)+'&floor='+encodeURIComponent(floor||1)},window.matchMedia('(prefers-reduced-motion: reduce)').matches?150:SEARCH_ZOOM_WAIT)}
function runSearch(){const q=searchInput.value.trim().toLowerCase();searchClear.hidden=q==='';if(!q){closeResults();return}const bMatches=buildings.filter(b=>!isCampusElement(b)&&String(b.name||'').toLowerCase().includes(q));const rMatches=searchRooms.filter(r=>{const b=byId(r.buildingId);return b&&!isCampusElement(b)&&[r.number,r.name,r.type,r.floor,b.name].join(' ').toLowerCase().includes(q)});searchResults.replaceChildren();if(!bMatches.length&&!rMatches.length){searchResults.appendChild(el('div','no-results','No buildings, rooms, offices, or facilities found for “'+searchInput.value.trim()+'”.'));searchResults.classList.add('open');return}if(bMatches.length){searchResults.appendChild(el('div','search-group-title','BUILDINGS'));bMatches.slice(0,MAX_RESULTS).forEach(b=>searchResults.appendChild(makeResultRow(b.name,'Building · '+b.floors+(b.floors===1?' floor':' floors')+' · Zooms in, then opens its floor layout',()=>zoomThenOpenFloor(b.id,1),floorUrl(b))))}if(rMatches.length){searchResults.appendChild(el('div','search-group-title','ROOMS, OFFICES & FACILITIES'));rMatches.slice(0,MAX_RESULTS).forEach(r=>{const b=byId(r.buildingId),f=floorNumber(r.floor),title=r.number!==''?r.number+' — '+r.name:r.name,meta=r.type+' · '+b.name+(r.floor!==''?' · '+r.floor:'');searchResults.appendChild(makeResultRow(title,meta,()=>zoomThenOpenFloor(b.id,f),'admin/floor-layout.php?building_id='+encodeURIComponent(b.id)+'&floor='+encodeURIComponent(f)))})}searchResults.classList.add('open')}
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
}
canvas.addEventListener('click',e=>{if(e.target===canvas)resetView()});document.addEventListener('keydown',e=>{if(e.key==='Escape'){hideInfo();closeResults()}});
computeBounds();render();applyFit();window.addEventListener('resize',applyFit);
/* Search sent from the Home page (campus-map.php?search=...) */
(function(){const q=(new URLSearchParams(location.search).get('search')||'').trim();if(!q)return;searchInput.value=q;runSearch();const sp=document.querySelector('.search-panel');if(sp)sp.scrollIntoView({block:'start'})})();
</script>
</body></html>
