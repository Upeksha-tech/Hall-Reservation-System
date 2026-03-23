<?php
session_start();

require_once __DIR__ . '/../Login/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../Login/login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$userId = (int) $_SESSION['user_id'];

if ($id <= 0) {
    header('Location: my_reservations.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT r.*, b.building_name, h.hall_name
        FROM reservations r
        LEFT JOIN building b ON r.building_id = b.building_id
        LEFT JOIN hall h ON r.hall_id = h.hall_id
        WHERE r.id = :id AND r.user_id = :user_id
    ");
    $stmt->execute([':id' => $id, ':user_id' => $userId]);
    $r = $stmt->fetch();

    if (!$r) {
        header('Location: my_reservations.php');
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Error loading reservation.';
    exit;
}

$statusLabels = [
    'pending_hod'     => 'Pending HOD',
    'pending_payment' => 'Pending Payment',
    'pending_dean'    => 'Pending Dean',
    'pending_admin'   => 'Pending Admin',
    'approved'        => 'Approved',
    'rejected'        => 'Rejected',
    'cancelled'       => 'Cancelled',
];
$statusLabel = $statusLabels[$r['status']] ?? $r['status'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reservation Details</title>
    <style>
        :root { --maroon: #800000; --yellow: #ffc107; }
        body { font-family: Arial, sans-serif; background: #fdf7f2; margin: 0; padding: 0; }
        .container { max-width: 640px; margin: 40px auto; background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }
        header { background: linear-gradient(90deg, var(--maroon), #4b0000); color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        header h1 { margin: 0; font-size: 1.4rem; }
        main { padding: 20px 24px 24px; }
        dl { margin: 0; }
        dt { font-weight: 600; color: #333; margin-top: 12px; }
        dd { margin: 4px 0 0 0; color: #555; }
        .status-pill { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.85rem; font-weight: 600; }
        .status-pending_hod, .status-pending_dean, .status-pending_admin { background: #fff3cd; color: #856404; }
        .status-pending_payment { background: #cce5ff; color: #004085; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected, .status-cancelled { background: #f8d7da; color: #721c24; }
        .link { color: var(--maroon); text-decoration: none; font-weight: 600; }
        .link:hover { text-decoration: underline; }
        .btn { display: inline-block; margin-top: 16px; margin-right: 8px; background: var(--maroon); color: #fff; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-weight: 600; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>Reservation Details</h1>
        <a href="my_reservations.php" class="link" style="color:#ffc107;">&larr; My Reservations</a>
    </header>
    <main>
        <p><span class="status-pill status-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></p>

        <dl>
            <dt>Name</dt>
            <dd><?php echo htmlspecialchars($r['name']); ?></dd>

            <dt>Student / Registration No</dt>
            <dd><?php echo htmlspecialchars($r['student_no'] ?: '—'); ?></dd>

            <dt>Mobile</dt>
            <dd><?php echo htmlspecialchars($r['mobile']); ?></dd>

            <dt>Department / Association</dt>
            <dd><?php echo htmlspecialchars($r['department']); ?></dd>

            <dt>HOD / Senior Treasurer email</dt>
            <dd><?php echo htmlspecialchars($r['hod_email']); ?></dd>

            <dt>Purpose</dt>
            <dd><?php echo nl2br(htmlspecialchars($r['purpose'])); ?></dd>

            <dt>Building</dt>
            <dd><?php echo htmlspecialchars($r['building_name'] ?? ('Building #' . $r['building_id'])); ?></dd>

            <dt>Hall</dt>
            <dd><?php echo htmlspecialchars($r['hall_name'] ?? ('Hall #' . $r['hall_id'])); ?></dd>

            <dt>Start</dt>
            <dd><?php echo htmlspecialchars(date('l, F j, Y \a\t g:i A', strtotime($r['start_datetime']))); ?></dd>

            <dt>End</dt>
            <dd><?php echo htmlspecialchars(date('l, F j, Y \a\t g:i A', strtotime($r['end_datetime']))); ?></dd>

            <?php if ($r['rejected_reason']): ?>
                <dt>Rejection reason</dt>
                <dd><?php echo nl2br(htmlspecialchars($r['rejected_reason'])); ?></dd>
            <?php endif; ?>

            <dt>Submitted</dt>
            <dd><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))); ?></dd>

            <?php if ($r['hod_approved_at']): ?>
                <dt>HOD approved at</dt>
                <dd><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['hod_approved_at']))); ?></dd>
            <?php endif; ?>
            <?php if ($r['dean_approved_at']): ?>
                <dt>Dean approved at</dt>
                <dd><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['dean_approved_at']))); ?></dd>
            <?php endif; ?>
            <?php if ($r['admin_approved_at']): ?>
                <dt>Admin approved at</dt>
                <dd><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['admin_approved_at']))); ?></dd>
            <?php endif; ?>
        </dl>

        <?php if ($r['status'] === 'pending_payment'): ?>
            <a href="payment.php?token=<?php echo urlencode($r['approval_token']); ?>" class="btn">Go to payment page</a>
        <?php endif; ?>

        <?php if (!in_array($r['status'], ['approved', 'rejected', 'cancelled'], true)): ?>
            <a href="my_reservations.php?cancel_id=<?php echo (int)$r['id']; ?>" class="link" onclick="return confirm('Cancel this reservation?');">Cancel reservation</a>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
