<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$page_title = isset($page_title) ? $page_title . ' | ' . APP_NAME : APP_NAME;
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
                <!-- Points Display for Member -->
                <?php if (get_user_role() === 'Member'): ?>
                    <div style="font-family: var(--font-heading); color: var(--color-primary); display: flex; align-items: center; gap: 8px;">
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
