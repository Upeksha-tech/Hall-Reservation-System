<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: approved_history.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT r.*, b.building_name, h.hall_name
        FROM reservations r
        LEFT JOIN building b ON r.building_id = b.building_id
        LEFT JOIN hall h ON r.hall_id = h.hall_id
        WHERE r.id = :id AND r.status = 'approved'
    ");
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $r = null;
}

if (!$r) {
    header('Location: approved_history.php');
    exit;
}

$userEmail = $r['user_email'] ?? '';
if ($userEmail === '' || $userEmail === null) {
    try {
        $uStmt = $pdo->prepare("SELECT email FROM user WHERE user_id = ? LIMIT 1");
        $uStmt->execute([(int)$r['user_id']]);
        $u = $uStmt->fetch(PDO::FETCH_ASSOC);
        $userEmail = $u['email'] ?? '';
    } catch (PDOException $e) {
        $userEmail = '';
    }
}

$paymentRow = null;
try {
    $payStmt = $pdo->prepare("SELECT refundable_amount, non_refundable_amount FROM payment WHERE hall_id = ?");
    $payStmt->execute([$r['hall_id']]);
    $paymentRow = $payStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$slipPath = $r['payment_slip_path'] ?? '';
$slipUrl = $slipPath ? ($baseUrl . '/User/' . $slipPath) : null;
$slipExt = $slipPath ? strtolower(pathinfo($slipPath, PATHINFO_EXTENSION)) : '';
$isImage = in_array($slipExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approved Reservation #<?= (int)$r['id'] ?></title>
    <link rel="stylesheet" href="admin.css">
    <style>
        .nav-dropdown {
            margin-top: 6px;
        }
    </style>
    <style>
        .detail-row { margin: 12px 0; padding: 10px; background: #f9f9f9; border-radius: 4px; }
        .detail-row dt { font-weight: 600; color: #333; margin: 0; }
        .detail-row dd { margin: 4px 0 0 0; color: #555; }
        .payment-section { background: #e8f5e9; border: 1px solid #4caf50; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .slip-preview { max-width: 100%; max-height: 400px; border: 1px solid #ccc; border-radius: 4px; margin-top: 8px; }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <h1>Admin Portal</h1>
        <nav class="admin-nav">
            <a href="index.php">Pending Requests</a>
            <a href="approved_history.php" class="nav-link active">Approved History</a>
            <a href="generate_reports.php">Generate Reports</a>
            <a href="admin_free_slots.php">View Free Slots</a>
            <div class="nav-dropdown">
                <span class="nav-dropdown-trigger">Fixed Reservations ▾</span>
                <div class="nav-dropdown-menu">
                    <a href="upload_timetable.php">Upload Timetable</a>
                    <a href="fixed_reservations.php">Fixed Reservations</a>
                </div>
            </div>
            <div class="nav-dropdown">
                <span class="nav-dropdown-trigger">More ▾</span>
                <div class="nav-dropdown-menu">
                    <a href="../Login/create_user.php">Create User Accounts</a>
                    <a href="change_dean_email.php">Change Dean's Email</a>
                    <a href="manage_halls.php">Manage Halls & Prices</a>
                </div>
            </div>
            <a href="../Login/logout.php">Sign out</a>
        </nav>
    </header>

    <div class="admin-card">
        <h2>Approved Reservation #<?= (int)$r['id'] ?></h2>
        <p><a href="approved_history.php" style="color:var(--maroon);font-weight:600;">&larr; Back to Approved History</a></p>

        <h3>Applicant Details</h3>
        <dl>
            <div class="detail-row">
                <dt>Name</dt>
                <dd><?= htmlspecialchars($r['name']) ?></dd>
            </div>
            <div class="detail-row">
                <dt>Student / Registration No</dt>
                <dd><?= htmlspecialchars($r['student_no'] ?: '—') ?></dd>
            </div>
            <div class="detail-row">
                <dt>Mobile</dt>
                <dd><?= htmlspecialchars($r['mobile']) ?></dd>
            </div>
            <div class="detail-row">
                <dt>Email</dt>
                <dd><?= htmlspecialchars($userEmail ?: '—') ?></dd>
            </div>
            <div class="detail-row">
                <dt>Department / Association</dt>
                <dd><?= htmlspecialchars($r['department']) ?></dd>
            </div>
            <div class="detail-row">
                <dt>HOD / Senior Treasurer email</dt>
                <dd><?= htmlspecialchars($r['hod_email']) ?></dd>
            </div>
            <div class="detail-row">
                <dt>Purpose</dt>
                <dd><?= nl2br(htmlspecialchars($r['purpose'])) ?></dd>
            </div>
        </dl>

        <h3>Reservation Details</h3>
        <dl>
            <div class="detail-row">
                <dt>Building</dt>
                <dd><?= htmlspecialchars($r['building_name'] ?? '') ?></dd>
            </div>
            <div class="detail-row">
                <dt>Hall</dt>
                <dd><?= htmlspecialchars($r['hall_name'] ?? '') ?></dd>
            </div>
            <div class="detail-row">
                <dt>Start</dt>
                <dd><?= htmlspecialchars(date('l, F j, Y \a\t g:i A', strtotime($r['start_datetime']))) ?></dd>
            </div>
            <div class="detail-row">
                <dt>End</dt>
                <dd><?= htmlspecialchars(date('l, F j, Y \a\t g:i A', strtotime($r['end_datetime']))) ?></dd>
            </div>
        </dl>

        <h3>Timeline</h3>
        <dl>
            <div class="detail-row">
                <dt>Submitted</dt>
                <dd><?= htmlspecialchars($r['created_at']) ?></dd>
            </div>
            <div class="detail-row">
                <dt>HOD approved at</dt>
                <dd><?= htmlspecialchars($r['hod_approved_at'] ?? '—') ?></dd>
            </div>
            <div class="detail-row">
                <dt>Dean approved at</dt>
                <dd><?= htmlspecialchars($r['dean_approved_at'] ?? '—') ?></dd>
            </div>
            <div class="detail-row">
                <dt>Admin approved at</dt>
                <dd><?= htmlspecialchars($r['admin_approved_at'] ?? '—') ?></dd>
            </div>
        </dl>

        <div class="payment-section">
            <h3>Payment</h3>
            <?php if ($paymentRow): ?>
                <p>Refundable: LKR <?= number_format((float)$paymentRow['refundable_amount'], 2) ?> · Non-refundable: LKR <?= number_format((float)$paymentRow['non_refundable_amount'], 2) ?> · Total: LKR <?= number_format((float)$paymentRow['refundable_amount'] + (float)$paymentRow['non_refundable_amount'], 2) ?></p>
            <?php else: ?>
                <p><em>No payment required for this hall.</em></p>
            <?php endif; ?>
            <?php if ($slipUrl): ?>
                <p><strong>Payment slip:</strong></p>
                <?php if ($isImage): ?>
                    <img src="<?= htmlspecialchars($slipUrl) ?>" alt="Payment slip" class="slip-preview">
                <?php else: ?>
                    <a href="<?= htmlspecialchars($slipUrl) ?>" target="_blank">View payment slip (PDF)</a>
                <?php endif; ?>
            <?php else: ?>
                <p><em>No payment slip on file.</em></p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
