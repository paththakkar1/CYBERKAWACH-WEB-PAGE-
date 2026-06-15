<?php
$page_title = 'Security Quizzes';
$page_heading = 'Interactive Training Labs';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_login();

$user_id = $_SESSION['user_id'];
$user_role = get_user_role();
$error = '';
$quiz_selected = null;
$quiz_questions = [];
$attempt_result = null;

// -------------------------------------------------------------
// CREATE QUIZ & QUESTIONS (ADMIN & CORE)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_quiz'])) {
    if (!has_role('Core')) {
        $error = 'Access denied.';
    } else {
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $points = intval($_POST['points'] ?? 50);
        $csrf_token = $_POST['csrf_token'] ?? '';
        
        // Questions array
        $q_texts = $_POST['q_text'] ?? [];
        $opts_a = $_POST['opt_a'] ?? [];
        $opts_b = $_POST['opt_b'] ?? [];
        $opts_c = $_POST['opt_c'] ?? [];
        $opts_d = $_POST['opt_d'] ?? [];
        $corrects = $_POST['correct'] ?? [];
        
        if (!verify_csrf_token($csrf_token)) {
            $error = 'Security check failed. Refresh and try again.';
        } elseif (empty($title) || empty($description) || count($q_texts) < 1) {
            $error = 'Please fill out quiz details and add at least one question.';
        } else {
            try {
                $db->beginTransaction();
                
                // Insert Quiz
                $stmtQz = $db->prepare("INSERT INTO quizzes (title, description, points) VALUES (?, ?, ?)");
                $stmtQz->execute([$title, $description, $points]);
                $new_quiz_id = $db->lastInsertId();
                
                // Insert Questions
                $stmtQn = $db->prepare("INSERT INTO questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?)");
                
                for ($i = 0; $i < count($q_texts); $i++) {
                    if (empty(trim($q_texts[$i]))) continue;
                    
                    $stmtQn->execute([
                        $new_quiz_id,
                        sanitize($q_texts[$i]),
                        sanitize($opts_a[$i] ?? ''),
                        sanitize($opts_b[$i] ?? ''),
                        sanitize($opts_c[$i] ?? ''),
                        sanitize($opts_d[$i] ?? ''),
                        sanitize($corrects[$i] ?? 'A')
                    ]);
                }
                
                $db->commit();
                log_event($user_id, 'Create Quiz', "Created quiz ID $new_quiz_id: $title (Points: $points)");
                set_flash_message('success', 'Security quiz lab deployed successfully.');
                redirect('quizzes.php');
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Failed to create quiz database records: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// SUBMIT QUIZ ANSWERS (MEMBERS)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $quiz_id = intval($_POST['quiz_id']);
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Security check failed.');
        redirect("quizzes.php?id=$quiz_id");
    }
    
    try {
        // Fetch quiz points
        $stmtQuiz = $db->prepare("SELECT * FROM quizzes WHERE id = ?");
        $stmtQuiz->execute([$quiz_id]);
        $quiz = $stmtQuiz->fetch();
        
        if (!$quiz) {
            set_flash_message('error', 'Quiz not found.');
            redirect('quizzes.php');
        }
        
        // Fetch questions
        $stmtQns = $db->prepare("SELECT id, correct_option FROM questions WHERE quiz_id = ?");
        $stmtQns->execute([$quiz_id]);
        $questions = $stmtQns->fetchAll();
        
        $total_questions = count($questions);
        $correct_answers = 0;
        
        foreach ($questions as $q) {
            $user_choice = $_POST['answer_' . $q['id']] ?? '';
            if (strtoupper($user_choice) === strtoupper($q['correct_option'])) {
                $correct_answers++;
            }
        }
        
        $passed = ($correct_answers / $total_questions) >= 0.6 ? 1 : 0; // 60% passing score
        
        $db->beginTransaction();
        
        // Check if already passed previously to avoid duplicate points
        $stmtCheckPrev = $db->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND quiz_id = ? AND passed = 1");
        $stmtCheckPrev->execute([$user_id, $quiz_id]);
        $previously_passed = $stmtCheckPrev->fetchColumn() > 0;
        
        // Record attempt
        $stmtAttempt = $db->prepare("INSERT INTO quiz_attempts (user_id, quiz_id, score, passed) VALUES (?, ?, ?, ?)");
        $stmtAttempt->execute([$user_id, $quiz_id, $correct_answers, $passed]);
        $attempt_id = $db->lastInsertId();
        
        $points_awarded = 0;
        if ($passed && !$previously_passed) {
            // Award points
            $stmtReward = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
            $stmtReward->execute([$quiz['points'], $user_id]);
            $points_awarded = $quiz['points'];
            
            // Refresh session points
            $_SESSION['user_points'] += $points_awarded;
        }
        
        $db->commit();
        
        log_event($user_id, 'Submit Quiz', "Attempted Quiz ID $quiz_id. Score: $correct_answers/$total_questions. Passed: $passed. XP Awarded: $points_awarded");
        
        if ($passed) {
            if ($points_awarded > 0) {
                set_flash_message('success', "Congratulations! You passed the quiz and earned +{$points_awarded} XP!");
            } else {
                set_flash_message('success', "You passed the quiz! (Practice run - no extra points awarded)");
            }
        } else {
            set_flash_message('error', "Quiz failed. Score: $correct_answers / $total_questions. You need at least 60% to pass.");
        }
        
        redirect("quizzes.php?id=$quiz_id&attempt=$attempt_id");
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        set_flash_message('error', 'Database write error while scoring quiz.');
        redirect('quizzes.php');
    }
}

// -------------------------------------------------------------
// DETAILS VIEW & LIST VIEW FETCH
// -------------------------------------------------------------
// 1. Specific Quiz Detail
if (isset($_GET['id'])) {
    $quiz_id = intval($_GET['id']);
    try {
        $stmtQuiz = $db->prepare("SELECT * FROM quizzes WHERE id = ?");
        $stmtQuiz->execute([$quiz_id]);
        $quiz_selected = $stmtQuiz->fetch();
        
        if ($quiz_selected) {
            // Fetch questions
            $stmtQ = $db->prepare("SELECT * FROM questions WHERE quiz_id = ?");
            $stmtQ->execute([$quiz_id]);
            $quiz_questions = $stmtQ->fetchAll();
            
            // Fetch latest attempt if exists
            if (isset($_GET['attempt'])) {
                $stmtAtt = $db->prepare("SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ?");
                $stmtAtt->execute([intval($_GET['attempt']), $user_id]);
                $attempt_result = $stmtAtt->fetch();
            } else {
                // Fetch best attempt
                $stmtBest = $db->prepare("SELECT * FROM quiz_attempts WHERE user_id = ? AND quiz_id = ? ORDER BY score DESC, attempted_at DESC LIMIT 1");
                $stmtBest->execute([$user_id, $quiz_id]);
                $attempt_result = $stmtBest->fetch();
            }
        }
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to retrieve quiz questions.');
    }
}

// 2. Fetch all quizzes
try {
    $stmtAll = $db->query("
        SELECT q.*, 
        (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count,
        (SELECT MAX(score) FROM quiz_attempts WHERE quiz_id = q.id AND user_id = " . intval($user_id) . ") as my_best_score,
        (SELECT MAX(passed) FROM quiz_attempts WHERE quiz_id = q.id AND user_id = " . intval($user_id) . ") as my_pass_status
        FROM quizzes q 
        ORDER BY q.created_at DESC
    ");
    $quizzes = $stmtAll->fetchAll();
} catch (PDOException $e) {
    $quizzes = [];
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($quiz_selected): ?>
    <!-- ==========================================================
         QUIZ DEPLOYMENT SCREEN
         ========================================================== -->
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid var(--border-glow); padding-bottom: 15px; margin-bottom: 25px;">
            <div>
                <a href="quizzes.php" class="text-cyan" style="font-size:0.85rem; font-family:var(--font-heading);"><i class="fa-solid fa-arrow-left"></i> All Training Labs</a>
                <h3 style="margin: 5px 0 0 0;"><?php echo sanitize($quiz_selected['title']); ?></h3>
            </div>
            <span class="badge badge-member">+<?php echo $quiz_selected['points']; ?> XP Reward</span>
        </div>

        <p style="color:var(--text-muted); font-size:1rem; margin-bottom: 30px;"><?php echo sanitize($quiz_selected['description']); ?></p>

        <?php if ($attempt_result): ?>
            <!-- Results Callout Banner -->
            <div class="alert alert-<?php echo $attempt_result['passed'] ? 'success' : 'error'; ?>" style="margin-bottom:30px;">
                <i class="fa-solid <?php echo $attempt_result['passed'] ? 'fa-award' : 'fa-triangle-exclamation'; ?>" style="font-size: 1.5rem;"></i>
                <div>
                    <strong>Attempt Score: <?php echo $attempt_result['score'] . ' / ' . count($quiz_questions); ?> Correct</strong>
                    <span style="display:block; font-size:0.8rem; opacity:0.8;">
                        <?php if ($attempt_result['passed']): ?>
                            Lab Cleared successfully! Points credited to your profile.
                        <?php else: ?>
                            Lab Failed. You need at least 60% correct answers to pass. Re-evaluate your answers and try again.
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Question Submission Form -->
        <form action="quizzes.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="quiz_id" value="<?php echo $quiz_selected['id']; ?>">
            
            <?php $i = 1; foreach ($quiz_questions as $qn): ?>
                <div class="quiz-question-box">
                    <h4 style="font-size:1rem; margin-bottom:15px; font-family:var(--font-body); font-weight:600; line-height: 1.5;">
                        <span class="text-cyan" style="font-family:var(--font-heading); font-size: 0.9rem; margin-right:5px;">Q<?php echo $i++; ?>.</span> 
                        <?php echo sanitize($qn['question_text']); ?>
                    </h4>
                    
                    <div class="quiz-options">
                        <input type="radio" name="answer_<?php echo $qn['id']; ?>" id="q_<?php echo $qn['id']; ?>_A" value="A" class="quiz-option-radio" required>
                        <label for="q_<?php echo $qn['id']; ?>_A" class="quiz-option-btn" data-question-id="<?php echo $qn['id']; ?>">
                            <span class="quiz-option-label">A</span>
                            <span><?php echo sanitize($qn['option_a']); ?></span>
                        </label>

                        <input type="radio" name="answer_<?php echo $qn['id']; ?>" id="q_<?php echo $qn['id']; ?>_B" value="B" class="quiz-option-radio">
                        <label for="q_<?php echo $qn['id']; ?>_B" class="quiz-option-btn" data-question-id="<?php echo $qn['id']; ?>">
                            <span class="quiz-option-label">B</span>
                            <span><?php echo sanitize($qn['option_b']); ?></span>
                        </label>

                        <input type="radio" name="answer_<?php echo $qn['id']; ?>" id="q_<?php echo $qn['id']; ?>_C" value="C" class="quiz-option-radio">
                        <label for="q_<?php echo $qn['id']; ?>_C" class="quiz-option-btn" data-question-id="<?php echo $qn['id']; ?>">
                            <span class="quiz-option-label">C</span>
                            <span><?php echo sanitize($qn['option_c']); ?></span>
                        </label>

                        <input type="radio" name="answer_<?php echo $qn['id']; ?>" id="q_<?php echo $qn['id']; ?>_D" value="D" class="quiz-option-radio">
                        <label for="q_<?php echo $qn['id']; ?>_D" class="quiz-option-btn" data-question-id="<?php echo $qn['id']; ?>">
                            <span class="quiz-option-label">D</span>
                            <span><?php echo sanitize($qn['option_d']); ?></span>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:30px;">
                <a href="quizzes.php" class="btn btn-secondary">Exit Lab</a>
                <button type="submit" name="submit_quiz" class="btn btn-primary">
                    <i class="fa-solid fa-upload"></i> Submit Lab Assessment
                </button>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- ==========================================================
         QUIZZES ARCHIVE & CREATOR SCREEN
         ========================================================== -->
    <div class="dashboard-layout" style="grid-template-columns: <?php echo has_role('Core') ? '1.2fr 0.8fr' : '1fr'; ?>;">
        
        <!-- Left: Quiz List -->
        <div>
            <div class="card">
                <h3 class="card-title text-cyan"><i class="fa-solid fa-graduation-cap"></i> Interactive Security Training Labs</h3>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom: 25px;">Complete training assessments to sharpen your defensive skills and increase your Leaderboard XP score.</p>
                
                <?php if (!empty($quizzes)): ?>
                    <div style="display:grid; grid-template-columns:1fr; gap:20px;">
                        <?php foreach ($quizzes as $quiz): ?>
                            <?php 
                            $passed = $quiz['my_pass_status'] == 1; 
                            $attempted = $quiz['my_best_score'] !== null;
                            ?>
                            <div class="card <?php echo $passed ? 'card-success' : ($attempted ? 'card-pink' : ''); ?>" style="padding:20px; border-width: 1px; border-color: rgba(255,255,255,0.04);">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:12px;">
                                    <div>
                                        <h4 style="margin:0; font-size:1.1rem;"><?php echo sanitize($quiz['title']); ?></h4>
                                        <span style="font-size:0.75rem; color:var(--text-muted);"><i class="fa-solid fa-circle-question"></i> <?php echo $quiz['question_count']; ?> Questions</span>
                                    </div>
                                    <span class="badge badge-member">+<?php echo $quiz['points']; ?> XP</span>
                                </div>
                                
                                <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:15px;"><?php echo sanitize($quiz['description']); ?></p>
                                
                                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border-glow); padding-top:12px;">
                                    <div>
                                        <?php if ($passed): ?>
                                            <span class="text-success" style="font-size:0.8rem; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Passed (Best Score: <?php echo $quiz['my_best_score'] . '/' . $quiz['question_count']; ?>)</span>
                                        <?php elseif ($attempted): ?>
                                            <span class="text-pink" style="font-size:0.8rem; font-weight:700;"><i class="fa-solid fa-circle-xmark"></i> Failed (Best: <?php echo $quiz['my_best_score'] . '/' . $quiz['question_count']; ?>)</span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.8rem;"><i class="fa-solid fa-hourglass-start"></i> No attempts yet</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <a href="quizzes.php?id=<?php echo $quiz['id']; ?>" class="btn <?php echo $passed ? 'btn-success' : 'btn-primary'; ?>" style="padding: 6px 12px; font-size:0.75rem;">
                                        <?php echo $passed ? 'Review Lab' : 'Deploy Lab'; ?> <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fa-solid fa-box-open" style="font-size: 3rem; margin-bottom: 15px;"></i>
                        <p>No training labs registered.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Admin Creator (Admin & Core only) -->
        <?php if (has_role('Core')): ?>
            <div>
                <div class="card card-pink">
                    <h3 class="card-title text-pink"><i class="fa-solid fa-plus-minus"></i> Deploy Security Quiz</h3>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <i class="fa-solid fa-circle-xmark"></i>
                            <span><?php echo sanitize($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <form action="quizzes.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <div class="form-group">
                            <label for="title" class="form-label">Quiz Title</label>
                            <input type="text" id="title" name="title" class="form-control" placeholder="SQL Injection Attacks" required value="<?php echo isset($_POST['title']) ? sanitize($_POST['title']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="description" class="form-label">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3" placeholder="Brief outline of security quiz topics..." required><?php echo isset($_POST['description']) ? sanitize($_POST['description']) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="points" class="form-label">Experience Points Reward</label>
                            <input type="number" id="points" name="points" class="form-control" min="10" placeholder="50 XP" required value="<?php echo isset($_POST['points']) ? intval($_POST['points']) : 50; ?>">
                        </div>

                        <!-- Question Form Slots (Provide 2 slots for simplicity in sidebar) -->
                        <div style="border-top:1px dashed var(--border-glow); padding-top:15px; margin-top:20px;">
                            <h4 class="text-cyan" style="font-size:0.8rem; margin-bottom:15px;"><i class="fa-solid fa-list-check"></i> Quiz Question Details</h4>
                            
                            <?php for ($num = 1; $num <= 2; $num++): ?>
                                <div style="background:rgba(0,0,0,0.15); border:1px solid var(--border-glow); padding:12px; border-radius:4px; margin-bottom:15px;">
                                    <div class="form-group">
                                        <label class="form-label" style="color:var(--color-primary);">Question #<?php echo $num; ?></label>
                                        <input type="text" name="q_text[]" class="form-control" placeholder="What does SQL injection do?" required>
                                    </div>
                                    <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                                        <div>
                                            <label class="form-label">Option A</label>
                                            <input type="text" name="opt_a[]" class="form-control" placeholder="Option A" required>
                                        </div>
                                        <div>
                                            <label class="form-label">Option B</label>
                                            <input type="text" name="opt_b[]" class="form-control" placeholder="Option B" required>
                                        </div>
                                        <div>
                                            <label class="form-label">Option C</label>
                                            <input type="text" name="opt_c[]" class="form-control" placeholder="Option C" required>
                                        </div>
                                        <div>
                                            <label class="form-label">Option D</label>
                                            <input type="text" name="opt_d[]" class="form-control" placeholder="Option D" required>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Correct Option</label>
                                        <select name="correct[]" class="form-control">
                                            <option value="A">A</option>
                                            <option value="B">B</option>
                                            <option value="C">C</option>
                                            <option value="D">D</option>
                                        </select>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <button type="submit" name="add_quiz" class="btn btn-danger btn-block">
                            <i class="fa-solid fa-circle-check"></i> Deploy Security Quiz
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
