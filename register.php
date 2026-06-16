<?php
$page_title = 'Join Club';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    // Validations
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'All fields are mandatory.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address structure.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Check if email already registered
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Email is already registered on our systems.';
            } else {
                // Insert New User
                // Default status is 'Pending' for security. We will allow logging in once approved.
                $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
                $stmtInsert = $db->prepare("INSERT INTO users (name, email, password, role, status, points) VALUES (?, ?, ?, 'Student/Participant', 'Pending', 0)");
                $stmtInsert->execute([$name, $email, $hashed_pass]);
                $newUserId = $db->lastInsertId();

                log_event($newUserId, 'User Registration', "User registered email: $email, status set to Pending");
                set_flash_message('success', 'Registration submitted! Account activation is pending Core Committee approval. Administrators can activate this account in the Admin Panel.');
                redirect('login.php');
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
    <title>Register | CyberKavach OS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="auth-wrapper" style="min-height: 110vh;">
    <div class="card auth-card">
        <div class="auth-header">
            <div class="auth-logo">
                <i class="fa-solid fa-user-plus text-cyan"></i>
            </div>
            <h2>JOIN CYBERKAVACH</h2>
            <p class="text-muted" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 5px;">Access Request Form</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-xmark"></i>
                <span><?php echo sanitize($error); ?></span>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-group">
                <label for="name" class="form-label">Full Name</label>
                <input type="text" id="name" name="name" class="form-control" placeholder="Rohan Sharma" required value="<?php echo isset($_POST['name']) ? sanitize($_POST['name']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="rohan@gmail.com" required value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Set Passphrase</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Minimum 6 characters" required>
            </div>

            <div class="form-group" style="margin-bottom: 25px;">
                <label for="confirm_password" class="form-label">Confirm Passphrase</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-type passphrase" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <i class="fa-solid fa-file-signature"></i> Request Registration
            </button>
        </form>
        
        <div style="margin-top: 25px; text-align: center; font-size: 0.85rem;">
            <span class="text-muted">Already registered?</span> 
            <a href="login.php" class="text-cyan" style="font-weight: 600;">Secure Login</a>
        </div>
    </div>
</div>

</body>
</html>
