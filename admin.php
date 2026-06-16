<?php
$page_title = 'Admin Controls';
$page_heading = 'Root Security Console';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Enforce Super Admin role
require_role('Admin');

$user_id = $_SESSION['user_id'];
$error = '';

// -------------------------------------------------------------
// POST / GET ACTIONS
// -------------------------------------------------------------
// 1. Approve User (Status -> Active)
if (isset($_GET['action']) && $_GET['action'] === 'approve') {
    $target_id = intval($_GET['id']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $stmt = $db->prepare("UPDATE users SET status = 'Active' WHERE id = ?");
            $stmt->execute([$target_id]);
            
            // Get user email
            $stmtEmail = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmtEmail->execute([$target_id]);
            $target_email = $stmtEmail->fetchColumn();
            
            log_event($user_id, 'User Approval', "Approved account registration for ID $target_id: '$target_email'");
            set_flash_message('success', "Account approved: $target_email is now Active.");
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to approve account.');
        }
    }
    redirect('admin.php');
}

// 2. Change User Status (Active / Suspended)
if (isset($_GET['action']) && $_GET['action'] === 'status') {
    $target_id = intval($_GET['id']);
    $new_status = sanitize($_GET['value'] ?? '');
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } elseif (!in_array($new_status, ['Active', 'Suspended'])) {
        set_flash_message('error', 'Invalid status selection.');
    } else {
        try {
            // Check self suspension protection
            if ($target_id === intval($user_id)) {
                set_flash_message('error', 'Root block protection: Cannot suspend yourself!');
            } else {
                $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $target_id]);
                
                log_event($user_id, 'User Status Update', "Set status of user ID $target_id to '$new_status'");
                set_flash_message('success', "Status set to $new_status.");
            }
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to update user status.');
        }
    }
    redirect('admin.php');
}

// 3. Change Role (7 Roles Support)
if (isset($_GET['action']) && $_GET['action'] === 'role') {
    $target_id = intval($_GET['id']);
    $new_role = sanitize($_GET['value'] ?? '');
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    $allowed_roles = [
        'Student/Participant',
        'Club Member',
        'Social Media Coord.',
        'Content Coordinator',
        'Tech Coordinator',
        'Student Coordinator',
        'Faculty Coordinator'
    ];
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } elseif (!in_array($new_role, $allowed_roles)) {
        set_flash_message('error', 'Invalid role selection.');
    } else {
        try {
            // Check self role demotion protection
            if ($target_id === intval($user_id)) {
                set_flash_message('error', 'Root protection: Cannot change your own role.');
            } else {
                $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$new_role, $target_id]);
                
                log_event($user_id, 'User Role Update', "Changed role of user ID $target_id to '$new_role'");
                set_flash_message('success', "Role updated to $new_role.");
            }
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to update user privilege.');
        }
    }
    redirect('admin.php');
}

// 4. Trigger DB Backup
if (isset($_POST['backup_db'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed.';
    } else {
        try {
            $backup_file = __DIR__ . '/cyberkavach_backup_' . date('Ymd_His') . '.db';
            if (copy(DB_FILE, $backup_file)) {
                log_event($user_id, 'Database Backup', "Created backup file: " . basename($backup_file));
                set_flash_message('success', 'Database backup file compiled successfully: ' . basename($backup_file));
                redirect('admin.php');
            } else {
                $error = 'Failed to clone SQLite file.';
            }
        } catch (Exception $e) {
            $error = 'Backup failure: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------
// RENDER FETCH
// -------------------------------------------------------------
// Fetch All Users
$all_users = [];
try {
    $stmtUsers = $db->query("SELECT * FROM users ORDER BY created_at DESC");
    $all_users = $stmtUsers->fetchAll();
} catch (PDOException $e) {
    // Fail silently
}

// Fetch All Audit Logs
$audit_logs = [];
try {
    $stmtLogs = $db->query("
        SELECT l.*, u.name as user_name, u.role as user_role, u.email as user_email 
        FROM audit_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        ORDER BY l.created_at DESC 
        LIMIT 100
    ");
    $audit_logs = $stmtLogs->fetchAll();
} catch (PDOException $e) {
    // Fail silently
}

// Database stats
$db_size = file_exists(DB_FILE) ? round(filesize(DB_FILE) / 1024, 2) . ' KB' : 'Unknown';

include __DIR__ . '/includes/header.php';
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error">
        <i class="fa-solid fa-circle-xmark"></i>
        <span><?php echo sanitize($error); ?></span>
    </div>
<?php endif; ?>

<div class="dashboard-layout" style="grid-template-columns: 1fr;">
    <!-- USER ACCOUNTS MANAGER SECTION -->
    <div class="card card-pink" style="margin-bottom:30px;">
        <h3 class="card-title text-pink"><i class="fa-solid fa-users-gear"></i> User Directory & Permissions</h3>
        <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom: 20px;">Review registered credentials, activate pending join requests, suspend active accounts, or elevate/revoke moderator access privileges.</p>

        <?php if (!empty($all_users)): ?>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Join Date</th>
                            <th>Name</th>
                            <th>Email Address</th>
                            <th>Privilege</th>
                            <th>Status</th>
                            <th>Point Score</th>
                            <th>Actions Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_users as $usr): ?>
                            <?php $is_me = intval($usr['id']) === intval($user_id); ?>
                            <tr style="<?php echo $is_me ? 'background: rgba(0,229,255,0.02);' : ''; ?>">
                                <td style="font-size:0.8rem; font-family:monospace;"><?php echo sanitize($usr['created_at']); ?></td>
                                <td>
                                    <strong><?php echo sanitize($usr['name']); ?></strong>
                                    <?php echo $is_me ? '<span style="font-size:0.75rem; color:var(--color-primary);"> (You)</span>' : ''; ?>
                                </td>
                                <td><?php echo sanitize($usr['email']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($usr['role']); ?>">
                                        <?php echo sanitize($usr['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-status-<?php echo strtolower($usr['status']); ?>">
                                        <?php echo sanitize($usr['status']); ?>
                                    </span>
                                </td>
                                <td style="font-weight:bold; color:var(--color-success);"><?php echo $usr['points']; ?> XP</td>
                                <td>
                                    <?php if (!$is_me): ?>
                                        <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                            <!-- Approval / Status toggles -->
                                            <?php if ($usr['status'] === 'Pending'): ?>
                                                <a href="admin.php?action=approve&id=<?php echo $usr['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-check"></i> Approve</a>
                                            <?php elseif ($usr['status'] === 'Active'): ?>
                                                <a href="admin.php?action=status&id=<?php echo $usr['id']; ?>&value=Suspended&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:4px 8px; font-size:0.7rem;" onclick="return confirm('Confirm suspension of this user?');"><i class="fa-solid fa-ban"></i> Suspend</a>
                                            <?php elseif ($usr['status'] === 'Suspended'): ?>
                                                <a href="admin.php?action=status&id=<?php echo $usr['id']; ?>&value=Active&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-circle-check"></i> Reactivate</a>
                                            <?php endif; ?>

                                            <!-- Role selection dropdown -->
                                            <form action="admin.php" method="GET" style="display:inline-flex; align-items:center; margin:0;">
                                                <input type="hidden" name="action" value="role">
                                                <input type="hidden" name="id" value="<?php echo $usr['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <select name="value" onchange="if(confirm('Change role of this user?')) this.form.submit();" class="form-control" style="padding:4px 8px; font-size:0.7rem; height:auto; width:150px; display:inline-block; border-color:var(--border-glow);">
                                                    <?php 
                                                    $roles_list = [
                                                        'Student/Participant',
                                                        'Club Member',
                                                        'Social Media Coord.',
                                                        'Content Coordinator',
                                                        'Tech Coordinator',
                                                        'Student Coordinator',
                                                        'Faculty Coordinator'
                                                    ];
                                                    foreach ($roles_list as $rl) {
                                                        $sel = ($usr['role'] === $rl) ? 'selected' : '';
                                                        echo "<option value='$rl' $sel>$rl</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.75rem; font-style:italic;">Protected Owner Account</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color:var(--text-muted);">No records found.</p>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-layout">
    <!-- LEFT: System logs scrolling list -->
    <div>
        <div class="card">
            <h3 class="card-title text-cyan"><i class="fa-solid fa-terminal"></i> Global Audit Trail Logs</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom: 20px;">System activity, login logs, configuration adjustments, and user validations are captured here.</p>

            <div class="table-responsive" style="max-height:500px; overflow-y:auto; border: 1px solid var(--border-glow); border-radius: 4px;">
                <table class="table-custom" style="margin-top:0;">
                    <thead style="position: sticky; top:0; background:var(--bg-surface); z-index:5;">
                        <tr>
                            <th>Timestamp</th>
                            <th>Account Actor</th>
                            <th>Action Action</th>
                            <th>Details Log</th>
                            <th>Network IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($audit_logs)): ?>
                            <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td style="font-size:0.75rem; font-family:monospace; color:var(--text-muted);"><?php echo sanitize($log['created_at']); ?></td>
                                    <td>
                                        <?php if ($log['user_id']): ?>
                                            <strong><?php echo sanitize($log['user_name']); ?></strong>
                                            <span class="badge badge-<?php echo strtolower($log['user_role'] ?? 'Member'); ?>" style="font-size:0.55rem; padding: 1px 3px; display:inline-block; margin-left:3px;">
                                                <?php echo sanitize($log['user_role'] ?? 'Member'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Unauthenticated Guest</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-member" style="font-size:0.65rem; border-color:transparent; background:rgba(0,229,255,0.08);">
                                            <?php echo sanitize($log['action']); ?>
                                        </span>
                                    </td>
                                    <td style="font-size:0.85rem; color:var(--text-muted);"><?php echo sanitize($log['details']); ?></td>
                                    <td style="font-size:0.75rem; font-family:monospace;"><?php echo sanitize($log['ip_address']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; color:var(--text-muted);">No log records generated yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT: Database utilities & info -->
    <div>
        <div class="card card-pink">
            <h3 class="card-title text-pink"><i class="fa-solid fa-server"></i> System Database Registry</h3>
            
            <div style="margin-top: 15px; font-size:0.9rem;">
                <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                    <span class="text-muted">DB File Driver:</span>
                    <strong>SQLite 3</strong>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                    <span class="text-muted">Database Name:</span>
                    <strong>cyberkavach.db</strong>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom: 25px;">
                    <span class="text-muted">Allocated File Size:</span>
                    <strong><?php echo $db_size; ?></strong>
                </div>

                <form action="admin.php" method="POST" style="border-top:1px dashed var(--border-glow); padding-top:20px;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:15px;"><i class="fa-solid fa-triangle-exclamation text-warning"></i> <strong>Note:</strong> Triggering a backup compiles a duplicate database clone in the workspace root directory immediately.</p>
                    
                    <button type="submit" name="backup_db" class="btn btn-danger btn-block">
                        <i class="fa-solid fa-copy"></i> Compile Database Backup
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
