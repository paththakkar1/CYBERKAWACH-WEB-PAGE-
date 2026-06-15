<?php
$page_title = 'Help Desk';
$page_heading = 'Support Tickets & Help Desk';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = $_SESSION['user_id'];
$user_role = get_user_role();
$error = '';
$ticket_selected = null;
$replies = [];

// -------------------------------------------------------------
// POST / ACTION HANDLERS
// -------------------------------------------------------------
// 1. Open New Ticket (Members)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_ticket'])) {
    $subject = sanitize($_POST['subject'] ?? '');
    $message = sanitize($_POST['message'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Refresh and try again.';
    } elseif (empty($subject) || empty($message)) {
        $error = 'Subject and description are mandatory.';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO tickets (user_id, subject, message, status) VALUES (?, ?, ?, 'Open')");
            $stmt->execute([$user_id, $subject, $message]);
            $new_ticket_id = $db->lastInsertId();
            
            log_event($user_id, 'Open Ticket', "Opened ticket ID $new_ticket_id: $subject");
            set_flash_message('success', 'Support ticket opened successfully. Core members will review it shortly.');
            redirect('tickets.php');
        } catch (PDOException $e) {
            $error = 'Failed to submit ticket.';
        }
    }
}

// 2. Submit Reply (All roles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reply'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $message = sanitize($_POST['message'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
        redirect("tickets.php?id=$ticket_id");
    } elseif (empty($message)) {
        set_flash_message('error', 'Message cannot be empty.');
        redirect("tickets.php?id=$ticket_id");
    } else {
        try {
            // Check ticket ownership/permissions
            $stmtCheck = $db->prepare("SELECT user_id FROM tickets WHERE id = ?");
            $stmtCheck->execute([$ticket_id]);
            $owner_id = $stmtCheck->fetchColumn();
            
            if (!$owner_id || (!has_role('Core') && intval($owner_id) !== intval($user_id))) {
                set_flash_message('error', 'Unauthorized ticket access.');
                redirect('tickets.php');
            }
            
            // Insert Reply
            $stmtReply = $db->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)");
            $stmtReply->execute([$ticket_id, $user_id, $message]);
            
            // Update Ticket Status
            // If Core/Admin replies, set to 'In Progress', else set to 'Open' (Member waiting)
            $new_status = has_role('Core') ? 'In Progress' : 'Open';
            $stmtStatus = $db->prepare("UPDATE tickets SET status = ? WHERE id = ?");
            $stmtStatus->execute([$new_status, $ticket_id]);
            
            log_event($user_id, 'Ticket Reply', "Replied to ticket ID $ticket_id");
            set_flash_message('success', 'Reply submitted.');
            redirect("tickets.php?id=$ticket_id");
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to publish reply.');
            redirect("tickets.php?id=$ticket_id");
        }
    }
}

// 3. Resolve Ticket (Core/Admin only)
if (isset($_GET['resolve']) && has_role('Core')) {
    $ticket_id = intval($_GET['resolve']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $stmt = $db->prepare("UPDATE tickets SET status = 'Resolved' WHERE id = ?");
            $stmt->execute([$ticket_id]);
            
            log_event($user_id, 'Resolve Ticket', "Closed ticket ID $ticket_id");
            set_flash_message('success', 'Ticket case set to Resolved.');
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to resolve case.');
        }
    }
    redirect("tickets.php?id=$ticket_id");
}

// -------------------------------------------------------------
// RENDER FETCH
// -------------------------------------------------------------
// Case A: Specific ticket details selected
if (isset($_GET['id'])) {
    $ticket_id = intval($_GET['id']);
    try {
        $stmtT = $db->prepare("
            SELECT t.*, u.name as creator_name, u.role as creator_role, u.email as creator_email 
            FROM tickets t 
            JOIN users u ON t.user_id = u.id 
            WHERE t.id = ? LIMIT 1
        ");
        $stmtT->execute([$ticket_id]);
        $ticket_selected = $stmtT->fetch();
        
        if ($ticket_selected) {
            // Verify access: Member can only view their own
            if (!has_role('Core') && intval($ticket_selected['user_id']) !== intval($user_id)) {
                set_flash_message('error', 'Unauthorized access.');
                redirect('tickets.php');
            }
            
            // Fetch replies
            $stmtRep = $db->prepare("
                SELECT r.*, u.name as user_name, u.role as user_role 
                FROM ticket_replies r 
                JOIN users u ON r.user_id = u.id 
                WHERE r.ticket_id = ? 
                ORDER BY r.created_at ASC
            ");
            $stmtRep->execute([$ticket_id]);
            $replies = $stmtRep->fetchAll();
        }
    } catch (PDOException $e) {
        set_flash_message('error', 'Database error.');
    }
}

// Case B: Fetch tickets list
$tickets = [];
try {
    if (has_role('Core')) {
        // Admin / Core sees all
        $stmtList = $db->query("
            SELECT t.*, u.name as user_name, u.email as user_email,
            (SELECT COUNT(*) FROM ticket_replies WHERE ticket_id = t.id) as reply_count 
            FROM tickets t 
            JOIN users u ON t.user_id = u.id 
            ORDER BY 
                CASE t.status 
                    WHEN 'Open' THEN 1 
                    WHEN 'In Progress' THEN 2 
                    WHEN 'Resolved' THEN 3 
                END ASC, 
                t.created_at DESC
        ");
    } else {
        // Member sees only their own
        $stmtList = $db->prepare("
            SELECT t.*, u.name as user_name, u.email as user_email,
            (SELECT COUNT(*) FROM ticket_replies WHERE ticket_id = t.id) as reply_count 
            FROM tickets t 
            JOIN users u ON t.user_id = u.id 
            WHERE t.user_id = ? 
            ORDER BY t.created_at DESC
        ");
        $stmtList->execute([$user_id]);
    }
    $tickets = $stmtList->fetchAll();
} catch (PDOException $e) {
    $tickets = [];
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($ticket_selected): ?>
    <!-- ==========================================================
         DETAILED TICKET VIEW SCREEN
         ========================================================== -->
    <div class="card" style="margin-bottom:30px;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid var(--border-glow); padding-bottom: 15px; margin-bottom: 25px;">
            <div>
                <a href="tickets.php" class="text-cyan" style="font-size:0.85rem; font-family:var(--font-heading);"><i class="fa-solid fa-arrow-left"></i> Support Archives</a>
                <h3 style="margin: 5px 0 0 0;">Case #<?php echo $ticket_selected['id']; ?>: <?php echo sanitize($ticket_selected['subject']); ?></h3>
            </div>
            
            <div style="display:flex; gap:10px; align-items:center;">
                <span class="badge badge-status-<?php echo strtolower($ticket_selected['status']); ?>">
                    <?php echo sanitize($ticket_selected['status']); ?>
                </span>
                <?php if (has_role('Core') && $ticket_selected['status'] !== 'Resolved'): ?>
                    <a href="tickets.php?resolve=<?php echo $ticket_selected['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding: 6px 12px; font-size:0.75rem;"><i class="fa-solid fa-check-double"></i> Mark Resolved</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ticket Body Details -->
        <div style="background: rgba(0,0,0,0.15); border: 1px solid var(--border-glow); padding: 20px; border-radius: 6px; margin-bottom: 35px;">
            <div style="display:flex; justify-content:space-between; font-size: 0.8rem; color:var(--text-muted); margin-bottom:12px; border-bottom: 1px dashed rgba(255,255,255,0.05); padding-bottom:10px;">
                <span>Opened by: <strong><?php echo sanitize($ticket_selected['creator_name']); ?></strong> (<?php echo sanitize($ticket_selected['creator_email']); ?>)</span>
                <span><i class="fa-solid fa-clock"></i> <?php echo sanitize($ticket_selected['created_at']); ?></span>
            </div>
            <p style="white-space:pre-wrap; line-height:1.6; font-size:0.95rem;"><?php echo sanitize($ticket_selected['message']); ?></p>
        </div>

        <!-- Chat Replies History -->
        <h4 class="text-cyan" style="font-size:0.95rem; margin-bottom: 20px;"><i class="fa-solid fa-comments"></i> Case Discussion Thread</h4>
        <div style="display:flex; flex-direction:column; gap:15px; margin-bottom: 35px;">
            <?php if (!empty($replies)): ?>
                <?php foreach ($replies as $reply): ?>
                    <?php $is_staff = in_array($reply['user_role'], ['Admin', 'Core']); ?>
                    <div class="ticket-bubble <?php echo $is_staff ? 'ticket-bubble-admin' : 'ticket-bubble-user'; ?>">
                        <div class="ticket-bubble-meta">
                            <strong><?php echo sanitize($reply['user_name']); ?> <?php echo $is_staff ? '<span class="badge badge-core" style="font-size: 0.55rem; padding:1px 3px;">Staff</span>' : ''; ?></strong>
                            <span><?php echo sanitize($reply['created_at']); ?></span>
                        </div>
                        <p style="white-space:pre-wrap; font-size:0.9rem; line-height:1.5; color: var(--text-primary);"><?php echo sanitize($reply['message']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.85rem; text-align:center; padding: 15px;">No comments posted yet. Staff will respond soon.</p>
            <?php endif; ?>
        </div>

        <!-- Reply Form Box (Only if not resolved) -->
        <?php if ($ticket_selected['status'] !== 'Resolved'): ?>
            <div style="border-top:1px dashed var(--border-glow); padding-top:25px;">
                <h4 class="text-cyan" style="font-size:0.9rem; margin-bottom:15px;"><i class="fa-solid fa-reply"></i> Post Message Reply</h4>
                <form action="tickets.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticket_selected['id']; ?>">
                    
                    <div class="form-group" style="margin-bottom:20px;">
                        <textarea name="message" class="form-control" rows="4" placeholder="Type support comment details here..." required></select></textarea>
                    </div>
                    
                    <button type="submit" name="submit_reply" class="btn btn-primary">
                        <i class="fa-solid fa-paper-plane"></i> Send Reply
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-lock"></i>
                <span>This case ticket has been marked Resolved and closed for discussion. Raise a new query if you have further issues.</span>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- ==========================================================
         TICKETS DIRECTORY & CREATOR SCREEN
         ========================================================== -->
    <div class="dashboard-layout" style="grid-template-columns: <?php echo get_user_role() === 'Member' ? '1.2fr 0.8fr' : '1fr'; ?>;">
        
        <!-- Left: Tickets List -->
        <div>
            <div class="card">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-circle-question"></i> Help Desk Cases</h3>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom: 20px;">
                    <?php echo has_role('Core') ? 'Manage user complaints, questions, and security vulnerability reports raised by club members.' : 'View your raised queries and communications with CyberKavach club operators.'; ?>
                </p>
                
                <?php if (!empty($tickets)): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Ticket Case ID</th>
                                    <th>Sender</th>
                                    <th>Subject Query</th>
                                    <th>Replies</th>
                                    <th>Last Updated</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-weight: bold;">#<?php echo $ticket['id']; ?></td>
                                        <td>
                                            <strong><?php echo sanitize($ticket['user_name']); ?></strong>
                                            <span style="display:block; font-size:0.75rem; color:var(--text-muted);"><?php echo sanitize($ticket['user_email']); ?></span>
                                        </td>
                                        <td><strong><?php echo sanitize($ticket['subject']); ?></strong></td>
                                        <td><span class="badge badge-member"><?php echo $ticket['reply_count']; ?></span></td>
                                        <td style="font-size:0.8rem;"><?php echo sanitize($ticket['created_at']); ?></td>
                                        <td>
                                            <span class="badge badge-status-<?php echo strtolower($ticket['status']); ?>">
                                                <?php echo sanitize($ticket['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="tickets.php?id=<?php echo $ticket['id']; ?>" class="btn btn-primary" style="padding: 5px 10px; font-size: 0.75rem;"><i class="fa-solid fa-envelope-open"></i> View Details</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fa-solid fa-ticket-simple" style="font-size: 3rem; margin-bottom: 15px;"></i>
                        <p>No active support cases recorded.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Raise Ticket form (Members only) -->
        <?php if (get_user_role() === 'Member'): ?>
            <div>
                <div class="card card-pink">
                    <h3 class="card-title text-pink"><i class="fa-solid fa-ticket"></i> Open Support Case</h3>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <i class="fa-solid fa-circle-xmark"></i>
                            <span><?php echo sanitize($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <form action="tickets.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <div class="form-group">
                            <label for="subject" class="form-label">Query Subject</label>
                            <input type="text" id="subject" name="subject" class="form-control" placeholder="Point reward issue / Bug report" required value="<?php echo isset($_POST['subject']) ? sanitize($_POST['subject']) : ''; ?>">
                        </div>

                        <div class="form-group" style="margin-bottom: 25px;">
                            <label for="message" class="form-label">Issue Details</label>
                            <textarea id="message" name="message" class="form-control" rows="8" placeholder="Outline your questions, issues, or details here..." required><?php echo isset($_POST['message']) ? sanitize($_POST['message']) : ''; ?></textarea>
                        </div>

                        <button type="submit" name="open_ticket" class="btn btn-danger btn-block">
                            <i class="fa-solid fa-paper-plane"></i> Deploy Case Request
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
