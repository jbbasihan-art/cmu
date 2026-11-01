<?php
session_start();

require_once __DIR__ . '/config/database.php';

function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$categories = [
    'Building Information',
    'Room / Office / Facility',
    'Map or Location Error',
    'Contact Details',
    'Operating Hours',
    'Other'
];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* Optional: update-request.php?info_id=5 links the request to a record */
$infoId = isset($_GET['info_id']) ? (int)$_GET['info_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $infoId = (int)($_POST['info_id'] ?? 0);
}

$errors = [];
$old = ['name' => '', 'email' => '', 'category' => '', 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name']        = trim((string)($_POST['requester_name'] ?? ''));
    $old['email']       = trim((string)($_POST['requester_email'] ?? ''));
    $old['category']    = trim((string)($_POST['category'] ?? ''));
    $old['description'] = trim((string)($_POST['description'] ?? ''));

    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        /* Honeypot filled in: a bot. Pretend success. */
        header('Location: update-request.php?submitted=1');
        exit;
    } else {
        if ($old['name'] === '' || mb_strlen($old['name']) > 150) {
            $errors[] = 'Please enter your name (up to 150 characters).';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 190) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!in_array($old['category'], $categories, true)) {
            $errors[] = 'Please choose a category.';
        }
        if (mb_strlen($old['description']) < 10 || mb_strlen($old['description']) > 3000) {
            $errors[] = 'Description must be between 10 and 3000 characters.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO update_request
                    (info_id, requester_name, requester_email,
                     category, description, status)
                 VALUES
                    (:info_id, :name, :email,
                     :category, :description, 'Pending')
                 RETURNING update_request_id, status, date_submitted"
            );

            $stmt->bindValue(
                ':info_id',
                $infoId > 0 ? $infoId : null,
                $infoId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL
            );
            $stmt->bindValue(':name', $old['name']);
            $stmt->bindValue(':email', $old['email']);
            $stmt->bindValue(':category', $old['category']);
            $stmt->bindValue(':description', $old['description']);
            $stmt->execute();

            $saved = $stmt->fetch(PDO::FETCH_ASSOC);

            /* ---- EMAIL NOTIFICATION GOES HERE (added later) ----
             * sendUpdateRequestEmail([...]);
             * ---------------------------------------------------- */

            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['request_id'] = (int)$saved['update_request_id'];

            header('Location: update-request.php?submitted=1');
            exit;

        } catch (Throwable $ex) {
            error_log('Update request save error: ' . $ex->getMessage());
            $errors[] = 'Sorry, we could not save your request. Please try again later.';
        }
    }
}

$submitted = isset($_GET['submitted']);
$requestId = $submitted ? ($_SESSION['request_id'] ?? null) : null;
if ($submitted) {
    unset($_SESSION['request_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Request an Update | CMU Campus Navigator</title>

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

/* NAVBAR */

.navbar {
    min-height: 86px;
    padding: 14px 4%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: #fff;
    border-bottom: 1px solid #dce4ef;
    box-shadow: 0 2px 8px rgba(20, 45, 80, 0.06);
}

.logo {
    display: flex;
    align-items: center;
    gap: 13px;
}

.logo img {
    width: 54px;
    height: 54px;
    object-fit: contain;
}

.logo h2 {
    margin: 0 0 5px;
    color: #0b3b82;
    font-size: 21px;
}

.logo span {
    color: #52627a;
    font-size: 13px;
}

.nav-links {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.nav-links a {
    padding: 11px 16px;
    border-radius: 7px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    color: #0b3b82;
    background: #edf3fc;
}

.nav-links a.active {
    color: #fff;
    background: #0b3b82;
}

.nav-links a:hover {
    filter: brightness(.95);
}

/* PAGE */

main {
    max-width: 1200px;
    margin: 0 auto;
    padding: 34px 24px 40px;
}

.page-heading {
    margin-bottom: 26px;
}

.eyebrow {
    color: #805900;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 1.5px;
}

.page-heading h1 {
    margin: 9px 0;
    color: #0b3b82;
    font-size: 34px;
}

.page-heading p {
    max-width: 700px;
    margin: 0;
    color: #52627a;
    line-height: 1.7;
}

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
    box-shadow: 0 4px 14px rgba(11, 59, 130, 0.05);
}

.panel h2 {
    margin: 0 0 6px;
    color: #0b3b82;
    font-size: 22px;
}

.panel-sub {
    margin: 0 0 22px;
    color: #52627a;
    font-size: 14px;
    line-height: 1.6;
}

/* FORM */

.two {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.field {
    margin-bottom: 18px;
}

.field label {
    display: block;
    margin-bottom: 7px;
    color: #43536b;
    font-size: 14px;
    font-weight: 700;
}

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

.field textarea {
    min-height: 150px;
    resize: vertical;
    line-height: 1.6;
}

.field input:focus,
.field select:focus,
.field textarea:focus {
    outline: 2px solid #d6e5fb;
    border-color: #0b3b82;
}

.field-foot {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 6px;
    color: #68758a;
    font-size: 12px;
}

.counter.warn {
    color: #b42318;
    font-weight: 700;
}

.hp {
    position: absolute;
    left: -9999px;
    height: 0;
    overflow: hidden;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 6px;
}

.btn {
    display: inline-block;
    padding: 14px 24px;
    border: 0;
    border-radius: 7px;
    background: #0b3b82;
    color: #fff;
    text-decoration: none;
    font: inherit;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}

.btn:hover {
    background: #082d63;
}

.btn:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.btn-light {
    background: #edf3fc;
    color: #0b3b82;
}

.btn-light:hover {
    background: #dce8fa;
}

.privacy {
    margin: 16px 0 0;
    color: #68758a;
    font-size: 12px;
    line-height: 1.6;
}

/* NOTICES */

.notice {
    padding: 14px 16px;
    margin-bottom: 20px;
    border-radius: 8px;
    line-height: 1.6;
    font-size: 14px;
}

.notice.error {
    background: #fff0ee;
    border: 1px solid #f2c3bd;
    color: #a1261c;
}

.notice ul {
    margin: 0;
    padding-left: 18px;
}

/* SUCCESS */

.success-box {
    text-align: center;
    padding: 30px 10px;
}

.success-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    margin-bottom: 16px;
    border-radius: 50%;
    background: #edf8ef;
    border: 2px solid #b9e2c0;
    color: #216e39;
    font-size: 34px;
}

.success-box h2 {
    margin-bottom: 10px;
    font-size: 26px;
}

.success-box p {
    max-width: 460px;
    margin: 0 auto 8px;
    color: #52627a;
    line-height: 1.7;
}

.request-id {
    display: inline-block;
    margin: 12px 0 22px;
    padding: 10px 18px;
    border-radius: 8px;
    background: #fffbea;
    border: 1px solid #f0dc8a;
    color: #684d00;
    font-weight: 800;
    letter-spacing: .5px;
}

/* SIDE PANELS */

.side {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.side .panel {
    padding: 22px;
    margin: 0;
}

.side h3 {
    margin: 0 0 14px;
    color: #0b3b82;
    font-size: 18px;
}

.steps {
    margin: 0;
    padding: 0;
    list-style: none;
    counter-reset: step;
}

.steps li {
    position: relative;
    padding: 0 0 16px 44px;
    color: #43536b;
    font-size: 14px;
    line-height: 1.6;
}

.steps li:last-child {
    padding-bottom: 0;
}

.steps li::before {
    counter-increment: step;
    content: counter(step);
    position: absolute;
    left: 0;
    top: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #0b3b82;
    color: #fff;
    font-size: 13px;
    font-weight: 800;
}

.steps strong {
    display: block;
    color: #0b3b82;
}

.tips {
    margin: 0;
    padding-left: 18px;
    color: #43536b;
    font-size: 14px;
    line-height: 1.8;
}

.status-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.status-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #43536b;
    font-size: 14px;
}

.badge {
    display: inline-block;
    min-width: 82px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
}

.badge.pending {
    background: #fff8d8;
    color: #805900;
}

.badge.approved {
    background: #d1e7dd;
    color: #146c43;
}

.badge.rejected {
    background: #f8d7da;
    color: #b02a37;
}

/* FOOTER */

footer {
    padding: 22px;
    text-align: center;
    background: #fff;
    border-top: 1px solid #dce4ef;
}

footer p {
    margin: 0;
    color: #52627a;
    font-size: 13px;
}

/* RESPONSIVE */

@media (max-width: 980px) {
    .layout {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 650px) {
    .navbar {
        align-items: flex-start;
        flex-direction: column;
        padding: 16px;
    }

    .logo h2 {
        font-size: 18px;
    }

    main {
        padding: 22px 14px 30px;
    }

    .page-heading h1 {
        font-size: 28px;
    }

    .panel {
        padding: 20px 16px;
    }

    .two {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .btn {
        width: 100%;
        text-align: center;
    }
}
</style>
<link rel="stylesheet" href="mobile.css">
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
        <a href="index.php">Home</a>
        <a href="campus-map.php">Campus Map</a>
        <a href="update-request.php" class="active">Request an Update</a>
    </nav>
</header>

<main>

    <section class="page-heading">
        <span class="eyebrow">HELP US IMPROVE</span>
        <h1>Request an Update</h1>
        <p>
            Found outdated or incorrect information about a building, room, office,
            or facility? Tell us what needs to change and the administrator will
            review your request.
        </p>
    </section>

    <div class="layout">

        <!-- MAIN COLUMN -->
        <section class="panel">

            <?php if ($submitted): ?>

                <div class="success-box">
                    <div class="success-icon">✓</div>
                    <h2>Request Submitted</h2>
                    <p>Thank you for helping us keep the campus information accurate.</p>

                    <?php if ($requestId): ?>
                        <div class="request-id">Request ID: #<?= (int)$requestId ?></div>
                    <?php endif; ?>

                    <p>The administrator will review your request.</p>

                    <div class="actions" style="justify-content:center;margin-top:22px">
                        <a href="update-request.php" class="btn">Submit Another Request</a>
                        <a href="campus-map.php" class="btn btn-light">Back to Campus Map</a>
                    </div>
                </div>

            <?php else: ?>

                <h2>Update Request Form</h2>
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

                <form method="POST" action="update-request.php" id="requestForm" novalidate>

                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="info_id" value="<?= (int)$infoId ?>">

                    <!-- Honeypot: real users never see or fill this -->
                    <div class="hp" aria-hidden="true">
                        <label>Website
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </label>
                    </div>

                    <div class="two">
                        <div class="field">
                            <label for="requester_name">Your Name *</label>
                            <input
                                type="text"
                                id="requester_name"
                                name="requester_name"
                                maxlength="150"
                                required
                                placeholder="Juan Dela Cruz"
                                value="<?= h($old['name']) ?>"
                            >
                        </div>

                        <div class="field">
                            <label for="requester_email">Your Email *</label>
                            <input
                                type="email"
                                id="requester_email"
                                name="requester_email"
                                maxlength="190"
                                required
                                placeholder="you@example.com"
                                value="<?= h($old['email']) ?>"
                            >
                        </div>
                    </div>

                    <div class="field">
                        <label for="category">Category *</label>
                        <select id="category" name="category" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option
                                    value="<?= h($cat) ?>"
                                    <?= $old['category'] === $cat ? 'selected' : '' ?>
                                >
                                    <?= h($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="description">What needs to be updated? *</label>

                        <textarea
                            id="description"
                            name="description"
                            maxlength="3000"
                            required
                            placeholder="Describe what is wrong, what the correct information is, and where you saw the mistake."
                        ><?= h($old['description']) ?></textarea>

                        <div class="field-foot">
                            <span>Minimum 10 characters.</span>
                            <span class="counter" id="counter">0 / 3000</span>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn" id="submitBtn">Submit Request</button>
                        <a href="campus-map.php" class="btn btn-light">Cancel</a>
                    </div>

                    <p class="privacy">
                        Your name and email are only used so the administrator can follow up
                        on your request.
                    </p>

                </form>

            <?php endif; ?>

        </section>

        <!-- SIDE COLUMN -->
        <aside class="side">

            <section class="panel">
                <h3>How it works</h3>

                <ol class="steps">
                    <li>
                        <strong>Submit</strong>
                        Fill in the form with what needs to be corrected.
                    </li>
                    <li>
                        <strong>Review</strong>
                        The administrator checks your request.
                    </li>
                    <li>
                        <strong>Update</strong>
                        If approved, the campus information is updated.
                    </li>
                </ol>
            </section>

            <section class="panel">
                <h3>Tips for a faster review</h3>

                <ul class="tips">
                    <li>Name the building, room, or office.</li>
                    <li>State the correct information clearly.</li>
                    <li>Mention the floor, if it applies.</li>
                    <li>Use one request per issue.</li>
                </ul>
            </section>

            <section class="panel">
                <h3>Request status</h3>

                <div class="status-list">
                    <div class="status-item">
                        <span class="badge pending">Pending</span>
                        Waiting for review
                    </div>
                    <div class="status-item">
                        <span class="badge approved">Approved</span>
                        Update accepted
                    </div>
                    <div class="status-item">
                        <span class="badge rejected">Rejected</span>
                        Could not be applied
                    </div>
                </div>
            </section>

        </aside>

    </div>

</main>

<footer>
    <p>© <?= date('Y') ?> CMU Campus Navigator | City of Malabon University</p>
</footer>

<script>
const description = document.getElementById("description");
const counter = document.getElementById("counter");
const form = document.getElementById("requestForm");
const submitBtn = document.getElementById("submitBtn");

if (description && counter) {
    function updateCounter() {
        const length = description.value.length;

        counter.textContent = length + " / 3000";
        counter.classList.toggle("warn", length > 0 && length < 10);
    }

    description.addEventListener("input", updateCounter);
    updateCounter();
}

/* Basic checks before sending, and stop double submits */
if (form && submitBtn) {
    form.addEventListener("submit", event => {
        const name = form.requester_name.value.trim();
        const email = form.requester_email.value.trim();
        const category = form.category.value;
        const text = form.description.value.trim();

        const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

        if (!name || !emailOk || !category || text.length < 10) {
            event.preventDefault();

            alert(
                "Please enter your name, a valid email, choose a category, " +
                "and describe the update (at least 10 characters)."
            );
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = "Submitting...";
    });
}
</script>

</body>
</html>