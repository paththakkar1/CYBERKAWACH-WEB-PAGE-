<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Only allow coordinators and admins
if (!has_role('Core')) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

header('Content-Type: application/json');

$event_id = intval($_GET['event_id'] ?? 0);

if ($event_id <= 0) {
    echo json_encode([]);
    exit();
}

try {
    $stmtRList = $db->prepare("
        SELECT r.id, r.status, u.name as attendee_name, u.email as attendee_email 
        FROM registrations r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.event_id = ?
    ");
    $stmtRList->execute([$event_id]);
    $r_list = $stmtRList->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($r_list);
} catch (PDOException $e) {
    echo json_encode([]);
}
exit();
