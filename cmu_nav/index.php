<?php
/* Load buildings, rooms, offices and facilities for the live search suggestions.
   If the database is unreachable the page still opens; only the suggestions are empty. */
$searchItems = [];
$dbFile = __DIR__ . "/config/database.php";
if (is_file($dbFile)) {
    try {
        require_once $dbFile;
        if (isset($conn)) {
            $skip = '/ground|court|parking|spiked|campus\s*border|boundary\s*wall/i';
            $bName = [];
            foreach ($conn->query("SELECT building_id, building_name, total_floors FROM buildings ORDER BY building_name ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = trim((string)($r["building_name"] ?? ""));
                if ($name === "" || preg_match($skip, $name)) continue;
                $id = (int)$r["building_id"];
                $bName[$id] = $name;
                $f = max(1, (int)($r["total_floors"] ?? 1));
                $searchItems[] = ["type" => "Building", "title" => $name, "meta" => "Building · " . $f . ($f === 1 ? " floor" : " floors"), "b" => $id, "floor" => 1];
            }
            foreach ($conn->query("SELECT building_id, room_number, room_name, floor FROM rooms ORDER BY room_name ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $b = (int)$r["building_id"]; if (!isset($bName[$b])) continue;
                $num = trim((string)($r["room_number"] ?? "")); $nm = trim((string)($r["room_name"] ?? ""));
                $fl = trim((string)($r["floor"] ?? "")); $fn = max(1, (int)$fl);
                $searchItems[] = ["type" => "Room", "title" => ($num !== "" ? $num . " — " : "") . $nm, "meta" => "Room · " . $bName[$b] . ($fl !== "" ? " · " . $fl : ""), "b" => $b, "floor" => $fn];
            }
            foreach ($conn->query("SELECT building_id, office_name FROM offices ORDER BY office_name ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $b = (int)$r["building_id"]; if (!isset($bName[$b])) continue;
                $searchItems[] = ["type" => "Office", "title" => trim((string)($r["office_name"] ?? "")), "meta" => "Office · " . $bName[$b], "b" => $b, "floor" => 1];
            }
            foreach ($conn->query("SELECT building_id, facility_name, category FROM facilities ORDER BY facility_name ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $b = (int)$r["building_id"]; if (!isset($bName[$b])) continue;
                $searchItems[] = ["type" => "Facility", "title" => trim((string)($r["facility_name"] ?? "")), "meta" => "Facility · " . $bName[$b], "b" => $b, "floor" => 1];
            }
        }
    } catch (Throwable $ex) {
        error_log("Home search data error: " . $ex->getMessage());
        $searchItems = [];
    }
}
$searchItems = array_values(array_filter($searchItems, function ($i) { return $i["title"] !== ""; }));
$searchItemsJson = json_encode($searchItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: "[]";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>CMU Campus Navigator</title>

    <!-- Everything needed for the page is inside this file, so a missing style.css can't break it -->
    <style>
        :root {
            --navy: #0b3b82;
            --navy-dark: #072a5c;
            --yellow: #ffd23f;
            --ice: #edf3fc;
            --ink: #14233d;
            --muted: #5f6f89;
            --line: #e3eaf5;
            --nav-h: 66px;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--ink);
            background: #f6f9fe;
            padding-bottom: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px) + 12px);
            overflow-x: hidden;
        }

        a { color: inherit; text-decoration: none; }

        /* ---------- LOADING SCREEN (cannot get stuck) ---------- */
        #cmu-loader {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #fff;
            /* CSS-only fallback: hides itself after 2s even if JavaScript never runs */
            animation: loader-out 0.4s ease 2s forwards;
        }
        #cmu-loader.loader-hidden { opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .4s, visibility .4s; }
        #cmu-loader img { width: 88px; height: 88px; object-fit: contain; animation: pulse 1s ease-in-out infinite alternate; }
        #cmu-loader p { margin: 16px 0 12px; color: var(--navy); font-size: 17px; font-weight: 700; }
        .dots { display: flex; gap: 8px; }
        .dots span { width: 10px; height: 10px; border-radius: 50%; background: var(--yellow); animation: bounce .6s infinite alternate; }
        .dots span:nth-child(2) { animation-delay: .2s; }
        .dots span:nth-child(3) { animation-delay: .4s; }

        @keyframes loader-out { to { opacity: 0; visibility: hidden; pointer-events: none; } }
        @keyframes pulse { from { transform: scale(.95); } to { transform: scale(1.05); } }
        @keyframes bounce { from { transform: translateY(0); opacity: .5; } to { transform: translateY(-8px); opacity: 1; } }

        /* ---------- TOP BAR ---------- */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: calc(10px + env(safe-area-inset-top, 0px)) 18px 10px;
            background: #fff;
            border-bottom: 1px solid var(--line);
        }
        .brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .brand img { width: 46px; height: 46px; object-fit: contain; flex: 0 0 46px; }
        .brand h1 { margin: 0; font-size: 19px; line-height: 1.15; font-weight: 800; color: var(--navy); }
        .brand small { display: block; margin-top: 3px; font-size: 11.5px; color: var(--muted); }

        .desktop-links { display: none; gap: 8px; }
        .desktop-links a { padding: 10px 14px; border-radius: 8px; font-size: 14px; font-weight: 700; color: var(--navy); background: var(--ice); }
        .desktop-links a:hover { background: #dce8fa; }

        .page { max-width: 560px; margin: 0 auto; padding: 14px 14px 0; }

        /* ---------- HERO ---------- */
        .hero {
            position: relative;
            border-radius: 26px;
            padding: 22px 20px 20px;
            color: #fff;
            background:
                linear-gradient(180deg, rgba(8,32,76,.55) 0%, rgba(8,32,76,.88) 70%, rgba(7,26,62,.96) 100%),
                url("cmu-building.jpg") center / cover no-repeat,
                var(--navy-dark);
            box-shadow: 0 14px 30px rgba(11, 59, 130, .28);
        }
        .pill {
            display: inline-block;
            padding: 7px 14px;
            border-radius: 999px;
            background: var(--yellow);
            color: var(--navy-dark);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .6px;
        }
        .hero h2 { margin: 22px 0 10px; font-size: clamp(32px, 9.5vw, 42px); line-height: 1.05; font-weight: 900; letter-spacing: -.5px; }
        .hero h2 span { display: block; color: var(--yellow); }
        .hero p { margin: 0 0 18px; font-size: 14px; line-height: 1.55; color: #e4ecfa; max-width: 34ch; }

        .search {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 6px 0 16px;
            height: 50px;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 6px 16px rgba(0,0,0,.18);
        }
        .search svg { flex: 0 0 18px; color: var(--navy); }
        .search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: inherit; font-size: 14.5px; color: var(--ink); }
        .search input::placeholder { color: #7b8aa3; }
        .search button { width: 40px; height: 40px; border: 0; border-radius: 10px; background: transparent; color: var(--navy); cursor: pointer; }

        .hero-actions { display: flex; gap: 10px; margin-top: 14px; }
        .btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 50px;
            border-radius: 14px;
            font-size: 14.5px;
            font-weight: 800;
            transition: transform .15s ease;
        }
        .btn:active { transform: scale(.97); }
        .btn-primary { background: var(--yellow); color: var(--navy-dark); }
        .btn-ghost { border: 1.5px solid rgba(255,255,255,.7); color: #fff; background: rgba(255,255,255,.08); }

        /* ---------- QUICK CARDS ---------- */
        .quick { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 18px; }
        .qcard {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 14px 14px 16px;
            border-radius: 18px;
            background: #fff;
            border: 1px solid var(--line);
            box-shadow: 0 4px 14px rgba(11, 59, 130, .07);
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .qcard:active { transform: scale(.97); }
        .qicon {
            width: 44px; height: 44px;
            display: grid; place-items: center;
            margin-bottom: 14px;
            border-radius: 12px;
            background: var(--ice);
            color: var(--navy);
        }
        .qicon svg { width: 24px; height: 24px; }
        .qcard strong { font-size: 15.5px; font-weight: 800; color: var(--navy-dark); }
        .qcard span { font-size: 12px; color: var(--muted); }

        /* ---------- INTERACTIVE MAP CARD ---------- */
        .map-card {
            display: flex;
            gap: 14px;
            align-items: center;
            margin-top: 14px;
            padding: 12px;
            border-radius: 18px;
            background: #fff;
            border: 1px solid var(--line);
            box-shadow: 0 4px 14px rgba(11, 59, 130, .07);
        }
        .mini-map {
            position: relative;
            flex: 0 0 104px;
            height: 104px;
            border-radius: 12px;
            background-color: #eaf1fb;
            background-image:
                linear-gradient(var(--line) 1px, transparent 1px),
                linear-gradient(90deg, var(--line) 1px, transparent 1px);
            background-size: 20px 20px;
            overflow: hidden;
        }
        .mini-map i { position: absolute; font-style: normal; font-size: 22px; line-height: 1; }
        .mini-map i:nth-child(1) { left: 16px; top: 14px; }
        .mini-map i:nth-child(2) { right: 18px; top: 36px; }
        .mini-map i:nth-child(3) { left: 40px; bottom: 10px; }
        .map-card h3 { margin: 0 0 4px; font-size: 16px; font-weight: 800; color: var(--navy-dark); }
        .map-card p { margin: 0; font-size: 13px; line-height: 1.45; color: var(--muted); }

        /* ---------- FEATURES ---------- */
        .section-head { margin: 30px 0 14px; }
        .section-head h2 { margin: 0 0 6px; font-size: 22px; font-weight: 800; color: var(--navy-dark); }
        .section-head p { margin: 0; font-size: 14px; line-height: 1.55; color: var(--muted); }

        .feature-list { display: grid; gap: 10px; }
        .feature {
            display: flex; gap: 14px; align-items: flex-start;
            padding: 14px;
            border-radius: 16px;
            background: #fff;
            border: 1px solid var(--line);
        }
        .feature .qicon { margin: 0; flex: 0 0 44px; font-size: 22px; }
        .feature h3 { margin: 0 0 3px; font-size: 15px; font-weight: 800; color: var(--navy-dark); }
        .feature p { margin: 0; font-size: 13px; line-height: 1.5; color: var(--muted); }

        /* ---------- ABOUT ---------- */
        .about {
            margin-top: 28px;
            padding: 22px 20px;
            border-radius: 22px;
            color: #fff;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%);
            scroll-margin-top: 80px;
        }
        .about h2 { margin: 0 0 10px; font-size: 24px; font-weight: 800; }
        .about p { margin: 0; font-size: 14px; line-height: 1.65; color: #dbe6f8; }

        footer { padding: 22px 16px 8px; text-align: center; font-size: 12px; color: var(--muted); }
        footer p { margin: 0; }

        /* ---------- BOTTOM NAV (app style) ---------- */
        .bottom-nav {
            position: fixed;
            left: 0; right: 0; bottom: 0;
            z-index: 60;
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            height: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px));
            padding-bottom: env(safe-area-inset-bottom, 0px);
            background: #fff;
            border-top: 1px solid var(--line);
            box-shadow: 0 -6px 18px rgba(11, 59, 130, .07);
        }
        .bottom-nav a {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #4a5b78;
        }
        .bottom-nav svg { width: 24px; height: 24px; }
        .bottom-nav a.active { color: var(--navy); }
        .bottom-nav a.active::after {
            content: "";
            position: absolute;
            bottom: 7px;
            width: 22px; height: 3px;
            border-radius: 3px;
            background: var(--yellow);
        }

        /* ---------- SEARCH SUGGESTIONS ---------- */
        .search-wrap { position: relative; }
        .suggest { display: none; position: absolute; left: 0; right: 0; top: 56px; z-index: 40; max-height: 300px; overflow-y: auto; background: #fff; color: var(--ink); border-radius: 14px; box-shadow: 0 12px 28px rgba(0,0,0,.3); }
        .suggest.open { display: block; }
        .sg-group { padding: 8px 14px; font-size: 11px; font-weight: 800; letter-spacing: .6px; color: var(--navy); background: var(--ice); }
        .sg-item { display: block; padding: 11px 14px; border-bottom: 1px solid var(--line); color: var(--ink); }
        .sg-item:last-child { border-bottom: 0; }
        .sg-item:active, .sg-item:hover { background: #f4f8ff; }
        .sg-item b { display: block; font-size: 14px; color: var(--navy-dark); overflow-wrap: anywhere; }
        .sg-item small { display: block; margin-top: 2px; font-size: 12px; color: var(--muted); }
        .sg-empty { padding: 14px; font-size: 13px; color: var(--muted); text-align: center; }

        /* ---------- LARGER SCREENS ---------- */
        @media (min-width: 769px) {
            body { padding-bottom: 0; }
            .bottom-nav { display: none; }
            .desktop-links { display: flex; }
            .topbar { padding-left: 5%; padding-right: 5%; }
            .page { max-width: 760px; padding-top: 28px; }
            .quick { grid-template-columns: repeat(4, 1fr); }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
            #cmu-loader { display: none; }
        }
    </style>
</head>

<body>

    <!-- LOADING SCREEN -->
    <div id="cmu-loader" role="status" aria-label="Loading CMU Campus Navigator">
        <img src="cmu logo.jpg" alt="CMU Logo">
        <p>CMU Campus Navigator</p>
        <div class="dots" aria-hidden="true"><span></span><span></span><span></span></div>
    </div>

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="brand">
            <img src="cmu logo.jpg" alt="CMU Logo">
            <div>
                <h1>CMU Campus Navigator</h1>
                <small>City of Malabon University</small>
            </div>
        </div>

        <nav class="desktop-links" aria-label="Main">
            <a href="index.php#home">Home</a>
            <a href="campus-map.php">Campus Map</a>
            <a href="admin/University-info.php">University Info</a>
            <a href="update_request.php">Feedback</a>
            <a href="index.php#about">About</a>
        </nav>
    </header>

    <main class="page" id="home">

        <!-- HERO -->
        <section class="hero">
            <span class="pill">CITY OF MALABON UNIVERSITY</span>

            <h2>Explore the CMU <span>Campus</span></h2>

            <p>Find buildings, rooms, offices, facilities, and other important locations around the university.</p>

            <div class="search-wrap">
            <form class="search" action="campus-map.php" method="get" role="search">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                <input type="search" name="search" placeholder="Search a building, room, or office" aria-label="Search a building, room, or office" autocomplete="off" enterkeyhint="search">
                <button type="submit" aria-label="Search">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
            </form>
            <div class="suggest" id="suggest" role="listbox"></div>
            </div>

            <div class="hero-actions">
                <a href="campus-map.php" class="btn btn-primary">Explore Campus</a>
                <a href="#about" class="btn btn-ghost">Learn More</a>
            </div>
        </section>

        <!-- QUICK ACCESS -->
        <section class="quick" aria-label="Quick access">
            <a href="campus-map.php" class="qcard">
                <div class="qicon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M3 6.5l6-2.5 6 2.5 6-2.5v13.5l-6 2.5-6-2.5-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/></svg>
                </div>
                <strong>Campus Map</strong>
                <span>Find any building</span>
            </a>

            <a href="admin/University-info.php" class="qcard">
                <div class="qicon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-5 9 5"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/></svg>
                </div>
                <strong>University Info</strong>
                <span>Mission, vision, and more</span>
            </a>

            <a href="update_request.php" class="qcard">
                <div class="qicon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/><path d="M8.5 10.5h.01M12 10.5h.01M15.5 10.5h.01"/></svg>
                </div>
                <strong>Feedback</strong>
                <span>Send a request</span>
            </a>

            <a href="#about" class="qcard">
                <div class="qicon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6c-2-1.5-5-2-8-1.5v13c3-.5 6 0 8 1.5 2-1.5 5-2 8-1.5v-13C17 4 14 4.5 12 6z"/><path d="M12 6v13"/></svg>
                </div>
                <strong>About</strong>
                <span>About this system</span>
            </a>
        </section>

        <!-- INTERACTIVE MAP -->
        <a href="campus-map.php" class="map-card" aria-label="Open the interactive CMU Campus Map">
            <div class="mini-map" aria-hidden="true"><i>📍</i><i>📍</i><i>📍</i></div>
            <div>
                <h3>Interactive Map</h3>
                <p>Explore campus locations and find buildings, rooms, and offices.</p>
            </div>
        </a>

        <!-- FEATURES -->
        <section id="map">
            <div class="section-head">
                <h2>Campus Information</h2>
                <p>Easily find the places and information you need inside CMU.</p>
            </div>

            <div class="feature-list">
                <div class="feature">
                    <div class="qicon">🏢</div>
                    <div>
                        <h3>Buildings</h3>
                        <p>View building information, rooms, offices, and other details available in each location.</p>
                    </div>
                </div>
                <div class="feature">
                    <div class="qicon">🔎</div>
                    <div>
                        <h3>Search &amp; Find</h3>
                        <p>Search for rooms, offices, facilities, departments, and other campus information.</p>
                    </div>
                </div>
                <div class="feature">
                    <div class="qicon">📷</div>
                    <div>
                        <h3>360° Virtual Tour</h3>
                        <p>Explore supported campus locations through available 360° virtual tour links.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ABOUT -->
        <section class="about" id="about">
            <h2>Navigate CMU with ease.</h2>
            <p>
                CMU Campus Navigator is a web-based campus navigation and information system designed to help
                students, visitors, faculty, and other users locate important places within City of Malabon University.
            </p>
        </section>

        <footer>
            <p>© 2026 CMU Campus Navigator | City of Malabon University</p>
        </footer>
    </main>

    <!-- BOTTOM NAV -->
    <nav class="bottom-nav" aria-label="Main">
        <a href="index.php#home" class="active" aria-current="page">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11l8-7 8 7"/><path d="M6 10v10h12V10"/></svg>
            Home
        </a>
        <a href="campus-map.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><path d="M3 6.5l6-2.5 6 2.5 6-2.5v13.5l-6 2.5-6-2.5-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/></svg>
            Map
        </a>
        <a href="admin/University-info.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/></svg>
            Info
        </a>
        <a href="update_request.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/></svg>
            Feedback
        </a>
        <a href="#about">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 12.5h8M8 16h8"/></svg>
            About
        </a>
    </nav>

    <script>
        // Hide loader as soon as the page is ready (CSS fallback also hides it after 2s)
        function hideLoader() {
            var loader = document.getElementById("cmu-loader");
            if (loader) loader.classList.add("loader-hidden");
        }
        document.addEventListener("DOMContentLoaded", function () { setTimeout(hideLoader, 800); });
        window.addEventListener("load", hideLoader);


        // Live search suggestions (buildings, rooms, offices, facilities)
        (function () {
            var items = <?= $searchItemsJson ?>;
            var input = document.querySelector(".search input");
            var box = document.getElementById("suggest");
            if (!input || !box) return;

            function make(tag, cls, text) {
                var n = document.createElement(tag);
                if (cls) n.className = cls;
                if (text !== undefined) n.textContent = text;
                return n;
            }
            function close() { box.classList.remove("open"); box.textContent = ""; }
            function url(i) {
                return "admin/floor-layout.php?building_id=" + encodeURIComponent(i.b) + "&floor=" + encodeURIComponent(i.floor);
            }
            function show() {
                var q = input.value.trim().toLowerCase();
                if (!q) { close(); return; }
                var found = items.filter(function (i) {
                    return (i.title + " " + i.meta).toLowerCase().indexOf(q) !== -1;
                }).slice(0, 8);

                box.textContent = "";
                if (!found.length) {
                    box.appendChild(make("div", "sg-empty", "No results for \u201C" + input.value.trim() + "\u201D"));
                } else {
                    var last = "";
                    found.forEach(function (i) {
                        var group = i.type === "Building" ? "BUILDINGS" : "ROOMS, OFFICES & FACILITIES";
                        if (group !== last) { box.appendChild(make("div", "sg-group", group)); last = group; }
                        var a = make("a", "sg-item");
                        a.href = url(i);
                        a.appendChild(make("b", "", i.title));
                        a.appendChild(make("small", "", i.meta));
                        box.appendChild(a);
                    });
                }
                box.classList.add("open");
            }
            input.addEventListener("input", show);
            input.addEventListener("focus", show);
            input.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
            document.addEventListener("click", function (e) {
                if (!e.target.closest(".search-wrap")) close();
            });
        })();

        // Highlight bottom-nav item for the section in view
        (function () {
            var links = document.querySelectorAll(".bottom-nav a");
            var about = document.getElementById("about");
            if (!about || !("IntersectionObserver" in window)) return;
            new IntersectionObserver(function (entries) {
                var inAbout = entries[0].isIntersecting;
                links.forEach(function (a) { a.classList.remove("active"); });
                links[inAbout ? 4 : 0].classList.add("active");
            }, { threshold: 0.4 }).observe(about);
        })();
    </script>
</body>
</html>