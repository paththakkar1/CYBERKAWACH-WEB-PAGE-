<?php
$page_title = 'Dashboard';
$page_heading = 'System Control Portal';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = $_SESSION['user_id'];
$user_role = get_user_role();
$user_name = $_SESSION['user_name'];

// -------------------------------------------------------------
// POST ACTIONS
// -------------------------------------------------------------
// 1. Guest Interest Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_interest'])) {
    $interest_area = sanitize($_POST['interest_area'] ?? '');
    $reason = sanitize($_POST['reason'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed. Refresh and try again.');
    } elseif (empty($interest_area) || empty($reason)) {
        set_flash_message('error', 'Please fill in all fields.');
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO interest_forms (user_id, interest_area, reason) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $interest_area, $reason]);
            log_event($user_id, 'Interest Form Submitted', "Area: $interest_area");
            set_flash_message('success', 'Your interest form has been submitted! Coordinators will review it to promote you.');
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to submit interest form.');
        }
    }
    redirect('dashboard.php');
}

// 2. Faculty Badge Awarding Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['award_badge_action'])) {
    $target_member_id = intval($_POST['target_member_id'] ?? 0);
    $badge_name = sanitize($_POST['badge_name'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } elseif ($target_member_id <= 0 || empty($badge_name)) {
        set_flash_message('error', 'Please select a member and badge.');
    } else {
        if (award_badge($target_member_id, $badge_name, $user_id)) {
            $stmtName = $db->prepare("SELECT name FROM users WHERE id = ?");
            $stmtName->execute([$target_member_id]);
            $tgt_name = $stmtName->fetchColumn();
            set_flash_message('success', "Awarded '$badge_name' badge to $tgt_name.");
        } else {
            set_flash_message('error', 'Failed to award badge. Member may already have it.');
        }
    }
    redirect('dashboard.php');
}

// 3. Simulated policy toggle logic for Faculty Coordinator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_policy'])) {
    $policy = sanitize($_POST['policy_name'] ?? '');
    $state = sanitize($_POST['policy_state'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        log_event($user_id, 'Policy Change', "Set security policy '$policy' to '$state'");
        set_flash_message('success', "Policy '$policy' updated to $state successfully.");
    }
    redirect('dashboard.php');
}

// 4. Simulated vm power control action for Tech Coordinator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vm_control'])) {
    $vm_name = sanitize($_POST['vm_name'] ?? '');
    $vm_state = sanitize($_POST['vm_state'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        log_event($user_id, 'VM Control', "Toggled VM '$vm_name' power to '$vm_state'");
        set_flash_message('success', "VM '$vm_name' status set to '$vm_state' successfully.");
    }
    redirect('dashboard.php');
}

// -------------------------------------------------------------
// GLOBAL DATA RETRIEVAL (Shared)
// -------------------------------------------------------------
$announcements = [];
$spotlights = [];
try {
    $stmt = $db->query("SELECT a.*, u.name as author_name FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC LIMIT 5");
    $announcements = $stmt->fetchAll();
    
    $stmtSpot = $db->query("SELECT s.*, u.name as nominee_name FROM spotlights s JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC LIMIT 3");
    $spotlights = $stmtSpot->fetchAll();
} catch (PDOException $e) {}

// -------------------------------------------------------------
// ROLE-SPECIFIC DATA RETRIEVAL
// -------------------------------------------------------------
if ($user_role === 'Faculty Coordinator') {
    try {
        $total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $total_pending = $db->query("SELECT COUNT(*) FROM users WHERE status = 'Pending'")->fetchColumn();
        $total_events = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
        $open_tickets = $db->query("SELECT COUNT(*) FROM tickets WHERE status != 'Resolved'")->fetchColumn();
        
        $stmtLogs = $db->query("SELECT l.*, u.name as user_name FROM audit_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 6");
        $recent_logs = $stmtLogs->fetchAll();
        
        $stmtApproval = $db->query("SELECT * FROM users WHERE status = 'Pending' ORDER BY created_at DESC LIMIT 5");
        $pending_users_list = $stmtApproval->fetchAll();

        // Retrieve active members and badges for appreciation form
        $all_members = $db->query("SELECT id, name, email FROM users WHERE role = 'Club Member' AND status = 'Active' ORDER BY name ASC")->fetchAll();
        $all_badges = $db->query("SELECT name FROM badges ORDER BY name ASC")->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
} elseif ($user_role === 'Student Coordinator') {
    try {
        $active_members = $db->query("SELECT COUNT(*) FROM users WHERE role = 'Club Member' AND status = 'Active'")->fetchColumn();
        $upcoming_events_count = $db->query("SELECT COUNT(*) FROM events WHERE date >= date('now')")->fetchColumn();
        $pending_tickets = $db->query("SELECT COUNT(*) FROM tickets WHERE status = 'Open'")->fetchColumn();
        
        $pending_users_app = $db->query("SELECT COUNT(*) FROM users WHERE status = 'Pending'")->fetchColumn();
        $pending_events_app = $db->query("SELECT COUNT(*) FROM events WHERE status = 'Pending Approval'")->fetchColumn();
        $pending_approvals_count = $pending_users_app + $pending_events_app;
        
        $stmtEv = $db->query("SELECT * FROM events WHERE date >= date('now') ORDER BY date ASC LIMIT 5");
        $upcoming_events = $stmtEv->fetchAll();
        
        $stmtTk = $db->query("SELECT t.*, u.name as user_name FROM tickets t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 5");
        $recent_tickets = $stmtTk->fetchAll();

        // Top 3 Leaderboard highlight
        $leaderboard_top = $db->query("SELECT name, points FROM users WHERE status = 'Active' ORDER BY points DESC LIMIT 3")->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
} elseif ($user_role === 'Tech Coordinator') {
    try {
        $active_requests = $db->query("SELECT COUNT(*) FROM tech_requests WHERE status = 'Pending'")->fetchColumn();
        $unresolved_tickets = $db->query("SELECT COUNT(*) FROM tickets WHERE status != 'Resolved'")->fetchColumn();
        
        $stmtReq = $db->query("
            SELECT tr.*, u.name as requester_name 
            FROM tech_requests tr 
            JOIN users u ON tr.user_id = u.id 
            ORDER BY tr.created_at DESC LIMIT 5
        ");
        $recent_tech_requests = $stmtReq->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
} elseif ($user_role === 'Content Coordinator') {
    try {
        $pending_content = $db->query("SELECT COUNT(*) FROM content_items WHERE status = 'Pending Approval'")->fetchColumn();
        $draft_content = $db->query("SELECT COUNT(*) FROM content_items WHERE status = 'Draft'")->fetchColumn();
        $published_content = $db->query("SELECT COUNT(*) FROM content_items WHERE status = 'Published'")->fetchColumn();
        
        $stmtCnt = $db->query("
            SELECT ci.*, u.name as author_name 
            FROM content_items ci 
            JOIN users u ON ci.user_id = u.id 
            ORDER BY ci.created_at DESC LIMIT 5
        ");
        $content_pipeline = $stmtCnt->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
} elseif ($user_role === 'Social Media Coord.') {
    try {
        $pending_social = $db->query("SELECT COUNT(*) FROM social_campaigns WHERE status = 'Draft'")->fetchColumn();
        $approved_social = $db->query("SELECT COUNT(*) FROM social_campaigns WHERE status = 'Approved'")->fetchColumn();
        
        $stmtCmp = $db->query("
            SELECT sc.*, u.name as publisher_name 
            FROM social_campaigns sc 
            JOIN users u ON sc.user_id = u.id 
            ORDER BY sc.created_at DESC LIMIT 5
        ");
        $social_pipeline = $stmtCmp->fetchAll();
        
        $cert_shares_count = $db->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
} elseif ($user_role === 'Club Member') {
    try {
        $points_q = $db->prepare("SELECT points FROM users WHERE id = ?");
        $points_q->execute([$user_id]);
        $_SESSION['user_points'] = $points_q->fetchColumn();
        $my_points = $_SESSION['user_points'];
        
        $my_rank = $db->prepare("SELECT COUNT(*) + 1 FROM users WHERE points > ?");
        $my_rank->execute([$my_points]);
        $rank = $my_rank->fetchColumn();
        
        $my_events_count = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?");
        $my_events_count->execute([$user_id]);
        $registered_count = $my_events_count->fetchColumn();
        
        $my_quizzes_count = $db->prepare("SELECT COUNT(DISTINCT quiz_id) FROM quiz_attempts WHERE user_id = ? AND passed = 1");
        $my_quizzes_count->execute([$user_id]);
        $quizzes_count = $my_quizzes_count->fetchColumn();
        
        $stmtMyEv = $db->prepare("
            SELECT e.*, r.status as reg_status 
            FROM registrations r 
            JOIN events e ON r.event_id = e.id 
            WHERE r.user_id = ? AND e.date >= date('now') 
            ORDER BY e.date ASC LIMIT 5
        ");
        $stmtMyEv->execute([$user_id]);
        $my_upcoming_events = $stmtMyEv->fetchAll();

        $stmtRecQ = $db->prepare("
            SELECT * FROM quizzes 
            WHERE id NOT IN (SELECT quiz_id FROM quiz_attempts WHERE user_id = ? AND passed = 1) LIMIT 3
        ");
        $stmtRecQ->execute([$user_id]);
        $rec_quizzes = $stmtRecQ->fetchAll();

        $level = 1;
        $next_level_xp = 100;
        $prev_level_xp = 0;
        if ($my_points >= 500) {
            $level = 4;
            $next_level_xp = 1000;
            $prev_level_xp = 500;
        } elseif ($my_points >= 250) {
            $level = 3;
            $next_level_xp = 500;
            $prev_level_xp = 250;
        } elseif ($my_points >= 100) {
            $level = 2;
            $next_level_xp = 250;
            $prev_level_xp = 100;
        }
        $level_progress = (($my_points - $prev_level_xp) / ($next_level_xp - $prev_level_xp)) * 100;
        $level_progress = max(0, min(100, $level_progress));

        $stmtMyBadges = $db->prepare("
            SELECT b.* FROM user_badges ub 
            JOIN badges b ON ub.badge_id = b.id 
            WHERE ub.user_id = ?
        ");
        $stmtMyBadges->execute([$user_id]);
        $my_badges = $stmtMyBadges->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
} else {
    try {
        $stmtCheckInterest = $db->prepare("SELECT * FROM interest_forms WHERE user_id = ? LIMIT 1");
        $stmtCheckInterest->execute([$user_id]);
        $interest_details = $stmtCheckInterest->fetch();
        $interest_submitted = !empty($interest_details);

        $upcoming_workshops_guest = $db->query("SELECT * FROM events WHERE date >= date('now') ORDER BY date ASC LIMIT 3")->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch guest metrics.');
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php 
$flash = get_flash_message();
if ($flash): 
?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fa-solid <?php echo ($flash['type'] === 'success') ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
        <span><?php echo sanitize($flash['message']); ?></span>
    </div>
<?php endif; ?>

<!-- Metric Stats Widget Grid -->
<div class="stats-grid">
    <?php if ($user_role === 'Faculty Coordinator'): ?>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Total Registrations</h3>
                <div class="stat-value text-cyan"><?php echo $total_users; ?></div>
            </div>
            <i class="fa-solid fa-users stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Awaiting Approval</h3>
                <div class="stat-value text-warning"><?php echo $total_pending; ?></div>
            </div>
            <i class="fa-solid fa-user-clock stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Created Events</h3>
                <div class="stat-value text-purple"><?php echo $total_events; ?></div>
            </div>
            <i class="fa-solid fa-calendar-check stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Active Support Cases</h3>
                <div class="stat-value text-pink"><?php echo $open_tickets; ?></div>
            </div>
            <i class="fa-solid fa-ticket stat-icon"></i>
        </div>

    <?php elseif ($user_role === 'Student Coordinator'): ?>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Active Members</h3>
                <div class="stat-value text-cyan"><?php echo $active_members; ?></div>
            </div>
            <i class="fa-solid fa-user-shield stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Upcoming Events</h3>
                <div class="stat-value text-success"><?php echo $upcoming_events_count; ?></div>
            </div>
            <i class="fa-solid fa-calendar-plus stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Unresolved Support</h3>
                <div class="stat-value text-warning"><?php echo $pending_tickets; ?></div>
            </div>
            <i class="fa-solid fa-envelope-open-text stat-icon"></i>
        </div>
        <a href="approvals.php" class="stat-card" style="text-decoration:none; display:flex; border-color:rgba(112,0,255,0.15);">
            <div class="stat-info">
                <h3>Pending Approvals</h3>
                <div class="stat-value text-purple"><?php echo $pending_approvals_count; ?></div>
            </div>
            <i class="fa-solid fa-square-check stat-icon text-purple"></i>
        </a>

    <?php elseif ($user_role === 'Tech Coordinator'): ?>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Active IT Requests</h3>
                <div class="stat-value text-cyan"><?php echo $active_requests; ?></div>
            </div>
            <i class="fa-solid fa-microchip stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Sandbox Systems</h3>
                <div class="stat-value text-success">3 / 4</div>
            </div>
            <i class="fa-solid fa-server stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Open Support Cases</h3>
                <div class="stat-value text-warning"><?php echo $unresolved_tickets; ?></div>
            </div>
            <i class="fa-solid fa-ticket stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>IT Access Level</h3>
                <div class="stat-value text-pink">Root/IT</div>
            </div>
            <i class="fa-solid fa-screwdriver-wrench stat-icon"></i>
        </div>

    <?php elseif ($user_role === 'Content Coordinator'): ?>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Pending Approvals</h3>
                <div class="stat-value text-warning"><?php echo $pending_content; ?></div>
            </div>
            <i class="fa-solid fa-clock stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Draft Articles</h3>
                <div class="stat-value text-cyan"><?php echo $draft_content; ?></div>
            </div>
            <i class="fa-solid fa-file-signature stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Published Guides</h3>
                <div class="stat-value text-success"><?php echo $published_content; ?></div>
            </div>
            <i class="fa-solid fa-book-open stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>SEO Score</h3>
                <div class="stat-value text-pink">94%</div>
            </div>
            <i class="fa-solid fa-chart-line stat-icon"></i>
        </div>

    <?php elseif ($user_role === 'Social Media Coord.'): ?>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Pending Releases</h3>
                <div class="stat-value text-warning"><?php echo $pending_social; ?></div>
            </div>
            <i class="fa-solid fa-share-nodes stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Approved Queue</h3>
                <div class="stat-value text-success"><?php echo $approved_social; ?></div>
            </div>
            <i class="fa-solid fa-circle-check stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Shared Credentials</h3>
                <div class="stat-value text-cyan"><?php echo $cert_shares_count; ?></div>
            </div>
            <i class="fa-solid fa-graduation-cap stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Reach Target</h3>
                <div class="stat-value text-pink">5.0K</div>
            </div>
            <i class="fa-solid fa-gauge-high stat-icon"></i>
        </div>

    <?php elseif ($user_role === 'Club Member'): ?>
        <div class="stat-card">
            <div class="stat-info">
                <h3>My Shield Level</h3>
                <div class="stat-value text-cyan"><?php echo $my_points; ?> <span style="font-size: 0.9rem; font-weight: normal;">XP</span></div>
            </div>
            <i class="fa-solid fa-shield-halved stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>My Ranking</h3>
                <div class="stat-value text-purple">#<?php echo $rank; ?></div>
            </div>
            <i class="fa-solid fa-trophy stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>My Event RSVPs</h3>
                <div class="stat-value text-success"><?php echo $registered_count; ?></div>
            </div>
            <i class="fa-solid fa-calendar-day stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Quizzes Passed</h3>
                <div class="stat-value text-pink"><?php echo $quizzes_count; ?></div>
            </div>
            <i class="fa-solid fa-award stat-icon"></i>
        </div>

    <?php else: ?>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Access Status</h3>
                <div class="stat-value text-warning">Guest</div>
            </div>
            <i class="fa-solid fa-user-lock stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>My Ranking</h3>
                <div class="stat-value text-muted">Unranked</div>
            </div>
            <i class="fa-solid fa-trophy stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>My Points</h3>
                <div class="stat-value text-muted">0 XP</div>
            </div>
            <i class="fa-solid fa-shield-halved stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Verification Level</h3>
                <div class="stat-value text-purple">L0 - Guest</div>
            </div>
            <i class="fa-solid fa-user-check stat-icon"></i>
        </div>
    <?php endif; ?>
</div>

<div class="dashboard-layout">
    <!-- Main Left Column -->
    <div>
        <?php if ($user_role === 'Faculty Coordinator'): ?>
            <!-- FACULTY VIEW: Approvals, Policy controls & Logs -->
            <div class="card card-pink" style="margin-bottom: 30px;">
                <h3 class="card-title text-pink"><i class="fa-solid fa-user-check"></i> Pending Approvals</h3>
                <?php if (!empty($pending_users_list)): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Registered Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_users_list as $p_user): ?>
                                    <tr>
                                        <td><strong><?php echo sanitize($p_user['name']); ?></strong></td>
                                        <td><?php echo sanitize($p_user['email']); ?></td>
                                        <td><?php echo sanitize($p_user['created_at']); ?></td>
                                        <td>
                                            <a href="admin.php?action=approve&id=<?php echo $p_user['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding: 5px 10px; font-size: 0.75rem;"><i class="fa-solid fa-check"></i> Approve</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 10px;">All account registrations have been reviewed. No pending approvals.</p>
                <?php endif; ?>
            </div>

            <!-- Global Policy Panel (Simulated switches) -->
            <div class="card" style="margin-bottom: 30px;">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-shield-halved"></i> Global Policy Control Panel</h3>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 15px;">Configure real-time security restrictions across the CyberKavach node system.</p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <form action="dashboard.php" method="POST" style="background: var(--bg-surface-elevated); padding: 12px; border: 1px solid var(--border-glow); border-radius: 4px;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="policy_name" value="Enforce Multi-Factor Authentication">
                        <input type="hidden" name="policy_state" value="Enabled">
                        <input type="hidden" name="toggle_policy" value="1">
                        <div style="font-weight: 600; font-size: 0.85rem;">Enforce 2FA Authenticator</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 10px;">Require 2FA tokens for all coordinator accounts.</div>
                        <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.7rem;"><i class="fa-solid fa-lock"></i> Enable 2FA Restriction</button>
                    </form>
                    <form action="dashboard.php" method="POST" style="background: var(--bg-surface-elevated); padding: 12px; border: 1px solid var(--border-glow); border-radius: 4px;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="policy_name" value="Lock Public Registrations">
                        <input type="hidden" name="policy_state" value="Active">
                        <input type="hidden" name="toggle_policy" value="1">
                        <div style="font-weight: 600; font-size: 0.85rem;">Lock Portal Registrations</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 10px;">Block new public user signups temporarily.</div>
                        <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 0.7rem;"><i class="fa-solid fa-user-lock"></i> Enforce Signup Lock</button>
                    </form>
                </div>
            </div>

            <!-- Appreciation Badge Form -->
            <div class="card card-pink" style="margin-bottom: 30px;">
                <h3 class="card-title text-pink"><i class="fa-solid fa-award"></i> Assign Appreciation Award</h3>
                <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:15px;">Award a digital badge of merit to highlight outstanding members.</p>
                <form action="dashboard.php" method="POST" style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                        <label for="target_member_id" class="form-label">Select Club Member</label>
                        <select id="target_member_id" name="target_member_id" class="form-control" style="height:40px;" required>
                            <option value="" disabled selected>-- Select Member --</option>
                            <?php foreach ($all_members as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo sanitize($m['name']); ?> (<?php echo sanitize($m['email']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                        <label for="badge_name" class="form-label">Select Merit Badge</label>
                        <select id="badge_name" name="badge_name" class="form-control" style="height:40px;" required>
                            <option value="" disabled selected>-- Select Badge --</option>
                            <?php foreach ($all_badges as $b): ?>
                                <option value="<?php echo sanitize($b['name']); ?>"><?php echo sanitize($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="award_badge_action" class="btn btn-primary" style="height:40px;"><i class="fa-solid fa-gift"></i> Bestow Badge</button>
                </form>
            </div>

            <div class="card">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-server"></i> System Security Logs</h3>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Actor</th>
                                <th>Action</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_logs as $log): ?>
                                <tr>
                                    <td style="font-size: 0.8rem; font-family: monospace;"><?php echo sanitize($log['created_at']); ?></td>
                                    <td><strong><?php echo sanitize($log['user_name'] ?? 'System / Guest'); ?></strong></td>
                                    <td><span class="badge badge-member" style="font-size:0.65rem;"><?php echo sanitize($log['action']); ?></span></td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo sanitize($log['details']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($user_role === 'Student Coordinator'): ?>
            <!-- STUDENT COORD VIEW: Scheduled workshops, Approvals, Leaderboard highlight -->
            <div class="card card-success" style="margin-bottom: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 class="card-title text-success" style="margin-bottom:0;"><i class="fa-solid fa-calendar-day"></i> Upcoming Workshops</h3>
                    <a href="events.php" class="btn btn-success" style="padding: 6px 12px; font-size: 0.75rem;"><i class="fa-solid fa-plus"></i> New Event</a>
                </div>
                <?php if (!empty($upcoming_events)): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Event Title</th>
                                    <th>Schedule Date</th>
                                    <th>Location</th>
                                    <th>Award</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming_events as $event): ?>
                                    <tr>
                                        <td><strong><a href="events.php" class="text-cyan"><?php echo sanitize($event['title']); ?></a></strong></td>
                                        <td><?php echo sanitize($event['date']) . ' at ' . sanitize($event['time']); ?></td>
                                        <td><?php echo sanitize($event['location']); ?></td>
                                        <td><span class="badge badge-member">+<?php echo $event['points_reward']; ?> XP</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 10px;">No scheduled workshops currently.</p>
                <?php endif; ?>
            </div>

            <!-- Approvals summary panel -->
            <div class="card" style="margin-bottom: 30px;">
                <h3 class="card-title text-purple"><i class="fa-solid fa-square-check"></i> Pending Approvals Dispatch</h3>
                <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:15px;">You have <strong><?php echo $pending_approvals_count; ?></strong> pending tasks awaiting action.</p>
                <div style="display:flex; gap:15px;">
                    <a href="approvals.php" class="btn btn-primary" style="flex:1;"><i class="fa-solid fa-users-gear"></i> Member Registrations (<?php echo $pending_users_app; ?>)</a>
                    <a href="approvals.php" class="btn btn-secondary" style="flex:1;"><i class="fa-solid fa-calendar-check"></i> Co-Sign Events (<?php echo $pending_events_app; ?>)</a>
                </div>
            </div>

            <!-- Leaderboard Highlight View -->
            <div class="card">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-ranking-star"></i> Top Members Highlight</h3>
                <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 15px;">
                    <?php 
                    $pos = 1;
                    foreach ($leaderboard_top as $top_user): 
                    ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; background:var(--bg-surface-elevated); padding:10px 15px; border-radius:4px; border:1px solid var(--border-glow);">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span style="font-family:var(--font-heading); font-weight:bold; font-size:1.1rem; width:20px;"><?php echo $pos++; ?></span>
                                <strong><?php echo sanitize($top_user['name']); ?></strong>
                            </div>
                            <span style="font-weight:bold; color:var(--text-primary);"><?php echo $top_user['points']; ?> XP</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($user_role === 'Tech Coordinator'): ?>
            <!-- TECH COORD VIEW: IT Requests logs & repo manager -->
            <div class="card card-pink" style="margin-bottom: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 class="card-title text-pink" style="margin-bottom:0;"><i class="fa-solid fa-microchip"></i> IT & Technical Resources Requests</h3>
                    <a href="tech_module.php" class="btn btn-danger" style="padding: 6px 12px; font-size: 0.75rem;"><i class="fa-solid fa-arrow-right"></i> Open Console</a>
                </div>
                <?php if (!empty($recent_tech_requests)): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Requester</th>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_tech_requests as $t_req): ?>
                                    <tr>
                                        <td><strong><?php echo sanitize($t_req['requester_name']); ?></strong></td>
                                        <td>
                                            <div style="font-weight:600;"><?php echo sanitize($t_req['title']); ?></div>
                                            <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo sanitize($t_req['description']); ?></div>
                                        </td>
                                        <td style="font-size:0.75rem; font-weight:600;"><?php echo sanitize($t_req['request_type']); ?></td>
                                        <td><span class="badge badge-status-<?php echo strtolower($t_req['status']); ?>"><?php echo sanitize($t_req['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">No technical requests logged yet.</p>
                <?php endif; ?>
            </div>

            <!-- Resource VM Status Dashboard -->
            <div class="card" style="margin-bottom: 30px;">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-server"></i> Sandbox Systems Status Dashboard</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                    <div style="background:var(--bg-surface-elevated); border:1px solid var(--border-glow); padding:12px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <strong>Kali Sandbox Server</strong>
                            <div style="font-size:0.75rem; color:var(--text-muted);">Status: Online</div>
                        </div>
                        <form action="dashboard.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="vm_name" value="Kali Sandbox Server">
                            <input type="hidden" name="vm_state" value="Rebooting">
                            <button type="submit" name="vm_control" class="btn btn-secondary" style="padding:4px 8px; font-size:0.65rem;"><i class="fa-solid fa-rotate"></i> Reboot</button>
                        </form>
                    </div>
                    <div style="background:var(--bg-surface-elevated); border:1px solid var(--border-glow); padding:12px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <strong>Lab Router Wireshark B</strong>
                            <div style="font-size:0.75rem; color:var(--text-muted);">Status: Maintenance</div>
                        </div>
                        <form action="dashboard.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="vm_name" value="Lab Router Wireshark B">
                            <input type="hidden" name="vm_state" value="Online">
                            <button type="submit" name="vm_control" class="btn btn-primary" style="padding:4px 8px; font-size:0.65rem;"><i class="fa-solid fa-play"></i> Start VM</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Repo/Tool Access manager Quick Form -->
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-screwdriver-wrench"></i> Quick IT Privileges Request</h3>
                <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:15px;">Need VM provisioning, port forwarding, or repository writes? Submit a quick resource ticket.</p>
                <form action="tech_module.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="submit_request" value="1">
                    <div style="display:flex; gap:10px; margin-bottom:10px;">
                        <select name="request_type" class="form-control" style="flex:1;" required>
                            <option value="Resource Allocation">Resource Allocation</option>
                            <option value="Repo Access">Repo Write Privileges</option>
                            <option value="IT Support Task">IT Support Task</option>
                        </select>
                        <input type="text" name="resource_name" class="form-control" placeholder="Target VM / Repo" style="flex:1;">
                    </div>
                    <div style="display:flex; gap:10px; margin-bottom:10px;">
                        <input type="text" name="title" class="form-control" placeholder="Brief Title" style="flex:1;" required>
                    </div>
                    <textarea name="description" class="form-control" placeholder="Reasoning..." rows="2" style="margin-bottom:10px;" required></textarea>
                    <button type="submit" class="btn btn-danger btn-block" style="font-size:0.75rem;"><i class="fa-solid fa-paper-plane"></i> Dispatch Request</button>
                </form>
            </div>

        <?php elseif ($user_role === 'Content Coordinator'): ?>
            <!-- CONTENT COORD VIEW: Blog calendar, newsletter builder -->
            <div class="card card-success" style="margin-bottom: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 class="card-title text-success" style="margin-bottom:0;"><i class="fa-solid fa-newspaper"></i> Content Pipeline</h3>
                    <a href="content_module.php" class="btn btn-success" style="padding: 6px 12px; font-size: 0.75rem;"><i class="fa-solid fa-arrow-right"></i> Editor Board</a>
                </div>
                <?php if (!empty($content_pipeline)): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Author</th>
                                    <th>Article Title</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($content_pipeline as $c_item): ?>
                                    <tr>
                                        <td><strong><?php echo sanitize($c_item['author_name']); ?></strong></td>
                                        <td>
                                            <div style="font-weight:600;"><?php echo sanitize($c_item['title']); ?></div>
                                            <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo sanitize(substr($c_item['body'], 0, 80)) . '...'; ?></div>
                                        </td>
                                        <td><span style="font-size:0.7rem; font-weight:600; text-transform:uppercase;"><?php echo sanitize($c_item['content_type']); ?></span></td>
                                        <td>
                                            <span class="badge badge-status-<?php echo ($c_item['status'] === 'Published') ? 'active' : 'pending'; ?>">
                                                <?php echo sanitize($c_item['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">No content drafts submitted in database.</p>
                <?php endif; ?>
            </div>

            <!-- Content Calendar View -->
            <div class="card" style="margin-bottom: 30px;">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-calendar-days"></i> Content Calendar Feed</h3>
                <div style="display:flex; flex-direction:column; gap:10px; margin-top:15px;">
                    <?php 
                    $calendar_count = 0;
                    foreach ($content_pipeline as $c_item) {
                        if ($c_item['status'] === 'Published' && !empty($c_item['scheduled_date'])) {
                            $calendar_count++;
                            ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--bg-surface-elevated); padding:10px 15px; border-radius:4px; border:1px solid var(--border-glow);">
                                <div>
                                    <span style="font-size:0.7rem; font-weight:700; text-transform:uppercase; background:#000000; color:#ffffff; padding:2px 6px; border-radius:3px; margin-right:8px;"><?php echo sanitize($c_item['content_type']); ?></span>
                                    <strong style="font-size:0.85rem;"><?php echo sanitize($c_item['title']); ?></strong>
                                </div>
                                <span style="font-size:0.8rem; font-family:monospace; color:var(--text-muted);"><?php echo sanitize($c_item['scheduled_date']); ?></span>
                            </div>
                            <?php
                        }
                    }
                    if ($calendar_count === 0) {
                        echo '<p style="color:var(--text-muted); font-size:0.8rem;">No scheduled posts published in the calendar feed.</p>';
                    }
                    ?>
                </div>
            </div>

            <!-- Quick Draft Creator Form -->
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-pen-nib"></i> Compose Quick Content Draft</h3>
                <form action="content_module.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="submit_content" value="1">
                    <div style="display:flex; gap:10px; margin-bottom:10px;">
                        <select name="content_type" class="form-control" style="flex:1;" required>
                            <option value="Blog Post">Blog Article</option>
                            <option value="Newsletter">Newsletter Draft</option>
                            <option value="CTF Guide">CTF Solution Write-up</option>
                        </select>
                        <input type="date" name="scheduled_date" class="form-control" style="flex:1;">
                    </div>
                    <div style="margin-bottom:10px;">
                        <input type="text" name="title" class="form-control" placeholder="Article / Newsletter Title" required>
                    </div>
                    <textarea name="body" class="form-control" placeholder="Write draft text here..." rows="3" style="margin-bottom:10px;" required></textarea>
                    <button type="submit" class="btn btn-danger btn-block" style="font-size:0.75rem;"><i class="fa-solid fa-cloud-arrow-up"></i> Deploy Content Draft</button>
                </form>
            </div>

        <?php elseif ($user_role === 'Social Media Coord.'): ?>
            <!-- SOCIAL COORD VIEW: Campaigns, certificate shares -->
            <div class="card card-pink" style="margin-bottom: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 class="card-title text-pink" style="margin-bottom:0;"><i class="fa-solid fa-share-nodes"></i> Campaigns Pipeline</h3>
                    <a href="social_module.php" class="btn btn-danger" style="padding: 6px 12px; font-size: 0.75rem;"><i class="fa-solid fa-arrow-right"></i> Media Desk</a>
                </div>
                <?php if (!empty($social_pipeline)): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Platform</th>
                                    <th>Content</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($social_pipeline as $s_camp): ?>
                                    <tr>
                                        <td><strong><i class="fa-brands fa-<?php echo strtolower($s_camp['platform']); ?>"></i> <?php echo sanitize($s_camp['platform']); ?></strong></td>
                                        <td style="font-size:0.8rem; color:var(--text-muted);"><?php echo sanitize($s_camp['content']); ?></td>
                                        <td><span class="badge badge-status-<?php echo ($s_camp['status'] === 'Shared') ? 'active' : 'pending'; ?>"><?php echo sanitize($s_camp['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">No social campaigns scheduled yet.</p>
                <?php endif; ?>
            </div>

            <!-- Shared Certificate Analytics -->
            <div class="card" style="margin-bottom:30px;">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-chart-line"></i> Shared Certificates Analytics</h3>
                <p style="color:var(--text-muted); font-size:0.8rem;">Monitor verify-logs of automated student credential validation links published on public channels.</p>
                <div style="background:var(--bg-surface-elevated); border:1px solid var(--border-glow); padding:15px; border-radius:4px; margin-top:15px; text-align:center;">
                    <div style="font-size:1.8rem; font-weight:800; font-family:var(--font-heading);"><?php echo $cert_shares_count; ?></div>
                    <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; margin-top:4px;">Unique Certificate UUIDs Live</div>
                </div>
            </div>

            <!-- Quick Social Release Form -->
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-hashtag"></i> Fast Social Campaign Release</h3>
                <form action="social_module.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="submit_campaign" value="1">
                    <div style="display:flex; gap:10px; margin-bottom:10px;">
                        <select name="platform" class="form-control" style="flex:1;" required>
                            <option value="LinkedIn">LinkedIn</option>
                            <option value="Twitter">Twitter / X</option>
                            <option value="Discord">Discord</option>
                        </select>
                        <input type="datetime-local" name="scheduled_time" class="form-control" style="flex:1;">
                    </div>
                    <textarea name="content" class="form-control" placeholder="Write social post content here..." rows="3" style="margin-bottom:10px;" required></textarea>
                    <button type="submit" class="btn btn-danger btn-block" style="font-size:0.75rem;"><i class="fa-solid fa-paper-plane"></i> Deploy Social Campaign</button>
                </form>
            </div>

        <?php elseif ($user_role === 'Club Member'): ?>
            <!-- MEMBER VIEW: XP Rank, Spotlights, Achievements & Registered workshops -->
            
            <!-- Rank Progression Panel -->
            <div class="card" style="margin-bottom: 30px; border-color: var(--border-glow);">
                <h3 class="card-title"><i class="fa-solid fa-ranking-star"></i> Rank Progress</h3>
                <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.85rem; margin-bottom:8px;">
                    <span>Level <?php echo $level; ?>: <strong><?php echo sanitize($user_role); ?></strong></span>
                    <span style="color:#000000; font-weight:bold;"><?php echo $my_points; ?> / <?php echo $next_level_xp; ?> XP</span>
                </div>
                <div style="width:100%; height:12px; background:rgba(0,0,0,0.08); border:1px solid var(--border-glow); border-radius:6px; overflow:hidden; position:relative;">
                    <div style="width:<?php echo $level_progress; ?>%; height:100%; background:#000000; border-radius:5px; transition:width 1s ease;"></div>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--text-muted); margin-top:8px;">
                    <span><?php echo $prev_level_xp; ?> XP</span>
                    <span>Next Rank: <?php echo $next_level_xp - $my_points; ?> XP Required</span>
                </div>
            </div>

            <!-- Member Spotlight Showcase -->
            <?php if (!empty($spotlights)): ?>
                <div class="card" style="margin-bottom: 30px; border-color: var(--border-glow);">
                    <h3 class="card-title"><i class="fa-solid fa-trophy"></i> Member Spotlight</h3>
                    <div style="display:flex; flex-direction:column; gap:12px; margin-top:10px;">
                        <?php foreach ($spotlights as $spot): ?>
                            <div style="background:rgba(0,0,0,0.02); border-left:3px solid #000000; padding:12px; border-radius: 0 4px 4px 0;">
                                <div style="font-family:var(--font-heading); font-size:0.9rem; color:#000000; font-weight:700;">
                                    <?php echo sanitize($spot['nominee_name']); ?>
                                </div>
                                <div style="font-size:0.8rem; font-weight:bold; color:#000000; margin:3px 0;"><?php echo sanitize($spot['title']); ?></div>
                                <p style="font-size:0.8rem; color:var(--text-muted); line-height:1.4;"><?php echo sanitize($spot['reason']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Earned Achievements Vault -->
            <div class="card" style="margin-bottom: 30px; border-color: var(--border-glow);">
                <h3 class="card-title"><i class="fa-solid fa-award"></i> Digital Badge Vault</h3>
                <?php if (!empty($my_badges)): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:15px;">
                        <?php foreach ($my_badges as $bdg): ?>
                            <div style="background:var(--bg-surface-elevated); border:1px solid var(--border-glow); padding:10px 15px; border-radius:4px; display:flex; align-items:center; gap:10px; min-width:180px;" title="<?php echo sanitize($bdg['description']); ?>">
                                <i class="fa-solid <?php echo sanitize($bdg['icon']); ?>" style="font-size:1.4rem; color:#000000;"></i>
                                <div>
                                    <div style="font-size:0.85rem; font-weight:bold;"><?php echo sanitize($bdg['name']); ?></div>
                                    <div style="font-size:0.7rem; color:var(--text-muted);">Badge Earned</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.85rem; margin-top:10px;">Sharpen your skills. Deploy labs or join workshops to unlock achievements.</p>
                <?php endif; ?>
            </div>

            <!-- MEMBER VIEW: My registered events & announcements -->
            <div class="card card-success" style="margin-bottom: 30px;">
                <h3 class="card-title text-success"><i class="fa-solid fa-user-check"></i> Registered Workshops</h3>
                <?php if (!empty($my_upcoming_events)): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Workshop</th>
                                    <th>Schedule Date</th>
                                    <th>Location</th>
                                    <th>Value</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($my_upcoming_events as $m_event): ?>
                                    <tr>
                                        <td><strong><?php echo sanitize($m_event['title']); ?></strong></td>
                                        <td><?php echo sanitize($m_event['date']) . ' at ' . sanitize($m_event['time']); ?></td>
                                        <td><?php echo sanitize($m_event['location']); ?></td>
                                        <td><span class="badge badge-member">+<?php echo $m_event['points_reward']; ?> XP</span></td>
                                        <td>
                                            <span class="badge badge-status-<?php echo strtolower($m_event['reg_status']); ?>">
                                                <?php echo sanitize($m_event['reg_status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 10px;">
                        You have not RSVP'd for any upcoming events. 
                        <a href="events.php" class="text-cyan" style="font-weight:600;">Check event calendar</a>.
                    </p>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- GUEST VIEW: Participant Welcome viewport, interest form -->
            <div class="card card-pink" style="margin-bottom: 30px;">
                <h3 class="card-title text-pink"><i class="fa-solid fa-user-ninja"></i> Welcome to CyberKavach!</h3>
                <p style="margin-top:10px; line-height:1.6;">
                    Hello, <strong><?php echo sanitize($user_name); ?></strong>! Your account is currently verified at the **Student/Participant** guest privilege level.
                </p>
                <p style="margin-top:10px; color:var(--text-muted); font-size:0.9rem;">
                    As a participant, you can view notices, RSVP for upcoming club events, and browse the platform. Submit the Interest & Volunteering request below to apply for **Club Member** promotion, which unlocks security quizzes, leaderboard progression, and digital credentials badges.
                </p>
            </div>

            <!-- Account Verification status note -->
            <div class="card" style="margin-bottom: 30px;">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-shield-halved"></i> Membership Elevation Status</h3>
                <?php if ($interest_submitted): ?>
                    <div style="background:var(--bg-surface-elevated); border:1px solid var(--border-glow); padding:15px; border-radius:4px; display:flex; flex-direction:column; gap:10px; margin-top:15px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong>Interest Request Logged</strong>
                            <span class="badge badge-status-pending" style="font-size:0.7rem;">Awaiting Review</span>
                        </div>
                        <div style="font-size:0.85rem;"><strong>Proposed Area:</strong> <?php echo sanitize($interest_details['interest_area']); ?></div>
                        <div style="font-size:0.85rem; color:var(--text-muted);"><strong>Reasoning:</strong> <?php echo sanitize($interest_details['reason']); ?></div>
                    </div>
                <?php else: ?>
                    <div style="display:flex; align-items:center; gap:12px; margin-top:15px;">
                        <i class="fa-solid fa-circle-exclamation text-warning" style="font-size:1.8rem;"></i>
                        <div>
                            <strong>No promotion request on file</strong>
                            <div style="font-size:0.8rem; color:var(--text-muted);">Submit the form below to initiate your membership upgrade pipeline.</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Guest Interest & Volunteering Form -->
            <?php if (!$interest_submitted): ?>
                <div class="card card-pink" style="margin-bottom: 30px;">
                    <h3 class="card-title text-pink"><i class="fa-solid fa-file-signature"></i> Interest & Volunteering Form</h3>
                    <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:15px;">Specify your security interests and reasons for wanting to upgrade to Club Member.</p>
                    <form action="dashboard.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label for="interest_area" class="form-label">Primary Cybersecurity Interest Area</label>
                            <select id="interest_area" name="interest_area" class="form-control" required>
                                <option value="" disabled selected>-- Choose Area --</option>
                                <option value="Web Penetration Testing">Web Penetration Testing & OWASP</option>
                                <option value="Cryptography & Cyber Defense">Cryptography & Cyber Defense</option>
                                <option value="Reverse Engineering & Malware Analysis">Reverse Engineering & Malware Analysis</option>
                                <option value="Network Auditing & Wireless Hacking">Network Auditing & Wireless Hacking</option>
                                <option value="IT Administration & Security Policy">IT Administration & Security Policy</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label for="reason" class="form-label">Why do you want to join as a full Club Member?</label>
                            <textarea id="reason" name="reason" class="form-control" rows="4" placeholder="Mention any past projects, coding background, or cybersecurity learning goals..." required></textarea>
                        </div>

                        <button type="submit" name="submit_interest" class="btn btn-danger btn-block">
                            <i class="fa-solid fa-paper-plane"></i> Submit Membership Request
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Available Workshops Grid -->
            <div class="card">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-calendar-days"></i> Upcoming Learning Workshops</h3>
                <div style="display:flex; flex-direction:column; gap:10px; margin-top:15px;">
                    <?php if (!empty($upcoming_workshops_guest)): ?>
                        <?php foreach ($upcoming_workshops_guest as $ev): ?>
                            <div style="background:var(--bg-surface-elevated); padding:10px 15px; border-radius:4px; border:1px solid var(--border-glow); display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <strong style="font-size:0.9rem;"><?php echo sanitize($ev['title']); ?></strong>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo sanitize($ev['date']) . ' at ' . sanitize($ev['time']) . ' | ' . sanitize($ev['location']); ?></div>
                                </div>
                                <a href="events.php" class="btn btn-primary" style="padding:4px 8px; font-size:0.7rem;">RSVP</a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:var(--text-muted); font-size:0.8rem;">No workshops scheduled currently.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Announcements / Notices board (Standard at the bottom for everyone except Members who have it separated) -->
        <?php if ($user_role !== 'Club Member'): ?>
            <div class="card" style="margin-top: 30px;">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-bullhorn"></i> Club Notice Feed</h3>
                <?php if (!empty($announcements)): ?>
                    <div class="notice-feed">
                        <?php foreach ($announcements as $ann): ?>
                            <div class="notice-item">
                                <div class="notice-meta">
                                    <span><i class="fa-solid fa-user-ninja"></i> <?php echo sanitize($ann['author_name'] ?? 'Admin'); ?></span>
                                    <span><i class="fa-solid fa-clock"></i> <?php echo sanitize($ann['created_at']); ?></span>
                                </div>
                                <div class="notice-title">
                                    <?php if ($ann['priority'] === 'High'): ?>
                                        <span class="badge badge-status-suspended" style="font-size:0.6rem; padding: 2px 4px; vertical-align: middle;">Urg</span>
                                    <?php endif; ?>
                                    <strong><?php echo sanitize($ann['title']); ?></strong>
                                </div>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;"><?php echo sanitize($ann['content']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 10px;">No announcement bulletins posted.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar Right Column -->
    <div>
        <!-- Notice Feed Widget (Only shown in right column for Club Members since it is inline for other roles) -->
        <?php if ($user_role === 'Club Member'): ?>
            <div class="card" style="margin-bottom: 30px;">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-bullhorn"></i> Notice Feed</h3>
                <?php if (!empty($announcements)): ?>
                    <div class="notice-feed">
                        <?php foreach ($announcements as $ann): ?>
                            <div class="notice-item" style="padding: 10px 0;">
                                <div class="notice-title" style="font-size: 0.9rem; margin-bottom: 2px;">
                                    <strong><?php echo sanitize($ann['title']); ?></strong>
                                </div>
                                <div style="font-size:0.75rem; color: var(--text-muted);"><?php echo sanitize(substr($ann['content'], 0, 70)) . (strlen($ann['content']) > 70 ? '...' : ''); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 10px;">No announcements.</p>
                <?php endif; ?>
                <div style="margin-top: 15px; text-align: right;">
                    <a href="announcements.php" class="text-cyan" style="font-size: 0.8rem; font-family: var(--font-heading);">Open Notice Board <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Role Specific Actions / Quick Widgets -->
        <?php if ($user_role === 'Faculty Coordinator'): ?>
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-gears"></i> Core System Tools</h3>
                <ul style="list-style: none; display: flex; flex-direction: column; gap: 10px; margin-top: 15px;">
                    <li>
                        <a href="admin.php" class="btn btn-danger btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-users-gear"></i> Manage User Accounts
                        </a>
                    </li>
                    <li>
                        <a href="events.php" class="btn btn-secondary btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-calendar-plus"></i> Schedule Security Event
                        </a>
                    </li>
                    <li>
                        <a href="announcements.php" class="btn btn-secondary btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-bullhorn"></i> Post Critical Alert
                        </a>
                    </li>
                </ul>
            </div>

        <?php elseif ($user_role === 'Student Coordinator'): ?>
            <div class="card card-success">
                <h3 class="card-title text-success"><i class="fa-solid fa-folder-open"></i> Quick Manager</h3>
                <ul style="list-style: none; display: flex; flex-direction: column; gap: 10px; margin-top: 15px;">
                    <li>
                        <a href="events.php" class="btn btn-success btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-calendar-plus"></i> Create New Event
                        </a>
                    </li>
                    <li>
                        <a href="quizzes.php" class="btn btn-secondary btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-plus-minus"></i> Create Security Quiz
                        </a>
                    </li>
                    <li>
                        <a href="announcements.php" class="btn btn-secondary btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-bullhorn"></i> Write New Notice
                        </a>
                    </li>
                </ul>
            </div>

        <?php elseif ($user_role === 'Tech Coordinator'): ?>
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-terminal"></i> Technical Quick Links</h3>
                <ul style="list-style: none; display: flex; flex-direction: column; gap: 10px; margin-top: 15px;">
                    <li>
                        <a href="tech_module.php" class="btn btn-danger btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-server"></i> Sandbox Systems Console
                        </a>
                    </li>
                    <li>
                        <a href="tickets.php" class="btn btn-secondary btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-ticket"></i> Open IT Support Tickets
                        </a>
                    </li>
                    <li>
                        <a href="https://github.com" target="_blank" class="btn btn-secondary btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-brands fa-github"></i> GitHub Organisation
                        </a>
                    </li>
                </ul>
            </div>

        <?php elseif ($user_role === 'Content Coordinator'): ?>
            <div class="card card-success">
                <h3 class="card-title text-success"><i class="fa-solid fa-pen-nib"></i> Content Quick Tools</h3>
                <ul style="list-style: none; display: flex; flex-direction: column; gap: 10px; margin-top: 15px;">
                    <li>
                        <a href="content_module.php" class="btn btn-success btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-newspaper"></i> Edit Publication Pipeline
                        </a>
                    </li>
                    <li>
                        <a href="announcements.php" class="btn btn-secondary btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-bullhorn"></i> Compose Club Notice
                        </a>
                    </li>
                </ul>
            </div>

        <?php elseif ($user_role === 'Social Media Coord.'): ?>
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-share-nodes"></i> Social Quick Tools</h3>
                <ul style="list-style: none; display: flex; flex-direction: column; gap: 10px; margin-top: 15px;">
                    <li>
                        <a href="social_module.php" class="btn btn-danger btn-block" style="font-size:0.75rem; justify-content: flex-start;">
                            <i class="fa-solid fa-hashtag"></i> Open Media Release Desk
                        </a>
                    </li>
                </ul>
            </div>

        <?php elseif ($user_role === 'Club Member'): ?>
            <!-- Recommended Quizzes for Members -->
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-fire"></i> Hot Challenges</h3>
                <?php if (!empty($rec_quizzes)): ?>
                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                        <?php foreach ($rec_quizzes as $quiz): ?>
                            <li style="border-bottom: 1px solid var(--border-glow); padding-bottom: 8px;">
                                <div style="font-size: 0.85rem; font-weight: 600;"><?php echo sanitize($quiz['title']); ?></div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-top: 5px;">
                                    <span style="font-size:0.75rem; color: var(--text-muted);">Reward: +<?php echo $quiz['points']; ?> XP</span>
                                    <a href="quizzes.php?id=<?php echo $quiz['id']; ?>" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.7rem;">Deploy Lab</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 10px;">Amazing! You've completed all available security quizzes.</p>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Guest Quick Info & FAQs -->
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-circle-info"></i> Guest Quick Guide</h3>
                <p style="font-size:0.8rem; color:var(--text-muted); line-height:1.5; margin-top:10px;">
                    Welcome! Once you submit the Interest & Volunteering form, a Faculty or Student Coordinator will review your submission and elevate your role. This will unlock the global leaderboard, achievements, certificates and sandbox labs.
                </p>
                <div style="border-top:1px dashed var(--border-glow); margin-top:15px; padding-top:10px; font-size:0.8rem;">
                    <i class="fa-solid fa-circle-question"></i> Need help? Drop by our <a href="tickets.php" class="text-cyan">Support Desk</a>.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
