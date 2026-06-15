<?php
$page_title = 'Login';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please fill in all credentials.';
    } else {
        try {
            // Find User
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Check Status
                if ($user['status'] === 'Pending') {
                    $error = 'Your account activation is pending Core Committee approval.';
                    log_event($user['id'], 'Login Blocked', 'Attempted login with Pending status');
                } elseif ($user['status'] === 'Suspended') {
                    $error = 'Your account has been suspended. Contact support.';
                    log_event($user['id'], 'Login Blocked', 'Attempted login with Suspended status');
                } else {
                    // Successful login
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_points'] = $user['points'];

                    log_event($user['id'], 'User Login', 'User authenticated successfully');
                    set_flash_message('success', 'Access granted. Welcome back, ' . $user['name'] . '!');
                    redirect('dashboard.php');
                }
            } else {
                $error = 'Invalid email or password combination.';
                // Log failed attempt (use guest identifier null)
                log_event(null, 'Failed Login', "Failed login attempt for email: $email");
            }
        } catch (PDOException $e) {
            $error = 'System error occurred. Please contact the administrator.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | CyberKavach OS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="auth-wrapper">
    <div class="card auth-card">
        <div class="auth-header">
            <div class="auth-logo">
                <i class="fa-solid fa-shield-halved text-cyan"></i>
            </div>
            <h2>CYBERKAVACH OS</h2>
            <p class="text-muted" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 5px;">Secure Authentication Gate</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-xmark"></i>
                <span><?php echo sanitize($error); ?></span>
            </div>
        <?php endif; ?>

        <?php 
        // Display registration success messages if redirected
        $flash = get_flash_message(); 
        if ($flash): 
        ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <i class="fa-solid fa-circle-check"></i>
                <span><?php echo sanitize($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-group">
                <label for="email" class="form-label">Authorized Email Address</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="user@cyberkavach.org" required value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">
            </div>

            <div class="form-group" style="margin-bottom: 25px;">
                <label for="password" class="form-label">Passphrase</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <i class="fa-solid fa-key"></i> Authenticate
            </button>
        </form>
        
        <div style="margin-top: 25px; text-align: center; font-size: 0.85rem;">
            <span class="text-muted">Not registered?</span> 
            <a href="register.php" class="text-cyan" style="font-weight: 600;">Request Access</a>
        </div>
        
        <div style="margin-top: 20px; border-top: 1px solid var(--border-glow); padding-top: 15px; font-size: 0.75rem; text-align: center; color: var(--text-muted);">
            <div style="margin-bottom: 5px;"><i class="fa-solid fa-triangle-exclamation text-warning"></i> <strong>Initial Accounts:</strong></div>
            <div style="font-family: monospace; line-height: 1.4;">
                Admin: admin@cyberkavach.org / Admin@12345<br>
                Core: core@cyberkavach.org / Core@12345<br>
                Member: member@cyberkavach.org / Member@12345
            </div>
        </div>
    </div>
</div>

</body>
</html>
