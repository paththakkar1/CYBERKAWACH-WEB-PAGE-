<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// JWT Security Constant & Helpers
define('JWT_SECRET', 'cyberkavach_super_secret_signing_key_2026');

function jwt_encode($payload, $expiry = 3600) {
    $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload['exp'] = time() + $expiry;
    
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function jwt_decode($jwt) {
    if (empty($jwt)) return false;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    list($header, $payload, $signature) = $parts;
    
    $sigToCheck = hash_hmac('sha256', $header . "." . $payload, JWT_SECRET, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($sigToCheck));
    
    if (!hash_equals($base64UrlSignature, $signature)) return false;
    
    $decodedPayload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);
    if (isset($decodedPayload['exp']) && $decodedPayload['exp'] < time()) return false;
    
    return $decodedPayload;
}

function sync_jwt_session() {
    $jwt = $_COOKIE['cyberkavach_jwt'] ?? null;
    $refresh = $_COOKIE['cyberkavach_refresh'] ?? null;

    if ($jwt) {
        $payload = jwt_decode($jwt);
        if ($payload) {
            $_SESSION['user_id'] = $payload['user_id'];
            $_SESSION['user_name'] = $payload['name'];
            $_SESSION['user_role'] = $payload['role'];
            $_SESSION['user_points'] = $payload['points'] ?? 0;
            return true;
        }
    }

    if ($refresh) {
        $payload = jwt_decode($refresh);
        if ($payload && isset($payload['refresh']) && $payload['refresh'] === true) {
            $new_payload = [
                'user_id' => $payload['user_id'],
                'name' => $payload['name'],
                'role' => $payload['role'],
                'points' => $payload['points'] ?? 0
            ];
            $new_jwt = jwt_encode($new_payload, 3600);
            setcookie('cyberkavach_jwt', $new_jwt, time() + 3600, '/', '', false, true);
            
            $_SESSION['user_id'] = $payload['user_id'];
            $_SESSION['user_name'] = $payload['name'];
            $_SESSION['user_role'] = $payload['role'];
            $_SESSION['user_points'] = $payload['points'] ?? 0;
            return true;
        }
    }

    if (!isset($_COOKIE['cyberkavach_jwt']) && !isset($_COOKIE['cyberkavach_refresh'])) {
        unset($_SESSION['user_id']);
        unset($_SESSION['user_name']);
        unset($_SESSION['user_role']);
        unset($_SESSION['user_points']);
    }
    return false;
}

sync_jwt_session();

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
 * @param string $role Required role
 * @return bool
 */
function has_role($role) {
    if (!is_logged_in()) {
        return false;
    }
    
    $current_role = get_user_role();
    
    // Map legacy role requests ('Admin', 'Core', 'Member') to new 7 roles
    if ($role === 'Admin') {
        return $current_role === 'Faculty Coordinator';
    }
    if ($role === 'Core') {
        return in_array($current_role, ['Faculty Coordinator', 'Student Coordinator']);
    }
    if ($role === 'Member') {
        return $current_role !== 'Student/Participant';
    }
    
    $roles_hierarchy = [
        'Student/Participant' => 1,
        'Club Member'         => 2,
        'Social Media Coord.' => 3,
        'Content Coordinator' => 4,
        'Tech Coordinator'    => 5,
        'Student Coordinator' => 6,
        'Faculty Coordinator' => 7
    ];
    
    $current_weight = $roles_hierarchy[$current_role] ?? 0;
    $required_weight = $roles_hierarchy[$role] ?? 0;
    
    return $current_weight >= $required_weight;
}

/**
 * Check if the user has access to a specific module or permission level
 * @param string $access_type 'all' | 'high' | 'tech' | 'content' | 'social' | 'member' | 'guest'
 * @return bool
 */
function has_access($access_type) {
    if (!is_logged_in()) {
        return false;
    }
    
    $role = get_user_role();
    
    if ($role === 'Faculty Coordinator') {
        return true;
    }
    
    if ($role === 'Student Coordinator') {
        return in_array($access_type, ['high', 'tech', 'content', 'social', 'member', 'guest']);
    }
    
    if ($access_type === 'tech') {
        return $role === 'Tech Coordinator';
    }
    if ($access_type === 'content') {
        return $role === 'Content Coordinator';
    }
    if ($access_type === 'social') {
        return $role === 'Social Media Coord.';
    }
    
    if ($access_type === 'member') {
        return in_array($role, ['Club Member', 'Tech Coordinator', 'Content Coordinator', 'Social Media Coord.']);
    }
    
    if ($access_type === 'guest') {
        return true;
    }
    
    return false;
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
