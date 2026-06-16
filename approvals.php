<?php
$page_title = 'Approvals Dashboard';
$page_heading = 'Central Approvals Board';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Enforce Core (or higher, e.g. Admin) role
require_role('Core');

$user_id = intval($_SESSION['user_id']);
$user_role = get_user_role();
$error = '';
$success = '';

// -------------------------------------------------------------
// POST / GET ACTIONS
// -------------------------------------------------------------
// 1. Approve User Registration
if (isset($_GET['action']) && $_GET['action'] === 'approve_user') {
    $target_id = intval($_GET['id']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $stmt = $db->prepare("UPDATE users SET status = 'Active' WHERE id = ?");
            $stmt->execute([$target_id]);
            
            $stmtEmail = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmtEmail->execute([$target_id]);
            $target_email = $stmtEmail->fetchColumn();
            
            log_event($user_id, 'User Approval', "Approved account registration for ID $target_id: '$target_email'");
            set_flash_message('success', "Account approved: $target_email is now Active.");
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to approve account.');
        }
    }
    redirect('approvals.php');
}

// 2. Reject/Delete User Registration
if (isset($_GET['action']) && $_GET['action'] === 'reject_user') {
    $target_id = intval($_GET['id']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $stmtEmail = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmtEmail->execute([$target_id]);
            $target_email = $stmtEmail->fetchColumn();
            
            $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$target_id]);
            
            log_event($user_id, 'User Rejection', "Rejected and deleted pending registration ID $target_id: '$target_email'");
            set_flash_message('success', "Pending account registration rejected and removed.");
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to reject registration.');
        }
    }
    redirect('approvals.php');
}

// 3. Approve Proposed Event (Co-signing)
if (isset($_GET['action']) && $_GET['action'] === 'approve_event') {
    $event_id = intval($_GET['id']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            // Check if reviewer is the creator (multi-coordinator verification rule)
            $stmtEv = $db->prepare("SELECT created_by, title FROM events WHERE id = ?");
            $stmtEv->execute([$event_id]);
            $event = $stmtEv->fetch();
            
            if (!$event) {
                set_flash_message('error', 'Event not found.');
            } elseif (intval($event['created_by']) === $user_id && $user_role !== 'Admin') {
                // Admins can bypass co-signing rule; Core members cannot approve their own events
                set_flash_message('error', 'Security Violation: Multi-coordinator rule prevents approving your own proposed events.');
            } else {
                $stmt = $db->prepare("UPDATE events SET status = 'Approved' WHERE id = ?");
                $stmt->execute([$event_id]);
                
                log_event($user_id, 'Approve Event', "Co-signed/approved proposed event ID $event_id: '{$event['title']}'");
                set_flash_message('success', "Event '{$event['title']}' approved and added to global schedule.");
            }
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to approve event.');
        }
    }
    redirect('approvals.php');
}

// 4. Reject/Delete Proposed Event
if (isset($_GET['action']) && $_GET['action'] === 'reject_event') {
    $event_id = intval($_GET['id']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $stmtEv = $db->prepare("SELECT created_by, title FROM events WHERE id = ?");
            $stmtEv->execute([$event_id]);
            $event = $stmtEv->fetch();
            
            if (!$event) {
                set_flash_message('error', 'Event not found.');
            } else {
                $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
                $stmt->execute([$event_id]);
                
                log_event($user_id, 'Reject Event', "Rejected and deleted event proposal: '{$event['title']}'");
                set_flash_message('success', "Event proposal '{$event['title']}' rejected and removed.");
            }
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to reject event proposal.');
        }
    }
    redirect('approvals.php');
}

// 5. Deploy Member Spotlight nomination
if (isset($_POST['nominate_spotlight'])) {
    $nominee_id = intval($_POST['nominee_id'] ?? 0);
    $title = sanitize($_POST['title'] ?? '');
    $reason = sanitize($_POST['reason'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed.';
    } elseif ($nominee_id <= 0 || empty($title) || empty($reason)) {
        $error = 'All nomination fields are required.';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO spotlights (user_id, title, reason, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nominee_id, $title, $reason, $user_id]);
            
            // Log & feedback
            $stmtUser = $db->prepare("SELECT name FROM users WHERE id = ?");
            $stmtUser->execute([$nominee_id]);
            $nominee_name = $stmtUser->fetchColumn();
            
            log_event($user_id, 'Member Spotlight', "Nominated member '$nominee_name' for spotlight: '$title'");
            $success = "Member spotlight successfully configured for $nominee_name.";
        } catch (PDOException $e) {
            $error = 'Failed to record member spotlight: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------
// RENDER FETCH
// -------------------------------------------------------------
// 1. Fetch users awaiting approval
$pending_users = [];
try {
    $stmtPU = $db->query("SELECT * FROM users WHERE status = 'Pending' ORDER BY created_at DESC");
    $pending_users = $stmtPU->fetchAll();
} catch (PDOException $e) {}

// 2. Fetch events awaiting co-signing approval
$pending_events = [];
try {
    $stmtPE = $db->query("
        SELECT e.*, u.name as organizer_name 
        FROM events e 
        LEFT JOIN users u ON e.created_by = u.id 
        WHERE e.status = 'Pending Approval' 
        ORDER BY e.date ASC, e.time ASC
    ");
    $pending_events = $stmtPE->fetchAll();
} catch (PDOException $e) {}

// 3. Fetch active users (to select for spotlight nomination)
$active_members = [];
try {
    $stmtAM = $db->query("SELECT id, name, email FROM users WHERE status = 'Active' AND role = 'Club Member' ORDER BY name ASC");
    $active_members = $stmtAM->fetchAll();
} catch (PDOException $e) {}

// 4. Fetch existing spotlights
$existing_spotlights = [];
try {
    $stmtSpot = $db->query("
        SELECT s.*, u.name as nominee_name, cr.name as creator_name 
        FROM spotlights s 
        JOIN users u ON s.user_id = u.id 
        LEFT JOIN users cr ON s.created_by = cr.id 
        ORDER BY s.created_at DESC 
        LIMIT 10
    ");
    $existing_spotlights = $stmtSpot->fetchAll();
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
    <!-- LEFT COLUMN: APPROVAL QUEUES -->
    <div style="display:flex; flex-direction:column; gap:30px;">
        
        <!-- Queue 1: Proposed Events (Multi-coordinator validation) -->
        <div class="card card-pink">
            <h3 class="card-title text-pink"><i class="fa-solid fa-calendar-check"></i> Coordinator Event Proposals</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom: 20px;">Review and co-sign event schedules proposed by other coordinators. The multi-coordinator workflow prohibits approving your own proposals.</p>
            
            <?php if (!empty($pending_events)): ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Proposed By</th>
                                <th>Workshop Details</th>
                                <th>Venue</th>
                                <th>Reward</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_events as $pe): ?>
                                <?php $is_my_event = intval($pe['created_by']) === $user_id; ?>
                                <tr style="<?php echo $is_my_event ? 'background: rgba(0,0,0,0.03);' : ''; ?>">
                                    <td>
                                        <strong><?php echo sanitize($pe['organizer_name'] ?? 'Coordinator'); ?></strong>
                                        <?php if ($is_my_event): ?>
                                            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">(Your Proposal)</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:var(--text-primary);"><?php echo sanitize($pe['title']); ?></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted);"><?php echo sanitize($pe['date']) . ' at ' . sanitize($pe['time']); ?></div>
                                    </td>
                                    <td style="font-size:0.85rem;"><?php echo sanitize($pe['location']); ?></td>
                                    <td><span class="badge badge-member">+<?php echo $pe['points_reward']; ?> XP</span></td>
                                    <td>
                                        <div style="display:flex; gap:8px;">
                                            <?php if ($is_my_event && $user_role !== 'Admin'): ?>
                                                <span class="text-muted" style="font-size:0.75rem; font-style:italic;">Awaiting co-signer</span>
                                            <?php else: ?>
                                                <a href="approvals.php?action=approve_event&id=<?php echo $pe['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-check"></i> Approve</a>
                                            <?php endif; ?>
                                            <a href="approvals.php?action=reject_event&id=<?php echo $pe['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:4px 8px; font-size:0.7rem;" onclick="return confirm('Reject and delete this event proposal?');"><i class="fa-solid fa-xmark"></i> Reject</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">All workshop proposals have been reviewed. No pending approvals.</p>
            <?php endif; ?>
        </div>
        
        <!-- Queue 2: User Signups -->
        <div class="card">
            <h3 class="card-title text-cyan"><i class="fa-solid fa-user-clock"></i> Pending Account Registrations</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom: 20px;">Authorize or discard security club portal access requests from newly registered members.</p>
            
            <?php if (!empty($pending_users)): ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Date Joined</th>
                                <th>Full Name</th>
                                <th>Email Address</th>
                                <th>Actions Control</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_users as $pu): ?>
                                <tr>
                                    <td style="font-family:monospace; font-size:0.8rem;"><?php echo sanitize($pu['created_at']); ?></td>
                                    <td><strong><?php echo sanitize($pu['name']); ?></strong></td>
                                    <td><?php echo sanitize($pu['email']); ?></td>
                                    <td>
                                        <div style="display:flex; gap:8px;">
                                            <a href="approvals.php?action=approve_user&id=<?php echo $pu['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-check"></i> Approve</a>
                                            <a href="approvals.php?action=reject_user&id=<?php echo $pu['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:4px 8px; font-size:0.7rem;" onclick="return confirm('Decline and remove this registration?');"><i class="fa-solid fa-ban"></i> Discard</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">No pending member join requests found.</p>
            <?php endif; ?>
        </div>
        
    </div>

    <!-- RIGHT COLUMN: MEMBER RECOGNITION NOMINATIONS -->
    <div style="display:flex; flex-direction:column; gap:30px;">
        
        <!-- Spotlight Nomination Form -->
        <div class="card card-pink">
            <h3 class="card-title text-pink"><i class="fa-solid fa-ranking-star"></i> Nominate Member Spotlight</h3>
            <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom: 15px;">Award a prominent spotlight placement on the club home feed to recognize outstanding learning achievements, CTF solutions, or contributions.</p>
            
            <form action="approvals.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="nominee_id" class="form-label">Select Active Member</label>
                    <select id="nominee_id" name="nominee_id" class="form-control" required>
                        <option value="" disabled selected>-- Choose Nominee --</option>
                        <?php foreach ($active_members as $am): ?>
                            <option value="<?php echo $am['id']; ?>"><?php echo sanitize($am['name']) . ' (' . sanitize($am['email']) . ')'; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="title" class="form-label">Spotlight Honor Title</label>
                    <input type="text" id="title" name="title" class="form-control" placeholder="Member of the Month / CTF Champion" required>
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label for="reason" class="form-label">Reason / Contributions</label>
                    <textarea id="reason" name="reason" class="form-control" rows="3" placeholder="Describe their exceptional actions or project accomplishments..." required></textarea>
                </div>
                
                <button type="submit" name="nominate_spotlight" class="btn btn-danger btn-block">
                    <i class="fa-solid fa-trophy"></i> Publish Spotlight Award
                </button>
            </form>
        </div>
        
        <!-- Spotlight History -->
        <div class="card">
            <h3 class="card-title text-cyan"><i class="fa-solid fa-clock-rotate-left"></i> Spotlight History</h3>
            <?php if (!empty($existing_spotlights)): ?>
                <div style="display:flex; flex-direction:column; gap:12px; margin-top:15px; max-height: 350px; overflow-y:auto;">
                    <?php foreach ($existing_spotlights as $sp): ?>
                        <div style="border-bottom: 1px solid var(--border-glow); padding-bottom: 10px;">
                            <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--text-muted);">
                                <span>Nominated by <?php echo sanitize($sp['creator_name'] ?? 'Moderator'); ?></span>
                                <span><?php echo sanitize(date('Y-m-d', strtotime($sp['created_at']))); ?></span>
                            </div>
                            <div style="font-weight:600; font-size:0.9rem; color:var(--color-primary); margin: 3px 0;">
                                <?php echo sanitize($sp['nominee_name']); ?>
                            </div>
                            <div style="font-size:0.8rem; font-weight:bold; color:var(--text-primary);"><?php echo sanitize($sp['title']); ?></div>
                            <div style="font-size:0.8rem; color:var(--text-muted);"><?php echo sanitize($sp['reason']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.8rem;">No spotlights have been awarded yet.</p>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
