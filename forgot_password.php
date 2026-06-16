<?php
$page_title = 'Forgot Password';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        try {
            // Find User
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate OTP
                $otp = strval(mt_rand(100000, 999999));
                $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                // Clean old resets
                $stmtDel = $db->prepare("DELETE FROM password_resets WHERE email = ?");
                $stmtDel->execute([$email]);

                // Insert Reset Request
                $stmtInsert = $db->prepare("INSERT INTO password_resets (email, otp_code, expires_at) VALUES (?, ?, ?)");
                $stmtInsert->execute([$email, $otp, $expires_at]);

                log_event($user['id'], 'Password Reset Requested', "OTP generated for: $email");

                // Store in session temporarily for debug display
                $_SESSION['debug_otp'] = [
                    'email' => $email,
                    'code' => $otp
                ];

                set_flash_message('success', 'Verification code has been dispatched. Enter OTP below.');
                redirect('reset_password.php?email=' . urlencode($email));
            } else {
                $error = 'No user account found with that email address.';
            }
        } catch (PDOException $e) {
            $error = 'Failed to generate reset OTP request.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | CyberKavach OS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="auth-wrapper">
    <div class="card auth-card">
        <div class="auth-header">
            <div class="auth-logo">
                <i class="fa-solid fa-unlock-keyhole text-cyan"></i>
            </div>
            <h2>RESET PASSPHRASE</h2>
            <p class="text-muted" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 5px;">OTP Dispatch Gate</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-xmark"></i>
                <span><?php echo sanitize($error); ?></span>
            </div>
        <?php endif; ?>

        <form action="forgot_password.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-group" style="margin-bottom: 25px;">
                <label for="email" class="form-label">System Registered Email</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="user@cyberkavach.org" required value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <i class="fa-solid fa-paper-plane"></i> Request OTP Code
            </button>
        </form>
        
        <div style="margin-top: 25px; text-align: center; font-size: 0.85rem;">
            <a href="login.php" class="text-cyan" style="font-weight: 600;"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>
</div>

</body>
</html>
