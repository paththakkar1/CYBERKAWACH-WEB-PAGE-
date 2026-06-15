<?php
$page_title = 'Leaderboard';
$page_heading = 'Club Global Rankings';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = $_SESSION['user_id'];

// Fetch all users sorted by points
try {
    $stmt = $db->query("SELECT id, name, role, points, status FROM users WHERE status = 'Active' ORDER BY points DESC, name ASC");
    $rankings = $stmt->fetchAll();
} catch (PDOException $e) {
    $rankings = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h3 class="card-title text-cyan"><i class="fa-solid fa-trophy"></i> CyberKavach Leaderboard</h3>
    <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom: 25px;">Track top security researchers. Attend workshops, complete quizzes, and secure your systems to climb the ranks.</p>

    <?php if (!empty($rankings)): ?>
        <div class="leaderboard-list">
            <div class="leaderboard-row" style="background: rgba(0,0,0,0.2); border-color: transparent; font-family: var(--font-heading); font-size: 0.75rem; color: var(--text-muted); padding: 10px 20px;">
                <div>RANK</div>
                <div>MEMBER PRIVILEGE & NAME</div>
                <div style="text-align: right;">EXPERIENCE POINTS</div>
            </div>

            <?php $rank = 1; foreach ($rankings as $row): ?>
                <?php $is_self = intval($row['id']) === intval($user_id); ?>
                <div class="leaderboard-row" style="<?php echo $is_self ? 'border-color: var(--color-primary); background: rgba(0, 229, 255, 0.05); box-shadow: var(--shadow-cyber);' : ''; ?>">
                    <!-- Rank badge -->
                    <div class="leaderboard-rank leaderboard-rank-<?php echo $rank; ?>">
                        <?php if ($rank === 1): ?>
                            <i class="fa-solid fa-crown"></i>
                        <?php elseif ($rank === 2): ?>
                            <i class="fa-solid fa-award"></i>
                        <?php elseif ($rank === 3): ?>
                            <i class="fa-solid fa-medal"></i>
                        <?php else: ?>
                            #<?php echo $rank; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Member info -->
                    <div style="display:flex; align-items:center; gap:12px; overflow:hidden;">
                        <span class="badge badge-<?php echo strtolower($row['role']); ?>" style="font-size:0.6rem; padding: 2px 5px;">
                            <?php echo sanitize($row['role']); ?>
                        </span>
                        <span class="leaderboard-name" style="<?php echo $is_self ? 'color: var(--color-primary); font-weight:700;' : ''; ?>" title="<?php echo sanitize($row['name']); ?>">
                            <?php echo sanitize($row['name']); ?> <?php echo $is_self ? '<span style="font-size:0.75rem; font-weight:normal; color:var(--text-muted);">(You)</span>' : ''; ?>
                        </span>
                    </div>

                    <!-- Points -->
                    <div class="leaderboard-points">
                        <?php echo $row['points']; ?> <span style="font-size: 0.75rem; font-weight:normal; color:var(--text-muted);">XP</span>
                    </div>
                </div>
            <?php $rank++; endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color:var(--text-muted); text-align:center; padding: 30px;">No rankings available. Complete assignments to get ranked.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
