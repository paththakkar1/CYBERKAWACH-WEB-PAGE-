<?php
require_once __DIR__ . '/config.php';

try {
    // Connect to SQLite Database (will create the file if it doesn't exist)
    $db = new PDO("sqlite:" . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Enable Foreign Keys
    $db->exec("PRAGMA foreign_keys = ON;");
    
    // Create Tables if they don't exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'Member',
            points INTEGER DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'Pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL,
            date TEXT NOT NULL,
            time TEXT NOT NULL,
            location TEXT NOT NULL,
            max_participants INTEGER NOT NULL,
            points_reward INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        );
        
        CREATE TABLE IF NOT EXISTS registrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            event_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'Registered', -- Registered, Attended, Absent
            registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            UNIQUE(user_id, event_id)
        );
        
        CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            priority TEXT NOT NULL DEFAULT 'Low', -- Low, Medium, High
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        );
        
        CREATE TABLE IF NOT EXISTS quizzes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL,
            points INTEGER DEFAULT 50,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL,
            question_text TEXT NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            correct_option TEXT NOT NULL, -- A, B, C, D
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS quiz_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            quiz_id INTEGER NOT NULL,
            score INTEGER NOT NULL,
            passed INTEGER NOT NULL, -- 0 or 1
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            message TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Open', -- Open, In Progress, Resolved
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS ticket_replies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            details TEXT NOT NULL,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
    ");

    // Seed Initial Data if table 'users' is empty
    $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        // Seed Users
        // Admin
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status, points) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Super Admin Founder',
            'admin@cyberkavach.org',
            password_hash('Admin@12345', PASSWORD_DEFAULT),
            'Admin',
            'Active',
            100
        ]);
        $adminId = $db->lastInsertId();
        
        // Core Member
        $stmt->execute([
            'Core Member Priya',
            'core@cyberkavach.org',
            password_hash('Core@12345', PASSWORD_DEFAULT),
            'Core',
            'Active',
            250
        ]);
        
        // Regular Member
        $stmt->execute([
            'Club Member Rohan',
            'member@cyberkavach.org',
            password_hash('Member@12345', PASSWORD_DEFAULT),
            'Member',
            'Active',
            50
        ]);
        
        // Seed Announcements
        $stmtAnn = $db->prepare("INSERT INTO announcements (title, content, priority, created_by) VALUES (?, ?, ?, ?)");
        $stmtAnn->execute([
            'Welcome to the CyberKavach Club Platform!',
            'We are thrilled to launch our centralized digital OS. All club activities, events, and learning challenges will now be managed here. Complete your profile and attempt the introductory quizzes!',
            'High',
            $adminId
        ]);
        $stmtAnn->execute([
            'Upcoming Ethical Hacking Workshop',
            'A hands-on session on Web Application Security is scheduled for next week. Registration is free and exclusive to club members.',
            'Medium',
            $adminId
        ]);

        // Seed Events
        $stmtEv = $db->prepare("INSERT INTO events (title, description, date, time, location, max_participants, points_reward, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtEv->execute([
            'Intro to Cyber Security & Threat Landscape',
            'An introductory seminar focusing on social engineering attacks, basic network security, and defense tactics. Perfect for beginners.',
            date('Y-m-d', strtotime('+3 days')),
            '15:00',
            'Main Seminar Hall & Zoom Link',
            100,
            30,
            $adminId
        ]);
        $stmtEv->execute([
            'Hands-on Capture The Flag (CTF) Tournament',
            'Bring your laptops! A mini Jeopardy-style CTF covering cryptography, reverse engineering, and web exploitation. Winners get exclusive certificates!',
            date('Y-m-d', strtotime('+7 days')),
            '10:00',
            'IT Lab 3',
            50,
            100,
            $adminId
        ]);

        // Seed Quizzes & Questions
        // Quiz 1
        $db->exec("INSERT INTO quizzes (id, title, description, points) VALUES (1, 'Phishing Defense Essentials', 'Learn how to detect deceptive emails, URLs, and social engineering tricks.', 50)");
        
        $stmtQ = $db->prepare("INSERT INTO questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtQ->execute([
            1,
            'If you receive an urgent email from your bank asking you to verify your credentials via a link, what should you do?',
            'Click the link and verify immediately to secure the account.',
            'Ignore the email and delete it, or contact the bank directly through their official phone number.',
            'Reply to the email asking if it is authentic.',
            'Forward the email to all your friends to warn them.',
            'B'
        ]);
        $stmtQ->execute([
            1,
            'Which of the following URL structures is most likely a phishing link claiming to be PayPal?',
            'https://www.paypal.com/signin',
            'https://paypal-security-update.com/login',
            'https://paypal.com/security',
            'https://www.paypal.com/in/webapps/mpp/home',
            'B'
        ]);
        $stmtQ->execute([
            1,
            'What is the primary indicator that an email might be a phishing attempt?',
            'Generic greetings, generic email address domain, sense of urgency, and suspicious links.',
            'A high-resolution logo of the company.',
            'The presence of plain text instead of HTML.',
            'It is received during office hours.',
            'A'
        ]);

        // Quiz 2
        $db->exec("INSERT INTO quizzes (id, title, description, points) VALUES (2, 'Password Security & 2FA', 'Master password strength, password managers, and multi-factor authentication systems.', 50)");
        $stmtQ->execute([
            2,
            'Which of the following is considered the strongest password?',
            'P@$$w0rd123',
            'correct-horse-battery-staple',
            'JohnDoe1998',
            'S@12#d!9',
            'B'
        ]);
        $stmtQ->execute([
            2,
            'Why is Multi-Factor Authentication (MFA/2FA) highly recommended?',
            'It makes logging in much faster.',
            'It guarantees that you can never be hacked.',
            'It adds an extra layer of defense, requiring more than just a compromised password to access an account.',
            'It hides your IP address from attackers.',
            'C'
        ]);
        $stmtQ->execute([
            2,
            'Is it safe to reuse the same strong password across multiple social media accounts?',
            'Yes, as long as it contains symbols, numbers, and uppercase letters.',
            'Yes, it helps you remember them easily.',
            'No, because if one site suffers a data breach, all your other accounts are compromised (Credential Stuffing).',
            'Only if you change it every month.',
            'C'
        ]);

        // Quiz 3
        $db->exec("INSERT INTO quizzes (id, title, description, points) VALUES (3, 'Web Application Security', 'Test your knowledge on OWASP Top 10 vulnerabilities like SQL Injection, XSS, and broken auth.', 80)");
        $stmtQ->execute([
            3,
            'What is Cross-Site Scripting (XSS)?',
            'An attack that allows executing malicious scripts in another user\'s browser.',
            'An attack that targets the database by manipulating queries.',
            'An attack that floods a server with requests, causing it to crash.',
            'An attack that intercepts Wi-Fi traffic.',
            'A'
        ]);
        $stmtQ->execute([
            3,
            'How can developers best prevent SQL Injection (SQLi) vulnerabilities?',
            'By using client-side JavaScript validations.',
            'By encrypting the database tables.',
            'By using Prepared Statements (Parameterized Queries) and escaping input.',
            'By disabling database error messages.',
            'C'
        ]);
        $stmtQ->execute([
            3,
            'What does the "Secure" attribute on a cookie indicate?',
            'The cookie is encrypted.',
            'The cookie is only sent over HTTPS connections.',
            'The cookie cannot be accessed via JavaScript.',
            'The cookie expires immediately after closing the tab.',
            'B'
        ]);
        
        // Log setup completion
        $db->exec("INSERT INTO audit_logs (action, details) VALUES ('System Init', 'Database initialized and seeded successfully')");
    }

} catch (PDOException $e) {
    die("Database Connection / Setup Failed: " . $e->getMessage());
}

/**
 * Log administrative and user events
 * @param int|null $user_id
 * @param string $action
 * @param string $details
 */
function log_event($user_id, $action, $details) {
    global $db;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $details, $ip]);
    } catch (PDOException $e) {
        // Silently fail logging to prevent site crashes
    }
}
?>
