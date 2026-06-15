<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$uuid = sanitize($_GET['uuid'] ?? '');
$cert = null;

if (!empty($uuid)) {
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
    } catch (PDOException $e) {
        // Fail silently
    }
}

if (!$cert) {
    // If not found, show error screen
    $page_title = 'Invalid Certificate';
    include __DIR__ . '/includes/header.php';
    echo '<div class="card card-pink" style="max-width: 600px; margin: 40px auto; text-align: center; padding: 40px;">';
    echo '<i class="fa-solid fa-triangle-exclamation text-pink" style="font-size: 3.5rem; margin-bottom: 20px;"></i>';
    echo '<h3 class="text-pink">Certificate Verification Failed</h3>';
    echo '<p style="color:var(--text-muted); margin-bottom:25px;">The verification code provided is invalid or has been revoked. Ensure the URL is typed correctly.</p>';
    echo '<a href="dashboard.php" class="btn btn-primary">Return to Console</a>';
    echo '</div>';
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Extract certificate properties
$recipient_name = $cert['user_name'];
$recipient_email = $cert['user_email'];
$issue_date = date('F d, Y', strtotime($cert['created_at']));
$is_event = !empty($cert['event_id']);
$award_title = $is_event ? $cert['event_title'] : $cert['quiz_title'];
$award_type = $is_event ? 'Workshop Completion' : 'Lab Assessment passed';
$points_earned = $is_event ? $cert['event_points'] : $cert['quiz_points'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification: <?php echo sanitize($recipient_name); ?> - CyberKavach OS</title>
    <!-- Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;600;800;900&display=swap">
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-main: #ffffff;
            --bg-cert: #f9f9f9;
            --color-cyan: #000000;
            --color-purple: #666666;
            --color-pink: #000000;
            --text-primary: #000000;
            --text-muted: #666666;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-x: hidden;
            position: relative;
        }

        /* Minimal gray background grid */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background-image: 
                linear-gradient(rgba(0, 0, 0, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 0, 0, 0.02) 1px, transparent 1px);
            background-size: 30px 30px;
            z-index: -2;
            pointer-events: none;
        }

        /* Action bar */
        .actions-bar {
            width: 100%;
            max-width: 950px;
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            z-index: 10;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 4px;
            border: 1px solid var(--color-cyan);
            background: #000000;
            color: #ffffff;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            text-decoration: none;
        }
 
        .btn-action:hover {
            background: #222222;
            color: #ffffff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }
 
        .btn-secondary {
            border-color: var(--color-cyan);
            background: #ffffff;
            color: #000000;
            box-shadow: none;
        }
 
        .btn-secondary:hover {
            background: #f0f0f0;
            color: #000000;
            box-shadow: none;
        }
 
        /* Certificate Frame */
        .certificate-container {
            background-color: var(--bg-cert);
            border: 2px solid var(--color-cyan);
            border-radius: 12px;
            width: 100%;
            max-width: 950px;
            padding: 60px 80px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            position: relative;
            overflow: hidden;
        }

        /* Cyberpunk corners */
        .certificate-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 30px;
            height: 30px;
            border-top: 4px solid var(--color-pink);
            border-left: 4px solid var(--color-pink);
        }

        .certificate-container::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 30px;
            height: 30px;
            border-bottom: 4px solid var(--color-pink);
            border-right: 4px solid var(--color-pink);
        }

        .top-left-accent {
            position: absolute;
            top: 20px;
            left: 20px;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.6rem;
            color: var(--color-cyan);
            opacity: 0.4;
            letter-spacing: 2px;
        }

        .bottom-right-accent {
            position: absolute;
            bottom: 20px;
            right: 20px;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.6rem;
            color: var(--color-cyan);
            opacity: 0.4;
            letter-spacing: 2px;
        }

        /* Logo & Header */
        .cert-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .cert-logo {
            font-family: 'Orbitron', sans-serif;
            font-weight: 900;
            font-size: 1.8rem;
            color: var(--color-cyan);
            letter-spacing: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .cert-logo i {
            color: var(--color-cyan);
            -webkit-text-fill-color: initial;
        }

        .cert-subtitle {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.75rem;
            color: var(--color-cyan);
            letter-spacing: 4px;
            text-transform: uppercase;
            margin-bottom: 30px;
        }

        /* Certificate Title */
        .cert-title {
            text-align: center;
            font-family: 'Orbitron', sans-serif;
            font-weight: 800;
            font-size: 2.2rem;
            letter-spacing: 2px;
            color: var(--text-primary);
            text-shadow: none;
            margin-bottom: 25px;
            text-transform: uppercase;
        }

        /* Certificate Body */
        .cert-body {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 40px auto;
        }

        .cert-text-intro {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .cert-recipient-name {
            font-family: 'Orbitron', sans-serif;
            font-size: 2.4rem;
            font-weight: 900;
            color: var(--color-cyan);
            text-shadow: none;
            border-bottom: 1px dashed var(--color-cyan);
            display: inline-block;
            padding: 0 20px 10px 20px;
            margin-bottom: 25px;
            letter-spacing: 1px;
        }

        .cert-text-main {
            font-size: 1.05rem;
            color: var(--text-primary);
            line-height: 1.8;
            margin-bottom: 25px;
        }

        .cert-text-main strong {
            color: #000;
            text-shadow: none;
        }

        .cert-points-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(0, 0, 0, 0.04);
            border: 1px solid #000000;
            color: #000000;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.9rem;
            font-weight: bold;
            padding: 6px 16px;
            border-radius: 20px;
            letter-spacing: 1px;
        }

        /* Footer layout (Signatures and verification) */
        .cert-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 50px;
            gap: 40px;
        }

        .cert-signature-block {
            flex: 1;
            text-align: center;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            padding-top: 15px;
            max-width: 220px;
        }

        .cert-signature-svg {
            font-family: 'Orbitron', sans-serif;
            font-style: italic;
            font-size: 1.15rem;
            color: var(--color-cyan);
            margin-bottom: 10px;
            opacity: 0.85;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            letter-spacing: 1px;
        }

        .cert-signature-svg.signature-admin {
            color: var(--color-pink);
        }

        .cert-signature-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.65rem;
            font-weight: bold;
            letter-spacing: 1px;
            color: var(--text-primary);
            text-transform: uppercase;
        }

        .cert-signature-subtitle {
            font-size: 0.65rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Verification QR placeholder */
        .cert-verification-block {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .qr-placeholder {
            width: 90px;
            height: 90px;
            border: 2px solid var(--color-cyan);
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.02);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin-bottom: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }

        .qr-placeholder i {
            font-size: 2.2rem;
            color: var(--color-cyan);
            opacity: 0.7;
        }

        /* Futuristic scanline inside QR */
        .qr-placeholder::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 2px;
            background: #000000;
            box-shadow: none;
            top: 0;
            left: 0;
            animation: qrScan 3s infinite linear;
        }

        .verification-text {
            font-size: 0.6rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            line-height: 1.4;
        }

        .verification-uuid {
            font-family: monospace;
            font-size: 0.65rem;
            color: var(--color-cyan);
            margin-top: 4px;
        }

        @keyframes qrScan {
            0% { top: 0; }
            50% { top: calc(100% - 2px); }
            100% { top: 0; }
        }

        /* Print Settings */
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
                min-height: initial;
                padding: 0;
            }

            body::before, body::after {
                display: none !important;
            }

            .actions-bar {
                display: none !important;
            }

            .certificate-container {
                border: 2px solid #000 !important;
                background: #fff !important;
                box-shadow: none !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 40px 50px !important;
                border-radius: 0 !important;
            }

            .cert-title {
                color: #000 !important;
                text-shadow: none !important;
            }

            .cert-recipient-name {
                color: #000 !important;
                text-shadow: none !important;
                border-bottom: 2px solid #000 !important;
            }

            .cert-text-main, .cert-text-main strong {
                color: #000 !important;
                text-shadow: none !important;
            }

            .cert-signature-svg {
                color: #000 !important;
            }

            .qr-placeholder {
                border-color: #000 !important;
            }

            .qr-placeholder i {
                color: #000 !important;
            }

            .qr-placeholder::before {
                display: none !important;
            }

            .verification-uuid {
                color: #000 !important;
            }
        }
    </style>
</head>
<body>

    <div class="actions-bar">
        <a href="profile.php" class="btn-action btn-secondary"><i class="fa-solid fa-arrow-left"></i> My Profile</a>
        <button onclick="window.print();" class="btn-action"><i class="fa-solid fa-print"></i> Save / Print PDF</button>
    </div>

    <div class="certificate-container">
        <div class="top-left-accent">SYS_STATUS: VALIDATED // SEC_LEVEL: ACTIVE</div>
        
        <div class="cert-header">
            <div class="cert-logo">
                <i class="fa-solid fa-shield-halved"></i> CyberKavach
            </div>
            <div class="cert-subtitle">Security Education Registry</div>
        </div>

        <div class="cert-title">Certificate of Achievement</div>

        <div class="cert-body">
            <div class="cert-text-intro">This certifies that</div>
            <div class="cert-recipient-name"><?php echo sanitize($recipient_name); ?></div>
            <div class="cert-text-main">
                has successfully completed the <?php echo $is_event ? 'live workshop session' : 'interactive training lab'; ?> titled<br>
                <strong>"<?php echo sanitize($award_title); ?>"</strong><br>
                demonstrating command of key defensive concepts, threat methodologies, and mitigation controls.
            </div>
            <div class="cert-points-badge">
                <i class="fa-solid fa-shield-halved"></i> +<?php echo $points_earned; ?> XP CREDITED
            </div>
        </div>

        <div class="cert-footer">
            <!-- Signature 1 -->
            <div class="cert-signature-block">
                <div class="cert-signature-svg signature-admin">SuperAdminFounder</div>
                <div class="cert-signature-title">Super Admin Founder</div>
                <div class="cert-signature-subtitle">CyberKavach Authority</div>
            </div>

            <!-- Verification Block -->
            <div class="cert-verification-block">
                <div class="qr-placeholder">
                    <i class="fa-solid fa-qrcode"></i>
                </div>
                <div class="verification-text">Verified Registry</div>
                <div class="verification-text">Issued: <?php echo $issue_date; ?></div>
                <div class="verification-uuid"><?php echo substr($uuid, 0, 18); ?>...</div>
            </div>

            <!-- Signature 2 -->
            <div class="cert-signature-block">
                <div class="cert-signature-svg">CoreCommittee</div>
                <div class="cert-signature-title">Core Committee Lead</div>
                <div class="cert-signature-subtitle">Technical Validator</div>
            </div>
        </div>

        <div class="bottom-right-accent">VALIDATION_HASH: <?php echo md5($uuid); ?></div>
    </div>

</body>
</html>
