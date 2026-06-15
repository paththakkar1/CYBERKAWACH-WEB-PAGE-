<?php
$page_title = 'Events';
$page_heading = 'Workshop Calendar & RSVPs';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = $_SESSION['user_id'];
$user_role = get_user_role();
$error = '';

// -------------------------------------------------------------
// EVENT ACTIONS (ADMIN & CORE)
// -------------------------------------------------------------
// 1. Create Event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    if (!has_role('Core')) {
        $error = 'Access denied.';
    } else {
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $date = sanitize($_POST['date'] ?? '');
        $time = sanitize($_POST['time'] ?? '');
        $location = sanitize($_POST['location'] ?? '');
        $max_participants = intval($_POST['max_participants'] ?? 0);
        $points_reward = intval($_POST['points_reward'] ?? 0);
        $csrf_token = $_POST['csrf_token'] ?? '';
        
        if (!verify_csrf_token($csrf_token)) {
            $error = 'Security check failed. Refresh and try again.';
        } elseif (empty($title) || empty($description) || empty($date) || empty($time) || empty($location) || $max_participants <= 0) {
            $error = 'Please fill all fields and provide a valid capacity.';
        } else {
            try {
                $status = ($user_role === 'Admin') ? 'Approved' : 'Pending Approval';
                $stmt = $db->prepare("INSERT INTO events (title, description, date, time, location, max_participants, points_reward, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $description, $date, $time, $location, $max_participants, $points_reward, $user_id, $status]);
                
                log_event($user_id, 'Create Event', "Created event: $title (Status: $status, Points: $points_reward)");
                
                if ($status === 'Approved') {
                    set_flash_message('success', 'Security workshop scheduled successfully.');
                } else {
                    set_flash_message('warning', 'Workshop proposal submitted! It must be co-signed/approved by another coordinator before going live.');
                }
                redirect('events.php');
            } catch (PDOException $e) {
                $error = 'Failed to schedule event.';
            }
        }
    }
}

// 2. Mark Attendance (Admin & Core only)
if (isset($_GET['mark_attendance']) && has_role('Core')) {
    $reg_id = intval($_GET['mark_attendance']);
    $new_status = sanitize($_GET['status'] ?? '');
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } elseif (!in_array($new_status, ['Attended', 'Absent', 'Registered'])) {
        set_flash_message('error', 'Invalid attendance status.');
    } else {
        try {
            // Fetch registration details
            $stmtReg = $db->prepare("SELECT r.*, e.points_reward, u.points as current_user_points, u.id as attendee_id FROM registrations r JOIN events e ON r.event_id = e.id JOIN users u ON r.user_id = u.id WHERE r.id = ?");
            $stmtReg->execute([$reg_id]);
            $reg = $stmtReg->fetch();
            
            if ($reg) {
                $old_status = $reg['status'];
                $points_diff = 0;
                
                // Calculate points adjustment
                if ($old_status !== 'Attended' && $new_status === 'Attended') {
                    // Award points
                    $points_diff = $reg['points_reward'];
                } elseif ($old_status === 'Attended' && $new_status !== 'Attended') {
                    // Revoke points
                    $points_diff = -($reg['points_reward']);
                }
                
                $db->beginTransaction();
                
                // Update Registration Status
                $stmtUpdateReg = $db->prepare("UPDATE registrations SET status = ? WHERE id = ?");
                $stmtUpdateReg->execute([$new_status, $reg_id]);
                
                // Adjust User Points
                if ($points_diff !== 0) {
                    $stmtUpdateUser = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
                    $stmtUpdateUser->execute([$points_diff, $reg['attendee_id']]);
                }
                
                $db->commit();
                
                log_event($user_id, 'Mark Attendance', "Updated attendance of user ID {$reg['attendee_id']} to '$new_status'. Points diff: $points_diff");
                set_flash_message('success', 'Attendance marked and points adjusted.');
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            set_flash_message('error', 'Failed to update attendance.');
        }
    }
    redirect('events.php');
}

// Toggle Live Check-in (Admin & Core only)
if (isset($_GET['toggle_checkin']) && has_role('Core')) {
    $event_id = intval($_GET['toggle_checkin']);
    $active_val = intval($_GET['status'] ?? 0);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            if ($active_val === 1) {
                $code = strval(rand(100000, 999999));
                $stmt = $db->prepare("UPDATE events SET checkin_active = 1, checkin_code = ? WHERE id = ?");
                $stmt->execute([$code, $event_id]);
                log_event($user_id, 'Check-in Activated', "Activated live check-in for event ID $event_id. Code: $code");
                set_flash_message('success', "Live check-in activated! Passcode: $code");
            } else {
                $stmt = $db->prepare("UPDATE events SET checkin_active = 0, checkin_code = NULL WHERE id = ?");
                $stmt->execute([$event_id]);
                log_event($user_id, 'Check-in Deactivated', "Deactivated live check-in for event ID $event_id");
                set_flash_message('success', 'Live check-in deactivated.');
            }
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to toggle check-in status.');
        }
    }
    redirect('events.php');
}

// 3. Delete Event (Admin & Core only)
if (isset($_GET['delete']) && has_role('Core')) {
    $delete_id = intval($_GET['delete']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$delete_id]);
            
            log_event($user_id, 'Delete Event', "Deleted event ID $delete_id");
            set_flash_message('success', 'Workshop event deleted.');
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to delete event.');
        }
    }
    redirect('events.php');
}

// -------------------------------------------------------------
// MEMBER ACTIONS
// -------------------------------------------------------------
// 1. RSVP (Register) for Event
if (isset($_GET['rsvp'])) {
    $event_id = intval($_GET['rsvp']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            // Check availability
            $stmtEv = $db->prepare("
                SELECT e.*, 
                (SELECT COUNT(*) FROM registrations WHERE event_id = e.id) as rsvp_count 
                FROM events e WHERE e.id = ?
            ");
            $stmtEv->execute([$event_id]);
            $event = $stmtEv->fetch();
            
            if (!$event) {
                set_flash_message('error', 'Event not found.');
            } elseif ($event['rsvp_count'] >= $event['max_participants']) {
                set_flash_message('error', 'This event has reached maximum capacity.');
            } elseif (strtotime($event['date']) < strtotime(date('Y-m-d'))) {
                set_flash_message('error', 'Cannot RSVP to past events.');
            } else {
                // Register
                $stmtRegister = $db->prepare("INSERT OR IGNORE INTO registrations (user_id, event_id, status) VALUES (?, ?, 'Registered')");
                $stmtRegister->execute([$user_id, $event_id]);
                
                log_event($user_id, 'RSVP Event', "Registered for event ID $event_id: '{$event['title']}'");
                set_flash_message('success', 'Spot reserved! We look forward to seeing you.');
            }
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to submit RSVP.');
        }
    }
    redirect('events.php');
}

// 2. Cancel RSVP
if (isset($_GET['cancel_rsvp'])) {
    $event_id = intval($_GET['cancel_rsvp']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            // Check status: Cannot cancel if already marked attended
            $stmtCheck = $db->prepare("SELECT status FROM registrations WHERE user_id = ? AND event_id = ?");
            $stmtCheck->execute([$user_id, $event_id]);
            $status = $stmtCheck->fetchColumn();
            
            if ($status === 'Attended') {
                set_flash_message('error', 'Cannot cancel RSVP after attendance is marked.');
            } else {
                $stmtCancel = $db->prepare("DELETE FROM registrations WHERE user_id = ? AND event_id = ?");
                $stmtCancel->execute([$user_id, $event_id]);
                
                log_event($user_id, 'Cancel RSVP', "Cancelled RSVP for event ID $event_id");
                set_flash_message('success', 'RSVP cancelled successfully.');
            }
        } catch (PDOException $e) {
            set_flash_message('error', 'Failed to cancel RSVP.');
        }
    }
    redirect('events.php');
}

// 3. Submit Passcode Live Check-in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_checkin'])) {
    $event_id = intval($_POST['event_id']);
    $code_submitted = trim($_POST['checkin_passcode'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
    } else {
        try {
            $stmtEv = $db->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
            $stmtEv->execute([$event_id]);
            $event = $stmtEv->fetch();
            
            if (!$event) {
                set_flash_message('error', 'Event not found.');
            } elseif (intval($event['checkin_active']) !== 1 || empty($event['checkin_code'])) {
                set_flash_message('error', 'Live check-in is not active for this event.');
            } elseif ($event['checkin_code'] !== $code_submitted) {
                set_flash_message('error', 'Invalid check-in passcode.');
            } else {
                $db->beginTransaction();
                
                $stmtCheck = $db->prepare("SELECT id, status FROM registrations WHERE user_id = ? AND event_id = ?");
                $stmtCheck->execute([$user_id, $event_id]);
                $reg = $stmtCheck->fetch();
                
                $points_reward = intval($event['points_reward']);
                
                if ($reg) {
                    if ($reg['status'] === 'Attended') {
                        $db->rollBack();
                        set_flash_message('info', 'You have already checked in for this event!');
                        redirect('events.php');
                    } else {
                        $stmtUpdate = $db->prepare("UPDATE registrations SET status = 'Attended' WHERE id = ?");
                        $stmtUpdate->execute([$reg['id']]);
                        
                        $stmtPoints = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
                        $stmtPoints->execute([$points_reward, $user_id]);
                    }
                } else {
                    $stmtInsert = $db->prepare("INSERT INTO registrations (user_id, event_id, status) VALUES (?, ?, 'Attended')");
                    $stmtInsert->execute([$user_id, $event_id]);
                    
                    $stmtPoints = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
                    $stmtPoints->execute([$points_reward, $user_id]);
                }
                
                // Auto generate certificate
                generate_certificate($user_id, $event_id, null);
                
                // Award Event Champion badge
                award_badge($user_id, 'Event Champion');
                
                // Check milestones
                check_xp_milestones($user_id);
                
                $db->commit();
                
                log_event($user_id, 'Live Check-in Success', "Checked in to event ID $event_id. Points: +$points_reward XP");
                set_flash_message('success', "Check-in successful! +{$points_reward} XP. Certificate generated.");
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            set_flash_message('error', 'Failed to process check-in.');
        }
    }
    redirect('events.php');
}

// -------------------------------------------------------------
// RENDER DATA FETCH
// -------------------------------------------------------------
// Fetch all events (Only approved ones for members, all for core/admin)
try {
    $where_clause = has_role('Core') ? "" : "WHERE e.status = 'Approved'";
    $stmtEvents = $db->query("
        SELECT e.*, u.name as organizer_name,
        (SELECT COUNT(*) FROM registrations WHERE event_id = e.id) as rsvp_count,
        (SELECT status FROM registrations WHERE event_id = e.id AND user_id = " . intval($user_id) . ") as my_rsvp_status
        FROM events e 
        LEFT JOIN users u ON e.created_by = u.id 
        $where_clause
        ORDER BY e.date ASC, e.time ASC
    ");
    $events = $stmtEvents->fetchAll();
} catch (PDOException $e) {
    $events = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="dashboard-layout" style="grid-template-columns: <?php echo has_role('Core') ? '1.2fr 0.8fr' : '1fr'; ?>;">
    
    <!-- Left Column: Events Schedule -->
    <div>
        <div class="card">
            <h3 class="card-title text-cyan"><i class="fa-solid fa-calendar-days"></i> Workshop & Seminars Schedule</h3>
            
            <?php if (!empty($events)): ?>
                <div style="display:flex; flex-direction:column; gap:25px; margin-top:20px;">
                    <?php foreach ($events as $event): ?>
                        <?php 
                        $is_past = strtotime($event['date']) < strtotime(date('Y-m-d')); 
                        $is_registered = !empty($event['my_rsvp_status']);
                        ?>
                        <div class="card <?php echo $is_past ? 'card-pink' : 'card-success'; ?>" style="padding: 20px; border-width: 1px; border-color: rgba(255,255,255,0.05);">
                            <!-- Header info -->
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 12px;">
                                <div>
                                    <h4 style="margin:0; font-size:1.15rem;"><?php echo sanitize($event['title']); ?></h4>
                                    <span style="font-size:0.75rem; color:var(--text-muted);">Organized by: <strong><?php echo sanitize($event['organizer_name'] ?? 'Core Committee'); ?></strong></span>
                                </div>
                                <div style="text-align: right;">
                                    <span class="badge badge-member">+<?php echo $event['points_reward']; ?> XP</span>
                                    <?php if ($is_past): ?>
                                        <div class="badge badge-status-suspended" style="margin-top: 5px; font-size:0.6rem;">Archived</div>
                                    <?php elseif ($event['status'] === 'Pending Approval'): ?>
                                        <div class="badge badge-status-pending" style="margin-top: 5px; font-size:0.6rem;">Proposed</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Main details -->
                            <p style="color:var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;"><?php echo sanitize($event['description']); ?></p>
                            
                            <!-- Meta stats -->
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 15px; font-size: 0.8rem; border-top: 1px solid var(--border-glow); padding-top: 15px; margin-bottom: 15px;">
                                <div><i class="fa-solid fa-calendar-day text-cyan"></i> <?php echo sanitize($event['date']); ?></div>
                                <div><i class="fa-solid fa-clock text-cyan"></i> <?php echo sanitize($event['time']); ?></div>
                                <div><i class="fa-solid fa-location-dot text-cyan"></i> <?php echo sanitize($event['location']); ?></div>
                                <div><i class="fa-solid fa-users text-cyan"></i> <?php echo $event['rsvp_count'] . ' / ' . $event['max_participants']; ?> registered</div>
                            </div>
                            
                            <!-- Action Control -->
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                                <div>
                                    <?php if ($is_registered): ?>
                                        <span class="badge badge-status-<?php echo strtolower($event['my_rsvp_status']); ?>">
                                            Status: <?php echo sanitize($event['my_rsvp_status']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                    <?php if (has_role('Core')): ?>
                                        <!-- Check-in Toggles for Coordinator -->
                                        <?php if ($event['status'] === 'Approved'): ?>
                                            <?php if ($event['checkin_active']): ?>
                                                <a href="events.php?toggle_checkin=<?php echo $event['id']; ?>&status=0&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:6px 12px; font-size:0.75rem;"><i class="fa-solid fa-wifi-slash"></i> Close Check-in</a>
                                            <?php else: ?>
                                                <a href="events.php?toggle_checkin=<?php echo $event['id']; ?>&status=1&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:6px 12px; font-size:0.75rem;"><i class="fa-solid fa-wifi"></i> Start Check-in</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <a href="events.php?delete=<?php echo $event['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:6px 12px; font-size:0.75rem;" onclick="return confirm('Delete event? Registered members will be cancelled.');"><i class="fa-solid fa-trash-can"></i> Delete</a>
                                    <?php endif; ?>
                                    
                                    <?php if (!$is_past && $event['status'] === 'Approved'): ?>
                                        <?php if ($is_registered): ?>
                                            <?php if ($event['my_rsvp_status'] !== 'Attended'): ?>
                                                <a href="events.php?cancel_rsvp=<?php echo $event['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding:6px 12px; font-size:0.75rem;"><i class="fa-solid fa-xmark"></i> Cancel RSVP</a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($event['rsvp_count'] < $event['max_participants']): ?>
                                                <a href="events.php?rsvp=<?php echo $event['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding:6px 12px; font-size:0.75rem;"><i class="fa-solid fa-check"></i> Register Spot</a>
                                            <?php else: ?>
                                                <span class="text-pink" style="font-size:0.8rem; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> Event Full</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Live Check-in Box -->
                            <?php if ($event['status'] === 'Approved' && $event['checkin_active']): ?>
                                <?php if (has_role('Core')): ?>
                                    <div style="margin-top: 15px; background: rgba(0, 0, 0, 0.02); border: 1px solid var(--border-glow); padding: 12px; border-radius: 4px; display: flex; align-items: center; justify-content: space-between;">
                                        <span style="font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-wifi"></i> Live Check-in Code:
                                        </span>
                                        <span style="font-family: var(--font-heading); font-size: 1.4rem; font-weight: 800; color: var(--color-primary); letter-spacing: 2px;">
                                            <?php echo sanitize($event['checkin_code']); ?>
                                        </span>
                                    </div>
                                <?php elseif ($is_registered && $event['my_rsvp_status'] !== 'Attended'): ?>
                                    <form action="events.php" method="POST" style="margin-top: 15px; background: rgba(0, 0, 0, 0.02); border: 1px dashed var(--border-glow); padding: 15px; border-radius: 4px; display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-satellite-dish"></i>
                                            <span style="font-size: 0.85rem; font-weight: bold; color: var(--color-primary);">Check-in is Live:</span>
                                        </div>
                                        <div style="display: flex; gap: 8px; flex-grow: 1; justify-content: flex-end; max-width: 320px;">
                                            <input type="text" name="checkin_passcode" placeholder="6-Digit Passcode" class="form-control" style="padding: 6px 12px; font-size: 0.8rem; width: 140px; text-align: center;" required>
                                            <button type="submit" name="submit_checkin" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.75rem;">Submit</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <!-- Attendance Manager Section for Core / Admin -->
                            <?php if (has_role('Core')): ?>
                                <?php 
                                // Fetch registrations for this event
                                try {
                                    $stmtRList = $db->prepare("SELECT r.*, u.name as attendee_name, u.email as attendee_email FROM registrations r JOIN users u ON r.user_id = u.id WHERE r.event_id = ?");
                                    $stmtRList->execute([$event['id']]);
                                    $r_list = $stmtRList->fetchAll();
                                } catch (PDOException $e) {
                                    $r_list = [];
                                }
                                ?>
                                <div style="margin-top:20px; border-top: 1px dashed var(--border-glow); padding-top: 15px;">
                                    <h5 style="font-size:0.8rem; margin-bottom:10px; color:var(--text-primary);"><i class="fa-solid fa-users-gear"></i> RSVP Registry (<?php echo count($r_list); ?> Attendees)</h5>
                                    <?php if (!empty($r_list)): ?>
                                        <div class="table-responsive">
                                            <table class="table-custom" style="margin-top:5px; font-size:0.8rem;">
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Email</th>
                                                        <th>RSVP Status</th>
                                                        <th>Mark Attendance</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="attendance-tbody-<?php echo $event['id']; ?>">
                                                    <?php foreach ($r_list as $reg): ?>
                                                        <tr>
                                                            <td><strong><?php echo sanitize($reg['attendee_name']); ?></strong></td>
                                                            <td style="color:var(--text-muted); font-size:0.75rem;"><?php echo sanitize($reg['attendee_email']); ?></td>
                                                            <td>
                                                                <span class="badge badge-status-<?php echo strtolower($reg['status']); ?>" style="font-size:0.6rem; padding: 2px 4px;">
                                                                    <?php echo sanitize($reg['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <div style="display:flex; gap:5px;">
                                                                    <a href="events.php?mark_attendance=<?php echo $reg['id']; ?>&status=Attended&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding: 3px 6px; font-size:0.65rem;" title="Mark Attended"><i class="fa-solid fa-check"></i> Attended</a>
                                                                    <a href="events.php?mark_attendance=<?php echo $reg['id']; ?>&status=Absent&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding: 3px 6px; font-size:0.65rem;" title="Mark Absent"><i class="fa-solid fa-user-xmark"></i> Absent</a>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p style="color:var(--text-muted); font-size:0.75rem;">No member registrations recorded for this workshop yet.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fa-solid fa-calendar-xmark" style="font-size: 3rem; margin-bottom: 15px;"></i>
                    <p>No events schedules found in database.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Right Column: Add Event Form (Core / Admin only) -->
    <?php if (has_role('Core')): ?>
        <div>
            <div class="card card-pink">
                <h3 class="card-title text-pink"><i class="fa-solid fa-calendar-plus"></i> Schedule Security Event</h3>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fa-solid fa-circle-xmark"></i>
                        <span><?php echo sanitize($error); ?></span>
                    </div>
                <?php endif; ?>

                <form action="events.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="form-group">
                        <label for="title" class="form-label">Workshop Title</label>
                        <input type="text" id="title" name="title" class="form-control" placeholder="SQL Injection Deep Dive" required value="<?php echo isset($_POST['title']) ? sanitize($_POST['title']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="description" class="form-label">Brief Description</label>
                        <textarea id="description" name="description" class="form-control" rows="4" placeholder="Brief outline of the learning outcomes and pre-requisites..." required><?php echo isset($_POST['description']) ? sanitize($_POST['description']) : ''; ?></textarea>
                    </div>

                    <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label for="date" class="form-label">Schedule Date</label>
                            <input type="date" id="date" name="date" class="form-control" required value="<?php echo isset($_POST['date']) ? sanitize($_POST['date']) : ''; ?>">
                        </div>
                        <div>
                            <label for="time" class="form-label">Start Time</label>
                            <input type="time" id="time" name="time" class="form-control" required value="<?php echo isset($_POST['time']) ? sanitize($_POST['time']) : ''; ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="location" class="form-label">Venue / Meeting URL</label>
                        <input type="text" id="location" name="location" class="form-control" placeholder="Lab 3 / Zoom Link" required value="<?php echo isset($_POST['location']) ? sanitize($_POST['location']) : ''; ?>">
                    </div>

                    <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px;">
                        <div>
                            <label for="max_participants" class="form-label">Max Seat Capacity</label>
                            <input type="number" id="max_participants" name="max_participants" class="form-control" min="5" placeholder="50" required value="<?php echo isset($_POST['max_participants']) ? intval($_POST['max_participants']) : 50; ?>">
                        </div>
                        <div>
                            <label for="points_reward" class="form-label">Experience Reward</label>
                            <input type="number" id="points_reward" name="points_reward" class="form-control" min="0" placeholder="50 XP" value="<?php echo isset($_POST['points_reward']) ? intval($_POST['points_reward']) : 50; ?>">
                        </div>
                    </div>

                    <button type="submit" name="add_event" class="btn btn-danger btn-block">
                        <i class="fa-solid fa-calendar-plus"></i> Deploy Workshop
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (has_role('Core')): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const activeEvents = [
        <?php 
        $active_ids = [];
        foreach ($events as $ev) {
            if ($ev['status'] === 'Approved' && $ev['checkin_active']) {
                $active_ids[] = intval($ev['id']);
            }
        }
        echo implode(',', $active_ids);
        ?>
    ];
    
    if (activeEvents.length > 0) {
        setInterval(function() {
            activeEvents.forEach(function(eventId) {
                fetch('api_attendance.php?event_id=' + eventId)
                    .then(response => response.json())
                    .then(data => {
                        const tbody = document.getElementById('attendance-tbody-' + eventId);
                        if (!tbody) return;
                        
                        let html = '';
                        if (data && data.length > 0) {
                            data.forEach(function(reg) {
                                const statusClass = reg.status.toLowerCase();
                                html += `<tr>
                                    <td><strong>${escapeHtml(reg.attendee_name)}</strong></td>
                                    <td style="color:var(--text-muted); font-size:0.75rem;">${escapeHtml(reg.attendee_email)}</td>
                                    <td>
                                        <span class="badge badge-status-${statusClass}" style="font-size:0.6rem; padding: 2px 4px;">
                                            ${escapeHtml(reg.status)}
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:5px;">
                                            <a href="events.php?mark_attendance=${reg.id}&status=Attended&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success" style="padding: 3px 6px; font-size:0.65rem;" title="Mark Attended"><i class="fa-solid fa-check"></i> Attended</a>
                                            <a href="events.php?mark_attendance=${reg.id}&status=Absent&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger" style="padding: 3px 6px; font-size:0.65rem;" title="Mark Absent"><i class="fa-solid fa-user-xmark"></i> Absent</a>
                                        </div>
                                    </td>
                                </tr>`;
                            });
                        } else {
                            html = '<tr><td colspan="4" style="text-align:center; color:var(--text-muted);">No member registrations recorded for this workshop yet.</td></tr>';
                        }
                        tbody.innerHTML = html;
                    })
                    .catch(err => console.error(err));
            });
        }, 5000);
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
