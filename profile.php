<?php
$page_title = 'My Profile';
$page_heading = 'Member Credentials';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch fresh user data
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        set_flash_message('error', 'User details not found.');
        redirect('dashboard.php');
    }
    
    // Fetch user badges
    $stmtB = $db->prepare("SELECT b.* FROM user_badges ub JOIN badges b ON ub.badge_id = b.id WHERE ub.user_id = ?");
    $stmtB->execute([$user_id]);
    $my_badges = $stmtB->fetchAll();
    
    // Fetch user certificates
    $stmtC = $db->prepare("
        SELECT c.*, e.title as event_title, q.title as quiz_title 
        FROM certificates c 
        LEFT JOIN events e ON c.event_id = e.id 
        LEFT JOIN quizzes q ON c.quiz_id = q.id 
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmtC->execute([$user_id]);
    $my_certificates = $stmtC->fetchAll();
} catch (PDOException $e) {
    set_flash_message('error', 'Database error.');
    redirect('dashboard.php');
}

// Handle Profile Details Update
if (isset($_POST['update_profile'])) {
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (empty($name) || empty($email)) {
        $error = 'Name and email are required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email structure.';
    } else {
        try {
            // Check email uniqueness (excluding self)
            $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $check->execute([$email, $user_id]);
            if ($check->fetch()) {
                $error = 'Email is already registered by another user.';
            } else {
                $update = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                $update->execute([$name, $email, $user_id]);
                
                $_SESSION['user_name'] = $name;
                log_event($user_id, 'Profile Update', "Updated profile name: $name, email: $email");
                
                set_flash_message('success', 'Profile credentials updated successfully.');
                redirect('profile.php');
            }
        } catch (PDOException $e) {
            $error = 'Failed to update credentials.';
        }
    }
}

// Handle Password Change Update
if (isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
        $error = 'All password fields are required.';
    } elseif (!password_verify($current_pass, $user['password'])) {
        $error = 'Current password verification failed.';
    } elseif (strlen($new_pass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new_pass !== $confirm_pass) {
        $error = 'New passwords do not match.';
    } else {
        try {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $updatePass = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updatePass->execute([$hashed, $user_id]);
            
            log_event($user_id, 'Password Change', 'User changed login password');
            set_flash_message('success', 'Passphrase changed successfully.');
            redirect('profile.php');
        } catch (PDOException $e) {
            $error = 'Failed to update password.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="dashboard-layout" style="grid-template-columns: 1fr 1fr;">
    <!-- Profile Card details -->
    <div>
        <div class="card" style="margin-bottom: 30px;">
            <h3 class="card-title text-cyan"><i class="fa-solid fa-user-shield"></i> Profile Details</h3>
            
            <?php if (!empty($error) && isset($_POST['update_profile'])): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <span><?php echo sanitize($error); ?></span>
                </div>
            <?php endif; ?>

            <form action="profile.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label class="form-label">System Role Privilege</label>
                    <input type="text" class="form-control" value="<?php echo sanitize($user['role']); ?>" readonly style="background: var(--bg-surface-elevated); border-color: var(--border-glow); font-weight: bold; color: var(--color-primary);">
                </div>

                <div class="form-group">
                    <label class="form-label">Total Experience Points</label>
                    <input type="text" class="form-control" value="<?php echo sanitize($user['points']); ?> XP" readonly style="background: var(--bg-surface-elevated); border-color: var(--border-glow); font-weight: bold; color: var(--color-primary);">
                </div>

                <div class="form-group">
                    <label for="name" class="form-label">Display Name</label>
                    <input type="text" id="name" name="name" class="form-control" value="<?php echo sanitize($user['name']); ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 25px;">
                    <label for="email" class="form-label">System Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" required>
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-floppy-disk"></i> Update Profile Credentials
                </button>
            </form>
        </div>
    </div>

    <!-- Password update card -->
    <div>
        <div class="card card-pink">
            <h3 class="card-title text-pink"><i class="fa-solid fa-key"></i> Cryptographic Passphrase Change</h3>

            <?php if (!empty($error) && isset($_POST['change_password'])): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <span><?php echo sanitize($error); ?></span>
                </div>
            <?php endif; ?>

            <form action="profile.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="form-group">
                    <label for="current_password" class="form-label">Current Passphrase</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" placeholder="••••••••••••" required>
                </div>

                <div class="form-group">
                    <label for="new_password" class="form-label">New Passphrase</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" placeholder="At least 6 characters" required>
                </div>

                <div class="form-group" style="margin-bottom: 25px;">
                    <label for="confirm_password" class="form-label">Re-type New Passphrase</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm new passphrase" required>
                </div>

                <button type="submit" name="change_password" class="btn btn-danger btn-block">
                    <i class="fa-solid fa-shield-halved"></i> Rotate Passphrase
                </button>
            </form>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 30px; border-color: var(--border-glow);">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px;">
        <!-- Achievements Column -->
        <div>
            <h4 style="font-size:0.95rem; border-bottom:1px solid var(--border-glow); padding-bottom:8px; margin-bottom:15px; color:#000000;">
                <i class="fa-solid fa-award"></i> Badges
            </h4>
            <?php if (!empty($my_badges)): ?>
                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <?php foreach ($my_badges as $bdg): ?>
                        <span style="font-size:0.75rem; padding: 6px 10px; display:inline-flex; align-items:center; gap:6px; background: var(--bg-surface-elevated); border: 1px solid var(--border-glow); border-radius:4px; color:#000000;" title="<?php echo sanitize($bdg['description']); ?>">
                            <i class="fa-solid <?php echo sanitize($bdg['icon']); ?>" style="color:#000000;"></i>
                            <?php echo sanitize($bdg['name']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.8rem;">No achievements unlocked yet.</p>
            <?php endif; ?>
        </div>

        <!-- Certificates Column -->
        <div>
            <h4 style="font-size:0.95rem; border-bottom:1px solid var(--border-glow); padding-bottom:8px; margin-bottom:15px; color:#000000;">
                <i class="fa-solid fa-graduation-cap"></i> Certificate Vault
            </h4>
            <?php if (!empty($my_certificates)): ?>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php foreach ($my_certificates as $crt): ?>
                        <?php $cert_title = !empty($crt['event_title']) ? $crt['event_title'] : $crt['quiz_title']; ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.8rem; background:var(--bg-surface-elevated); padding:8px 12px; border:1px solid var(--border-glow); border-radius:4px;">
                            <span style="font-weight:600; color:var(--text-primary); text-overflow:ellipsis; overflow:hidden; white-space:nowrap; max-width:200px;"><?php echo sanitize($cert_title); ?></span>
                            <a href="certificate.php?uuid=<?php echo $crt['uuid']; ?>" target="_blank" style="font-weight:bold; font-size:0.75rem; color:#000000;"><i class="fa-solid fa-arrow-up-right-from-square"></i> View</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.8rem;">No certificates generated yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
