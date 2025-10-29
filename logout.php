<?php
// Start the session (required to access session variables)
session_start();

// Unset all session variables
$_SESSION = array();

// Destroy the session (deletes the session data on the server)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redirect the user to the home page (index.html)
header("Location: index.html");
exit;
?>
