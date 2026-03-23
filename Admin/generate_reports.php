<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/../User/google-client.php';

$msg = '';
$error = '';
$reportUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromDate = trim($_POST['from_date'] ?? '');
    $toDate = trim($_POST['to_date'] ?? '');
    $buildingId = (int)($_POST['building_id'] ?? 0);
    $hallId = (int)($_POST['hall_id'] ?? 0);

    if (!$fromDate || !$toDate) {
        $error = 'Please select start and end date.';
    } else {
        $from = DateTime::createFromFormat('Y-m-d', $fromDate);
        $to = DateTime::createFromFormat('Y-m-d', $toDate);
        if (!$from || !$to || $to < $from) {
            $error = 'Invalid date range.';
        } else {
            $fromStr = $from->format('Y-m-d 00:00:00');
            $toStr = $to->format('Y-m-d 23:59:59');
            $extra = '';
            $extraParams = [];
            if ($hallId > 0) {
                $extra = " AND r.hall_id = ? ";
                $extraParams[] = $hallId;
            } elseif ($buildingId > 0) {
                $extra = " AND r.building_id = ? ";
                $extraParams[] = $buildingId;
            }
            $overlapWhere = " r.start_datetime < ? AND r.end_datetime > ? ";

            // Normal (non-fixed) reservations in window
            $totalRes = $pdo->prepare("SELECT COUNT(*) as c FROM reservations r WHERE " . $overlapWhere . $extra);
            $totalRes->execute(array_merge([$toStr, $fromStr], $extraParams));
            $total = (int)$totalRes->fetch()['c'];

            // Fixed reservations (weekly rules) overlapping the date window
            $fixedExtra = '';
            $fixedParams = [];
            if ($hallId > 0) {
                $fixedExtra = " AND fr.hall_id = ? ";
                $fixedParams[] = $hallId;
            } elseif ($buildingId > 0) {
                $fixedExtra = " AND fr.building_id = ? ";
                $fixedParams[] = $buildingId;
            }
            $fixedTotalStmt = $pdo->prepare("
                SELECT COUNT(*) AS c
                FROM fixed_reservations fr
                WHERE fr.from_date <= ? AND fr.to_date >= ? $fixedExtra
            ");
            $fixedTotalStmt->execute(array_merge([$to->format('Y-m-d'), $from->format('Y-m-d')], $fixedParams));
            $totalFixed = (int)($fixedTotalStmt->fetch()['c'] ?? 0);

            // Status counts (normal reservations)
            $statusCounts = [];
            foreach (['pending_hod','pending_payment','pending_dean','pending_admin','approved','rejected','cancelled'] as $st) {
                $s = $pdo->prepare("SELECT COUNT(*) as c FROM reservations r WHERE r.status = ? AND " . $overlapWhere . $extra);
                $s->execute(array_merge([$st, $toStr, $fromStr], $extraParams));
                $statusCounts[$st] = (int)$s->fetch()['c'];
            }
            $pendingTotal = $statusCounts['pending_hod'] + $statusCounts['pending_payment'] + $statusCounts['pending_dean'] + $statusCounts['pending_admin'];
            $completed = $pdo->prepare("SELECT COUNT(*) as c FROM reservations r WHERE r.status = 'approved' AND r.end_datetime < NOW() AND " . $overlapWhere . $extra);
            $completed->execute(array_merge([$toStr, $fromStr], $extraParams));
            $completedCount = (int)$completed->fetch()['c'];

            // Normal utilization per hall
            $hallUtilSql = "
                SELECT
                    h.hall_id,
                    b.building_name,
                    h.hall_name,
                    COUNT(r.id) as cnt,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime)), 0) as total_minutes
                FROM hall h
                JOIN building b ON b.building_id = h.building_id
                LEFT JOIN reservations r
                    ON r.hall_id = h.hall_id
                   AND r.status IN ('approved','pending_admin','pending_dean','pending_payment','pending_hod')
                   AND r.start_datetime < ?
                   AND r.end_datetime > ?
                " . ($buildingId > 0 ? " WHERE h.building_id = ? " : "") . "
                GROUP BY h.hall_id, b.building_name, h.hall_name
            ";
            $huParams = [$toStr, $fromStr];
            if ($buildingId > 0) $huParams[] = $buildingId;
            $hallUtil = $pdo->prepare($hallUtilSql);
            $hallUtil->execute($huParams);
            $hallUtilData = $hallUtil->fetchAll(PDO::FETCH_ASSOC);

            $normalByHall = [];
            foreach ($hallUtilData as $hu) {
                $hid = (int)$hu['hall_id'];
                $normalByHall[$hid] = [
                    'building_name' => $hu['building_name'],
                    'hall_name'     => $hu['hall_name'],
                    'cnt'           => (int)$hu['cnt'],
                    'minutes'       => (int)($hu['total_minutes'] ?? 0),
                ];
            }

            // Fixed reservations per hall (expanded across date range)
            $fixedByHall = [];
            $fixedInstances = [];
            $fixedSql = "
                SELECT fr.hall_id, fr.day_of_week, fr.start_time, fr.end_time, fr.from_date, fr.to_date,
                       h.hall_name, b.building_name, fr.purpose
                FROM fixed_reservations fr
                JOIN hall h ON h.hall_id = fr.hall_id
                JOIN building b ON b.building_id = fr.building_id
                WHERE fr.from_date <= ? AND fr.to_date >= ?
            ";
            $fsParams = [$to->format('Y-m-d'), $from->format('Y-m-d')];
            if ($hallId > 0) {
                $fixedSql .= " AND fr.hall_id = ? ";
                $fsParams[] = $hallId;
            } elseif ($buildingId > 0) {
                $fixedSql .= " AND fr.building_id = ? ";
                $fsParams[] = $buildingId;
            }
            $fixedStmt = $pdo->prepare($fixedSql);
            $fixedStmt->execute($fsParams);

            $daysInRange = max(1, (int)$from->diff($to)->days);
            $totalMinsAvailable = max(1, $daysInRange * 14 * 60);

            while ($fr = $fixedStmt->fetch(PDO::FETCH_ASSOC)) {
                $hid = (int)$fr['hall_id'];
                if (!isset($fixedByHall[$hid])) {
                    $fixedByHall[$hid] = [
                        'building_name' => $fr['building_name'],
                        'hall_name'     => $fr['hall_name'],
                        'cnt'           => 0,
                        'minutes'       => 0,
                    ];
                }

                $dow       = (int)$fr['day_of_week']; // 1=Mon..7=Sun
                $ruleStart = new DateTime($fr['from_date']);
                $ruleEnd   = new DateTime($fr['to_date']);

                $rangeStart = $from > $ruleStart ? clone $from : $ruleStart;
                $rangeEnd   = $to < $ruleEnd ? clone $to : $ruleEnd;

                $cursor = clone $rangeStart;
                while ($cursor <= $rangeEnd) {
                    if ((int)$cursor->format('N') === $dow) {
                        $startDt = new DateTime($cursor->format('Y-m-d') . ' ' . $fr['start_time']);
                        $endDt   = new DateTime($cursor->format('Y-m-d') . ' ' . $fr['end_time']);
                        $mins = max(0, (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60));
                        if ($mins > 0) {
                            $fixedByHall[$hid]['minutes'] += $mins;
                            $fixedByHall[$hid]['cnt']++;
                            $fixedInstances[] = [
                                $fr['building_name'] . ' ' . $fr['hall_name'],
                                $cursor->format('Y-m-d'),
                                $dow,
                                $fr['start_time'],
                                $fr['end_time'],
                                $fr['purpose'],
                            ];
                        }
                    }
                    $cursor->modify('+1 day');
                }
            }

            // Combined utilization per hall
            $combinedByHall = [];
            $allHallIds = array_unique(array_merge(array_keys($normalByHall), array_keys($fixedByHall)));
            foreach ($allHallIds as $hid) {
                $norm = $normalByHall[$hid] ?? ['building_name'=>'','hall_name'=>'','cnt'=>0,'minutes'=>0];
                $fix  = $fixedByHall[$hid] ?? ['cnt'=>0,'minutes'=>0];
                $minNormal = $norm['minutes'];
                $minFixed  = $fix['minutes'];
                $minTotal  = $minNormal + $minFixed;

                $combinedByHall[$hid] = [
                    'building_name' => $norm['building_name'] ?: ($fix['building_name'] ?? ''),
                    'hall_name'     => $norm['hall_name']     ?: ($fix['hall_name'] ?? ''),
                    'cnt_normal'    => $norm['cnt'],
                    'cnt_fixed'     => $fix['cnt'],
                    'cnt_total'     => $norm['cnt'] + $fix['cnt'],
                    'hours_normal'  => round($minNormal / 60, 1),
                    'hours_fixed'   => round($minFixed / 60, 1),
                    'hours_total'   => round($minTotal / 60, 1),
                    'util_normal'   => $totalMinsAvailable ? round($minNormal / $totalMinsAvailable * 100, 1) : 0,
                    'util_fixed'    => $totalMinsAvailable ? round($minFixed  / $totalMinsAvailable * 100, 1) : 0,
                    'util_total'    => $totalMinsAvailable ? round($minTotal  / $totalMinsAvailable * 100, 1) : 0,
                ];
            }

            // Peak day and top halls still based on normal reservations
            $dayOfWeek = $pdo->prepare("
                SELECT DAYOFWEEK(r.start_datetime) as d, COUNT(*) as c
                FROM reservations r WHERE r.status NOT IN ('cancelled') AND " . $overlapWhere . $extra . "
                GROUP BY d ORDER BY c DESC LIMIT 1
            ");
            $dayOfWeek->execute(array_merge([$toStr, $fromStr], $extraParams));
            $peakDay = $dayOfWeek->fetch();
            $dayNames = [1=>'Sunday',2=>'Monday',3=>'Tuesday',4=>'Wednesday',5=>'Thursday',6=>'Friday',7=>'Saturday'];
            $peakDayName = $peakDay ? ($dayNames[$peakDay['d']] ?? '') : '-';

            $topHalls = $pdo->prepare("
                SELECT h.hall_name, COUNT(r.id) as cnt
                FROM reservations r
                JOIN hall h ON r.hall_id = h.hall_id
                WHERE r.status NOT IN ('cancelled') AND " . $overlapWhere . ($buildingId > 0 ? " AND r.building_id = ? " : "") . "
                GROUP BY r.hall_id ORDER BY cnt DESC LIMIT 10
            ");
            $topHalls->execute($buildingId > 0 ? [$toStr, $fromStr, $buildingId] : [$toStr, $fromStr]);
            $topHallsData = $topHalls->fetchAll(PDO::FETCH_ASSOC);

            // Send to Google Sheets
            $googleClient = getGoogleClientWithToken();
            if (!($googleClient instanceof Google_Client)) {
                $error = 'Google OAuth not connected. Connect via User page (OAuth) to enable report generation.';
            } else {
                try {
                    $sheetsService = new Google_Service_Sheets($googleClient);
                    $spreadsheet = new Google_Service_Sheets_Spreadsheet([
                        'properties' => ['title' => 'Hall Reservation Report ' . $fromDate . ' to ' . $toDate],
                    ]);
                    $spreadsheet = $sheetsService->spreadsheets->create($spreadsheet);
                    $spreadsheetId = $spreadsheet->getSpreadsheetId();

                    // Make the sheet editable/viewable by anyone with the link
                    try {
                        $driveService = new Google_Service_Drive($googleClient);
                        $perm = new Google_Service_Drive_Permission([
                            'type' => 'anyone',
                            'role' => 'writer',
                            'allowFileDiscovery' => false,
                        ]);
                        $driveService->permissions->create($spreadsheetId, $perm, ['sendNotificationEmail' => false]);
                    } catch (Throwable $permEx) {
                        // If permission setting fails, still keep the sheet; user may adjust sharing manually.
                    }

                    $overallData = [
                        ['=== Overall System Usage ==='],
                        ['Metric', 'Value'],
                        ['Total Normal Reservations', $total],
                        ['Total Fixed Reservations (timetable)', $totalFixed],
                        ['Total Reservations (incl. fixed)', $total + $totalFixed],
                        ['Total Approved', $statusCounts['approved']],
                        ['Total Rejected', $statusCounts['rejected']],
                        ['Total Cancelled', $statusCounts['cancelled']],
                        ['Total Pending', $pendingTotal],
                        ['Total Completed (past)', $completedCount],
                        [],
                        ['=== Normal Reservations per Hall ==='],
                        ['Hall', 'Reservations', 'Hours Booked', 'Utilization %'],
                    ];
                    foreach ($combinedByHall as $row) {
                        if ($row['cnt_normal'] <= 0) continue;
                        $overallData[] = [
                            trim($row['building_name'] . ' ' . $row['hall_name']),
                            $row['cnt_normal'],
                            $row['hours_normal'],
                            $row['util_normal'] / 100,
                        ];
                    }
                    $overallData[] = [];
                    $overallData[] = ['=== Fixed Reservations per Hall ==='];
                    $overallData[] = ['Hall', 'Fixed Reservations', 'Fixed Hours', 'Fixed Utilization %'];
                    foreach ($combinedByHall as $row) {
                        if ($row['cnt_fixed'] <= 0) continue;
                        $overallData[] = [
                            trim($row['building_name'] . ' ' . $row['hall_name']),
                            $row['cnt_fixed'],
                            $row['hours_fixed'],
                            $row['util_fixed'] / 100,
                        ];
                    }
                    $overallData[] = [];
                    $overallData[] = ['=== Combined Hall Utilization ==='];
                    $overallData[] = ['Hall', 'Total Reservations', 'Total Hours', 'Total Utilization %'];
                    $combinedHeaderRow = count($overallData);
                    $combinedCount = 0;
                    foreach ($combinedByHall as $row) {
                        if ($row['cnt_total'] <= 0) continue;
                        $overallData[] = [
                            trim($row['building_name'] . ' ' . $row['hall_name']),
                            $row['cnt_total'],
                            $row['hours_total'],
                            $row['util_total'] / 100,
                        ];
                        $combinedCount++;
                    }
                    // Detailed normal reservations (using reserved start/end datetimes)
                    $overallData[] = [];
                    $overallData[] = ['=== Normal Reservation Details ==='];
                    $overallData[] = ['Hall', 'Start Datetime', 'End Datetime', 'Status', 'Purpose'];
                    $detailSql = "
                        SELECT b.building_name, h.hall_name, r.start_datetime, r.end_datetime, r.status, r.purpose
                        FROM reservations r
                        JOIN hall h ON r.hall_id = h.hall_id
                        JOIN building b ON b.building_id = r.building_id
                        WHERE r.start_datetime < ? AND r.end_datetime > ?
                          " . ($hallId > 0 ? " AND r.hall_id = ? " : ($buildingId > 0 ? " AND r.building_id = ? " : "")) . "
                        ORDER BY r.start_datetime
                    ";
                    $detailParams = [$toStr, $fromStr];
                    if ($hallId > 0) {
                        $detailParams[] = $hallId;
                    } elseif ($buildingId > 0) {
                        $detailParams[] = $buildingId;
                    }
                    $detailStmt = $pdo->prepare($detailSql);
                    $detailStmt->execute($detailParams);
                    while ($row = $detailStmt->fetch(PDO::FETCH_ASSOC)) {
                        $overallData[] = [
                            trim($row['building_name'] . ' ' . $row['hall_name']),
                            $row['start_datetime'],
                            $row['end_datetime'],
                            $row['status'],
                            $row['purpose'],
                        ];
                    }

                    $overallData[] = [];
                    $overallData[] = ['=== Peak Usage & Top Halls ==='];
                    $overallData[] = ['Most booked day of week', $peakDayName];
                    $overallData[] = [];
                    $overallData[] = ['Top Requested Halls', 'Reservations'];
                    foreach ($topHallsData as $th) {
                        $overallData[] = [$th['hall_name'], (int)$th['cnt']];
                    }

                    $body = new Google_Service_Sheets_BatchUpdateValuesRequest([
                        // RAW ensures nothing is interpreted as a formula, avoiding "Formula parse error"
                        'valueInputOption' => 'RAW',
                        'data' => [new Google_Service_Sheets_ValueRange(['range' => 'Sheet1!A1', 'values' => $overallData])],
                    ]);
                    $sheetsService->spreadsheets_values->batchUpdate($spreadsheetId, $body);

                    $reportUrl = 'https://docs.google.com/spreadsheets/d/' . $spreadsheetId;
                    $msg = 'Report generated. <a href="' . htmlspecialchars($reportUrl) . '" target="_blank">Open in Google Sheets</a>.';
                } catch (Throwable $e) {
                    $error = 'Failed to create report: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

$buildings = $pdo->query("SELECT building_id, building_name FROM building ORDER BY building_name")->fetchAll(PDO::FETCH_ASSOC);
$halls = $pdo->query("SELECT hall_id, hall_name, building_id FROM hall ORDER BY hall_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Generate Reports</title>
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
            <a href="generate_reports.php" class="nav-link active">Generate Reports</a>
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

    <?php if ($error): ?><div class="errors"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>

    <div class="admin-card">
        <h2>Generate Reports</h2>
        <p>Generate a report with overall usage, hall utilization, peak usage, and top halls. Uses Google Sheets with graphical representations.</p>
        <form method="post">
            <div class="form-group">
                <label>From Date</label>
                <input type="date" name="from_date" required>
            </div>
            <div class="form-group">
                <label>To Date</label>
                <input type="date" name="to_date" required>
            </div>
            <div class="form-group">
                <label>Building (optional)</label>
                <select name="building_id" id="rep_building">
                    <option value="">All</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['building_id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Hall (optional)</label>
                <select name="hall_id" id="rep_hall">
                    <option value="">All</option>
                    <?php foreach ($halls as $h): ?>
                        <option value="<?= (int)$h['hall_id'] ?>" data-building="<?= (int)$h['building_id'] ?>"><?= htmlspecialchars($h['hall_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-primary">Generate Report</button>
        </form>
    </div>
</div>
<script>
document.getElementById('rep_building').addEventListener('change', function(){
    const bid = this.value;
    document.querySelectorAll('#rep_hall option[data-building]').forEach(o => {
        o.style.display = !bid || o.dataset.building === bid ? '' : 'none';
    });
});
</script>
</body>
</html>
