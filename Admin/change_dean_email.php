<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';

$msg = '';
$error = '';
$currentEmail = '';

try {
    $stmt = $pdo->prepare("SELECT email FROM user WHERE user_role = 'dean' LIMIT 1");
    $stmt->execute();
    $dean = $stmt->fetch();
    $currentEmail = $dean['email'] ?? '';
} catch (PDOException $e) {
    $error = 'Error loading dean email.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newEmail = trim($_POST['dean_email'] ?? '');
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $upd = $pdo->prepare("UPDATE user SET email = :email WHERE user_role = 'dean'");
            $upd->execute([':email' => $newEmail]);
            if ($upd->rowCount() > 0) {
                $msg = 'Dean email has been updated successfully.';
                $currentEmail = $newEmail;
            } else {
                $msg = 'Dean email updated (or no change was needed).';
                $currentEmail = $newEmail;
            }
        } catch (PDOException $e) {
            $error = 'Failed to update: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Dean's Email</title>
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
                <span class="nav-dropdown-trigger active">More ▾</span>
                <div class="nav-dropdown-menu">
                    <a href="../Login/create_user.php">Create User Accounts</a>
                    <a href="change_dean_email.php">Change Dean's Email</a>
                    <a href="manage_halls.php">Manage Halls & Prices</a>
                </div>
            </div>
            <a href="../Login/logout.php">Sign out</a>
        </nav>
    </header>

    <?php if ($error): ?><div class="errors"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="admin-card">
        <h2>Change Dean's Email Address</h2>
        <p>Update the email address for the Dean (user_role = 'dean'). Approval request emails will be sent to this address.</p>
        <form method="post" style="max-width: 400px;">
            <div class="form-group">
                <label for="dean_email">Dean's Email</label>
                <input type="email" id="dean_email" name="dean_email" required
                       value="<?= htmlspecialchars($currentEmail) ?>">
            </div>
            <button type="submit" class="btn-primary">Update Email</button>
        </form>
    </div>
</div>
</body>
</html>
