<?php
$page_title = 'Welcome';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Fetch public upcoming events
$upcoming_events = [];
try {
    $stmt = $db->query("SELECT * FROM events WHERE date >= date('now') ORDER BY date ASC, time ASC LIMIT 3");
    $upcoming_events = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fail silently
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CyberKavach Club | Central Operating System</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="container">
    <!-- Navigation -->
    <nav class="public-nav">
        <div class="public-logo text-cyan">
            <i class="fa-solid fa-shield-halved"></i>
            <span>CYBERKAVACH</span>
        </div>
        <ul class="public-menu">
            <li><a href="#about" class="public-link">About</a></li>
            <li><a href="#features" class="public-link">Features</a></li>
            <li><a href="#events" class="public-link">Events</a></li>
            <?php if (is_logged_in()): ?>
                <li><a href="dashboard.php" class="btn btn-primary" style="padding: 8px 16px;">Portal Dashboard</a></li>
            <?php else: ?>
                <li><a href="login.php" class="public-link">Login</a></li>
                <li><a href="register.php" class="btn btn-primary" style="padding: 8px 16px;">Join Club</a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- Hero Section -->
    <section class="landing-hero">
        <div class="hero-content">
            <div class="hero-subtitle">Cyber Security Awareness & Defence</div>
            <h1 class="hero-title">Defending the Digital Frontier of <span>CyberKavach</span></h1>
            <p class="hero-description">
                Welcome to the complete operating system of CyberKavach Club. Explore interactive ethical hacking labs, verify upcoming events, report vulnerabilities, and compete with members on the live leaderboard.
            </p>
            <div class="hero-buttons">
                <?php if (is_logged_in()): ?>
                    <a href="dashboard.php" class="btn btn-primary"><i class="fa-solid fa-gauge"></i> Enter OS Dashboard</a>
                <?php else: ?>
                    <a href="register.php" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Join the Club</a>
                    <a href="login.php" class="btn btn-secondary"><i class="fa-solid fa-right-to-bracket"></i> Login Access</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Stats Display -->
    <div class="stats-grid" style="margin-top: -50px; margin-bottom: 80px;">
        <div class="stat-card">
            <div class="stat-info">
                <h3>Shielded Members</h3>
                <div class="stat-value text-cyan">350+</div>
            </div>
            <i class="fa-solid fa-user-shield stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Live Events Run</h3>
                <div class="stat-value text-purple">18+</div>
            </div>
            <i class="fa-solid fa-network-wired stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Interactive Quizzes</h3>
                <div class="stat-value text-success">10+</div>
            </div>
            <i class="fa-solid fa-graduation-cap stat-icon"></i>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h3>Threats Resolved</h3>
                <div class="stat-value text-pink">1,240+</div>
            </div>
            <i class="fa-solid fa-bug stat-icon"></i>
        </div>
    </div>

    <!-- About Section -->
    <section id="about" style="margin-bottom: 100px; padding: 40px 0;">
        <div class="dashboard-layout" style="grid-template-columns: 1.2fr 0.8fr;">
            <div>
                <h2 style="font-family: var(--font-heading); font-size: 2rem; margin-bottom: 20px;">Protecting the Ecosystem</h2>
                <p style="color: var(--text-muted); font-size: 1.05rem; margin-bottom: 15px;">
                    CyberKavach is a cybersecurity initiative dedicated to promoting safe digital practices. We help students and tech enthusiasts learn core defensive skills, investigate systems safety, audit software code, and solve cybersecurity puzzles.
                </p>
                <p style="color: var(--text-muted); font-size: 1.05rem;">
                    This Central Operating System coordinates all security workshops, handles attendee RSVP tracking, stores training guides, facilitates skill-evaluation quizzes, and serves as our direct administrative support line.
                </p>
            </div>
            <div class="card card-pink" style="display: flex; flex-direction: column; justify-content: center; text-align: center; gap: 15px; background: rgba(255, 0, 85, 0.02);">
                <i class="fa-solid fa-terminal" style="font-size: 3rem; color: var(--color-accent);"></i>
                <h3 style="font-family: var(--font-heading);">Terminal Alert</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted);">Guest access restricted. Join the club to unlock advanced modules.</p>
                <a href="register.php" class="btn btn-danger" style="margin: 0 auto;">Register Now</a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" style="margin-bottom: 100px;">
        <div class="section-title">
            <h2>Digital OS Features</h2>
            <p>Our centralized club tools keep members informed, trained, and competitive.</p>
        </div>
        
        <div class="features-grid">
            <div class="card">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-user-lock"></i> Role-Based Dashboards</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem;">
                    Personalized viewports. Core members manage materials, Admins control permissions, and Members track accomplishments.
                </p>
            </div>
            <div class="card">
                <h3 class="card-title text-purple"><i class="fa-solid fa-calendar-check"></i> Event Scheduler</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem;">
                    RSVP for offline/online security meetups. Admin checks attendance and issues points to reward engagement.
                </p>
            </div>
            <div class="card">
                <h3 class="card-title text-success"><i class="fa-solid fa-trophy"></i> Interactive Quizzes</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem;">
                    Complete challenging security assessments to increase points and progress to the top of the club leaderboard.
                </p>
            </div>
        </div>
    </section>

    <!-- Upcoming Events -->
    <section id="events" style="margin-bottom: 100px;">
        <div class="section-title">
            <h2>Upcoming Security Events</h2>
            <p>Participate in workshops, hackathons, and seminars. Register today to lock in your spot.</p>
        </div>

        <?php if (!empty($upcoming_events)): ?>
            <div class="features-grid">
                <?php foreach ($upcoming_events as $event): ?>
                    <div class="card card-success">
                        <div style="font-size: 0.75rem; color: var(--color-success); font-family: var(--font-heading); margin-bottom: 10px; display: flex; justify-content: space-between;">
                            <span><i class="fa-solid fa-calendar"></i> <?php echo sanitize($event['date']); ?></span>
                            <span><i class="fa-solid fa-clock"></i> <?php echo sanitize($event['time']); ?></span>
                        </div>
                        <h3 class="card-title" style="margin-bottom: 10px; font-size: 1.1rem;"><?php echo sanitize($event['title']); ?></h3>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 20px; min-height: 50px;">
                            <?php echo sanitize(substr($event['description'], 0, 120)) . (strlen($event['description']) > 120 ? '...' : ''); ?>
                        </p>
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-glow); padding-top: 15px;">
                            <span style="font-size: 0.8rem; color: var(--text-muted);"><i class="fa-solid fa-location-dot"></i> <?php echo sanitize($event['location']); ?></span>
                            <span class="badge badge-member">+<?php echo $event['points_reward']; ?> XP</span>
                        </div>
                        <a href="login.php" class="btn btn-primary btn-block" style="margin-top: 20px; font-size: 0.75rem;">Login to RSVP</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card" style="text-align: center; padding: 40px;">
                <i class="fa-solid fa-calendar-xmark text-muted" style="font-size: 2.5rem; margin-bottom: 15px;"></i>
                <h3>No Upcoming Events</h3>
                <p style="color: var(--text-muted); margin-top: 10px;">Check back later or register to check our complete schedule archives.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Footer -->
    <footer style="text-align: center; padding: 40px 0; border-top: 1px solid rgba(0, 229, 255, 0.1); color: var(--text-muted); font-size: 0.85rem;">
        <p>&copy; <?php echo date('Y'); ?> CyberKavach Club. All rights reserved.</p>
        <p style="font-size: 0.75rem; margin-top: 5px; color: rgba(0, 229, 255, 0.4);">Digital OS v1.0.0 (SQLite Edition)</p>
    </footer>
</div>

</body>
</html>
