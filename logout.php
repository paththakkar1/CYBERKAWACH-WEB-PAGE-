<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (is_logged_in()) {
    log_event($_SESSION['user_id'], 'User Logout', 'User logged out and session destroyed');
    
    // Unset all session variables
    $_SESSION = [];
    
    // Destroy session cookie if set
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}

// Redirect to home
header("Location: index.php");
exit();
?>
