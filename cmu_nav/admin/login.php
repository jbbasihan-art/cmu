
<?php
session_start();

require_once "../config/database.php";

if (isset($_SESSION["admin_id"])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
$username = "";
$success = $_SESSION["logout_success"] ?? "";
unset($_SESSION["logout_success"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {
        $error = "Please enter your username and password.";
    } else {
        try {
            $stmt = $conn->prepare(
                "SELECT admin_id, username, password, full_name, role
                 FROM admin
                 WHERE username = :username
                 LIMIT 1"
            );

            $stmt->execute([
                "username" => $username
            ]);

            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            $stored = trim((string) ($admin["password"] ?? ""));

            $valid = $admin && (
                password_verify($password, $stored) ||
                hash_equals($stored, $password)
            );

            if ($valid) {
                session_regenerate_id(true);

                $_SESSION["admin_id"] = $admin["admin_id"];
                $_SESSION["username"] = $admin["username"];
                $_SESSION["full_name"] = $admin["full_name"];
                $_SESSION["role"] = $admin["role"];

                header("Location: dashboard.php");
                exit;
            }

            $error = "Invalid username or password.";

        } catch (PDOException $e) {
            error_log("Admin login database error: " . $e->getMessage());
            $error = "Unable to process login. Please try again.";
        }
    }
}

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | CMU Campus Navigator</title>
    <script src="../fade.js"></script>

    <style>
        :root {
            --blue: #0b3b82;
            --blue-dark: #082d63;
            --gold: #c39400;
            --text: #17233c;
            --muted: #68758a;
            --border: #d5deea;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background: #f7f9fc;
        }

        .navbar {
            min-height: 78px;
            display: flex;
            align-items: center;
            padding: 12px 7%;
            background: #fff;
            border-bottom: 1px solid #e5eaf2;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo img {
            width: 45px;
            height: 45px;
            object-fit: contain;
        }

        .logo h2 {
            color: var(--blue);
            font-size: 18px;
        }

        .logo p {
            margin-top: 4px;
            color: var(--muted);
            font-size: 11px;
        }

        .login-main {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 55px 20px;
            background:
                linear-gradient(
                    rgba(255, 255, 255, 0.58),
                    rgba(255, 255, 255, 0.58)
                ),
                url("../cmu-new-building-inspection.webp")
                center / cover no-repeat;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 38px;
            background: rgba(255, 255, 255, 0.97);
            border: 1px solid #e1e7ef;
            border-radius: 14px;
            box-shadow: 0 15px 40px rgba(11, 59, 130, 0.14);
        }

        .login-logo {
            margin-bottom: 30px;
            text-align: center;
        }

        .login-logo img {
            width: 76px;
            height: 76px;
            object-fit: contain;
            margin-bottom: 12px;
        }

        .login-logo h1 {
            margin-bottom: 10px;
            color: var(--blue);
            font-size: 26px;
        }

        .login-logo p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .error-message,
        .success-message {
            margin-bottom: 20px;
            padding: 12px;
            border: 1px solid;
            border-radius: 6px;
            font-size: 14px;
            line-height: 1.5;
        }

        .error-message {
            color: #b42318;
            background: #fef3f2;
            border-color: #fecdca;
        }

        .success-message {
            color: #176534;
            background: #e8f7ed;
            border-color: #a9dfba;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #263653;
            font-size: 14px;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 13px;
            color: var(--text);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(11, 59, 130, 0.1);
        }

        .login-button {
            width: 100%;
            padding: 14px;
            color: #fff;
            background: var(--blue);
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }

        .login-button:hover {
            background: var(--blue-dark);
        }

        .back-link {
            display: block;
            margin-top: 22px;
            color: var(--blue);
            text-align: center;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link:hover {
            color: var(--gold);
        }

        footer {
            padding: 22px 15px;
            color: #fff;
            background: var(--blue);
            text-align: center;
            font-size: 13px;
        }

        @media (max-width: 500px) {
            .navbar {
                padding: 14px 5%;
            }

            .login-main {
                padding: 35px 16px;
            }

            .login-card {
                padding: 28px 22px;
            }

            .login-logo h1 {
                font-size: 23px;
            }
        }
    </style>
<link rel="stylesheet" href="../mobile.css">
</head>

<body data-no-bg>
    <header class="navbar">
        <div class="logo">
            <img src="../cmu logo.jpg" alt="CMU Logo">
            <div>
                <h2>CMU Campus Navigator</h2>
                <p>City of Malabon University</p>
            </div>
        </div>
    </header>

    <main class="login-main">
        <section class="login-card">
            <div class="login-logo">
                <img src="../cmu logo.jpg" alt="CMU Logo">
                <h1>Admin Login</h1>
                <p>Sign in to manage campus information.</p>
            </div>

            <?php if ($success !== ""): ?>
                <div class="success-message" role="status">
                    ✓ <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="error-message" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="<?= e($username) ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="login-button">Login</button>
            </form>

            <a href="../index.php" class="back-link">
                ← Back to Home
            </a>
        </section>
    </main>

    <footer>
        © 2026 CMU Campus Navigator | City of Malabon University
    </footer>
</body>
</html>
