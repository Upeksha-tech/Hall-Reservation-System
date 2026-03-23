<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';

$msg = '';
$error = '';
$buildings = [];
$halls = [];
$payments = [];

try {
    $buildings = $pdo->query("SELECT building_id, building_name FROM building ORDER BY building_name")->fetchAll(PDO::FETCH_ASSOC);
    $halls = $pdo->query("
        SELECT h.hall_id, h.hall_name, h.building_id, h.hall_capacity, h.hall_type, b.building_name
        FROM hall h
        LEFT JOIN building b ON h.building_id = b.building_id
        ORDER BY b.building_name, h.hall_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $payments = $pdo->query("
        SELECT p.hall_id, p.refundable_amount, p.non_refundable_amount, h.hall_name, b.building_name
        FROM payment p
        LEFT JOIN hall h ON p.hall_id = h.hall_id
        LEFT JOIN building b ON h.building_id = b.building_id
        ORDER BY b.building_name, h.hall_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error loading data.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_building') {
        $name = trim($_POST['building_name'] ?? '');
        if ($name === '') {
            $error = 'Building name is required.';
        } else {
            try {
                $ins = $pdo->prepare("INSERT INTO building (building_name) VALUES (?)");
                $ins->execute([$name]);
                $msg = 'Building added successfully.';
                $buildings = $pdo->query("SELECT building_id, building_name FROM building ORDER BY building_name")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error = 'Failed to add building (duplicate name?).';
            }
        }
    } elseif ($action === 'add_hall') {
        $hallName = trim($_POST['hall_name'] ?? '');
        $buildingId = (int)($_POST['building_id'] ?? 0);
        $capacity = (int)($_POST['hall_capacity'] ?? 0);
        $hallType = trim($_POST['hall_type'] ?? 'Lecture Hall');
        $refundable = (float)($_POST['refundable_amount'] ?? 0);
        $nonRefundable = (float)($_POST['non_refundable_amount'] ?? 0);
        
        if ($hallName === '' || $buildingId <= 0) {
            $error = 'Hall name and building are required.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Insert hall
                $ins = $pdo->prepare("INSERT INTO hall (hall_name, building_id, hall_capacity, hall_type) VALUES (?, ?, ?, ?)");
                $ins->execute([$hallName, $buildingId, max(0, $capacity), $hallType ?: 'Lecture Hall']);
                
                // Get the new hall ID
                $newHallId = $pdo->lastInsertId();
                
                // Add prices if provided (optional)
                if ($refundable > 0 || $nonRefundable > 0) {
                    $stmt = $pdo->prepare("INSERT INTO payment (hall_id, refundable_amount, non_refundable_amount) VALUES (?, ?, ?)");
                    $stmt->execute([$newHallId, $refundable, $nonRefundable]);
                }
                
                $pdo->commit();
                $msg = 'Hall added successfully.' . ($refundable > 0 || $nonRefundable > 0 ? ' Prices saved.' : '');
                
                // Refresh data
                $halls = $pdo->query("
                    SELECT h.hall_id, h.hall_name, h.building_id, h.hall_capacity, h.hall_type, b.building_name
                    FROM hall h LEFT JOIN building b ON h.building_id = b.building_id
                    ORDER BY b.building_name, h.hall_name
                ")->fetchAll(PDO::FETCH_ASSOC);
                
                $payments = $pdo->query("
                    SELECT p.hall_id, p.refundable_amount, p.non_refundable_amount, h.hall_name, b.building_name
                    FROM payment p
                    LEFT JOIN hall h ON p.hall_id = h.hall_id
                    LEFT JOIN building b ON h.building_id = b.building_id
                    ORDER BY b.building_name, h.hall_name
                ")->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Failed to add hall: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'update_hall') {
        $hallId = (int)($_POST['hall_id'] ?? 0);
        $hallName = trim($_POST['hall_name'] ?? '');
        $buildingId = (int)($_POST['building_id'] ?? 0);
        $capacity = (int)($_POST['hall_capacity'] ?? 0);
        $hallType = trim($_POST['hall_type'] ?? 'Lecture Hall');
        
        if ($hallId <= 0 || $hallName === '' || $buildingId <= 0) {
            $error = 'Hall ID, name and building are required.';
        } else {
            try {
                $upd = $pdo->prepare("UPDATE hall SET hall_name = ?, building_id = ?, hall_capacity = ?, hall_type = ? WHERE hall_id = ?");
                $upd->execute([$hallName, $buildingId, max(0, $capacity), $hallType ?: 'Lecture Hall', $hallId]);
                $msg = 'Hall updated successfully.';
                $halls = $pdo->query("
                    SELECT h.hall_id, h.hall_name, h.building_id, h.hall_capacity, h.hall_type, b.building_name
                    FROM hall h LEFT JOIN building b ON h.building_id = b.building_id
                    ORDER BY b.building_name, h.hall_name
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error = 'Failed to update hall: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'add_price') {
        $hallId = (int)($_POST['hall_id'] ?? 0);
        $refundable = (float)($_POST['refundable_amount'] ?? 0);
        $nonRefundable = (float)($_POST['non_refundable_amount'] ?? 0);
        if ($hallId <= 0) {
            $error = 'Select a hall.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO payment (hall_id, refundable_amount, non_refundable_amount) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE refundable_amount = VALUES(refundable_amount), non_refundable_amount = VALUES(non_refundable_amount)");
                $stmt->execute([$hallId, $refundable, $nonRefundable]);
                $msg = 'Payment details saved.';
                $payments = $pdo->query("
                    SELECT p.hall_id, p.refundable_amount, p.non_refundable_amount, h.hall_name, b.building_name
                    FROM payment p LEFT JOIN hall h ON p.hall_id = h.hall_id
                    LEFT JOIN building b ON h.building_id = b.building_id
                    ORDER BY b.building_name, h.hall_name
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error = 'Failed to save: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'update_price') {
        $hallId = (int)($_POST['hall_id'] ?? 0);
        $refundable = (float)($_POST['refundable_amount'] ?? 0);
        $nonRefundable = (float)($_POST['non_refundable_amount'] ?? 0);
        if ($hallId <= 0) {
            $error = 'Invalid hall.';
        } else {
            try {
                $upd = $pdo->prepare("UPDATE payment SET refundable_amount = ?, non_refundable_amount = ? WHERE hall_id = ?");
                $upd->execute([$refundable, $nonRefundable, $hallId]);
                $msg = 'Prices updated.';
                $payments = $pdo->query("
                    SELECT p.hall_id, p.refundable_amount, p.non_refundable_amount, h.hall_name, b.building_name
                    FROM payment p LEFT JOIN hall h ON p.hall_id = h.hall_id
                    LEFT JOIN building b ON h.building_id = b.building_id
                    ORDER BY b.building_name, h.hall_name
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error = 'Failed to update.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Halls & Prices</title>
    <link rel="stylesheet" href="admin.css">
    <style>
        .admin-card { margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 4px; font-weight: 600; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .form-row { display: flex; gap: 12px; align-items: flex-end; }
        .form-row .form-group { flex: 1; margin-bottom: 0; }
        .btn-primary { background: #800000; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; }
        .btn-primary:hover { background: #600000; }
        .btn-secondary { background: #666; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9rem; }
        .btn-secondary:hover { background: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        th { background: #f5f5f5; font-weight: 600; }
        .inline-form { display: flex; gap: 8px; align-items: center; }
        .inline-form input { width: 100px; }
        .price-inputs { display: flex; gap: 8px; align-items: center; }
        .price-inputs input { width: 120px; }
        .nav-dropdown {
            position: relative;
            display: inline-flex;
            align-items: center;
            /* Extra padding below keeps hover active while moving into menu */
            padding-bottom: 6px;
            margin-top: 6px;
        }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <h1>Admin Portal</h1>
        <nav class="admin-nav">
            <a href="index.php" class="nav-link">Pending Requests</a>
            <a href="approved_history.php" class="nav-link">Approved History</a>
            <a href="generate_reports.php" class="nav-link">Generate Reports</a>
            <a href="admin_free_slots.php" class="nav-link">View Free Slots</a>
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
                    <a href="manage_halls.php" class="nav-link active">Manage Halls & Prices</a>
                </div>
            </div>
            <a href="../Login/logout.php" class="nav-link">Sign out</a>
        </nav>
    </header>

    <?php if ($error): ?><div class="errors"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- Add Building Section -->
    <div class="admin-card">
        <h2>Add Building</h2>
        <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="add_building">
            <div class="form-group" style="margin:0; min-width:200px;">
                <label for="building_name">Building Name</label>
                <input type="text" id="building_name" name="building_name" placeholder="e.g. A11" required>
            </div>
            <button type="submit" class="btn-primary">Add Building</button>
        </form>
    </div>

    <!-- Add Hall Section -->
    <div class="admin-card">
        <h2>Add Hall</h2>
        <form method="post">
            <input type="hidden" name="action" value="add_hall">
            <div class="form-row">
                <div class="form-group">
                    <label for="hall_name">Hall Name</label>
                    <input type="text" id="hall_name" name="hall_name" placeholder="e.g. 201" required>
                </div>
                <div class="form-group">
                    <label for="building_id">Building</label>
                    <select id="building_id" name="building_id" required>
                        <option value="">Select building</option>
                        <?php foreach ($buildings as $b): ?>
                            <option value="<?= (int)$b['building_id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="hall_capacity">Capacity</label>
                    <input type="number" id="hall_capacity" name="hall_capacity" min="0" value="30">
                </div>
                <div class="form-group">
                    <label for="hall_type">Hall Type</label>
                    <input type="text" id="hall_type" name="hall_type" value="Lecture Hall">
                </div>
            </div>
            <br>
            <h2>Add Payments</h2> 
            <div class="form-row" style="margin-top: 12px;">
                <div class="form-group">
                    <label for="add_refundable">Refundable (LKR) <span style="color: #666; font-size: 0.85rem;">Optional</span></label>
                    <input type="number" id="add_refundable" name="refundable_amount" min="0" step="0.01" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label for="add_non_refundable">Non-Refundable (LKR) <span style="color: #666; font-size: 0.85rem;">Optional</span></label>
                    <input type="number" id="add_non_refundable" name="non_refundable_amount" min="0" step="0.01" placeholder="Optional">
                </div>
            </div>
            <br> 
            <button type="submit" class="btn-primary">Add Hall</button>
        </form>
    </div>

    <!-- Manage Halls Section -->
    <div class="admin-card">
        <h2>Manage Halls</h2>
        <table>
            <tr>
                <th>Building</th>
                <th>Hall Name</th>
                <th>Capacity</th>
                <th>Type</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($halls as $h): ?>
                <tr>
                    <form method="post" style="display: contents;">
                        <input type="hidden" name="action" value="update_hall">
                        <input type="hidden" name="hall_id" value="<?= (int)$h['hall_id'] ?>">
                        <input type="hidden" name="building_id" value="<?= (int)$h['building_id'] ?>">
                        <input type="hidden" name="hall_name" value="<?= htmlspecialchars($h['hall_name']) ?>">
                        <td style="background: #f9f9f9; font-weight: 600;"><?= htmlspecialchars($h['building_name']) ?></td>
                        <td style="background: #f9f9f9; font-weight: 600;"><?= htmlspecialchars($h['hall_name']) ?></td>
                        <td>
                            <input type="number" name="hall_capacity" value="<?= (int)$h['hall_capacity'] ?>" min="0" style="width: 80px;">
                        </td>
                        <td>
                            <input type="text" name="hall_type" value="<?= htmlspecialchars($h['hall_type']) ?>" style="width: 120px;">
                        </td>
                        <td>
                            <button type="submit" class="btn-secondary">Update</button>
                        </td>
                    </form>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- Add/Update Prices Section -->
    <div class="admin-card">
        <h2>Manage Hall Prices</h2>
        <p>Set refundable and non-refundable amounts (LKR) for halls. Prices are optional - only set if required.</p>
        
        <form method="post" style="margin-bottom: 24px;">
            <input type="hidden" name="action" value="add_price">
            <div class="form-row">
                <div class="form-group">
                    <label for="hall_id_price">Hall</label>
                    <select id="hall_id_price" name="hall_id" required>
                        <option value="">Select hall</option>
                        <?php foreach ($halls as $h): ?>
                            <option value="<?= (int)$h['hall_id'] ?>"><?= htmlspecialchars(($h['building_name'] ?? '') . ' - ' . $h['hall_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="refundable_amount">Refundable (LKR)</label>
                    <input type="number" id="refundable_amount" name="refundable_amount" min="0" step="0.01" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label for="non_refundable_amount">Non-Refundable (LKR)</label>
                    <input type="number" id="non_refundable_amount" name="non_refundable_amount" min="0" step="0.01" placeholder="Optional">
                </div>
                <button type="submit" class="btn-primary">Save Prices</button>
            </div>
        </form>

        <h3>Current Prices</h3>
        <table>
            <tr>
                <th>Hall</th>
                <th>Refundable (LKR)</th>
                <th>Non-Refundable (LKR)</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= htmlspecialchars(($p['building_name'] ?? '') . ' - ' . $p['hall_name']) ?></td>
                    <td><?= number_format((float)$p['refundable_amount'], 2) ?></td>
                    <td><?= number_format((float)$p['non_refundable_amount'], 2) ?></td>
                    <td>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="update_price">
                            <input type="hidden" name="hall_id" value="<?= (int)$p['hall_id'] ?>">
                            <div class="price-inputs">
                                <input type="number" name="refundable_amount" value="<?= (float)$p['refundable_amount'] ?>" step="0.01" min="0" placeholder="Refundable">
                                <input type="number" name="non_refundable_amount" value="<?= (float)$p['non_refundable_amount'] ?>" step="0.01" min="0" placeholder="Non-Refundable">
                            </div>
                            <button type="submit" class="btn-secondary">Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if (empty($payments)): ?>
            <p style="margin-top: 12px; color: #666;">No prices set yet. Use the form above to add prices for halls.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
