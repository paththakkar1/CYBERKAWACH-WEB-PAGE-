<?php
$page_title = 'Technical Module';
$page_heading = 'Tech Resource Console';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Access control: Faculty Coordinator, Student Coordinator, and Tech Coordinator only
require_login();
if (!has_access('tech')) {
    set_flash_message('error', 'Access Denied: You do not have permission to view the Technical Module.');
    redirect('dashboard.php');
}

$user_id = intval($_SESSION['user_id']);
$user_role = get_user_role();
$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $request_type = sanitize($_POST['request_type'] ?? '');
    $title = sanitize($_POST['title'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $resource_name = sanitize($_POST['resource_name'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Refresh and try again.';
    } elseif (empty($request_type) || empty($title) || empty($description)) {
        $error = 'Please fill in all required fields.';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO tech_requests (user_id, request_type, title, description, resource_name, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$user_id, $request_type, $title, $description, $resource_name]);
            log_event($user_id, 'Tech Request Created', "Created tech request: $title ($request_type)");
            $success = 'Technical request logged successfully.';
        } catch (PDOException $e) {
            $error = 'Failed to record request.';
        }
    }
}

// Approve / Reject Tech Requests (Tech Coord, Student Coord, Faculty Coord)
if (isset($_GET['action']) && in_array($_GET['action'], ['approve', 'reject'])) {
    $target_id = intval($_GET['id']);
    $action = $_GET['action'];
    $csrf_token = $_GET['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $status = ($action === 'approve') ? 'Approved' : 'Rejected';
            $stmt = $db->prepare("UPDATE tech_requests SET status = ?, approved_by = ? WHERE id = ?");
            $stmt->execute([$status, $user_id, $target_id]);
            
            log_event($user_id, 'Tech Request Resolved', "Set status of tech request ID $target_id to $status");
            set_flash_message('success', "Tech request #$target_id has been $status.");
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to resolve request.');
        }
    }
    redirect('tech_module.php');
}

// Fetch requests
$requests = [];
try {
    $stmtReq = $db->query("
        SELECT tr.*, u.name as requester_name, u.role as requester_role, apr.name as approver_name 
        FROM tech_requests tr 
        JOIN users u ON tr.user_id = u.id 
        LEFT JOIN users apr ON tr.approved_by = apr.id 
        ORDER BY tr.created_at DESC
    ");
    $requests = $stmtReq->fetchAll();
} catch (PDOException $e) {}

// Standard Repo/Tool List
$tools = [
    ['name' => 'Kali Linux Shared Labs', 'status' => 'Online', 'desc' => 'Virtual machines hosting hacking environment sandboxes.'],
    ['name' => 'CyberKavach GitHub Organisation', 'status' => 'Active', 'desc' => 'Central repository space for code auditing tasks.'],
    ['name' => 'Local Wireshark Lab Router', 'status' => 'Maintenance', 'desc' => 'Physical traffic monitoring lab interface.'],
    ['name' => 'Internal CTF Scoring Platform', 'status' => 'Online', 'desc' => 'Live Jeopardy tournament scoreboard web engine.']
];

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
    <!-- LEFT: Requests log -->
    <div style="display:flex; flex-direction:column; gap:30px;">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-microchip"></i> IT & Technical Resources Request Logs</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:20px;">Review hardware resources allocation, repository authorizations, and support tasks logged by club modules.</p>
            
            <?php if (!empty($requests)): ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Requester</th>
                                <th>Task/Resource</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo sanitize($req['requester_name']); ?></strong>
                                        <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo sanitize($req['requester_role']); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo sanitize($req['title']); ?></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted);"><?php echo sanitize($req['description']); ?></div>
                                        <?php if (!empty($req['resource_name'])): ?>
                                            <div style="font-size:0.75rem; font-family:monospace; margin-top:4px; background:var(--bg-surface-elevated); padding:2px 6px; display:inline-block; border-radius:3px;">
                                                Target: <?php echo sanitize($req['resource_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase;"><?php echo sanitize($req['request_type']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-status-<?php echo strtolower($req['status']); ?>">
                                            <?php echo sanitize($req['status']); ?>
                                        </span>
                                        <?php if ($req['status'] !== 'Pending' && !empty($req['approver_name'])): ?>
                                            <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">By <?php echo sanitize($req['approver_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($req['status'] === 'Pending'): ?>
                                            <div style="display:flex; gap:6px;">
                                                <a href="tech_module.php?action=approve&id=<?php echo $req['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-check"></i> Approve</a>
                                                <a href="tech_module.php?action=reject&id=<?php echo $req['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:4px 8px; font-size:0.7rem;" onclick="return confirm('Reject this request?');"><i class="fa-solid fa-xmark"></i> Reject</a>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size:0.75rem; color:var(--text-muted); font-style:italic;">Resolved</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">No technical requests logged in database.</p>
            <?php endif; ?>
        </div>
        
        <!-- Standard Repos Status -->
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-server"></i> Sandbox Systems & Repository Access Status</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:15px;">
                <?php foreach ($tools as $t): ?>
                    <div style="background:var(--bg-surface-elevated); border:1px solid var(--border-glow); padding:15px; border-radius:6px; display:flex; flex-direction:column; gap:5px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong style="font-size:0.9rem;"><?php echo $t['name']; ?></strong>
                            <span class="badge badge-status-<?php echo ($t['status'] === 'Online' || $t['status'] === 'Active') ? 'active' : 'pending'; ?>" style="font-size:0.65rem;">
                                <?php echo $t['status']; ?>
                            </span>
                        </div>
                        <p style="font-size:0.8rem; color:var(--text-muted);"><?php echo $t['desc']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: Form -->
    <div style="display:flex; flex-direction:column; gap:30px;">
        <div class="card card-pink">
            <h3 class="card-title"><i class="fa-solid fa-screwdriver-wrench"></i> Log Technical/IT Request</h3>
            <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:15px;">Submit hardware resources requests, server outages alerts, repository addition asks, or tooling setup requirements.</p>
            
            <form action="tech_module.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="request_type" class="form-label">Request Classification</label>
                    <select id="request_type" name="request_type" class="form-control" required>
                        <option value="" disabled selected>-- Choose Classification --</option>
                        <option value="Resource Allocation">Resource Allocation (Hardware / Server)</option>
                        <option value="Repo Access">Repository / Tool Access Privilege</option>
                        <option value="IT Support Task">IT Support & Reset Task</option>
                        <option value="Event Sandbox">Technical Event Tool / VM Provision</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="resource_name" class="form-label">Target Resource/Repository Name (Optional)</label>
                    <input type="text" id="resource_name" name="resource_name" class="form-control" placeholder="GitHub org / Local VM #4 / Lab Router B">
                </div>
                
                <div class="form-group">
                    <label for="title" class="form-label">Request Title Summary</label>
                    <input type="text" id="title" name="title" class="form-control" placeholder="Need write access to quiz repos" required>
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label for="description" class="form-label">Request Context & Reason</label>
                    <textarea id="description" name="description" class="form-control" rows="4" placeholder="Detail the technical tasks, pre-requisites, or hardware support needed..." required></textarea>
                </div>
                
                <button type="submit" name="submit_request" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-paper-plane"></i> Dispatch Tech Request
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
