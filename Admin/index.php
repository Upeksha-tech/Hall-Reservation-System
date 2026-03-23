<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';

$username = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';
$error = '';

// Fetch pending_admin reservations (dean approved, awaiting admin)
try {
    $stmt = $pdo->prepare("
        SELECT r.*, b.building_name, h.hall_name
        FROM reservations r
        LEFT JOIN building b ON r.building_id = b.building_id
        LEFT JOIN hall h ON r.hall_id = h.hall_id
        WHERE r.status = 'pending_admin'
        ORDER BY r.dean_approved_at ASC
    ");
    $stmt->execute();
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error loading pending requests.';
    $pending = [];
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin - Approval Portal</title>
    <link rel="stylesheet" href="admin.css">
    <style>
        .nav-dropdown {
            margin-top: 6px;
        }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <h1>Admin Portal - <?= $username ?></h1>
        <nav class="admin-nav">
            <a href="index.php" class="nav-link active">Pending Requests</a>
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

    <?php if ($error): ?>
        <div class="errors"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="errors"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <div class="admin-card">
        <h2>Admin Approval Portal</h2>
        <p>Reservations that have been approved by HOD and Dean. Review and approve or reject below.</p>

        <?php if (empty($pending)): ?>
            <div class="empty-state">
                <p>No pending requests at the moment.</p>
            </div>
        <?php else: ?>
            <ul class="pending-list">
                <?php foreach ($pending as $r): ?>
                    <li class="pending-item">
                        <div class="meta">
                            Request #<?= (int)$r['id'] ?> · Dean approved: <?= htmlspecialchars($r['dean_approved_at'] ?? '') ?>
                        </div>
                        <div class="details">
                            <strong><?= htmlspecialchars($r['name']) ?></strong> · <?= htmlspecialchars($r['department']) ?><br>
                            Building: <?= htmlspecialchars($r['building_name'] ?? '') ?> · Hall: <?= htmlspecialchars($r['hall_name'] ?? '') ?><br>
                            Start: <?= htmlspecialchars($r['start_datetime']) ?> · End: <?= htmlspecialchars($r['end_datetime']) ?><br>
                            Purpose: <?= htmlspecialchars($r['purpose']) ?>
                        </div>
                        <div class="actions">
                            <a href="approval_detail.php?token=<?= urlencode($r['approval_token']) ?>" class="btn-approve">View Details & Approve</a>
                            <a href="<?= $baseUrl ?>/User/reservation_approve.php?token=<?= urlencode($r['approval_token']) ?>&step=admin&action=reject" class="btn-reject">Reject</a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var dropdowns = document.querySelectorAll('.nav-dropdown');
    dropdowns.forEach(function (dd) {
        var trigger = dd.querySelector('.nav-dropdown-trigger');
        if (!trigger) return;
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            // close others
            document.querySelectorAll('.nav-dropdown.open').forEach(function (other) {
                if (other !== dd) other.classList.remove('open');
            });
            dd.classList.toggle('open');
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('.nav-dropdown.open').forEach(function (dd) {
            dd.classList.remove('open');
        });
    });
});
</script>
</body>
</html>
