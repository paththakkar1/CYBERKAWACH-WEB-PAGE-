<?php
$page_title = 'Reset Password';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
$success = '';
$email = sanitize($_GET['email'] ?? $_POST['email'] ?? '');

// Handle Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_submit'])) {
    $otp_entered = trim($_POST['otp_code'] ?? '');
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (empty($email) || empty($otp_entered) || empty($new_pass) || empty($confirm_pass)) {
        $error = 'Please fill in all verification fields.';
    } elseif (strlen($new_pass) < 6) {
        $error = 'Passphrase must be at least 6 characters.';
    } elseif ($new_pass !== $confirm_pass) {
        $error = 'Passphrases do not match.';
    } else {
        try {
            // Retrieve OTP record
            $stmt = $db->prepare("SELECT * FROM password_resets WHERE email = ? AND otp_code = ? LIMIT 1");
            $stmt->execute([$email, $otp_entered]);
            $reset = $stmt->fetch();

            if ($reset) {
                // Check expiry
                $now = date('Y-m-d H:i:s');
                if (strtotime($reset['expires_at']) < strtotime($now)) {
                    $error = 'This verification code has expired. Please request a new one.';
                } else {
                    // Update User Password
                    $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                    $stmtUpdate = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
                    $stmtUpdate->execute([$hashed_pass, $email]);

                    // Clean resets table
                    $stmtClean = $db->prepare("DELETE FROM password_resets WHERE email = ?");
                    $stmtClean->execute([$email]);

                    // Clean debug OTP from session
                    unset($_SESSION['debug_otp']);

                    // Find User ID for logging
                    $stmtUser = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $stmtUser->execute([$email]);
                    $uid = $stmtUser->fetchColumn();

                    log_event($uid, 'Password Reset Completed', "Successfully changed passphrase for $email");
                    set_flash_message('success', 'Passphrase reset successful! Log in using your new credentials.');
                    redirect('login.php');
                }
            } else {
                $error = 'Invalid OTP verification code.';
            }
        } catch (PDOException $e) {
            $error = 'System error resetting passphrase.';
        }
    }
}

// Fetch debug OTP if present
$debug_message = '';
if (isset($_SESSION['debug_otp']) && $_SESSION['debug_otp']['email'] === $email) {
    $debug_message = 'Active OTP Code for ' . sanitize($email) . ': <strong>' . sanitize($_SESSION['debug_otp']['code']) . '</strong>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | CyberKavach OS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="auth-wrapper">
    <div class="card auth-card">
        <div class="auth-header">
            <div class="auth-logo">
                <i class="fa-solid fa-key text-cyan"></i>
            </div>
            <h2>RESET PASSPHRASE</h2>
            <p class="text-muted" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 5px;">OTP Verification</p>
        </div>

        <?php if (!empty($debug_message)): ?>
            <div class="alert alert-info" style="border-color: var(--color-primary); background: rgba(0, 0, 0, 0.02); text-align: center;">
                <i class="fa-solid fa-bug text-cyan"></i>
                <span><?php echo $debug_message; ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-xmark"></i>
                <span><?php echo sanitize($error); ?></span>
            </div>
        <?php endif; ?>

        <?php 
        $flash = get_flash_message();
        if ($flash): 
        ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <i class="fa-solid fa-circle-info"></i>
                <span><?php echo sanitize($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <form action="reset_password.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="email" value="<?php echo sanitize($email); ?>">

            <div class="form-group">
                <label for="otp_code" class="form-label">Enter 6-Digit OTP</label>
                <input type="text" id="otp_code" name="otp_code" class="form-control" placeholder="123456" required autocomplete="off" style="text-align: center; letter-spacing: 4px; font-weight: bold;">
            </div>

            <div class="form-group">
                <label for="new_password" class="form-label">New Passphrase</label>
                <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Minimum 6 characters" required>
            </div>

            <div class="form-group" style="margin-bottom: 25px;">
                <label for="confirm_password" class="form-label">Confirm New Passphrase</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-type passphrase" required>
            </div>

            <button type="submit" name="reset_submit" class="btn btn-primary btn-block">
                <i class="fa-solid fa-check-double"></i> Complete Reset
            </button>
        </form>
        
        <div style="margin-top: 25px; text-align: center; font-size: 0.85rem;">
            <a href="forgot_password.php" class="text-cyan" style="font-weight: 600;"><i class="fa-solid fa-arrow-left"></i> Request Code Again</a>
        </div>
    </div>
</div>

</body>
</html>
