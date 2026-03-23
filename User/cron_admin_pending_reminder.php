<?php
// Cron script: send daily reminder to admins about pending approvals.

require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/google-client.php';

try {
    // Find pending reservations
    $stmt = $pdo->query("
        SELECT id, name, purpose, status, start_datetime, end_datetime
        FROM reservations
        WHERE status IN ('pending_hod', 'pending_dean', 'pending_admin')
        ORDER BY start_datetime ASC
    ");
    $pending = $stmt->fetchAll();

    if (!$pending) {
        exit; // nothing to remind
    }

    // Get all admins
    $adminStmt = $pdo->query("SELECT email FROM user WHERE user_role = 'admin'");
    $admins = $adminStmt->fetchAll();

    if (!$admins) {
        exit;
    }

    $lines = [];
    foreach ($pending as $p) {
        $lines[] = "ID #{$p['id']} | {$p['name']} | {$p['purpose']} | "
                 . "{$p['status']} | " . $p['start_datetime'] . " - " . $p['end_datetime'];
    }

    $body = "Dear Admin,\n\n"
          . "There are pending hall reservation approvals in the system:\n\n"
          . implode("\n", $lines)
          . "\n\nPlease log in to the admin portal to review them.\n";

    $subject = "Daily Reminder: Pending Hall Reservations";

    foreach ($admins as $admin) {
        if (empty($admin['email'])) {
            continue;
        }
        sendGmail($admin['email'], $subject, $body);
    }
} catch (PDOException $e) {
    // silently fail for cron
}

