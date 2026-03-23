<?php
session_start();

require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/google-client.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$message = '';
$error = '';

// User can cancel while not yet approved by admin
if (isset($_GET['cancel_id'])) {
    $cancelId = (int) $_GET['cancel_id'];
    if ($cancelId > 0) {
        try {
            // Ensure reservation belongs to user and is not already approved/rejected
            $stmt = $pdo->prepare("
                SELECT * FROM reservations
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute([':id' => $cancelId, ':user_id' => $userId]);
            $reservation = $stmt->fetch();

            if ($reservation) {
                if ($reservation['status'] === 'approved') {
                    $error = 'Approved reservations cannot be cancelled.';
                } elseif ($reservation['status'] === 'rejected' || $reservation['status'] === 'cancelled') {
                    $error = 'This reservation is already ' . htmlspecialchars($reservation['status']) . '.';
                } else {
                    $upd = $pdo->prepare("
                        UPDATE reservations
                        SET status = 'cancelled',
                            updated_at = NOW()
                        WHERE id = :id AND user_id = :user_id
                    ");
                    $upd->execute([':id' => $cancelId, ':user_id' => $userId]);
                    $message = 'Reservation cancelled successfully.';
                }
            } else {
                $error = 'Reservation not found.';
            }
        } catch (PDOException $e) {
            $error = 'Failed to cancel reservation.';
        }
    }
}

// Load all reservations for user
try {
    $stmt = $pdo->prepare("
        SELECT r.*, b.building_name, h.hall_name
        FROM reservations r
        LEFT JOIN building b ON r.building_id = b.building_id
        LEFT JOIN hall h ON r.hall_id = h.hall_id
        WHERE r.user_id = :user_id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $reservations = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    echo "Error loading reservations.";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Reservations</title>
    <style>
        :root {
            --maroon: #800000;
            --yellow: #ffc107;
        }
        body {
            font-family: Arial, sans-serif;
            background: #fdf7f2;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 960px;
            margin: 40px auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        header {
            background: linear-gradient(90deg, var(--maroon), #4b0000);
            color: #fff;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        header h1 {
            margin: 0;
            font-size: 1.4rem;
        }
        main {
            padding: 20px 24px 24px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }
        th {
            background: rgba(128,0,0,0.05);
            color: #4b0000;
        }
        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending_hod,
        .status-pending_dean,
        .status-pending_admin {
            background: #fff3cd;
            color: #856404;
        }
        .status-pending_payment {
            background: #cce5ff;
            color: #004085;
        }
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        .status-rejected,
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        .btn-cancel {
            color: var(--maroon);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.8rem;
        }
        .btn-cancel:hover {
            text-decoration: underline;
        }
        .message {
            margin-bottom: 12px;
            padding: 8px 10px;
            border-left: 4px solid var(--yellow);
            background: rgba(255,193,7,0.08);
        }
        .error {
            border-left-color: #d32f2f;
            background: #ffe5e5;
            color: #b71c1c;
        }
        .success {
            border-left-color: #2e7d32;
            background: #e6ffed;
            color: #1b5e20;
        }
        .top-actions {
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
        }
        .link-inline {
            color: var(--maroon);
            text-decoration: none;
            font-weight: 600;
        }
        .link-inline:hover {
            text-decoration: underline;
        }
        .calendar-embed-wrapper { margin: 20px 0; }
        .calendar-embed-title { margin: 0 0 4px; font-size: 1rem; color: var(--maroon); }
        .calendar-embed-note { margin: 0 0 10px; font-size: 0.85rem; color: #666; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>My Hall Reservations</h1>
        <a href="../Login/logout.php" class="link-inline">Sign out</a>
    </header>
    <main>
        <div class="top-actions">
            <div>
                <a href="reservation_form.php" class="link-inline">&larr; New Reservation</a>
                &nbsp;|&nbsp;
                <a href="free_slots.php" class="link-inline">View free slots</a>
            </div>
            <div>
                Logged in as: <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!$reservations): ?>
            <p>No reservations found.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Hall</th>
                    <th>Building</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($reservations as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['hall_name'] ?? ('Hall #' . $r['hall_id'])); ?></td>
                        <td><?php echo htmlspecialchars($r['building_name'] ?? ('Building #' . $r['building_id'])); ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['start_datetime']))); ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['end_datetime']))); ?></td>
                        <td>
                            <?php
                            $statusClass = 'status-' . $r['status'];
                            $statusLabelMap = [
                                'pending_hod'     => 'Pending HOD',
                                'pending_payment' => 'Pending Payment',
                                'pending_dean'    => 'Pending Dean',
                                'pending_admin'   => 'Pending Admin',
                                'approved'        => 'Approved',
                                'rejected'        => 'Rejected',
                                'cancelled'       => 'Cancelled',
                            ];
                            $statusLabel = $statusLabelMap[$r['status']] ?? $r['status'];
                            ?>
                            <span class="status-pill <?php echo htmlspecialchars($statusClass); ?>">
                                <?php echo htmlspecialchars($statusLabel); ?>
                            </span>
                        </td>
                        <td>
                            <a href="reservation_detail.php?id=<?php echo (int)$r['id']; ?>" class="link-inline">View</a>
                            <?php if ($r['status'] === 'pending_payment'): ?>
                                &nbsp;|&nbsp;<a href="payment.php?token=<?php echo urlencode($r['approval_token']); ?>" class="link-inline">Pay</a>
                            <?php endif; ?>
                            <?php if (!in_array($r['status'], ['approved', 'rejected', 'cancelled'], true)): ?>
                                &nbsp;|&nbsp;<a class="btn-cancel"
                                   href="?cancel_id=<?php echo (int)$r['id']; ?>"
                                   onclick="return confirm('Are you sure you want to cancel this reservation?');">
                                    Cancel
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

