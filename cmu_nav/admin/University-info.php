<?php
/*
 * University Info (public page)
 * Location: hackaton/admin/University-info.php
 * No login check, so anyone can open it from the main navigation.
 */

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$universityName = "City of Malabon University";

$overview = "City of Malabon University (CMU) is a local university serving "
          . "the community of Malabon City. Replace this paragraph with the "
          . "official description of the university.";

$sealItems = [
    "The Shield" => "A symbol for solidarity and honor, this design element rendered in an emblematic shape of a notched shield, encloses the other elements within bold blue lines that connote emphasis and significance.",
    "The Torch" => "The lighted torch symbolizes the burning passion one must have for acquiring knowledge and for honing skills.",
    "The Globe" => "The globe with grids represents the ambition for global standards for education and production of globally competitive students. Its orbiting illusion stands for its continuous desire to do so.",
    "The Book and Academic Cap" => "An icon assembled from a combined stencil of an academic cap and a book set to remind one to hold education and achievement in high regard.",
    "The School of Fish" => "The School of Fish represents one of Malabon City’s main industry. It also symbolizes the roots of our students and the foundation of their existence."
];

$vision = "A leading university that is committed for the advancement of human lives and global competitiveness through academic excellence, research and community linkages.";

$mission = "CMU aims to provide transformative and globally competitive higher education, holistic formation, research and innovation, excellent delivery of services, and sustainable community extension and linkages.";

// Core values: CMU group (first three) and AHON group (last four)
$coreValues = [
    ["letter" => "C", "word" => "Competent",       "group" => "cmu"],
    ["letter" => "M", "word" => "Morally upright", "group" => "cmu"],
    ["letter" => "U", "word" => "United",          "group" => "cmu"],
    ["letter" => "A", "word" => "Adaptable",       "group" => "ahon"],
    ["letter" => "H", "word" => "Honest",          "group" => "ahon"],
    ["letter" => "O", "word" => "Optimistic",      "group" => "ahon"],
    ["letter" => "N", "word" => "Nationalistic",   "group" => "ahon"]
];

$valueGroups = ["cmu" => [], "ahon" => []];
foreach ($coreValues as $v) {
    $valueGroups[$v["group"]][] = $v;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>University Info | CMU Campus Navigator</title>

    <!-- Self-contained: does not depend on style.css or mobile.css -->
    <style>
        :root {
            --navy: #0b3b82;
            --navy-dark: #072a5c;
            --yellow: #ffd23f;
            --gold: #805900;
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
            position: fixed; inset: 0; z-index: 99999;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: #fff;
            animation: loader-out .4s ease 2s forwards;
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
            position: sticky; top: 0; z-index: 50;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: calc(10px + env(safe-area-inset-top, 0px)) 18px 10px;
            background: #fff; border-bottom: 1px solid var(--line);
        }
        .brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .brand img { width: 46px; height: 46px; object-fit: contain; flex: 0 0 46px; }
        .brand h1 { margin: 0; font-size: 19px; line-height: 1.15; font-weight: 800; color: var(--navy); }
        .brand small { display: block; margin-top: 3px; font-size: 11.5px; color: var(--muted); }

        .desktop-links { display: none; gap: 8px; flex-wrap: wrap; }
        .desktop-links a { padding: 10px 14px; border-radius: 8px; font-size: 14px; font-weight: 700; color: var(--navy); background: var(--ice); }
        .desktop-links a.active, .desktop-links a.admin-btn { color: #fff; background: var(--navy); }

        .page { max-width: 560px; margin: 0 auto; padding: 14px 14px 0; }

        /* ---------- HERO ---------- */
        .hero {
            position: relative;
            border-radius: 26px;
            padding: 22px 20px 22px;
            color: #fff;
            background:
                linear-gradient(180deg, rgba(8,32,76,.5) 0%, rgba(8,32,76,.88) 62%, rgba(7,26,62,.97) 100%),
                url("../cmu-new-building-inspection.webp") center / cover no-repeat,
                var(--navy-dark);
            box-shadow: 0 14px 30px rgba(11, 59, 130, .28);
        }
        .hero-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .pill {
            display: inline-block; padding: 7px 14px; border-radius: 999px;
            background: var(--yellow); color: var(--navy-dark);
            font-size: 11px; font-weight: 800; letter-spacing: .6px;
        }
        .seal-badge {
            width: 62px; height: 62px; flex: 0 0 62px; padding: 4px;
            object-fit: contain; background: #fff; border: 3px solid var(--yellow); border-radius: 50%;
        }
        .hero h2 { margin: 36px 0 10px; font-size: clamp(28px, 8.4vw, 36px); line-height: 1.08; font-weight: 900; letter-spacing: -.4px; }
        .hero h2 span { display: block; color: var(--yellow); }
        .hero p { margin: 0 0 20px; font-size: 14px; line-height: 1.65; color: #e4ecfa; }
        .btn {
            display: flex; align-items: center; justify-content: center;
            min-height: 50px; border-radius: 14px;
            background: var(--yellow); color: var(--navy-dark);
            font-size: 14.5px; font-weight: 800;
        }
        .btn:active { transform: scale(.98); }

        /* ---------- SECTIONS ---------- */
        .section-head { margin: 30px 0 14px; }
        .section-head h2 { margin: 0 0 5px; font-size: 22px; font-weight: 800; color: var(--navy-dark); }
        .section-head p { margin: 0; font-size: 13.5px; line-height: 1.55; color: var(--muted); }

        /* Seal */
        .seal-list { display: grid; gap: 10px; }
        .seal-card {
            display: flex; gap: 14px; align-items: flex-start;
            padding: 14px; border-radius: 18px;
            background: #fff; border: 1px solid var(--line);
            box-shadow: 0 4px 14px rgba(11, 59, 130, .06);
        }
        .seal-no {
            flex: 0 0 40px; width: 40px; height: 40px;
            display: grid; place-items: center;
            border-radius: 12px; background: var(--ice); color: var(--navy);
            font-size: 14px; font-weight: 800;
        }
        .seal-card h3 { margin: 2px 0 4px; font-size: 15.5px; font-weight: 800; color: var(--navy-dark); }
        .seal-card p { margin: 0; font-size: 13px; line-height: 1.6; color: #3a4a63; }

        /* Vision & mission */
        .vm-card { padding: 20px; border-radius: 22px; margin-bottom: 12px; }
        .vm-card h3 { margin: 0 0 10px; font-size: 12px; font-weight: 800; letter-spacing: 1.6px; }
        .vm-card p { margin: 0; font-size: 15px; line-height: 1.7; }
        .vm-card.vision { color: #fff; background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%); box-shadow: 0 12px 26px rgba(11, 59, 130, .22); }
        .vm-card.vision h3 { color: var(--yellow); }
        .vm-card.vision p { color: #e9f0fc; }
        .vm-card.mission { background: #fff8dc; border: 1px solid #efdca0; }
        .vm-card.mission h3 { color: var(--gold); }
        .vm-card.mission p { color: #2b3a52; }

        /* Core values */
        .value-card {
            margin-bottom: 12px; padding: 16px; border-radius: 20px;
            background: #fff; border: 1px solid var(--line);
            box-shadow: 0 4px 14px rgba(11, 59, 130, .06);
        }
        .value-card > h3 { margin: 0 0 12px; font-size: 12px; font-weight: 800; letter-spacing: 1.6px; }
        .value-card.cmu > h3 { color: var(--navy); }
        .value-card.ahon > h3 { color: var(--gold); }
        .value-row { display: flex; align-items: center; gap: 14px; padding: 9px 0; border-top: 1px solid var(--line); }
        .value-row:first-of-type { border-top: 0; }
        .value-letter {
            flex: 0 0 44px; width: 44px; height: 44px;
            display: grid; place-items: center;
            border-radius: 50%; color: #fff; font-size: 20px; font-weight: 800;
        }
        .value-card.cmu .value-letter { background: var(--navy); }
        .value-card.ahon .value-letter { background: var(--gold); }
        .value-word { font-size: 15.5px; font-weight: 700; color: var(--ink); }

        footer { padding: 22px 16px 8px; text-align: center; font-size: 12px; color: var(--muted); }
        footer p { margin: 0; }

        /* ---------- BOTTOM NAV (app style) ---------- */
        .bottom-nav {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 60;
            display: grid; grid-template-columns: repeat(5, 1fr);
            height: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px));
            padding-bottom: env(safe-area-inset-bottom, 0px);
            background: #fff; border-top: 1px solid var(--line);
            box-shadow: 0 -6px 18px rgba(11, 59, 130, .07);
        }
        .bottom-nav a {
            position: relative;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
            font-size: 11px; font-weight: 700; color: #4a5b78;
        }
        .bottom-nav svg { width: 24px; height: 24px; }
        .bottom-nav a.active { color: var(--navy); }
        .bottom-nav a.active::after {
            content: ""; position: absolute; bottom: 7px;
            width: 22px; height: 3px; border-radius: 3px; background: var(--yellow);
        }

        /* ---------- LARGER SCREENS ---------- */
        @media (min-width: 769px) {
            body { padding-bottom: 0; }
            .bottom-nav { display: none; }
            .desktop-links { display: flex; }
            .topbar { padding-left: 5%; padding-right: 5%; }
            .page { max-width: 760px; padding-top: 28px; }
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
        <img src="../cmu logo.jpg" alt="CMU Logo">
        <p>CMU Campus Navigator</p>
        <div class="dots" aria-hidden="true"><span></span><span></span><span></span></div>
    </div>

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="brand">
            <img src="../cmu logo.jpg" alt="CMU Logo">
            <div>
                <h1>CMU Campus Navigator</h1>
                <small>City of Malabon University</small>
            </div>
        </div>

        <nav class="desktop-links" aria-label="Main">
            <a href="../index.php#home">Home</a>
            <a href="../campus-map.php">Campus Map</a>
            <a href="University-info.php" class="active">University Info</a>
            <a href="../update_request.php">Feedback</a>
            <a href="../index.php#about">About</a>
            <a href="login.php" class="admin-btn">Admin Login</a>
        </nav>
    </header>

    <main class="page">

        <!-- HERO -->
        <section class="hero" id="home">
            <div class="hero-top">
                <span class="pill">UNIVERSITY INFORMATION</span>
                <img src="../cmu logo.jpg" alt="City of Malabon University seal" class="seal-badge">
            </div>

            <h2>About <span><?= h($universityName) ?></span></h2>

            <p><?= h($overview) ?></p>

            <a href="../campus-map.php" class="btn">Explore Campus</a>
        </section>

        <!-- UNIVERSITY SEAL -->
        <section>
            <div class="section-head">
                <h2>University Seal</h2>
                <p>The meaning behind each symbol of the CMU seal.</p>
            </div>

            <div class="seal-list">
                <?php $n = 1; foreach ($sealItems as $title => $text): ?>
                    <article class="seal-card">
                        <span class="seal-no"><?= str_pad((string)$n++, 2, "0", STR_PAD_LEFT) ?></span>
                        <div>
                            <h3><?= h($title) ?></h3>
                            <p><?= h($text) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- VISION & MISSION -->
        <section>
            <div class="section-head">
                <h2>Vision &amp; Mission</h2>
            </div>

            <article class="vm-card vision">
                <h3>VISION</h3>
                <p><?= h($vision) ?></p>
            </article>

            <article class="vm-card mission">
                <h3>MISSION</h3>
                <p><?= h($mission) ?></p>
            </article>
        </section>

        <!-- CORE VALUES -->
        <section>
            <div class="section-head">
                <h2>Core Values</h2>
                <p>The CMU and AHON values every student carries.</p>
            </div>

            <?php foreach ($valueGroups as $group => $items): ?>
                <article class="value-card <?= h($group) ?>">
                    <h3><?= h(strtoupper($group)) ?></h3>
                    <?php foreach ($items as $value): ?>
                        <div class="value-row">
                            <span class="value-letter"><?= h($value["letter"]) ?></span>
                            <span class="value-word"><?= h($value["word"]) ?></span>
                        </div>
                    <?php endforeach; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <footer>
            <p>© <?= date("Y") ?> CMU Campus Navigator | City of Malabon University</p>
        </footer>
    </main>

    <!-- BOTTOM NAV -->
    <nav class="bottom-nav" aria-label="Main">
        <a href="../index.php#home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11l8-7 8 7"/><path d="M6 10v10h12V10"/></svg>
            Home
        </a>
        <a href="../campus-map.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><path d="M3 6.5l6-2.5 6 2.5 6-2.5v13.5l-6 2.5-6-2.5-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/></svg>
            Map
        </a>
        <a href="University-info.php" class="active" aria-current="page">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/></svg>
            Info
        </a>
        <a href="../update_request.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/></svg>
            Feedback
        </a>
        <a href="../index.php#about">
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
    </script>

</body>
</html>