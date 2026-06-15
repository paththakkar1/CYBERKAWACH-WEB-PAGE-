<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// System Constants
define('APP_NAME', 'CyberKavach OS');
define('DB_FILE', __DIR__ . '/cyberkavach.db');

// Error reporting (disable in production, keep enabled for dev)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Security: Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Validate CSRF Token
 * @param string $token
 * @return bool
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize User Input
 * @param string $data
 * @return string
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect helper
 * @param string $url
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Set flash message
 * @param string $type success | error | warning | info
 * @param string $message
 */
function set_flash_message($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 * @return array|null
 */
function get_flash_message() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Check if user is logged in
 * @return bool
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user role
 * @return string|null
 */
function get_user_role() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Check if user has specific role or higher
 * Roles order: Guest < Member < Core < Admin
 * @param string $role Required role
 * @return bool
 */
function has_role($role) {
    if (!is_logged_in()) {
        return false;
    }
    
    $current_role = get_user_role();
    $roles_hierarchy = ['Member' => 1, 'Core' => 2, 'Admin' => 3];
    
    $current_weight = $roles_hierarchy[$current_role] ?? 0;
    $required_weight = $roles_hierarchy[$role] ?? 0;
    
    return $current_weight >= $required_weight;
}

/**
 * Enforce login
 */
function require_login() {
    if (!is_logged_in()) {
        set_flash_message('error', 'Authentication required. Please log in.');
        redirect('login.php');
    }
}

/**
 * Enforce specific role
 * @param string $role Required role
 */
function require_role($role) {
    require_login();
    if (!has_role($role)) {
        set_flash_message('error', 'Access Denied: You do not have permission to view this page.');
        redirect('dashboard.php');
    }
}
?>
