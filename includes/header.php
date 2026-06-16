<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$page_title = isset($page_title) ? $page_title . ' | ' . APP_NAME : APP_NAME;

// Fetch in-app notifications
$notifications = [];
$unread_count = 0;
if (is_logged_in()) {
    $uid_notify = intval($_SESSION['user_id']);
    try {
        if (isset($_GET['clear_notifications'])) {
            $stmtClear = $db->prepare("UPDATE in_app_notifications SET read_status = 1 WHERE user_id = ?");
            $stmtClear->execute([$uid_notify]);
            $clean_uri = preg_replace('/[?&]clear_notifications=[^&]*/', '', $_SERVER['REQUEST_URI']);
            redirect($clean_uri);
        }
        
        $stmtNotify = $db->prepare("SELECT * FROM in_app_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmtNotify->execute([$uid_notify]);
        $notifications = $stmtNotify->fetchAll();
        
        $unread_stmt = $db->prepare("SELECT COUNT(*) FROM in_app_notifications WHERE user_id = ? AND read_status = 0");
        $unread_stmt->execute([$uid_notify]);
        $unread_count = $unread_stmt->fetchColumn();
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Stylesheet -->
    <link rel="stylesheet" href="style.css">
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php if (is_logged_in()): ?>
<div class="app-container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-wrapper">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 15px;">
                <button class="sidebar-toggle" id="sidebarCollapse">
                    <i class="fa fa-bars"></i>
                </button>
                <div class="topbar-title"><?php echo sanitize($page_heading ?? 'Dashboard'); ?></div>
            </div>
            
            <div class="topbar-actions">
                <!-- Notifications Bell -->
                <div style="position: relative; display: inline-block; margin-right: 10px;" id="notificationsWrapper">
                    <button class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.75rem;" onclick="var d = document.getElementById('notificationsDropdown'); d.style.display = (d.style.display === 'block') ? 'none' : 'block';">
                        <i class="fa-solid fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                            <span style="position: absolute; top: -5px; right: -5px; background: #000000; color: #ffffff; border: 1px solid #ffffff; border-radius: 50%; width: 16px; height: 16px; font-size: 0.65rem; display: flex; align-items: center; justify-content: center; font-weight: bold;"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <div id="notificationsDropdown" style="display: none; position: absolute; right: 0; top: 35px; width: 300px; background: #ffffff; border: 1px solid var(--border-glow); border-radius: 4px; box-shadow: var(--shadow-cyber); z-index: 100; padding: 10px; text-align: left;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glow); padding-bottom: 5px; margin-bottom: 8px;">
                            <span style="font-size: 0.75rem; font-weight: bold; font-family: var(--font-heading); color: #000000;">Notifications</span>
                            <?php if ($unread_count > 0): ?>
                                <?php
                                $sep = (strpos($_SERVER['REQUEST_URI'], '?') !== false) ? '&' : '?';
                                ?>
                                <a href="<?php echo $_SERVER['REQUEST_URI'] . $sep; ?>clear_notifications=1" style="font-size: 0.7rem; text-decoration: underline; color: #000000;">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <div style="max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                            <?php if (!empty($notifications)): ?>
                                <?php foreach ($notifications as $n): ?>
                                    <div style="font-size: 0.75rem; padding: 6px; border-bottom: 1px dashed var(--border-glow); color: #000000; <?php echo $n['read_status'] == 0 ? 'font-weight: bold; background: rgba(0,0,0,0.02);' : ''; ?>">
                                        <div><?php echo sanitize($n['message']); ?></div>
                                        <div style="font-size: 0.65rem; color: var(--text-muted); margin-top: 3px; font-family: monospace;"><?php echo sanitize($n['created_at']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size: 0.75rem; color: var(--text-muted); text-align: center; padding: 10px 0;">No new notifications.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Points Display for Member -->
                <?php if (get_user_role() === 'Club Member'): ?>
                    <div style="font-family: var(--font-heading); color: var(--color-primary); display: flex; align-items: center; gap: 8px; margin-right: 10px;">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span><?php echo $_SESSION['user_points'] ?? 0; ?> XP</span>
                    </div>
                <?php endif; ?>
                
                <div class="badge <?php echo 'badge-' . strtolower(get_user_role()); ?>">
                    <?php echo get_user_role(); ?>
                </div>
                
                <a href="logout.php" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.75rem;">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </header>
        <main class="content-body">
            
            <!-- Flash Message Banner -->
            <?php $flash = get_flash_message(); if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?>">
                    <i class="fa-solid <?php 
                        echo $flash['type'] === 'success' ? 'fa-circle-check' : 
                            ($flash['type'] === 'error' ? 'fa-circle-xmark' : 'fa-circle-exclamation'); 
                    ?>"></i>
                    <span><?php echo sanitize($flash['message']); ?></span>
                </div>
            <?php endif; ?>
<?php else: ?>
    <!-- Public Header if on landing page or auth pages -->
    <?php if (basename($_SERVER['PHP_SELF']) !== 'index.php' && 
              basename($_SERVER['PHP_SELF']) !== 'login.php' && 
              basename($_SERVER['PHP_SELF']) !== 'register.php'): ?>
        <?php redirect('login.php'); ?>
    <?php endif; ?>
<?php endif; ?>
