<?php
$page_title = 'Permission Requests';
$page_heading = 'Access & Approval Workflow';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = intval($_SESSION['user_id']);
$user_role = get_user_role();
$user_name = $_SESSION['user_name'];
$error = '';
$success = '';

// Check if user is an approver (Faculty Coordinator or Student Coordinator)
$is_approver = in_array($user_role, ['Faculty Coordinator', 'Student Coordinator']);

// -------------------------------------------------------------
// POST / GET ACTIONS
// -------------------------------------------------------------

// 1. Submit a request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $request_type = sanitize($_POST['request_type'] ?? '');
    $title = sanitize($_POST['title'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $budget_amount = floatval($_POST['budget_amount'] ?? 0.0);
    $venue_name = sanitize($_POST['venue_name'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Refresh and try again.';
    } elseif (empty($request_type) || empty($title) || empty($description)) {
        $error = 'Please fill in all required fields.';
    } else {
        try {
            $db->beginTransaction();
            
            // Insert request
            $stmt = $db->prepare("INSERT INTO permission_requests (user_id, request_type, title, description, budget_amount, venue_name, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$user_id, $request_type, $title, $description, $budget_amount, $venue_name]);
            $request_id = $db->lastInsertId();

            // Insert initial timeline entry
            $stmtTimeline = $db->prepare("INSERT INTO request_timeline (request_id, actor_id, stage, remarks) VALUES (?, NULL, 'Submitted', 'Request raised on portal.')");
            $stmtTimeline->execute([$request_id]);

            $db->commit();
            log_event($user_id, 'Request Submitted', "Created permission request ID $request_id: $title ($request_type)");
            $success = 'Permission request submitted and logged in active tracking.';
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Failed to submit request: ' . $e->getMessage();
        }
    }
}

// 2. Action on request (Approver only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request']) && $is_approver) {
    $request_id = intval($_POST['request_id'] ?? 0);
    $new_status = sanitize($_POST['action_status'] ?? '');
    $remarks = sanitize($_POST['remarks'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed.';
    } elseif ($request_id <= 0 || !in_array($new_status, ['Under Review', 'Approved', 'Rejected'])) {
        $error = 'Invalid action configuration.';
    } else {
        try {
            $db->beginTransaction();

            // Update request status
            $stmt = $db->prepare("UPDATE permission_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$new_status, $request_id]);

            // Add timeline log
            $stmtTimeline = $db->prepare("INSERT INTO request_timeline (request_id, actor_id, stage, remarks) VALUES (?, ?, ?, ?)");
            $stmtTimeline->execute([$request_id, $user_id, $new_status, empty($remarks) ? "Status transitioned to $new_status." : $remarks]);

            // Retrieve requester ID and title to send notification
            $stmtReqInfo = $db->prepare("SELECT user_id, title FROM permission_requests WHERE id = ? LIMIT 1");
            $stmtReqInfo->execute([$request_id]);
            $reqInfo = $stmtReqInfo->fetch();

            if ($reqInfo) {
                $requester_id = $reqInfo['user_id'];
                $req_title = $reqInfo['title'];
                $notify_msg = "Your request '{$req_title}' has been updated to {$new_status} by {$user_name} (Remarks: " . (empty($remarks) ? 'None' : $remarks) . ")";
                
                // Add notification
                $stmtNotify = $db->prepare("INSERT INTO in_app_notifications (user_id, message) VALUES (?, ?)");
                $stmtNotify->execute([$requester_id, $notify_msg]);
            }

            $db->commit();
            log_event($user_id, 'Request Action', "Transitioned status of request ID $request_id to $new_status");
            $success = "Request status successfully transitioned to $new_status.";
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Failed to transition status: ' . $e->getMessage();
        }
    }
}

// 3. Mock 48h Idle Escalation simulation (GET)
if (isset($_GET['escalate_id'])) {
    $escalate_id = intval($_GET['escalate_id']);
    $csrf_token = $_GET['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            // Mock timestamp to 3 days ago (72 hours)
            $mocked_time = date('Y-m-d H:i:s', strtotime('-3 days'));
            $stmt = $db->prepare("UPDATE permission_requests SET created_at = ? WHERE id = ?");
            $stmt->execute([$mocked_time, $escalate_id]);
            
            // Fetch requester ID and title to send notification
            $stmtReqInfo = $db->prepare("SELECT user_id, title FROM permission_requests WHERE id = ? LIMIT 1");
            $stmtReqInfo->execute([$escalate_id]);
            $reqInfo = $stmtReqInfo->fetch();
            
            if ($reqInfo) {
                // Add notification
                $notify_msg = "Idle escalation alert triggered for your request: '{$reqInfo['title']}'";
                $stmtNotify = $db->prepare("INSERT INTO in_app_notifications (user_id, message) VALUES (?, ?)");
                $stmtNotify->execute([$reqInfo['user_id'], $notify_msg]);
            }
            
            log_event($user_id, 'Escalation Simulated', "Simulated 48h idle escalation alert for request ID $escalate_id");
            set_flash_message('success', 'Idle escalation simulated successfully (timestamp updated to 72 hours ago).');
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to mock escalation.');
        }
    }
    redirect('requests.php');
}

// -------------------------------------------------------------
// DATA RETRIEVAL (Visibility Restricted)
// -------------------------------------------------------------
$requests = [];
try {
    if ($is_approver) {
        // Approvers can view ALL requests
        $stmtReqs = $db->query("
            SELECT pr.*, u.name as requester_name, u.role as requester_role 
            FROM permission_requests pr 
            JOIN users u ON pr.user_id = u.id 
            ORDER BY pr.created_at DESC
        ");
    } else {
        // Normal users can ONLY view their own requests
        $stmtReqs = $db->prepare("
            SELECT pr.*, u.name as requester_name, u.role as requester_role 
            FROM permission_requests pr 
            JOIN users u ON pr.user_id = u.id 
            WHERE pr.user_id = ? 
            ORDER BY pr.created_at DESC
        ");
        $stmtReqs->execute([$user_id]);
    }
    $requests = $stmtReqs->fetchAll();
} catch (PDOException $e) {}

// Selected Request for detailed timeline panel
$selected_request = null;
$timeline = [];
$sel_id = intval($_GET['view_id'] ?? 0);
if ($sel_id > 0) {
    try {
        // Ensure access restrictions
        if ($is_approver) {
            $stmtSel = $db->prepare("SELECT pr.*, u.name as requester_name, u.role as requester_role FROM permission_requests pr JOIN users u ON pr.user_id = u.id WHERE pr.id = ? LIMIT 1");
            $stmtSel->execute([$sel_id]);
        } else {
            $stmtSel = $db->prepare("SELECT pr.*, u.name as requester_name, u.role as requester_role FROM permission_requests pr JOIN users u ON pr.user_id = u.id WHERE pr.id = ? AND pr.user_id = ? LIMIT 1");
            $stmtSel->execute([$sel_id, $user_id]);
        }
        $selected_request = $stmtSel->fetch();

        if ($selected_request) {
            // Fetch timeline logs
            $stmtTime = $db->prepare("
                SELECT rt.*, u.name as actor_name, u.role as actor_role 
                FROM request_timeline rt 
                LEFT JOIN users u ON rt.actor_id = u.id 
                WHERE rt.request_id = ? 
                ORDER BY rt.action_time ASC
            ");
            $stmtTime->execute([$sel_id]);
            $timeline = $stmtTime->fetchAll();
        }
    } catch (PDOException $e) {}
}

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
    <!-- LEFT: Permission Requests Queue -->
    <div>
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-file-shield"></i> Permission & Access Logs</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:20px;">
                <?php echo $is_approver ? 'Central dashboard for reviewing and approving coordinator request pipelines.' : 'Track the live lifecycle and timeline of your access requests.'; ?>
            </p>

            <?php if (!empty($requests)): ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Requester</th>
                                <th>Request Details</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $r): ?>
                                <?php 
                                // Calculate idle duration
                                $created_time = strtotime($r['created_at']);
                                $hours_idle = (time() - $created_time) / 3600;
                                $is_escalated = ($hours_idle >= 48 && in_array($r['status'], ['Pending', 'Under Review']));
                                ?>
                                <tr style="<?php echo $is_escalated ? 'background: rgba(255,0,0,0.02);' : ''; ?>">
                                    <td>
                                        <strong><?php echo sanitize($r['requester_name']); ?></strong>
                                        <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo sanitize($r['requester_role']); ?></div>
                                        <div style="font-size:0.7rem; color:var(--text-muted); font-family:monospace; margin-top:2px;">
                                            <?php echo date('Y-m-d H:i', $created_time); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:var(--text-primary);"><?php echo sanitize($r['title']); ?></div>
                                        <span style="font-size:0.7rem; font-weight:700; text-transform:uppercase; background:var(--bg-surface-elevated); padding:2px 6px; border-radius:3px; display:inline-block; margin-top:4px;">
                                            <?php echo sanitize($r['request_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-status-<?php 
                                            echo ($r['status'] === 'Approved') ? 'active' : (($r['status'] === 'Rejected') ? 'suspended' : (($r['status'] === 'Under Review') ? 'pending' : 'pending')); 
                                        ?>">
                                            <?php echo sanitize($r['status']); ?>
                                        </span>
                                        <?php if ($is_escalated): ?>
                                            <div style="margin-top:6px; color:#ff0000; font-size:0.7rem; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> 48H Escalation Alert
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <a href="requests.php?view_id=<?php echo $r['id']; ?>" class="btn btn-secondary" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-clock-rotate-left"></i> Timeline</a>
                                            <?php if (in_array($r['status'], ['Pending', 'Under Review'])): ?>
                                                <a href="requests.php?escalate_id=<?php echo $r['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:4px 8px; font-size:0.7rem;" title="Simulate 48h Idle Escalation"><i class="fa-solid fa-hourglass-end"></i> Escalate</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">No requests logged in active tracking.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Timeline / Form Sidebar -->
    <div>
        <?php if ($selected_request): ?>
            <!-- TIMELINE DETAILS PANEL -->
            <div class="card card-pink" style="margin-bottom:30px;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-glow); padding-bottom:15px; margin-bottom:15px;">
                    <h3 class="card-title text-pink" style="margin:0;"><i class="fa-solid fa-clock-rotate-left"></i> Tracker Timeline</h3>
                    <a href="requests.php" class="text-muted" style="font-size:0.8rem; font-weight:bold;"><i class="fa-solid fa-xmark"></i> Close</a>
                </div>

                <div style="margin-bottom:15px;">
                    <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted);">Request ID #<?php echo $selected_request['id']; ?></div>
                    <h4 style="margin:4px 0 8px 0;"><?php echo sanitize($selected_request['title']); ?></h4>
                    <p style="font-size:0.85rem; color:var(--text-muted); line-height:1.4;"><?php echo sanitize($selected_request['description']); ?></p>
                    
                    <?php if (!empty($selected_request['venue_name'])): ?>
                        <div style="font-size:0.8rem; margin-top:8px;"><strong>Resource/Venue:</strong> <?php echo sanitize($selected_request['venue_name']); ?></div>
                    <?php endif; ?>
                    <?php if (floatval($selected_request['budget_amount']) > 0): ?>
                        <div style="font-size:0.8rem; margin-top:4px;"><strong>Budget Allocation:</strong> $<?php echo number_format($selected_request['budget_amount'], 2); ?></div>
                    <?php endif; ?>
                </div>

                <!-- Vertical Timeline logs -->
                <div style="display:flex; flex-direction:column; gap:15px; padding-left:10px; border-left:2px solid var(--color-primary); margin-top:20px; margin-bottom:25px;">
                    <?php foreach ($timeline as $t_log): ?>
                        <div style="position:relative; padding-bottom:5px;">
                            <div style="position:absolute; left:-16px; top:4px; width:10px; height:10px; border-radius:50%; background:#000000; border:2px solid #ffffff;"></div>
                            <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; font-weight:bold;">
                                <span style="text-transform:uppercase;"><?php echo sanitize($t_log['stage']); ?></span>
                                <span style="color:var(--text-muted); font-family:monospace;"><?php echo sanitize($t_log['action_time']); ?></span>
                            </div>
                            <div style="font-size:0.8rem; margin:2px 0;">
                                <?php if ($t_log['stage'] === 'Submitted'): ?>
                                    <span>Requester: <strong><?php echo sanitize($selected_request['requester_name']); ?></strong></span>
                                <?php else: ?>
                                    <span>Actor: <strong><?php echo sanitize($t_log['actor_name'] ?? 'System'); ?></strong> (<?php echo sanitize($t_log['actor_role'] ?? 'Coordinator'); ?>)</span>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:0.8rem; color:var(--text-muted); font-style:italic;">"<?php echo sanitize($t_log['remarks']); ?>"</p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Approver Workflow Controls -->
                <?php if ($is_approver && in_array($selected_request['status'], ['Pending', 'Under Review'])): ?>
                    <form action="requests.php?view_id=<?php echo $selected_request['id']; ?>" method="POST" style="border-top:1px dashed var(--border-glow); padding-top:20px;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="request_id" value="<?php echo $selected_request['id']; ?>">
                        
                        <div class="form-group">
                            <label for="action_status" class="form-label">Update Request State</label>
                            <select id="action_status" name="action_status" class="form-control" required>
                                <option value="" disabled selected>-- Transition State --</option>
                                <option value="Under Review">Set State: Under Review</option>
                                <option value="Approved">Grant Signature: Approved</option>
                                <option value="Rejected">Decline Request: Rejected</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label for="remarks" class="form-label">Approver Remarks / Comments</label>
                            <textarea id="remarks" name="remarks" class="form-control" rows="3" placeholder="Provide reason or conditional checks..." required></textarea>
                        </div>

                        <button type="submit" name="action_request" class="btn btn-primary btn-block">
                            <i class="fa-solid fa-signature"></i> Transition Request State
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- NEW REQUEST SUBMISSION FORM -->
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-file-circle-plus"></i> Submit Permission Request</h3>
            <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:15px;">Raise access requests for resource allocations, budgets, post approvals, or external collaborations.</p>

            <form action="requests.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="form-group">
                    <label for="request_type" class="form-label">Request Type</label>
                    <select id="request_type" name="request_type" class="form-control" onchange="toggleFormFields(this.value);" required>
                        <option value="" disabled selected>-- Choose Request Classification --</option>
                        <option value="Event Permission Request">Event Permission Request</option>
                        <option value="Resource/Venue Access Request">Resource/Venue Access Request</option>
                        <option value="Budget Approval Request">Budget Approval Request</option>
                        <option value="Social Media Posting Approval">Social Media Posting Approval</option>
                        <option value="Content Publishing Approval">Content Publishing Approval</option>
                        <option value="Certificate Generation Authorization">Certificate Generation Authorization</option>
                        <option value="External Collaboration Request">External Collaboration Request</option>
                    </select>
                </div>

                <div class="form-group" id="venue_field" style="display:none;">
                    <label for="venue_name" class="form-label">Target Resource/Classroom/Lab Name</label>
                    <input type="text" id="venue_name" name="venue_name" class="form-control" placeholder="IT Lab 3 / Seminar Hall A">
                </div>

                <div class="form-group" id="budget_field" style="display:none;">
                    <label for="budget_amount" class="form-label">Allocated Financial Requirement ($)</label>
                    <input type="number" id="budget_amount" name="budget_amount" class="form-control" placeholder="150.00" step="0.01" min="0">
                </div>

                <div class="form-group">
                    <label for="title" class="form-label">Request Summary Title</label>
                    <input type="text" id="title" name="title" class="form-control" placeholder="E.g., Host Cryptography Seminar" required>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label for="description" class="form-label">Details & Justification</label>
                    <textarea id="description" name="description" class="form-control" rows="4" placeholder="Detail targets, schedules, pre-requisites..." required></textarea>
                </div>

                <button type="submit" name="submit_request" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-paper-plane"></i> Dispatch Request Pipeline
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function toggleFormFields(val) {
    var venue = document.getElementById('venue_field');
    var budget = document.getElementById('budget_field');
    
    venue.style.display = 'none';
    budget.style.display = 'none';
    
    if (val === 'Resource/Venue Access Request') {
        venue.style.display = 'block';
    } else if (val === 'Budget Approval Request') {
        budget.style.display = 'block';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
