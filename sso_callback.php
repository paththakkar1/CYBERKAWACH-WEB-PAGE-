<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Google SSO simulation callback
try {
    $email = 'google_sso@cyberkavach.org';
    $name = 'Google SSO User';
    
    // Find or create SSO user
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $hashed_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmtInsert = $db->prepare("INSERT INTO users (name, email, password, role, status, points) VALUES (?, ?, ?, 'Club Member', 'Active', 10)");
        $stmtInsert->execute([$name, $email, $hashed_pass]);
        
        // Fetch newly created user details
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        log_event($user['id'], 'User SSO Registration', 'SSO self-registration via Google OAuth completed');
    }
    
    // Check if account status allows logging in (simulating approval checks)
    if ($user['status'] === 'Pending') {
        set_flash_message('error', 'SSO authentication succeeded, but your account activation is pending coordinator approval.');
        redirect('login.php');
    } elseif ($user['status'] === 'Suspended') {
        set_flash_message('error', 'Your account has been suspended. SSO log-in blocked.');
        redirect('login.php');
    }
    
    // Generate JWT access token (valid 1 hour)
    $payload_access = [
        'user_id' => $user['id'],
        'name' => $user['name'],
        'role' => $user['role'],
        'points' => $user['points']
    ];
    $jwt = jwt_encode($payload_access, 3600);
    
    // Generate JWT refresh token (valid 30 days)
    $payload_refresh = [
        'user_id' => $user['id'],
        'name' => $user['name'],
        'role' => $user['role'],
        'points' => $user['points'],
        'refresh' => true
    ];
    $refresh = jwt_encode($payload_refresh, 3600 * 24 * 30);
    
    // Set cookies (HTTP-Only)
    setcookie('cyberkavach_jwt', $jwt, time() + 3600, '/', '', false, true);
    setcookie('cyberkavach_refresh', $refresh, time() + (3600 * 24 * 30), '/', '', false, true);
    
    // Populate session for backward compatibility
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_points'] = $user['points'];
    
    log_event($user['id'], 'User SSO Login', 'Successfully authenticated via Google institutional SSO');
    set_flash_message('success', 'Google SSO authentication succeeded. Access granted.');
    redirect('dashboard.php');
    
} catch (PDOException $e) {
    set_flash_message('error', 'Institutional Google SSO process failed.');
    redirect('login.php');
}
