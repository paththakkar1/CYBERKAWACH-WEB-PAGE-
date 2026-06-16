<?php
$page_title = 'Social Media Module';
$page_heading = 'Social Campaign Console';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Access control: Faculty Coordinator, Student Coordinator, and Social Media Coord.
require_login();
if (!has_access('social')) {
    set_flash_message('error', 'Access Denied: You do not have permission to view the Social Media Module.');
    redirect('dashboard.php');
}

$user_id = intval($_SESSION['user_id']);
$user_role = get_user_role();
$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_campaign'])) {
    $platform = sanitize($_POST['platform'] ?? '');
    $content = sanitize($_POST['content'] ?? '');
    $scheduled_time = sanitize($_POST['scheduled_time'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Refresh and try again.';
    } elseif (empty($platform) || empty($content)) {
        $error = 'Please fill in all required fields.';
    } else {
        try {
            $status = (in_array($user_role, ['Faculty Coordinator', 'Student Coordinator'])) ? 'Approved' : 'Draft';
            $stmt = $db->prepare("INSERT INTO social_campaigns (user_id, platform, content, scheduled_time, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $platform, $content, $scheduled_time, $status]);
            log_event($user_id, 'Social Post Scheduled', "Created social campaign on $platform: " . substr($content, 0, 50));
            $success = 'Social campaign scheduled successfully.';
        } catch (PDOException $e) {
            $error = 'Failed to schedule campaign.';
        }
    }
}

// Approve / Reject / Post Social Campaigns
if (isset($_GET['action']) && in_array($_GET['action'], ['approve', 'reject', 'share'])) {
    $target_id = intval($_GET['id']);
    $action = $_GET['action'];
    $csrf_token = $_GET['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            if ($action === 'approve') {
                $status = 'Approved';
            } elseif ($action === 'reject') {
                $status = 'Draft'; // Revoke approval / reset to draft
            } else {
                $status = 'Shared';
            }
            $stmt = $db->prepare("UPDATE social_campaigns SET status = ?, approved_by = ? WHERE id = ?");
            $stmt->execute([$status, $user_id, $target_id]);
            
            log_event($user_id, 'Social Campaign Resolved', "Set status of campaign ID $target_id to $status");
            set_flash_message('success', "Social campaign #$target_id status updated to $status.");
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to update campaign.');
        }
    }
    redirect('social_module.php');
}

// Fetch campaigns
$campaigns = [];
try {
    $stmtCmp = $db->query("
        SELECT sc.*, u.name as publisher_name, u.role as publisher_role, apr.name as approver_name 
        FROM social_campaigns sc 
        JOIN users u ON sc.user_id = u.id 
        LEFT JOIN users apr ON sc.approved_by = apr.id 
        ORDER BY sc.created_at DESC
    ");
    $campaigns = $stmtCmp->fetchAll();
} catch (PDOException $e) {}

// Fetch total shared certificates count mock
$cert_shares_count = 0;
try {
    $stmtCertCount = $db->query("SELECT COUNT(*) FROM certificates");
    $cert_shares_count = $stmtCertCount->fetchColumn();
} catch (PDOException $e) {}

include __DIR__ . '/includes/header.php';
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error">
        <i class="fa-solid fa-circle-xmark"></i>
        <span><?php echo sanitize($error); ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <span><?php echo sanitize($success); ?></span>
    </div>
<?php endif; ?>

<div class="dashboard-layout" style="grid-template-columns: 1.2fr 0.8fr;">
    <!-- LEFT: Campaigns logs -->
    <div style="display:flex; flex-direction:column; gap:30px;">
        <!-- Analytics summary -->
        <div class="stats-grid" style="margin-bottom: 0px;">
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Social Post Campaigns</h3>
                    <div class="stat-value"><?php echo count($campaigns); ?></div>
                </div>
                <i class="fa-solid fa-bullhorn stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Shared Credentials</h3>
                    <div class="stat-value"><?php echo $cert_shares_count; ?></div>
                </div>
                <i class="fa-solid fa-graduation-cap stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Engagement Reach</h3>
                    <div class="stat-value">4.2K+</div>
                </div>
                <i class="fa-solid fa-chart-line stat-icon"></i>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-hashtag"></i> Scheduled Campaigns Approvals Logs</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:20px;">Deploy scheduled posts across social nodes and co-sign certificate templates verification alerts.</p>
            
            <?php if (!empty($campaigns)): ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Poster</th>
                                <th>Post & Platform</th>
                                <th>Schedule Date</th>
                                <th>Status</th>
                                <th>Actions Control</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo sanitize($c['publisher_name']); ?></strong>
                                        <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo sanitize($c['publisher_role']); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; text-transform:uppercase; margin-bottom:4px;">
                                            <i class="fa-brands fa-<?php 
                                                echo ($c['platform'] === 'Twitter') ? 'twitter' : 
                                                    (($c['platform'] === 'LinkedIn') ? 'linkedin' : 
                                                    (($c['platform'] === 'Instagram') ? 'instagram' : 'discord')); 
                                            ?>"></i> <?php echo sanitize($c['platform']); ?>
                                        </div>
                                        <p style="font-size:0.8rem; color:var(--text-muted); line-height:1.4;"><?php echo sanitize($c['content']); ?></p>
                                    </td>
                                    <td>
                                        <span style="font-size:0.8rem; font-family:monospace;"><?php echo !empty($c['scheduled_time']) ? sanitize($c['scheduled_time']) : 'Immediate'; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-status-<?php 
                                            echo ($c['status'] === 'Shared') ? 'active' : (($c['status'] === 'Approved') ? 'active' : 'pending'); 
                                        ?>">
                                            <?php echo sanitize($c['status']); ?>
                                        </span>
                                        <?php if ($c['status'] !== 'Draft' && !empty($c['approver_name'])): ?>
                                            <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">By <?php echo sanitize($c['approver_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:6px;">
                                            <?php if ($c['status'] === 'Draft'): ?>
                                                <a href="social_module.php?action=approve&id=<?php echo $c['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-thumbs-up"></i> Approve</a>
                                            <?php elseif ($c['status'] === 'Approved'): ?>
                                                <a href="social_module.php?action=share&id=<?php echo $c['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-primary" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-share-nodes"></i> Share Live</a>
                                                <a href="social_module.php?action=reject&id=<?php echo $c['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:4px 8px; font-size:0.7rem;" onclick="return confirm('Revoke approval?');"><i class="fa-solid fa-xmark"></i> Reject</a>
                                            <?php else: ?>
                                                <span style="font-size:0.75rem; color:var(--text-muted); font-style:italic;">Shared Live</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">No campaigns logged in database.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Form -->
    <div style="display:flex; flex-direction:column; gap:30px;">
        <div class="card card-pink">
            <h3 class="card-title"><i class="fa-solid fa-share-nodes"></i> Schedule Social Release</h3>
            <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:15px;">Schedule student verification notifications, certification alerts, and ethical hacking workshop details to official media boards.</p>
            
            <form action="social_module.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="platform" class="form-label">Social Platform Node</label>
                    <select id="platform" name="platform" class="form-control" required>
                        <option value="" disabled selected>-- Choose Platform --</option>
                        <option value="LinkedIn">LinkedIn Professional Network</option>
                        <option value="Twitter">Twitter / X Node</option>
                        <option value="Instagram">Instagram Media Feed</option>
                        <option value="Discord">Discord Server Announcements</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="scheduled_time" class="form-label">Scheduled Post Time (Optional)</label>
                    <input type="datetime-local" id="scheduled_time" name="scheduled_time" class="form-control">
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label for="content" class="form-label">Social Post Content</label>
                    <textarea id="content" name="content" class="form-control" rows="8" placeholder="Type your social post description or hashtags..." required></textarea>
                </div>
                
                <button type="submit" name="submit_campaign" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-paper-plane"></i> Deploy Social Campaign
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
