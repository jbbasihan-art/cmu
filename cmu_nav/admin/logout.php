

<?php
session_start();

/* Clear all session data */
$_SESSION = [];

/* Destroy the current session */
session_destroy();

/* Start a new session for the logout message */
session_start();
$_SESSION["logout_success"] = "You have been logged out successfully!";

/* Redirect to login page */
header("Location: login.php");
exit;
?>
