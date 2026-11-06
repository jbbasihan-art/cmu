<?php
session_start();

require_once __DIR__ . '/config/database.php';

function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$categories = [
    'Wrong or Outdated Information',
    'Map or Location Error',
    'Room / Office / Facility Issue',
    'Bug or Technical Problem',
    'Suggestion',
    'Compliment',
    'Other'
];

/* ---------- Buildings for the "Related Building" dropdown ---------- */
$buildingOptions = [];
try {
    $res = $conn->query("SELECT building_id, building_name FROM buildings ORDER BY building_name ASC");
    foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $name = trim((string)$b['building_name']);
        // Skip map-only elements (grounds, courts, parking, borders)
        if (preg_match('/ground|court|parking|spiked|border|boundary/i', $name)) continue;
        $buildingOptions[(int)$b['building_id']] = $name;
    }
} catch (Throwable $ex) {
    error_log('Feedback buildings query error: ' . $ex->getMessage());
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$old = [
    'name' => '', 'student_number' => '', 'email' => '', 'course_section' => '',
    'category' => '', 'building_id' => 0, 'location_details' => '',
    'rating' => 0, 'message' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name']             = trim((string)($_POST['student_name'] ?? ''));
    $old['student_number']   = trim((string)($_POST['student_number'] ?? ''));
    $old['email']            = trim((string)($_POST['email'] ?? ''));
    $old['course_section']   = trim((string)($_POST['course_section'] ?? ''));
    $old['category']         = trim((string)($_POST['category'] ?? ''));
    $old['building_id']      = (int)($_POST['building_id'] ?? 0);
    $old['location_details'] = trim((string)($_POST['location_details'] ?? ''));
    $old['rating']           = (int)($_POST['rating'] ?? 0);
    $old['message']          = trim((string)($_POST['message'] ?? ''));

    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        // Honeypot filled in: a bot. Pretend success.
        header('Location: update_request.php?submitted=1');
        exit;
    } else {
        if ($old['name'] === '' || mb_strlen($old['name']) > 150) {
            $errors[] = 'Please enter your full name (up to 150 characters).';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 190) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (mb_strlen($old['student_number']) > 50) {
            $errors[] = 'Student number is too long (up to 50 characters).';
        }
        if (mb_strlen($old['course_section']) > 100) {
            $errors[] = 'Course & section is too long (up to 100 characters).';
        }
        if (!in_array($old['category'], $categories, true)) {
            $errors[] = 'Please choose a category.';
        }
        if ($old['building_id'] !== 0 && !isset($buildingOptions[$old['building_id']])) {
            $errors[] = 'Please choose a valid building.';
        }
        if (mb_strlen($old['location_details']) > 150) {
            $errors[] = 'Room / Floor / Area is too long (up to 150 characters).';
        }
        if ($old['rating'] < 0 || $old['rating'] > 5) {
            $errors[] = 'Rating must be between 1 and 5 stars.';
        }
        if (mb_strlen($old['message']) < 10 || mb_strlen($old['message']) > 3000) {
            $errors[] = 'Your feedback must be between 10 and 3000 characters.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO feedback
                    (student_name, student_number, email, course_section,
                     category, rating, building_id, location_details,
                     message, status, is_archived, created_at, updated_at)
                 VALUES
                    (:student_name, :student_number, :email, :course_section,
                     :category, :rating, :building_id, :location_details,
                     :message, 'Pending', FALSE, NOW(), NOW())
                 RETURNING feedback_id"
            );

            $nullable = function ($v) { return $v === '' ? null : $v; };

            $stmt->bindValue(':student_name', $old['name']);
            $stmt->bindValue(':student_number', $nullable($old['student_number']));
            $stmt->bindValue(':email', $old['email']);
            $stmt->bindValue(':course_section', $nullable($old['course_section']));
            $stmt->bindValue(':category', $old['category']);
            $stmt->bindValue(':rating', $old['rating'] > 0 ? $old['rating'] : null,
                $old['rating'] > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':building_id', $old['building_id'] > 0 ? $old['building_id'] : null,
                $old['building_id'] > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':location_details', $nullable($old['location_details']));
            $stmt->bindValue(':message', $old['message']);
            $stmt->execute();

            $saved = $stmt->fetch(PDO::FETCH_ASSOC);

            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['feedback_id'] = (int)$saved['feedback_id'];

            header('Location: update_request.php?submitted=1');
            exit;

        } catch (Throwable $ex) {
            error_log('Feedback save error: ' . $ex->getMessage());
            $errors[] = 'Sorry, we could not save your feedback. Please try again later.';
        }
    }
}

$submitted = isset($_GET['submitted']);
$feedbackId = $submitted ? ($_SESSION['feedback_id'] ?? null) : null;
if ($submitted) {
    unset($_SESSION['feedback_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title>Student Feedback | CMU Campus Navigator</title>

<style>
:root {
    --navy: #0b3b82;
    --navy-dark: #072a5c;
    --yellow: #ffd23f;
    --ice: #edf3fc;
    --muted: #5f6f89;
    --line: #e3eaf5;
    --nav-h: 66px;
}

* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f3f6fb;
    color: #172b4d;
    overflow-x: hidden;
}

/* ---------- NAVBAR ---------- */
.navbar {
    min-height: 86px;
    padding: 14px 4%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: #fff;
    border-bottom: 1px solid #dce4ef;
    box-shadow: 0 2px 8px rgba(20, 45, 80, .06);
}
.logo { display: flex; align-items: center; gap: 13px; min-width: 0; }
.logo img { width: 54px; height: 54px; object-fit: contain; flex: 0 0 auto; }
.logo h2 { margin: 0 0 5px; color: var(--navy); font-size: 21px; }
.logo span { color: #52627a; font-size: 13px; }

.nav-links { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.nav-links a {
    padding: 11px 16px; border-radius: 7px; text-decoration: none;
    font-size: 14px; font-weight: 700; color: var(--navy); background: var(--ice);
}
.nav-links a.active, .nav-links a.nav-admin { color: #fff; background: var(--navy); }
.nav-links a:hover { filter: brightness(.95); }

.bottom-nav { display: none; }

/* ---------- PAGE ---------- */
main { max-width: 1200px; margin: 0 auto; padding: 34px 24px 40px; }

.page-heading { margin-bottom: 26px; }
.eyebrow { color: #805900; font-size: 12px; font-weight: 800; letter-spacing: 1.5px; }
.page-heading h1 { margin: 9px 0; color: var(--navy); font-size: 34px; }
.page-heading p { max-width: 700px; margin: 0; color: #52627a; line-height: 1.7; }

.layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 24px;
    align-items: start;
}

.panel {
    min-width: 0;
    padding: 28px;
    background: #fff;
    border: 1px solid #d8e1ee;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(11, 59, 130, .05);
}
.panel h2 { margin: 0 0 6px; color: var(--navy); font-size: 22px; }
.panel-sub { margin: 0 0 22px; color: #52627a; font-size: 14px; line-height: 1.6; }

/* ---------- FORM ---------- */
.two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.field { margin-bottom: 18px; min-width: 0; }
.field label { display: block; margin-bottom: 7px; color: #43536b; font-size: 14px; font-weight: 700; }
.field label .opt { font-weight: 400; color: #68758a; }

.field input,
.field select,
.field textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #cbd6e5;
    border-radius: 7px;
    background: #fff;
    color: #172b4d;
    font: inherit;
    font-size: 15px;
}
.field select { min-height: 46px; }
.field textarea { min-height: 150px; resize: vertical; line-height: 1.6; }
.field input:focus, .field select:focus, .field textarea:focus {
    outline: 2px solid #d6e5fb; border-color: var(--navy);
}

.field-foot { display: flex; justify-content: space-between; gap: 10px; margin-top: 6px; color: #68758a; font-size: 12px; }
.counter.warn { color: #b42318; font-weight: 700; }

/* Star rating */
.stars { display: flex; gap: 4px; }
.star {
    width: 44px; height: 44px; padding: 0;
    border: 0; background: transparent; cursor: pointer;
    font-size: 36px; line-height: 1; color: #cbd6e5;
}
.star.on { color: #f5b301; }

.hp { position: absolute; left: -9999px; height: 0; overflow: hidden; }

.actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 6px; }
.btn {
    display: inline-block; padding: 14px 24px; border: 0; border-radius: 7px;
    background: var(--navy); color: #fff; text-decoration: none;
    font: inherit; font-size: 15px; font-weight: 700; cursor: pointer; text-align: center;
}
.btn:hover { background: #082d63; }
.btn:disabled { opacity: .6; cursor: not-allowed; }
.btn-light { background: var(--ice); color: var(--navy); }
.btn-light:hover { background: #dce8fa; }

.privacy { margin: 16px 0 0; color: #68758a; font-size: 12px; line-height: 1.6; }

/* ---------- NOTICES ---------- */
.notice { padding: 14px 16px; margin-bottom: 20px; border-radius: 8px; line-height: 1.6; font-size: 14px; }
.notice.error { background: #fff0ee; border: 1px solid #f2c3bd; color: #a1261c; }
.notice ul { margin: 0; padding-left: 18px; }

/* ---------- SUCCESS ---------- */
.success-box { text-align: center; padding: 30px 10px; }
.success-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 72px; height: 72px; margin-bottom: 16px; border-radius: 50%;
    background: #edf8ef; border: 2px solid #b9e2c0; color: #216e39; font-size: 34px;
}
.success-box h2 { margin-bottom: 10px; font-size: 26px; }
.success-box p { max-width: 460px; margin: 0 auto 8px; color: #52627a; line-height: 1.7; }
.request-id {
    display: inline-block; margin: 12px 0 22px; padding: 10px 18px; border-radius: 8px;
    background: #fffbea; border: 1px solid #f0dc8a; color: #684d00;
    font-weight: 800; letter-spacing: .5px;
}

/* ---------- SUCCESS POPUP ---------- */
.modal-backdrop {
    position: fixed; top: 0; right: 0; bottom: 0; left: 0; z-index: 100;
    display: flex; align-items: center; justify-content: center;
    padding: 16px; background: rgba(7, 26, 62, .5);
    animation: fadeIn .2s ease;
}
.modal-backdrop[hidden] { display: none; }
.modal-card {
    width: 100%; max-width: 560px; max-height: 100%; overflow-y: auto;
    padding: 40px 28px 36px; text-align: center;
    background: #fff; border: 1px solid #d8e1ee; border-radius: 16px;
    box-shadow: 0 24px 60px rgba(7, 26, 62, .35);
    animation: popIn .25s ease;
}
.modal-card .success-icon { margin-bottom: 14px; }
.modal-card h2 { margin: 0 0 10px; color: var(--navy); font-size: 24px; }
.modal-card p { max-width: 420px; margin: 0 auto 8px; color: #52627a; font-size: 14px; line-height: 1.8; }
.modal-card .request-id { margin: 14px 0 18px; }
.modal-card .modal-actions {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 22px;
}
.modal-card .modal-actions .btn { padding: 13px 20px; font-size: 14px; width: auto; min-height: 0; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes popIn { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }

@media (max-width: 480px) {
    .modal-card { padding: 32px 18px 24px; }
    .modal-card h2 { font-size: 22px; }
    .modal-card .modal-actions { flex-direction: column; }
    .modal-card .modal-actions .btn { width: 100%; min-height: 48px; }
}

/* ---------- SIDE ---------- */
.side { display: flex; flex-direction: column; gap: 20px; }
.side .panel { padding: 22px; margin: 0; }
.side h3 { margin: 0 0 14px; color: var(--navy); font-size: 18px; }
.status-list { display: flex; flex-direction: column; gap: 10px; }
.status-item { display: flex; align-items: center; gap: 10px; color: #43536b; font-size: 14px; }
.badge {
    display: inline-block; min-width: 82px; padding: 4px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 700; text-align: center;
}
.badge.pending { background: #fff8d8; color: #805900; }
.badge.progress { background: #dce9fb; color: var(--navy); }
.badge.done { background: #d1e7dd; color: #146c43; }

footer { padding: 22px; text-align: center; background: #fff; border-top: 1px solid #dce4ef; }
footer p { margin: 0; color: #52627a; font-size: 13px; }

/* ---------- TABLET ---------- */
@media (max-width: 980px) {
    .layout { grid-template-columns: minmax(0, 1fr); }
}

/* ---------- MOBILE (app style) ---------- */
@media (max-width: 768px) {
    body { padding-bottom: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px) + 12px); }

    .navbar {
        position: sticky; top: 0; z-index: 50; min-height: 0;
        padding: calc(10px + env(safe-area-inset-top, 0px)) 18px 10px;
        box-shadow: none;
    }
    .nav-links { display: none; }
    .logo { gap: 12px; }
    .logo img { width: 46px; height: 46px; }
    .logo h2 { margin: 0; font-size: 19px; line-height: 1.15; font-weight: 800; }
    .logo span { display: block; margin-top: 3px; font-size: 11.5px; color: var(--muted); }

    main { padding: 18px 14px 26px; }
    .page-heading { margin-bottom: 18px; }
    .page-heading h1 { font-size: 28px; }
    .page-heading p { font-size: 14px; line-height: 1.6; }

    .panel { padding: 20px 16px; }
    .side .panel { padding: 18px 16px; }
    .layout { gap: 18px; }

    .two { grid-template-columns: 1fr; gap: 0; }

    /* 16px inputs stop iOS from zooming when a field is focused */
    .field input, .field select, .field textarea { font-size: 16px; }

    .star { width: 52px; height: 52px; font-size: 40px; }

    .actions { flex-direction: column-reverse; align-items: stretch; }
    .btn { width: 100%; min-height: 50px; }

    .bottom-nav {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 60;
        display: grid; grid-template-columns: repeat(4, 1fr);
        height: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px));
        padding-bottom: env(safe-area-inset-bottom, 0px);
        background: #fff; border-top: 1px solid var(--line);
        box-shadow: 0 -6px 18px rgba(11, 59, 130, .07);
    }
    .bottom-nav a {
        position: relative;
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
        font-size: 11px; font-weight: 700; color: #4a5b78; text-decoration: none;
    }
    .bottom-nav svg { width: 24px; height: 24px; }
    .bottom-nav a.active { color: var(--navy); }
    .bottom-nav a.active::after {
        content: ""; position: absolute; bottom: 7px;
        width: 22px; height: 3px; border-radius: 3px; background: var(--yellow);
    }
}

@media (max-width: 360px) {
    .star { width: 46px; height: 46px; font-size: 36px; }
}
</style>
</head>

<body>

<header class="navbar">
    <div class="logo">
        <img src="cmu%20logo.jpg" alt="CMU Logo">
        <div>
            <h2>CMU Campus Navigator</h2>
            <span>City of Malabon University</span>
        </div>
    </div>

    <nav class="nav-links">
        <a href="index.php#home">Home</a>
        <a href="campus-map.php">Campus Map</a>
        <a href="admin/University-info.php">University Info</a>
        <a href="update_request.php" class="active">Feedback</a>
        <a href="admin/login.php" class="nav-admin">Admin Login</a>
    </nav>
</header>

<main>

    <section class="page-heading">
        <span class="eyebrow">HELP US IMPROVE</span>
        <h1>Student Feedback</h1>
        <p>
            Tell us about your experience with the CMU Campus Navigator, report wrong
            or outdated campus information, or share a suggestion. The administrator
            reads every submission.
        </p>
    </section>

    <div class="layout">

        <!-- MAIN COLUMN -->
        <section class="panel">

                <h2>Feedback Form</h2>
                <p class="panel-sub">Fields marked with * are required.</p>

                <?php if ($errors): ?>
                    <div class="notice error">
                        <ul>
                            <?php foreach ($errors as $err): ?>
                                <li><?= h($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="update_request.php" id="feedbackForm" novalidate>

                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="rating" id="rating" value="<?= (int)$old['rating'] ?>">

                    <!-- Honeypot: real users never see or fill this -->
                    <div class="hp" aria-hidden="true">
                        <label>Website
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </label>
                    </div>

                    <div class="two">
                        <div class="field">
                            <label for="student_name">Full Name *</label>
                            <input type="text" id="student_name" name="student_name" maxlength="150" required
                                   placeholder="Juan Dela Cruz" autocomplete="name"
                                   value="<?= h($old['name']) ?>">
                        </div>
                        <div class="field">
                            <label for="email">Email Address *</label>
                            <input type="email" id="email" name="email" maxlength="190" required
                                   placeholder="you@example.com" autocomplete="email" inputmode="email"
                                   value="<?= h($old['email']) ?>">
                        </div>
                    </div>

                    <div class="two">
                        <div class="field">
                            <label for="student_number">Student Number <span class="opt">(optional)</span></label>
                            <input type="text" id="student_number" name="student_number" maxlength="50"
                                   placeholder="e.g. 2024-00123"
                                   value="<?= h($old['student_number']) ?>">
                        </div>
                        <div class="field">
                            <label for="course_section">Course &amp; Section <span class="opt">(optional)</span></label>
                            <input type="text" id="course_section" name="course_section" maxlength="100"
                                   placeholder="e.g. BSIT 2-A"
                                   value="<?= h($old['course_section']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label for="category">Category *</label>
                        <select id="category" name="category" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= h($cat) ?>" <?= $old['category'] === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="two">
                        <div class="field">
                            <label for="building_id">Related Building <span class="opt">(optional)</span></label>
                            <select id="building_id" name="building_id">
                                <option value="0">Not about a specific building</option>
                                <?php foreach ($buildingOptions as $bid => $bname): ?>
                                    <option value="<?= (int)$bid ?>" <?= $old['building_id'] === $bid ? 'selected' : '' ?>><?= h($bname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="location_details">Room / Floor / Area <span class="opt">(optional)</span></label>
                            <input type="text" id="location_details" name="location_details" maxlength="150"
                                   placeholder="e.g. Room 104, 1st Floor"
                                   value="<?= h($old['location_details']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label>How helpful is the Campus Navigator? <span class="opt">(optional)</span></label>
                        <div class="stars" id="stars" role="radiogroup" aria-label="Rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="button" class="star" data-value="<?= $i ?>" aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</button>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="message">Your Feedback *</label>
                        <textarea id="message" name="message" maxlength="3000" required
                                  placeholder="Describe your experience, what is wrong or missing, or what we can improve."><?= h($old['message']) ?></textarea>
                        <div class="field-foot">
                            <span>Minimum 10 characters.</span>
                            <span class="counter" id="counter">0 / 3000</span>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn" id="submitBtn">Submit Feedback</button>
                        <a href="campus-map.php" class="btn btn-light">Cancel</a>
                    </div>

                    <p class="privacy">
                        Your name and email are only used so the administrator can follow up on your feedback.
                    </p>

                </form>

        </section>

        <!-- SIDE COLUMN -->
        <aside class="side">
            <section class="panel">
                <h3>Feedback status</h3>
                <div class="status-list">
                    <div class="status-item"><span class="badge pending">Pending</span> Waiting for review</div>
                    <div class="status-item"><span class="badge progress">In Progress</span> Being worked on</div>
                    <div class="status-item"><span class="badge done">Done</span> Resolved by the admin</div>
                </div>
            </section>
        </aside>

    </div>

</main>

<footer>
    <p>© <?= date('Y') ?> CMU Campus Navigator | City of Malabon University</p>
</footer>

<?php if ($submitted): ?>
<!-- SUCCESS POPUP -->
<div class="modal-backdrop" id="successModal" role="dialog" aria-modal="true" aria-labelledby="successTitle">
    <div class="modal-card">
        <div class="success-icon">✓</div>
        <h2 id="successTitle">Feedback Submitted</h2>
        <p>Thank you for helping us improve the CMU Campus Navigator.</p>

        <?php if ($feedbackId): ?>
            <div class="request-id">Reference No.: FB-<?= str_pad((string)(int)$feedbackId, 5, '0', STR_PAD_LEFT) ?></div>
        <?php endif; ?>

        <p>The administrator will review your feedback. Keep the reference number if you need to follow up.</p>

        <div class="modal-actions">
            <a href="update_request.php" class="btn">Submit Another Feedback</a>
            <a href="campus-map.php" class="btn btn-light">Back to Campus Map</a>
        </div>
    </div>
</div>
<?php endif; ?>

<nav class="bottom-nav" aria-label="Main">
    <a href="index.php#home">
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
    <a href="update_request.php" class="active" aria-current="page">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/></svg>
        Feedback
    </a>
</nav>

<script>
const message = document.getElementById("message");
const counter = document.getElementById("counter");
const form = document.getElementById("feedbackForm");
const submitBtn = document.getElementById("submitBtn");
const ratingInput = document.getElementById("rating");
const starBtns = document.querySelectorAll("#stars .star");

function updateCounter() {
    const length = message.value.length;
    counter.textContent = length + " / 3000";
    counter.classList.toggle("warn", length > 0 && length < 10);
}
if (message && counter) {
    message.addEventListener("input", updateCounter);
    updateCounter();
}

/* Star rating: tap a star to set it, tap the same star again to clear */
function paintStars(value) {
    starBtns.forEach(function (b) {
        b.classList.toggle("on", Number(b.dataset.value) <= value);
    });
}
if (ratingInput && starBtns.length) {
    paintStars(Number(ratingInput.value) || 0);
    starBtns.forEach(function (btn) {
        btn.addEventListener("click", function () {
            const v = Number(btn.dataset.value);
            const next = Number(ratingInput.value) === v ? 0 : v;
            ratingInput.value = next;
            paintStars(next);
        });
    });
}

/* Success popup: close with Escape or by tapping outside the card.
   The ?submitted flag is removed so a refresh does not show it again. */
const successModal = document.getElementById("successModal");
if (successModal) {
    document.body.style.overflow = "hidden";
    function closeModal() {
        successModal.hidden = true;
        document.body.style.overflow = "";
        history.replaceState(null, "", "update_request.php");
    }
    successModal.addEventListener("click", function (e) {
        if (e.target === successModal) closeModal();
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") closeModal();
    });
}

/* Basic checks before sending, and stop double submits */
if (form && submitBtn) {
    form.addEventListener("submit", function (event) {
        const name = form.student_name.value.trim();
        const email = form.email.value.trim();
        const category = form.category.value;
        const text = form.message.value.trim();
        const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

        if (!name || !emailOk || !category || text.length < 10) {
            event.preventDefault();
            alert("Please enter your name, a valid email, choose a category, and write your feedback (at least 10 characters).");
            return;
        }
        submitBtn.disabled = true;
        submitBtn.textContent = "Submitting...";
    });
}
</script>

</body>
</html>
