<?php
$page_title = 'Dashboard';
$page_heading = 'System Control Portal';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = $_SESSION['user_id'];
$user_role = get_user_role();
$user_name = $_SESSION['user_name'];

// Fetch shared data: Announcements
$announcements = [];
try {
    $stmt = $db->query("SELECT a.*, u.name as author_name FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC LIMIT 5");
    $announcements = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fail silently
}

// Role-based data retrieval
if ($user_role === 'Admin') {
    // -------------------------------------------------------------
    // ADMIN METRICS
    // -------------------------------------------------------------
    try {
        $total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $total_pending = $db->query("SELECT COUNT(*) FROM users WHERE status = 'Pending'")->fetchColumn();
        $total_events = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
        $open_tickets = $db->query("SELECT COUNT(*) FROM tickets WHERE status != 'Resolved'")->fetchColumn();
        
        // Recent activity logs
        $stmtLogs = $db->query("SELECT l.*, u.name as user_name FROM audit_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 6");
        $recent_logs = $stmtLogs->fetchAll();
        
        // Users waiting approval
        $stmtApproval = $db->query("SELECT * FROM users WHERE status = 'Pending' ORDER BY created_at DESC LIMIT 5");
        $pending_users_list = $stmtApproval->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
} elseif ($user_role === 'Core') {
    // -------------------------------------------------------------
    // CORE METRICS
    // -------------------------------------------------------------
    try {
        $active_members = $db->query("SELECT COUNT(*) FROM users WHERE role = 'Member' AND status = 'Active'")->fetchColumn();
        $upcoming_events_count = $db->query("SELECT COUNT(*) FROM events WHERE date >= date('now')")->fetchColumn();
        $pending_tickets = $db->query("SELECT COUNT(*) FROM tickets WHERE status = 'Open'")->fetchColumn();
        $total_quizzes = $db->query("SELECT COUNT(*) FROM quizzes")->fetchColumn();
        
        // Upcoming events details
        $stmtEv = $db->query("SELECT * FROM events WHERE date >= date('now') ORDER BY date ASC LIMIT 5");
        $upcoming_events = $stmtEv->fetchAll();
        
        // Recent support tickets
        $stmtTk = $db->query("SELECT t.*, u.name as user_name FROM tickets t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 5");
        $recent_tickets = $stmtTk->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
} else {
    // -------------------------------------------------------------
    // MEMBER METRICS
    // -------------------------------------------------------------
    try {
        // Refresh points in session
        $points = $db->prepare("SELECT points FROM users WHERE id = ?");
        $points->execute([$user_id]);
        $_SESSION['user_points'] = $points->fetchColumn();
        
        $my_points = $_SESSION['user_points'];
        
        // Calculate Rank
        $my_rank = $db->prepare("SELECT COUNT(*) + 1 FROM users WHERE points > ?");
        $my_rank->execute([$my_points]);
        $rank = $my_rank->fetchColumn();
        
        // Registered events count
        $my_events_count = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?");
        $my_events_count->execute([$user_id]);
        $registered_count = $my_events_count->fetchColumn();
        
        // Quizzes completed count
        $my_quizzes_count = $db->prepare("SELECT COUNT(DISTINCT quiz_id) FROM quiz_attempts WHERE user_id = ? AND passed = 1");
        $my_quizzes_count->execute([$user_id]);
        $quizzes_count = $my_quizzes_count->fetchColumn();
        
        // Registered upcoming events list
        $stmtMyEv = $db->prepare("
            SELECT e.*, r.status as reg_status 
            FROM registrations r 
            JOIN events e ON r.event_id = e.id 
            WHERE r.user_id = ? AND e.date >= date('now') 
            ORDER BY e.date ASC 
            LIMIT 5
        ");
        $stmtMyEv->execute([$user_id]);
        $my_upcoming_events = $stmtMyEv->fetchAll();

        // Recommend quizzes
        $stmtRecQ = $db->prepare("
            SELECT * FROM quizzes 
            WHERE id NOT IN (SELECT quiz_id FROM quiz_attempts WHERE user_id = ? AND passed = 1) 
            LIMIT 3
        ");
        $stmtRecQ->execute([$user_id]);
        $rec_quizzes = $stmtRecQ->fetchAll();
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to fetch dashboard metrics.');
    }
}

include __DIR__ . '/includes/header.php';
?>

<!-- Metric Stats Widget Grid -->
<div class="stats-grid">
    <?php if ($user_role === 'Admin'): ?>
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

    <?php elseif ($user_role === 'Core'): ?>
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
        <div class="stat-card">
            <div class="stat-info">
                <h3>Security Quizzes</h3>
                <div class="stat-value text-purple"><?php echo $total_quizzes; ?></div>
            </div>
            <i class="fa-solid fa-graduation-cap stat-icon"></i>
        </div>

    <?php else: ?>
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
                <h3>My Event Registrations</h3>
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
    <?php endif; ?>
</div>

<div class="dashboard-layout">
    <!-- Main Left Column -->
    <div>
        <?php if ($user_role === 'Admin'): ?>
            <!-- ADMIN VIEW: Approvals and Logs -->
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
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_logs as $log): ?>
                                <tr>
                                    <td style="font-size: 0.8rem; font-family: monospace;"><?php echo sanitize($log['created_at']); ?></td>
                                    <td><strong><?php echo sanitize($log['user_name'] ?? 'System / Guest'); ?></strong></td>
                                    <td><span class="badge badge-member" style="font-size:0.65rem;"><?php echo sanitize($log['action']); ?></span></td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo sanitize($log['details']); ?></td>
                                    <td style="font-size: 0.8rem; font-family: monospace;"><?php echo sanitize($log['ip_address'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 15px; text-align: right;">
                    <a href="admin.php" class="text-cyan" style="font-size: 0.85rem; font-family: var(--font-heading);">Full Admin Panel <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>

        <?php elseif ($user_role === 'Core'): ?>
            <!-- CORE VIEW: Event summaries & Tickets -->
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
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming_events as $event): ?>
                                    <tr>
                                        <td><strong><a href="events.php" class="text-cyan"><?php echo sanitize($event['title']); ?></a></strong></td>
                                        <td><?php echo sanitize($event['date']) . ' at ' . sanitize($event['time']); ?></td>
                                        <td><?php echo sanitize($event['location']); ?></td>
                                        <td><span class="badge badge-member">+<?php echo $event['points_reward']; ?> XP</span></td>
                                        <td><span class="badge badge-status-active">Active</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 10px;">No scheduled workshops currently.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-envelope-open-text"></i> Help Desk Activity</h3>
                <?php if (!empty($recent_tickets)): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Sender</th>
                                    <th>Subject</th>
                                    <th>Created At</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_tickets as $ticket): ?>
                                    <tr>
                                        <td><strong><?php echo sanitize($ticket['user_name']); ?></strong></td>
                                        <td><?php echo sanitize($ticket['subject']); ?></td>
                                        <td style="font-size: 0.8rem;"><?php echo sanitize($ticket['created_at']); ?></td>
                                        <td>
                                            <span class="badge badge-status-<?php echo strtolower($ticket['status']); ?>">
                                                <?php echo sanitize($ticket['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="tickets.php?id=<?php echo $ticket['id']; ?>" class="btn btn-primary" style="padding: 5px 10px; font-size: 0.75rem;"><i class="fa-solid fa-reply"></i> Open Case</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 10px;">No help desk requests raised.</p>
                <?php endif; ?>
            </div>

        <?php else: ?>
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

            <!-- Bulletins Feed -->
            <div class="card">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-bullhorn"></i> Club Notices</h3>
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
        <!-- Notice Summary (Except for Members, who have it detailed in the left column) -->
        <?php if ($user_role !== 'Member'): ?>
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

        <!-- Role Specific Actions / Widgets -->
        <?php if ($user_role === 'Admin'): ?>
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

        <?php elseif ($user_role === 'Core'): ?>
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

        <?php else: ?>
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
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
