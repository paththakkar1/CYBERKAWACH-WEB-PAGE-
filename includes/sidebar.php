<?php
require_once __DIR__ . '/../config.php';

$current_page = basename($_SERVER['PHP_SELF']);
$user_name = $_SESSION['user_name'] ?? 'Guest';
$user_role = get_user_role();

// Get initials
$initials = '';
$words = explode(' ', $user_name);
foreach ($words as $w) {
    $initials .= strtoupper($w[0] ?? '');
}
$initials = substr($initials, 0, 2);
?>
<aside class="sidebar" id="appSidebar">
    <div class="sidebar-logo">
        <i class="fa-solid fa-shield-halved text-cyan" style="font-size: 1.8rem;"></i>
        <span class="sidebar-logo-text">CyberKavach</span>
    </div>
    
    <ul class="sidebar-menu">
        <li class="sidebar-item <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
            <a href="dashboard.php" class="sidebar-link">
                <i class="fa-solid fa-gauge-high"></i>
                <span>Dashboard</span>
            </a>
        </li>
        
        <li class="sidebar-item <?php echo $current_page === 'announcements.php' ? 'active' : ''; ?>">
            <a href="announcements.php" class="sidebar-link">
                <i class="fa-solid fa-bullhorn"></i>
                <span>Notice Board</span>
            </a>
        </li>
        
        <li class="sidebar-item <?php echo $current_page === 'events.php' ? 'active' : ''; ?>">
            <a href="events.php" class="sidebar-link">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Events</span>
            </a>
        </li>
        
        <li class="sidebar-item <?php echo $current_page === 'quizzes.php' ? 'active' : ''; ?>">
            <a href="quizzes.php" class="sidebar-link">
                <i class="fa-solid fa-graduation-cap"></i>
                <span>Security Quizzes</span>
            </a>
        </li>
        
        <li class="sidebar-item <?php echo $current_page === 'leaderboard.php' ? 'active' : ''; ?>">
            <a href="leaderboard.php" class="sidebar-link">
                <i class="fa-solid fa-trophy"></i>
                <span>Leaderboard</span>
            </a>
        </li>
        
        <li class="sidebar-item <?php echo $current_page === 'tickets.php' ? 'active' : ''; ?>">
            <a href="tickets.php" class="sidebar-link">
                <i class="fa-solid fa-circle-question"></i>
                <span>Help Desk</span>
            </a>
        </li>

        <?php if ($user_role === 'Admin'): ?>
            <li class="sidebar-item <?php echo $current_page === 'admin.php' ? 'active' : ''; ?>">
                <a href="admin.php" class="sidebar-link text-pink">
                    <i class="fa-solid fa-user-gear"></i>
                    <span>Admin Controls</span>
                </a>
            </li>
        <?php endif; ?>
        
        <li class="sidebar-item <?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
            <a href="profile.php" class="sidebar-link">
                <i class="fa-solid fa-user-shield"></i>
                <span>My Profile</span>
            </a>
        </li>
    </ul>
    
    <div class="sidebar-user">
        <div class="sidebar-avatar">
            <?php echo sanitize($initials); ?>
        </div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name" title="<?php echo sanitize($user_name); ?>">
                <?php echo sanitize($user_name); ?>
            </div>
            <div class="sidebar-user-role">
                <i class="fa-solid <?php 
                    echo $user_role === 'Admin' ? 'fa-key text-pink' : 
                        ($user_role === 'Core' ? 'fa-star text-purple' : 'fa-user text-cyan'); 
                ?>"></i>
                <span><?php echo $user_role; ?></span>
            </div>
        </div>
    </div>
</aside>
