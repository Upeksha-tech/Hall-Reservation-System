<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';

$token = trim($_GET['token'] ?? '');
if (!$token) {
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT r.*, b.building_name, h.hall_name
        FROM reservations r
        LEFT JOIN building b ON r.building_id = b.building_id
        LEFT JOIN hall h ON r.hall_id = h.hall_id
        WHERE r.approval_token = :token
    ");
    $stmt->execute([':token' => $token]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $r = null;
}

if (!$r || $r['status'] !== 'pending_admin') {
    header('Location: index.php?error=' . urlencode('Reservation not found or not pending approval.'));
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
$approveUrl = $baseUrl . '/User/reservation_approve.php?token=' . urlencode($token) . '&step=admin&action=approve';
$rejectUrl = $baseUrl . '/User/reservation_approve.php?token=' . urlencode($token) . '&step=admin&action=reject';

$slipPath = $r['payment_slip_path'] ?? '';
$slipUrl = $slipPath ? ($baseUrl . '/User/' . $slipPath) : null;
$slipExt = $slipPath ? strtolower(pathinfo($slipPath, PATHINFO_EXTENSION)) : '';
$isImage = in_array($slipExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review Reservation #<?= (int)$r['id'] ?></title>
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
        .payment-section { background: #fff3cd; border: 2px solid #ffc107; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .payment-section h3 { margin: 0 0 12px; color: #856404; }
        .slip-preview { max-width: 100%; max-height: 400px; border: 1px solid #ccc; border-radius: 4px; margin-top: 8px; }
        .slip-link { display: inline-block; margin-top: 8px; }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <h1>Admin Portal</h1>
        <nav class="admin-nav">
            <a href="index.php">Pending Requests</a>
            <a href="approved_history.php">Approved History</a>
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
        <h2>Review Reservation #<?= (int)$r['id'] ?> · Pending Approval</h2>
        <p><strong>Verify all details and the payment slip before approving.</strong> There is no online payment — the slip is the only proof of payment.</p>

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

        <h3>Approval Timeline</h3>
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
        </dl>

        <div class="payment-section">
            <h3>Payment Verification (Required)</h3>
            <p>This hall requires payment. Verify the payment slip shows the correct amount before approving.</p>
            <?php if ($paymentRow): ?>
                <p><strong>Required amounts:</strong><br>
                    Refundable: LKR <?= number_format((float)$paymentRow['refundable_amount'], 2) ?><br>
                    Non-refundable: LKR <?= number_format((float)$paymentRow['non_refundable_amount'], 2) ?><br>
                    <strong>Total: LKR <?= number_format((float)$paymentRow['refundable_amount'] + (float)$paymentRow['non_refundable_amount'], 2) ?></strong></p>
            <?php else: ?>
                <p><em>This hall is not in the payment table. No payment required.</em></p>
            <?php endif; ?>

            <?php if ($slipUrl): ?>
                <p><strong>Payment slip:</strong></p>
                <?php if ($isImage): ?>
                    <img src="<?= htmlspecialchars($slipUrl) ?>" alt="Payment slip" class="slip-preview">
                <?php else: ?>
                    <a href="<?= htmlspecialchars($slipUrl) ?>" target="_blank" class="slip-link">View payment slip (PDF)</a>
                <?php endif; ?>
            <?php else: ?>
                <p style="color:#c62828;"><strong>No payment slip uploaded.</strong> Please verify with the user before approving.</p>
            <?php endif; ?>
        </div>

        <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="<?= htmlspecialchars($approveUrl) ?>" class="btn-approve">Approve</a>
            <a href="<?= htmlspecialchars($rejectUrl) ?>" class="btn-reject">Reject</a>
            <a href="index.php" style="color:#666; font-size:0.9rem;">&larr; Back to Pending List</a>
        </div>
    </div>
</div>
</body>
</html>
