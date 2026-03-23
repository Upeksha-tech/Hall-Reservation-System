<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';

$username = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$error = '';

try {
    $stmt = $pdo->prepare("
        SELECT r.*, b.building_name, h.hall_name
        FROM reservations r
        LEFT JOIN building b ON r.building_id = b.building_id
        LEFT JOIN hall h ON r.hall_id = h.hall_id
        WHERE r.status = 'approved'
        ORDER BY r.admin_approved_at DESC
    ");
    $stmt->execute();
    $approved = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error loading approved reservations.';
    $approved = [];
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approved Reservations History</title>
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

    <?php if ($error): ?>
        <div class="errors"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="admin-card">
        <h2>Approved Reservations History</h2>
        <p>All reservations that have been approved by Admin. Click "View" to see full details.</p>

        <?php if (empty($approved)): ?>
            <div class="empty-state">
                <p>No approved reservations yet.</p>
            </div>
        <?php else: ?>
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr style="background:#f0f0f0;">
                        <th style="padding:8px; text-align:left;">ID</th>
                        <th style="padding:8px; text-align:left;">Name</th>
                        <th style="padding:8px; text-align:left;">Department</th>
                        <th style="padding:8px; text-align:left;">Building</th>
                        <th style="padding:8px; text-align:left;">Hall</th>
                        <th style="padding:8px; text-align:left;">Start</th>
                        <th style="padding:8px; text-align:left;">End</th>
                        <th style="padding:8px; text-align:left;">Approved</th>
                        <th style="padding:8px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approved as $r): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px;"><?= (int)$r['id'] ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars($r['name']) ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars($r['department']) ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars($r['building_name'] ?? '') ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars($r['hall_name'] ?? '') ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['start_datetime']))) ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['end_datetime']))) ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars($r['admin_approved_at'] ?? '') ?></td>
                            <td style="padding:8px;"><a href="approved_detail.php?id=<?= (int)$r['id'] ?>" class="link-inline">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
