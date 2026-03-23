<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/../User/google-client.php';

$msg = '';
$error = '';
$DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

try {
    $buildings = $pdo->query("SELECT building_id, building_name FROM building ORDER BY building_name")->fetchAll(PDO::FETCH_ASSOC);
    $tableExists = false;
    try {
        $pdo->query("SELECT 1 FROM fixed_reservations LIMIT 1");
        $tableExists = true;
    } catch (PDOException $e) {}
    $existing = [];
    if ($tableExists) {
        $existing = $pdo->query("
            SELECT fr.*, b.building_name, h.hall_name
            FROM fixed_reservations fr
            LEFT JOIN building b ON fr.building_id = b.building_id
            LEFT JOIN hall h ON fr.hall_id = h.hall_id
            ORDER BY fr.day_of_week, fr.start_time
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = 'Error loading data.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $dayOfWeek = (int)($_POST['day_of_week'] ?? 0);
        $buildingId = (int)($_POST['building_id'] ?? 0);
        $hallId = (int)($_POST['hall_id'] ?? 0);
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $fromDate = trim($_POST['from_date'] ?? '');
        $toDate = trim($_POST['to_date'] ?? '');

        if ($dayOfWeek < 1 || $dayOfWeek > 7 || $buildingId <= 0 || $hallId <= 0 || $startTime === '' || $endTime === '' || $purpose === '' || $fromDate === '' || $toDate === '') {
            $error = 'All fields are required.';
        } else {
            $from = DateTime::createFromFormat('Y-m-d', $fromDate);
            $to = DateTime::createFromFormat('Y-m-d', $toDate);
            if (!$from || !$to || $to < $from) {
                $error = 'Invalid date range.';
            } else {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS fixed_reservations (
                            id int(11) NOT NULL AUTO_INCREMENT,
                            day_of_week tinyint(1) NOT NULL,
                            building_id int(2) UNSIGNED NOT NULL,
                            hall_id int(2) UNSIGNED NOT NULL,
                            start_time time NOT NULL,
                            end_time time NOT NULL,
                            purpose varchar(255) NOT NULL,
                            from_date date NOT NULL,
                            to_date date NOT NULL,
                            created_at datetime NOT NULL DEFAULT current_timestamp(),
                            PRIMARY KEY (id),
                            KEY fk_fr_building (building_id),
                            KEY fk_fr_hall (hall_id),
                            CONSTRAINT fk_fr_building FOREIGN KEY (building_id) REFERENCES building (building_id) ON DELETE CASCADE,
                            CONSTRAINT fk_fr_hall FOREIGN KEY (hall_id) REFERENCES hall (hall_id) ON DELETE CASCADE
                        )
                    ");
                } catch (PDOException $e) {
                    // table may already exist with different structure
                }
                try {
                    $ins = $pdo->prepare("INSERT INTO fixed_reservations (day_of_week, building_id, hall_id, start_time, end_time, purpose, from_date, to_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$dayOfWeek, $buildingId, $hallId, $startTime, $endTime, $purpose, $fromDate, $toDate]);
                    $fixedId = (int)$pdo->lastInsertId();

                    $googleClient = getGoogleClientWithToken();
                    if ($googleClient instanceof Google_Client) {
                        $calendarService = new Google_Service_Calendar($googleClient);
                        $current = clone $from;
                        $count = 0;
                        while ($current <= $to) {
                            if ((int)$current->format('N') === $dayOfWeek) {
                                $startDt = $current->format('Y-m-d') . ' ' . $startTime;
                                $endDt = $current->format('Y-m-d') . ' ' . $endTime;
                                $event = new Google_Service_Calendar_Event([
                                    'summary' => 'Fixed: ' . $purpose,
                                    'description' => 'Fixed reservation',
                                    'start' => ['dateTime' => $startDt, 'timeZone' => 'Asia/Colombo'],
                                    'end' => ['dateTime' => $endDt, 'timeZone' => 'Asia/Colombo'],
                                ]);
                                try {
                                    $calendarService->events->insert('primary', $event);
                                    $count++;
                                } catch (Throwable $ex) {}
                            }
                            $current->modify('+1 day');
                        }
                        $msg = "Fixed reservation created. {$count} calendar events added.";
                    } else {
                        $msg = 'Fixed reservation saved to database. Google Calendar not connected - run OAuth to sync.';
                    }
                    $existing = $pdo->query("
                        SELECT fr.*, b.building_name, h.hall_name FROM fixed_reservations fr
                        LEFT JOIN building b ON fr.building_id = b.building_id
                        LEFT JOIN hall h ON fr.hall_id = h.hall_id
                        ORDER BY fr.day_of_week, fr.start_time
                    ")->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $error = 'Failed to save: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

$hallsJson = [];
foreach ($buildings as $b) {
    $h = $pdo->prepare("SELECT hall_id, hall_name FROM hall WHERE building_id = ? ORDER BY hall_name");
    $h->execute([$b['building_id']]);
    $hallsJson[$b['building_id']] = $h->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fixed Reservations</title>
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
                <span class="nav-dropdown-trigger active">Fixed Reservations ▾</span>
                <div class="nav-dropdown-menu">
                    <a href="upload_timetable.php">Upload Timetable</a>
                    <a href="fixed_reservations.php" class="nav-link active">Fixed Reservations</a>
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

    <?php if ($error): ?><div class="errors"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="admin-card">
        <h2>Create Fixed Reservation</h2>
        <p>Add recurring reservations to the calendar by day of week and date range. Uses the same Google Calendar as user reservations.</p>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Day of Week</label>
                <select name="day_of_week" required>
                    <?php foreach ($DAY_NAMES as $n => $name): ?>
                        <option value="<?= $n ?>"><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Building</label>
                <select id="building_id" name="building_id" required>
                    <option value="">Select</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['building_id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Hall</label>
                <select id="hall_id" name="hall_id" required>
                    <option value="">Select building first</option>
                </select>
            </div>
            <div class="form-group">
                <label>Start Time</label>
                <input type="time" name="start_time" required>
            </div>
            <div class="form-group">
                <label>End Time</label>
                <input type="time" name="end_time" required>
            </div>
            <div class="form-group">
                <label>Purpose</label>
                <input type="text" name="purpose" required placeholder="e.g. CS101 Lecture">
            </div>
            <div class="form-group">
                <label>From Date</label>
                <input type="date" name="from_date" required>
            </div>
            <div class="form-group">
                <label>To Date</label>
                <input type="date" name="to_date" required>
            </div>
            <button type="submit" class="btn-primary">Create Fixed Reservation</button>
        </form>
    </div>

    <?php if (!empty($existing)): ?>
    <div class="admin-card">
        <h2>Existing Fixed Reservations</h2>
        <table style="width:100%;border-collapse:collapse;">
            <tr style="background:#f0f0f0;"><th style="padding:8px;">Day</th><th style="padding:8px;">Building</th><th style="padding:8px;">Hall</th><th style="padding:8px;">Time</th><th style="padding:8px;">Purpose</th><th style="padding:8px;">Date Range</th></tr>
            <?php foreach ($existing as $e): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:8px;"><?= $DAY_NAMES[$e['day_of_week']] ?? '' ?></td>
                    <td style="padding:8px;"><?= htmlspecialchars($e['building_name'] ?? '') ?></td>
                    <td style="padding:8px;"><?= htmlspecialchars($e['hall_name'] ?? '') ?></td>
                    <td style="padding:8px;"><?= date('H:i', strtotime($e['start_time'])) ?> - <?= date('H:i', strtotime($e['end_time'])) ?></td>
                    <td style="padding:8px;"><?= htmlspecialchars($e['purpose']) ?></td>
                    <td style="padding:8px;"><?= htmlspecialchars($e['from_date']) ?> to <?= htmlspecialchars($e['to_date']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
</div>
<script>
const halls = <?= json_encode($hallsJson) ?>;
document.getElementById('building_id').addEventListener('change', function(){
    const sel = document.getElementById('hall_id');
    const bid = this.value;
    sel.innerHTML = '<option value="">Select hall</option>';
    if (bid && halls[bid]) {
        halls[bid].forEach(h => {
            const o = document.createElement('option');
            o.value = h.hall_id;
            o.textContent = h.hall_name;
            sel.appendChild(o);
        });
    }
});

// Nav dropdown click toggle
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
