<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/../User/google-client.php';

$username = htmlspecialchars($_SESSION['username'] ?? 'Admin');

// Time window: 08:00–22:00 (slots on the hour; last slot ends at 22:00)
$slotStartHour = 8;
$slotEndHour   = 22;
$slotHours = range($slotStartHour, $slotEndHour - 1);

// Build list of buildings and halls
try {
    $buildings = $pdo->query("SELECT building_id, building_name FROM building ORDER BY building_name")->fetchAll();
    $allHalls = $pdo->query("
        SELECT h.hall_id, h.hall_name, h.building_id, b.building_name
        FROM hall h
        JOIN building b ON h.building_id = b.building_id
        ORDER BY b.building_name, h.hall_name
    ")->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Error loading data.';
    exit;
}

$selectedDate = null;
$selectedBuildingId = isset($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$selectedHallId = isset($_GET['hall_id']) ? (int)$_GET['hall_id'] : null;
$dateStr = trim($_GET['date'] ?? '');
$dayStr = trim($_GET['day'] ?? ''); // which of the 7 days is selected to show time slots

if ($dateStr !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $dateStr);
    if ($d) {
        $selectedDate = $d;
    }
}

$viewDay = null; // the single day we're showing slots for (one of the 7)
if ($dayStr !== '' && $selectedDate) {
    $startRange = (clone $selectedDate)->modify('-3 days');
    $endRange = (clone $selectedDate)->modify('+3 days');
    $d = DateTime::createFromFormat('Y-m-d', $dayStr);
    if ($d && $d >= $startRange && $d <= $endRange) {
        $viewDay = $d;
    }
}

// Filter halls by building/hall
$halls = $allHalls;
if ($selectedBuildingId > 0) {
    $halls = array_filter($halls, function ($h) use ($selectedBuildingId) {
        return (int)$h['building_id'] === $selectedBuildingId;
    });
}
if ($selectedHallId > 0) {
    $halls = array_filter($halls, function ($h) use ($selectedHallId) {
        return (int)$h['hall_id'] === $selectedHallId;
    });
}
$halls = array_values($halls);

$busyRanges = []; // [hall_id][day_key][slot_index] = true
$calendarEvents = [];
$sevenDays = []; // array of DateTime for the 7 days

if ($selectedDate && count($halls) > 0) {
    $startRange = (clone $selectedDate)->modify('-3 days')->setTime(0, 0, 0);
    $endRange = (clone $selectedDate)->modify('+3 days')->setTime(23, 59, 59);

    $cursor = (clone $selectedDate)->modify('-3 days');
    for ($i = 0; $i < 7; $i++) {
        $sevenDays[] = (clone $cursor)->modify("+$i days");
    }

    $hallIds = array_map(function ($h) {
        return (int)$h['hall_id'];
    }, $halls);
    $placeholders = implode(',', array_fill(0, count($hallIds), '?'));

    $stmt = $pdo->prepare("
        SELECT hall_id, start_datetime, end_datetime
        FROM reservations
        WHERE status NOT IN ('cancelled', 'rejected')
          AND hall_id IN ($placeholders)
          AND start_datetime < ?
          AND end_datetime > ?
    ");
    $params = array_merge($hallIds, [$endRange->format('Y-m-d H:i:s'), $startRange->format('Y-m-d H:i:s')]);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $hid = (int)$row['hall_id'];
        $s = new DateTime($row['start_datetime']);
        $e = new DateTime($row['end_datetime']);
        if (!isset($busyRanges[$hid])) {
            $busyRanges[$hid] = [];
        }
        foreach ($sevenDays as $day) {
            $dayKey = $day->format('Y-m-d');
            if (!isset($busyRanges[$hid][$dayKey])) {
                $busyRanges[$hid][$dayKey] = [];
            }
            for ($i = 0; $i < count($slotHours); $i++) {
                $slotStart = (clone $day)->setTime($slotHours[$i], 0, 0);
                $slotEnd = (clone $day)->setTime($slotHours[$i] + 1, 0, 0);
                if ($s < $slotEnd && $e > $slotStart) {
                    $busyRanges[$hid][$dayKey][$i] = true;
                }
            }
        }
    }

    // Block fixed reservations (weekly rules stored in DB)
    try {
        $stmtFr = $pdo->prepare("
            SELECT hall_id, day_of_week, start_time, end_time, from_date, to_date
            FROM fixed_reservations
            WHERE hall_id IN ($placeholders)
              AND from_date <= ?
              AND to_date >= ?
        ");
        $stmtFr->execute(array_merge($hallIds, [$endRange->format('Y-m-d'), $startRange->format('Y-m-d')]));
        while ($fr = $stmtFr->fetch()) {
            $hid = (int)$fr['hall_id'];
            $dow = (int)$fr['day_of_week']; // 1=Mon..7=Sun
            $fromYmd = (string)$fr['from_date'];
            $toYmd = (string)$fr['to_date'];
            $startT = (string)$fr['start_time'];
            $endT = (string)$fr['end_time'];

            foreach ($sevenDays as $day) {
                $dayKey = $day->format('Y-m-d');
                if ($dayKey < $fromYmd || $dayKey > $toYmd) continue;
                if ((int)$day->format('N') !== $dow) continue;

                if (!isset($busyRanges[$hid])) $busyRanges[$hid] = [];
                if (!isset($busyRanges[$hid][$dayKey])) $busyRanges[$hid][$dayKey] = [];

                $fs = new DateTime($dayKey . ' ' . $startT);
                $fe = new DateTime($dayKey . ' ' . $endT);
                for ($i = 0; $i < count($slotHours); $i++) {
                    $slotStart = (clone $day)->setTime($slotHours[$i], 0, 0);
                    $slotEnd = (clone $day)->setTime($slotHours[$i] + 1, 0, 0);
                    if ($fs < $slotEnd && $fe > $slotStart) {
                        $busyRanges[$hid][$dayKey][$i] = true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // If fixed_reservations table missing or query fails, ignore and continue.
    }

    $googleClient = getGoogleClientWithToken();
    if ($googleClient instanceof Google_Client) {
        try {
            $calendarService = new Google_Service_Calendar($googleClient);
            $events = $calendarService->events->listEvents('primary', [
                'timeMin' => $startRange->format(DateTime::RFC3339),
                'timeMax' => $endRange->format(DateTime::RFC3339),
                'singleEvents' => true,
                'orderBy' => 'startTime',
            ]);
            $calendarEvents = $events->getItems();
        } catch (Throwable $e) {
            $calendarEvents = [];
        }
    }
}

// Build query string for links (preserve date + filters, add or replace day)
$baseParams = [
    'date' => $selectedDate ? $selectedDate->format('Y-m-d') : '',
    'building_id' => $selectedBuildingId ?: '',
    'hall_id' => $selectedHallId ?: '',
];
function dayLink($baseParams, $dayYmd) {
    $p = array_merge($baseParams, ['day' => $dayYmd]);
    return '?' . http_build_query(array_filter($p));
}

// For admin, we don't need reservation form link, but we can show details
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin - View Free Slots</title>
    <link rel="stylesheet" href="admin.css">
    <style>
        .nav-dropdown {
            margin-top: 6px;
        }
    </style>
    <style>
        :root { --maroon: #800000; --yellow: #ffc107; }
        body { font-family: Arial, sans-serif; background: #fdf7f2; margin: 0; padding: 0; }
        .container { max-width: 1100px; margin: 24px auto; background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }
        header { background: linear-gradient(90deg, var(--maroon), #4b0000); color: #fff; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
        header h1 { margin: 0; font-size: 1.3rem; }
        main { padding: 16px 20px 20px; }
        .filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px; padding: 12px; background: #f8f8f8; border-radius: 8px; }
        .filters label { display: block; font-weight: 600; margin-bottom: 2px; font-size: 0.9rem; }
        .filters input, .filters select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; }
        .filters button { padding: 8px 16px; background: var(--maroon); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 0.9rem; }
        .link { color: var(--maroon); text-decoration: none; font-weight: 600; }
        .link:hover { text-decoration: underline; }

        .section-title { font-size: 1rem; font-weight: 700; margin: 20px 0 10px 0; color: #4b0000; }
        .days-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
        .day-card { display: inline-block; padding: 12px 16px; border: 2px solid #ccc; border-radius: 8px; text-align: center; text-decoration: none; color: #333; font-size: 0.9rem; min-width: 100px; background: #fafafa; }
        .day-card:hover { border-color: var(--maroon); background: #fff5f5; color: var(--maroon); }
        .day-card.selected { border-color: var(--maroon); background: rgba(128,0,0,0.1); color: #4b0000; font-weight: 700; }
        .day-card .weekday { display: block; font-size: 0.75rem; color: #666; margin-bottom: 2px; }
        .day-card .date { display: block; font-weight: 600; }

        .slots-section { margin-top: 16px; }
        .slots-table { border-collapse: collapse; width: 100%; font-size: 0.9rem; }
        .slots-table th, .slots-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: center; }
        .slots-table th { background: rgba(128,0,0,0.1); color: #4b0000; font-weight: 600; }
        .slots-table .hall-name { text-align: left; font-weight: 600; background: #fafafa; }
        .slot-free { background: #d4edda; color: #155724; font-weight: 600; }
        .slot-busy { background: #f8d7da; color: #721c24; }
        .cal-section { margin-top: 16px; padding: 12px; background: #f8f8f8; border-radius: 6px; font-size: 0.9rem; }
        .cal-section h3 { margin: 0 0 8px 0; font-size: 0.95rem; }
        .cal-section ul { margin: 0; padding-left: 20px; }
        .calendar-embed-wrapper { margin: 16px 0; }
        .calendar-embed-title { margin: 0 0 4px; font-size: 1rem; color: var(--maroon); }
        .calendar-embed-note { margin: 0 0 10px; font-size: 0.85rem; color: #666; }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <h1>Admin Portal - <?= $username ?></h1>
        <nav class="admin-nav">
            <a href="index.php">Pending Requests</a>
            <a href="approved_history.php">Approved History</a>
            <a href="generate_reports.php">Generate Reports</a>
            <a href="admin_free_slots.php" class="nav-link active">View Free Slots</a>
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

    <main>
        <p style="margin:0 0 12px 0; font-size:0.95rem;">Select a date (required). Optionally filter by building and hall. You will see 7 days (3 before and 3 after). <strong>Click a date</strong> to see available time slots for that day.</p>

        <form method="get" class="filters">
            <div>
                <label for="date">Date *</label>
                <input type="date" id="date" name="date" required
                       value="<?php echo $selectedDate ? $selectedDate->format('Y-m-d') : ''; ?>">
            </div>
            <div>
                <label for="building_id">Building</label>
                <select id="building_id" name="building_id">
                    <option value="">All</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?php echo (int)$b['building_id']; ?>"
                            <?php echo $selectedBuildingId === (int)$b['building_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['building_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="hall_id">Hall</label>
                <select id="hall_id" name="hall_id">
                    <option value="">All</option>
                    <?php foreach ($allHalls as $h): ?>
                        <option value="<?php echo (int)$h['hall_id']; ?>"
                            <?php echo $selectedHallId === (int)$h['hall_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($h['building_name'] . ' – ' . $h['hall_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Show 7 days</button>
            </div>
        </form>

        <?php if ($selectedDate && count($halls) > 0): ?>
            <div class="section-title">1. Choose a date (7 days: 3 before and 3 after your selected date)</div>
            <div class="days-row">
                <?php foreach ($sevenDays as $day): ?>
                    <?php
                    $dayKey = $day->format('Y-m-d');
                    $isSelected = $viewDay && $viewDay->format('Y-m-d') === $dayKey;
                    $href = dayLink($baseParams, $dayKey);
                    $weekday = $day->format('D');
                    $dateLabel = $day->format('M j');
                    ?>
                    <a href="<?php echo htmlspecialchars($href); ?>" class="day-card <?php echo $isSelected ? 'selected' : ''; ?>">
                        <span class="weekday"><?php echo htmlspecialchars($weekday); ?></span>
                        <span class="date"><?php echo htmlspecialchars($dateLabel); ?></span>
                        <span class="year" style="font-size:0.7rem; color:#999;"><?php echo $day->format('Y'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($viewDay): ?>
                <div class="section-title">2. Available time slots for <?php echo $viewDay->format('l, F j, Y'); ?></div>
                <p style="margin:0 0 8px 0; font-size:0.9rem;">This shows the availability status for each hall and time slot. Free slots are available for booking.</p>
                <div class="slots-section">
                    <table class="slots-table">
                        <thead>
                        <tr>
                            <th>Hall</th>
                            <?php foreach ($slotHours as $h): ?>
                                <th><?php echo sprintf('%02d:00', $h); ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($halls as $hall): ?>
                            <tr>
                                <td class="hall-name"><?php echo htmlspecialchars($hall['building_name'] . ' ' . $hall['hall_name']); ?></td>
                                <?php
                                $dayKey = $viewDay->format('Y-m-d');
                                foreach ($slotHours as $i => $hour):
                                    $slotStart = (clone $viewDay)->setTime($hour, 0, 0);
                                    $slotEnd = (clone $viewDay)->setTime($hour + 1, 0, 0);
                                    $isBusy = isset($busyRanges[(int)$hall['hall_id']][$dayKey][$i]);
                                ?>
                                    <td class="<?php echo $isBusy ? 'slot-busy' : 'slot-free'; ?>">
                                        <?php if ($isBusy): ?>
                                            Busy
                                        <?php else: ?>
                                            Free
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="margin:12px 0 0 0; font-size:0.9rem; color:#666;">Click one of the 7 dates above to see time slots for that day.</p>
            <?php endif; ?>

            <?php if (!empty($calendarEvents)): ?>
                <div class="cal-section">
                    <h3>Calendar events in this period</h3>
                    <ul>
                        <?php foreach ($calendarEvents as $ev): ?>
                            <?php
                            $start = $ev->getStart()->dateTime ?? $ev->getStart()->date;
                            $end = $ev->getEnd()->dateTime ?? $ev->getEnd()->date;
                            $summary = $ev->getSummary() ?: '(No title)';
                            ?>
                            <li><?php echo htmlspecialchars($summary); ?> — <?php echo htmlspecialchars($start); ?> to <?php echo htmlspecialchars($end); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

        <?php elseif ($selectedDate && count($halls) === 0): ?>
            <p>No halls match the selected building/hall filter.</p>
        <?php else: ?>
            <p>Select a date and click "Show 7 days" to see the week. Then click a date to see time slots.</p>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
