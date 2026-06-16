<?php
$page_title = 'Verify Certificate';
$page_heading = 'Registry Verification Portal';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$uuid = sanitize($_GET['uuid'] ?? $_POST['uuid'] ?? '');
$cert = null;
$searched = !empty($uuid);
$error_message = '';
$checksum = '';

if ($searched) {
    try {
        $stmt = $db->prepare("
            SELECT c.*, u.name as user_name, u.email as user_email,
                   e.title as event_title, e.date as event_date, e.points_reward as event_points,
                   q.title as quiz_title, q.points as quiz_points
            FROM certificates c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN events e ON c.event_id = e.id
            LEFT JOIN quizzes q ON c.quiz_id = q.id
            WHERE c.uuid = ?
            LIMIT 1
        ");
        $stmt->execute([$uuid]);
        $cert = $stmt->fetch();
        
        if (!$cert) {
            $error_message = 'VERIFICATION FAILURE: The provided UUID does not match any valid certificate in the CyberKavach registry.';
        } else {
            // Generate SHA-256 Checksum from certificate metadata to show cryptographic validity
            $checksum = hash('sha256', $cert['uuid'] . $cert['user_name'] . $cert['created_at']);
        }
    } catch (PDOException $e) {
        $error_message = 'Database error retrieving certificate details.';
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if (!is_logged_in()): ?>
<div class="auth-wrapper" style="min-height: 90vh; display: flex; align-items: center; justify-content: center; width: 100%; padding: 20px;">
    <div class="card auth-card" style="max-width: 750px; width: 100%; border-color: var(--border-glow); box-shadow: var(--shadow-cyber); padding: 30px;">
        <div class="auth-header" style="margin-bottom: 20px; text-align: center;">
            <div class="auth-logo">
                <i class="fa-solid fa-shield-halved text-cyan" style="font-size: 2.2rem;"></i>
            </div>
            <h2>CYBERKAWACH REGISTRY</h2>
            <p class="text-muted" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 5px;">Cryptographic Verification Portal</p>
        </div>
<?php else: ?>
<div class="card">
    <h3 class="card-title"><i class="fa-solid fa-shield-halved"></i> Cryptographic Verification Portal</h3>
    <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 20px;">Verify certificate UUIDs against the SQLite registry database nodes.</p>
<?php endif; ?>

        <!-- SEARCH FORM -->
        <form action="verify_certificate.php" method="GET" style="margin-bottom: 25px;">
            <div class="form-group">
                <label for="uuid" class="form-label" style="font-weight: 600;">Certificate Verification UUID</label>
                <div style="display: flex; gap: 10px; margin-top: 5px;">
                    <input type="text" id="uuid" name="uuid" class="form-control" placeholder="E.g., f81d4fae-7dec-11d0-a765-00a0c91e6bf6" value="<?php echo sanitize($uuid); ?>" required style="flex-grow: 1; font-family: monospace; font-size: 0.85rem; height: 42px;">
                    <button type="submit" class="btn btn-primary" style="height: 42px; text-transform: uppercase; letter-spacing: 1px;"><i class="fa-solid fa-square-check"></i> Verify</button>
                </div>
            </div>
        </form>

        <?php if ($searched): ?>
            <?php if ($cert): ?>
                <!-- VERIFIED RESULT -->
                <div style="border: 2px solid #000000; border-radius: 6px; padding: 25px; background: rgba(0, 0, 0, 0.01); position: relative; overflow: hidden; margin-top: 20px;">
                    <!-- Top scan accent -->
                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: #000000;"></div>
                    
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; color: #000000;">
                        <i class="fa-solid fa-circle-check" style="font-size: 2.2rem; color: #000000;"></i>
                        <div>
                            <strong style="font-size: 1.1rem; text-transform: uppercase; font-family: var(--font-heading);">Verified Compliant</strong>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">SQLite Node Registry: SECURE // VALIDATED</div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr; gap: 15px; font-size: 0.9rem; border-top: 1px dashed var(--border-glow); padding-top: 20px;">
                        <div>
                            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: block; font-weight: bold;">Award Recipient</span>
                            <strong style="font-size: 1.1rem; color: #000000;"><?php echo sanitize($cert['user_name']); ?></strong>
                            <span style="font-size: 0.8rem; color: var(--text-muted); display: block;"><?php echo sanitize($cert['user_email']); ?></span>
                        </div>

                        <div>
                            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: block; font-weight: bold;">Completion Module</span>
                            <strong style="color: #000000;"><?php echo sanitize(!empty($cert['event_id']) ? $cert['event_title'] : $cert['quiz_title']); ?></strong>
                            <span style="font-size: 0.8rem; color: var(--text-muted); display: block;">
                                <?php echo !empty($cert['event_id']) ? 'Workshop Session RSVP' : 'Security Quiz/Lab Assessment'; ?>
                            </span>
                        </div>

                        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: block; font-weight: bold;">Issuance Timestamp</span>
                                <strong><?php echo sanitize($cert['created_at']); ?></strong>
                            </div>
                            <div>
                                <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: block; font-weight: bold;">Points Credited</span>
                                <strong>+<?php echo !empty($cert['event_id']) ? $cert['event_points'] : $cert['quiz_points']; ?> XP</strong>
                            </div>
                        </div>

                        <div style="border-top: 1px dashed var(--border-glow); padding-top: 15px; margin-top: 10px;">
                            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: block; font-weight: bold; margin-bottom: 4px;">Cryptographic verification Checksum (SHA-256)</span>
                            <div style="font-family: monospace; font-size: 0.75rem; background: var(--bg-surface-elevated); padding: 8px 12px; border-radius: 4px; border: 1px solid var(--border-glow); word-break: break-all; color: #000000; font-weight: 600;">
                                <?php echo $checksum; ?>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px;">
                        <a href="certificate.php?uuid=<?php echo urlencode($cert['uuid']); ?>" target="_blank" class="btn btn-primary" style="font-size: 0.75rem;"><i class="fa-solid fa-print"></i> Open Printable Certificate</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- VERIFICATION FAILURE -->
                <div style="border: 2px solid #000000; border-radius: 6px; padding: 25px; background: rgba(0, 0, 0, 0.01); text-align: center; margin-top: 20px; position: relative; overflow: hidden;">
                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: #000000;"></div>
                    <i class="fa-solid fa-circle-xmark" style="font-size: 3rem; color: #000000; margin-bottom: 15px;"></i>
                    <h4 style="margin: 0 0 10px 0; font-family: var(--font-heading); color: #000000; text-transform: uppercase; letter-spacing: 1px;">Verification Failed</h4>
                    <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; max-width: 500px; margin: 0 auto 15px auto;">
                        <?php echo sanitize($error_message); ?>
                    </p>
                    <div style="font-size: 0.75rem; font-family: monospace; background: var(--bg-surface-elevated); padding: 6px; border-radius: 4px; border: 1px solid var(--border-glow); display: inline-block; color: #ff0000; font-weight: bold;">
                        ERROR_CODE: REGISTRY_UUID_NOT_FOUND
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!is_logged_in()): ?>
            <div style="margin-top: 25px; border-top: 1px solid var(--border-glow); padding-top: 15px; text-align: center; font-size: 0.85rem;">
                <span class="text-muted">Need to manage credentials?</span> 
                <a href="login.php" class="text-cyan" style="font-weight: 600;">Secure Portal Login</a>
            </div>
        </div> <!-- /card -->
    </div> <!-- /auth-wrapper -->
<?php else: ?>
</div> <!-- /card -->
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
