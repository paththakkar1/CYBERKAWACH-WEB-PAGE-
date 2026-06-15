<?php
$page_title = 'Notice Board';
$page_heading = 'Central Bulletins & Alerts';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = $_SESSION['user_id'];
$user_role = get_user_role();
$error = '';

// Handle announcement insertion (Admin & Core only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_notice'])) {
    if (!has_role('Core')) {
        $error = 'Access denied.';
    } else {
        $title = sanitize($_POST['title'] ?? '');
        $content = sanitize($_POST['content'] ?? '');
        $priority = sanitize($_POST['priority'] ?? 'Low');
        $csrf_token = $_POST['csrf_token'] ?? '';
        
        if (!verify_csrf_token($csrf_token)) {
            $error = 'Security check failed. Refresh and try again.';
        } elseif (empty($title) || empty($content)) {
            $error = 'All fields are required.';
        } elseif (!in_array($priority, ['Low', 'Medium', 'High'])) {
            $error = 'Invalid priority level.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO announcements (title, content, priority, created_by) VALUES (?, ?, ?, ?)");
                $stmt->execute([$title, $content, $priority, $user_id]);
                
                log_event($user_id, 'Create Notice', "Created announcement: $title (Priority: $priority)");
                set_flash_message('success', 'Announcement published successfully on notice board.');
                redirect('announcements.php');
            } catch (PDOException $e) {
                $error = 'Failed to publish bulletin.';
            }
        }
    }
}

// Handle announcement deletion (Admin & Core only)
if (isset($_GET['delete']) && has_role('Core')) {
    $delete_id = intval($_GET['delete']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed. Deletion blocked.');
    } else {
        try {
            // Log before deleting
            $stmtTitle = $db->prepare("SELECT title FROM announcements WHERE id = ?");
            $stmtTitle->execute([$delete_id]);
            $title = $stmtTitle->fetchColumn();
            
            $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$delete_id]);
            
            log_event($user_id, 'Delete Notice', "Deleted announcement ID $delete_id: '$title'");
            set_flash_message('success', 'Notice deleted successfully.');
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to delete announcement.');
        }
    }
    redirect('announcements.php');
}

// Fetch all announcements
try {
    $stmt = $db->query("
        SELECT a.*, u.name as author_name, u.role as author_role 
        FROM announcements a 
        LEFT JOIN users u ON a.created_by = u.id 
        ORDER BY 
            CASE a.priority 
                WHEN 'High' THEN 1 
                WHEN 'Medium' THEN 2 
                WHEN 'Low' THEN 3 
            END ASC, 
            a.created_at DESC
    ");
    $all_notices = $stmt->fetchAll();
} catch (PDOException $e) {
    $all_notices = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="dashboard-layout" style="grid-template-columns: <?php echo has_role('Core') ? '1.2fr 0.8fr' : '1fr'; ?>;">
    
    <!-- Notice Board display -->
    <div>
        <div class="card">
            <h3 class="card-title text-cyan"><i class="fa-solid fa-bullhorn"></i> Club Notice Board</h3>
            
            <?php if (!empty($all_notices)): ?>
                <div class="notice-feed" style="margin-top: 20px;">
                    <?php foreach ($all_notices as $notice): ?>
                        <div class="notice-item" style="padding: 20px 0;">
                            <div class="notice-meta" style="font-size: 0.8rem; margin-bottom: 8px;">
                                <span style="display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-user-shield text-cyan"></i>
                                    <strong><?php echo sanitize($notice['author_name'] ?? 'Admin'); ?></strong>
                                    <span class="badge badge-<?php echo strtolower($notice['author_role'] ?? 'Admin'); ?>" style="font-size: 0.6rem; padding: 2px 4px;">
                                        <?php echo sanitize($notice['author_role'] ?? 'Admin'); ?>
                                    </span>
                                </span>
                                <span><i class="fa-solid fa-clock"></i> <?php echo sanitize($notice['created_at']); ?></span>
                            </div>
                            
                            <h4 class="notice-title" style="font-size: 1.15rem; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                                <span>
                                    <?php if ($notice['priority'] === 'High'): ?>
                                        <span class="badge badge-status-suspended" style="font-size: 0.65rem; vertical-align: middle; margin-right: 5px;">URGENT</span>
                                    <?php elseif ($notice['priority'] === 'Medium'): ?>
                                        <span class="badge badge-status-pending" style="font-size: 0.65rem; vertical-align: middle; margin-right: 5px;">INFO</span>
                                    <?php endif; ?>
                                    <?php echo sanitize($notice['title']); ?>
                                </span>
                                
                                <?php if (has_role('Core')): ?>
                                    <a href="announcements.php?delete=<?php echo $notice['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="text-pink" onclick="return confirm('Confirm deletion of this notice?');" style="font-size: 0.85rem;" title="Delete Notice">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                <?php endif; ?>
                            </h4>
                            
                            <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 10px; line-height: 1.6; white-space: pre-wrap;"><?php echo sanitize($notice['content']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fa-solid fa-box-open" style="font-size: 3rem; margin-bottom: 15px;"></i>
                    <p>Notice board is currently clean. Check back later!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Sidebar form for publishing (Core / Admin only) -->
    <?php if (has_role('Core')): ?>
        <div>
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-pen-nib"></i> Publish Notice</h3>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fa-solid fa-circle-xmark"></i>
                        <span><?php echo sanitize($error); ?></span>
                    </div>
                <?php endif; ?>

                <form action="announcements.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="form-group">
                        <label for="title" class="form-label">Notice Subject</label>
                        <input type="text" id="title" name="title" class="form-control" placeholder="Critical Server Maintenance" required value="<?php echo isset($_POST['title']) ? sanitize($_POST['title']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="priority" class="form-label">Alert Priority</label>
                        <select id="priority" name="priority" class="form-control">
                            <option value="Low">Low (General Updates)</option>
                            <option value="Medium" selected>Medium (Standard Info)</option>
                            <option value="High">High (Urgent Announcement)</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label for="content" class="form-label">Notice Details</label>
                        <textarea id="content" name="content" class="form-control" rows="8" placeholder="Enter notice details and instructions here..." required><?php echo isset($_POST['content']) ? sanitize($_POST['content']) : ''; ?></textarea>
                    </div>

                    <button type="submit" name="add_notice" class="btn btn-danger btn-block">
                        <i class="fa-solid fa-bullhorn"></i> Broadcast Notice
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
