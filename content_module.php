<?php
$page_title = 'Content Module';
$page_heading = 'Content Editor Console';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Access control: Faculty Coordinator, Student Coordinator, and Content Coordinator only
require_login();
if (!has_access('content')) {
    set_flash_message('error', 'Access Denied: You do not have permission to view the Content Module.');
    redirect('dashboard.php');
}

$user_id = intval($_SESSION['user_id']);
$user_role = get_user_role();
$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_content'])) {
    $content_type = sanitize($_POST['content_type'] ?? '');
    $title = sanitize($_POST['title'] ?? '');
    $body = sanitize($_POST['body'] ?? '');
    $scheduled_date = sanitize($_POST['scheduled_date'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Refresh and try again.';
    } elseif (empty($content_type) || empty($title) || empty($body)) {
        $error = 'Please fill in all required fields.';
    } else {
        try {
            // Content Coordinator drafts are "Pending Approval", Faculty/Student are pre-approved "Published" or "Pending" based on choice
            $status = (in_array($user_role, ['Faculty Coordinator', 'Student Coordinator'])) ? 'Published' : 'Pending Approval';
            $stmt = $db->prepare("INSERT INTO content_items (user_id, content_type, title, body, scheduled_date, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $content_type, $title, $body, $scheduled_date, $status]);
            log_event($user_id, 'Content Created', "Created content item: $title ($content_type) with status $status");
            $success = 'Content item saved and added to pipeline.';
        } catch (PDOException $e) {
            $error = 'Failed to record content.';
        }
    }
}

// Approve / Reject / Publish Content Items
if (isset($_GET['action']) && in_array($_GET['action'], ['approve', 'reject'])) {
    $target_id = intval($_GET['id']);
    $action = $_GET['action'];
    $csrf_token = $_GET['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $status = ($action === 'approve') ? 'Published' : 'Rejected';
            $stmt = $db->prepare("UPDATE content_items SET status = ?, approved_by = ? WHERE id = ?");
            $stmt->execute([$status, $user_id, $target_id]);
            
            log_event($user_id, 'Content Resolved', "Set status of content ID $target_id to $status");
            set_flash_message('success', "Content item #$target_id has been $status.");
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to resolve content.');
        }
    }
    redirect('content_module.php');
}

// Fetch content pipeline
$contents = [];
try {
    $stmtCnt = $db->query("
        SELECT ci.*, u.name as author_name, u.role as author_role, apr.name as approver_name 
        FROM content_items ci 
        JOIN users u ON ci.user_id = u.id 
        LEFT JOIN users apr ON ci.approved_by = apr.id 
        ORDER BY ci.created_at DESC
    ");
    $contents = $stmtCnt->fetchAll();
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
    <!-- LEFT: Content Pipeline log -->
    <div style="display:flex; flex-direction:column; gap:30px;">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-newspaper"></i> Content Pipeline & Approvals</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:20px;">Review, edit, and approve blog drafts, newsletter releases, and official club write-ups before publication.</p>
            
            <?php if (!empty($contents)): ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Author</th>
                                <th>Title & Context</th>
                                <th>Scheduled Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contents as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo sanitize($c['author_name']); ?></strong>
                                        <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo sanitize($c['author_role']); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo sanitize($c['title']); ?> <span style="font-size:0.7rem; text-transform:uppercase; background:var(--bg-surface-elevated); padding:2px 6px; border-radius:3px; font-weight:normal; margin-left:5px;"><?php echo sanitize($c['content_type']); ?></span></div>
                                        <p style="font-size:0.8rem; color:var(--text-muted); margin-top:5px; line-height:1.4; max-height:80px; overflow-y:auto;"><?php echo sanitize($c['body']); ?></p>
                                    </td>
                                    <td>
                                        <span style="font-size:0.8rem; font-family:monospace;"><?php echo !empty($c['scheduled_date']) ? sanitize($c['scheduled_date']) : 'Immediate'; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-status-<?php 
                                            echo ($c['status'] === 'Published') ? 'active' : (($c['status'] === 'Rejected') ? 'suspended' : 'pending'); 
                                        ?>">
                                            <?php echo sanitize($c['status']); ?>
                                        </span>
                                        <?php if ($c['status'] !== 'Pending Approval' && !empty($c['approver_name'])): ?>
                                            <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">By <?php echo sanitize($c['approver_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($c['status'] === 'Pending Approval'): ?>
                                            <div style="display:flex; gap:6px;">
                                                <a href="content_module.php?action=approve&id=<?php echo $c['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:4px 8px; font-size:0.7rem;"><i class="fa-solid fa-check"></i> Approve</a>
                                                <a href="content_module.php?action=reject&id=<?php echo $c['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:4px 8px; font-size:0.7rem;" onclick="return confirm('Reject this content item?');"><i class="fa-solid fa-xmark"></i> Reject</a>
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
                <p style="color:var(--text-muted); font-size:0.9rem; margin-top:10px;">No content drafts submitted in database.</p>
            <?php endif; ?>
        </div>
        
        <!-- Content Calendar Quick View -->
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-calendar-days"></i> Content Calendar Feed</h3>
            <div style="display:flex; flex-direction:column; gap:10px; margin-top:15px;">
                <?php 
                $scheduled_items = array_filter($contents, function($item) {
                    return $item['status'] === 'Published' && !empty($item['scheduled_date']);
                });
                if (!empty($scheduled_items)): 
                    foreach ($scheduled_items as $si):
                ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; background:var(--bg-surface-elevated); padding:10px 15px; border-radius:4px; border:1px solid var(--border-glow);">
                        <div>
                            <span style="font-size:0.7rem; font-weight:700; text-transform:uppercase; background:#000000; color:#ffffff; padding:2px 6px; border-radius:3px; margin-right:8px;"><?php echo sanitize($si['content_type']); ?></span>
                            <strong style="font-size:0.85rem;"><?php echo sanitize($si['title']); ?></strong>
                        </div>
                        <span style="font-size:0.8rem; font-family:monospace; color:var(--text-muted);"><?php echo sanitize($si['scheduled_date']); ?></span>
                    </div>
                <?php 
                    endforeach;
                else: 
                ?>
                    <p style="color:var(--text-muted); font-size:0.8rem;">No upcoming scheduled posts scheduled.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: Form -->
    <div style="display:flex; flex-direction:column; gap:30px;">
        <div class="card card-pink">
            <h3 class="card-title"><i class="fa-solid fa-pen-nib"></i> Draft Blog & Write-up</h3>
            <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:15px;">Compose official technical tutorials, monthly write-ups, or security awareness newsletters for public and member boards.</p>
            
            <form action="content_module.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="content_type" class="form-label">Content Medium Type</label>
                    <select id="content_type" name="content_type" class="form-control" required>
                        <option value="" disabled selected>-- Choose Medium --</option>
                        <option value="Blog Post">Blog Article & Write-up</option>
                        <option value="Newsletter">Monthly Security Newsletter</option>
                        <option value="CTF Guide">Lab / CTF Solution Write-up</option>
                        <option value="Announcement">Global Club Announcement</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="scheduled_date" class="form-label">Scheduled Publication Date (Optional)</label>
                    <input type="date" id="scheduled_date" name="scheduled_date" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="title" class="form-label">Article / Newsletter Title</label>
                    <input type="text" id="title" name="title" class="form-control" placeholder="SQL Injection Cheat Sheet" required>
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label for="body" class="form-label">Content Body / Markdown</label>
                    <textarea id="body" name="body" class="form-control" rows="8" placeholder="Draft your content in markdown or plain text..." required></textarea>
                </div>
                
                <button type="submit" name="submit_content" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Deploy Content Draft
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
