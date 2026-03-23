<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/../User/google-client.php';

$msg = '';
$error = '';

$DAY_MAP = [
    'monday' => 1, 'mon' => 1,
    'tuesday' => 2, 'tue' => 2, 'tues' => 2,
    'wednesday' => 3, 'wed' => 3,
    'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
    'friday' => 5, 'fri' => 5,
    'saturday' => 6, 'sat' => 6,
    'sunday' => 7, 'sun' => 7,
];

function parseDayOfWeek($val) {
    global $DAY_MAP;
    $v = strtolower(trim((string)$val));
    return $DAY_MAP[$v] ?? null;
}

function parseTime($val) {
    $val = trim((string)$val);
    // Handles "0800h-0850h" / "0800h" / "0800"
    if (preg_match('/(\d{4})h(?:\s*[-–]\s*(\d{4})h)?/i', $val, $m)) {
        $start = $m[1];
        return sprintf('%02d:%02d', (int)substr($start, 0, 2), (int)substr($start, 2, 2));
    }
    if (preg_match('/^(\d{4})$/', $val, $m)) {
        return sprintf('%02d:%02d', (int)substr($m[1], 0, 2), (int)substr($m[1], 2, 2));
    }
    if (preg_match('/(\d{1,2}):(\d{2})/', $val, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    if (preg_match('/(\d{1,2})\s*-\s*(\d{1,2})/', $val, $m)) {
        return sprintf('%02d:00', (int)$m[1]);
    }
    return null;
}

function parseEndTime($val, $startTime) {
    $val = trim((string)$val);
    // Handles "0800h-0850h"
    if (preg_match('/\d{4}h\s*[-–]\s*(\d{4})h/i', $val, $m)) {
        $end = $m[1];
        return sprintf('%02d:%02d', (int)substr($end, 0, 2), (int)substr($end, 2, 2));
    }
    if (preg_match('/(\d{1,2}):(\d{2})/', $val, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    if (preg_match('/(\d{1,2})\s*-\s*(\d{1,2})/', $val, $m)) {
        return sprintf('%02d:00', (int)$m[2]);
    }
    if ($startTime) {
        $dt = DateTime::createFromFormat('H:i', $startTime);
        if ($dt) {
            $dt->modify('+1 hour');
            return $dt->format('H:i');
        }
    }
    return null;
}

function normalizeCellText(string $text): string {
    $text = str_replace("\r", "", $text);
    // Keep newlines but normalize spacing
    $text = preg_replace('/[\\t ]+/', ' ', $text);
    $text = preg_replace('/\\n{3,}/', "\n\n", $text);
    return trim($text);
}

function isDayHeadingCell(string $cell): ?string {
    $cell = trim($cell);
    if ($cell === '') return null;
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($days as $d) {
        if (stripos($cell, $d) === 0) return $d;
    }
    return null;
}

function parseTimetableMatrix(array $values, array $hallLookup): array {
    $schedule = [];

    // Step 1: find hall header row (often contains "Lecture hall" or hall-like codes)
    $hallRowIndex = null;
    foreach ($values as $idx => $row) {
        if (!is_array($row) || count($row) < 2) continue;
        $rowStr = strtolower(implode(' ', array_slice($row, 0, min(8, count($row)))));
        if (strpos($rowStr, 'lecture hall') !== false || preg_match('/\b[a-z]\d+\s*\d{3}\b/i', $rowStr)) {
            $hallRowIndex = $idx;
            break;
        }
        $matches = 0;
        foreach ($row as $cell) {
            $c = trim((string)$cell);
            if ($c === '') continue;
            if (preg_match('/\b[a-z]\d+\s*\d{3}\b/i', $c)) $matches++;
        }
        if ($matches >= 3) {
            $hallRowIndex = $idx;
            break;
        }
    }
    if ($hallRowIndex === null) {
        throw new RuntimeException('Could not find hall names row (merged header row not detected).');
    }

    // Step 2: extract hall names by column position.
    // Common master timetable layout with merged cells:
    // - Row R: building codes merged across several hall columns (e.g., "A11")
    // - Row R+1: hall numbers (e.g., "207", "208")
    // After merge expansion, row R repeats "A11" across all those columns.
    $halls = []; // colIndex => "A11 207" (first occurrence per hall)
    $headerRow = $values[$hallRowIndex] ?? [];
    $headerRow2 = $values[$hallRowIndex + 1] ?? [];

    $buildingLikeCount = 0;
    $threeDigitCountRow1 = 0;
    $threeDigitCountRow2 = 0;
    for ($c = 0; $c < max(count($headerRow), count($headerRow2)); $c++) {
        $v1 = trim((string)($headerRow[$c] ?? ''));
        $v2 = trim((string)($headerRow2[$c] ?? ''));
        if ($v1 !== '' && preg_match('/^[A-Z]{1,3}\d{1,3}$/i', $v1)) $buildingLikeCount++;
        if ($v1 !== '' && preg_match('/^\d{3}$/', $v1)) $threeDigitCountRow1++;
        if ($v2 !== '' && preg_match('/^\d{3}$/', $v2)) $threeDigitCountRow2++;
    }
    $looksLikeSplitHeader = ($buildingLikeCount >= 3 && $threeDigitCountRow2 > $threeDigitCountRow1);

    for ($col = 0; $col < max(count($headerRow), count($headerRow2)); $col++) {
        $v1 = trim((string)($headerRow[$col] ?? ''));
        $v2 = trim((string)($headerRow2[$col] ?? ''));

        $hallName = '';
        if ($looksLikeSplitHeader) {
            // Combine building + hall number when possible.
            if ($v1 !== '' && $v2 !== '' && preg_match('/^\d{2,4}$/', $v2)) {
                $hallName = preg_replace('/\s+/', ' ', $v1 . ' ' . $v2);
            } else {
                $hallName = $v1;
            }
        } else {
            $hallName = $v1;
        }

        $hallName = trim(preg_replace('/\s+/', ' ', (string)$hallName));
        if ($hallName === '' || stripos($hallName, 'lecture hall') !== false || stripos($hallName, 'hall') === 0) {
            continue;
        }
        if (preg_match('/^time$/i', $hallName) || preg_match('/^day$/i', $hallName)) {
            continue;
        }

        $hallKeyCompact = strtolower(preg_replace('/[^a-z0-9]/i', '', $hallName));
        if (!in_array($hallName, $halls, true)) {
            $halls[$col] = $hallName;
        }
    }
    if (empty($halls)) {
        throw new RuntimeException('Could not extract hall columns from header row.');
    }

    // Step 3: find where timetable data begins (after a row containing "Time")
    $dataStartIndex = null;
    for ($i = $hallRowIndex + 1; $i < count($values); $i++) {
        $row = $values[$i] ?? [];
        $first = strtolower(trim((string)($row[0] ?? '')));
        $second = strtolower(trim((string)($row[1] ?? '')));
        if (strpos($first, 'time') !== false || strpos($second, 'time') !== false) {
            $dataStartIndex = $i + 1;
            break;
        }
    }
    if ($dataStartIndex === null) {
        // Fallback: start right after hall row
        $dataStartIndex = $hallRowIndex + 1;
    }

    // Step 4: parse rows - day headings + time slots
    $currentDay = null;
    for ($rowIdx = $dataStartIndex; $rowIdx < count($values); $rowIdx++) {
        $row = $values[$rowIdx] ?? [];
        if (!is_array($row) || count($row) === 0) continue;

        $cell0 = trim((string)($row[0] ?? ''));
        $cell1 = trim((string)($row[1] ?? ''));

        // With merged cells expanded, the Day cell (e.g. "Monday") can repeat on every time row.
        // So: set currentDay if detected, but do NOT automatically skip the row.
        $dayFrom0 = isDayHeadingCell($cell0);
        $dayFrom1 = isDayHeadingCell($cell1);
        if ($dayFrom0) $currentDay = $dayFrom0;
        if (!$currentDay && $dayFrom1) $currentDay = $dayFrom1;

        // time slot is usually in first or second column; pick the one that parses.
        // If cell0 is a Day, prefer parsing time from cell1 first.
        $startTime = null;
        $timeCell = '';
        if ($dayFrom0) {
            $startTime = parseTime($cell1);
            $timeCell = $cell1;
            if (!$startTime) {
                $startTime = parseTime($cell0);
                $timeCell = $cell0;
            }
        } else {
            $startTime = parseTime($cell0);
            $timeCell = $cell0;
            if (!$startTime) {
                $startTime = parseTime($cell1);
                $timeCell = $cell1;
            }
        }
        if (!$currentDay || !$startTime) {
            continue;
        }
        $endTime = parseEndTime($timeCell, $startTime);
        if (!$endTime) continue;

        $dow = parseDayOfWeek($currentDay);
        if (!$dow) continue;

        foreach ($halls as $col => $hallName) {
            $cellTextRaw = (string)($row[$col] ?? '');
            $cellText = normalizeCellText($cellTextRaw);
            if ($cellText === '') continue;

            // Match hall name to DB hall
            $hallKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $hallName)); // "A11 207" => "a11207"
            $hallRowDb = $hallLookup[$hallKey] ?? $hallLookup[strtolower(trim($hallName))] ?? null;
            if (!$hallRowDb) {
                foreach ($hallLookup as $hk => $h) {
                    if ($hk === '') continue;
                    $dbCombined = trim((string)($h['building_name'] ?? '')) . ' ' . trim((string)($h['hall_name'] ?? ''));
                    if (stripos($hallName, $dbCombined) !== false || stripos($dbCombined, $hallName) !== false) {
                        $hallRowDb = $h;
                        break;
                    }
                    if (stripos($hallName, (string)$h['hall_name']) !== false || stripos((string)$h['hall_name'], $hallName) !== false) {
                        $hallRowDb = $h;
                        break;
                    }
                }
            }
            if (!$hallRowDb) continue;

            $schedule[] = [
                'day_of_week' => $dow,
                'building_id' => $hallRowDb['building_id'],
                'hall_id' => $hallRowDb['hall_id'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'purpose' => $cellText,
                'hall_name' => trim((string)($hallRowDb['building_name'] ?? '')) !== ''
                    ? (trim((string)$hallRowDb['building_name']) . ' ' . trim((string)$hallRowDb['hall_name']))
                    : $hallName,
                'day' => $currentDay,
            ];
        }
    }

    return $schedule;
}

function gridValueToString($cell): string {
    if (!($cell instanceof Google_Service_Sheets_CellData)) return '';
    $formatted = $cell->getFormattedValue();
    if ($formatted !== null && $formatted !== '') return (string)$formatted;
    $ev = $cell->getEffectiveValue();
    if (!is_array($ev)) return '';
    if (isset($ev['stringValue'])) return (string)$ev['stringValue'];
    if (isset($ev['numberValue'])) return (string)$ev['numberValue'];
    if (isset($ev['boolValue'])) return $ev['boolValue'] ? 'TRUE' : 'FALSE';
    if (isset($ev['formulaValue'])) return (string)$ev['formulaValue'];
    return '';
}

function colIndexToLetters(int $colCount): string {
    // 1 => A, 26 => Z, 27 => AA ...
    $n = max(1, $colCount);
    $s = '';
    while ($n > 0) {
        $n--; // 0-based
        $s = chr(($n % 26) + 65) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

function sheetsGetMatrixWithMergedValues(Google_Service_Sheets $sheets, string $spreadsheetId, int $maxRows = 250, int $maxCols = 60): array {
    // Fetch first sheet grid data (includes merged regions), then "fill" merged blanks.
    $ss = $sheets->spreadsheets->get($spreadsheetId, [
        'includeGridData' => true,
        'ranges' => ['A1:' . colIndexToLetters($maxCols) . $maxRows],
    ]);
    $sheet = $ss->getSheets()[0] ?? null;
    if (!$sheet) {
        throw new RuntimeException('No sheets found after conversion.');
    }

    $data = $sheet->getData()[0] ?? null;
    $rowData = $data ? $data->getRowData() : null;
    if (!is_array($rowData)) $rowData = [];

    $matrix = [];
    for ($r = 0; $r < $maxRows; $r++) {
        $matrix[$r] = array_fill(0, $maxCols, '');
    }

    for ($r = 0; $r < min($maxRows, count($rowData)); $r++) {
        $rd = $rowData[$r];
        if (!($rd instanceof Google_Service_Sheets_RowData)) continue;
        $cells = $rd->getValues();
        if (!is_array($cells)) continue;
        for ($c = 0; $c < min($maxCols, count($cells)); $c++) {
            $matrix[$r][$c] = trim(gridValueToString($cells[$c]));
        }
    }

    // Apply merged regions (propagate top-left cell value across the merge if blanks)
    $merges = $sheet->getMerges();
    if (is_array($merges)) {
        foreach ($merges as $merge) {
            if (!($merge instanceof Google_Service_Sheets_GridRange)) continue;
            $sr = (int)($merge->getStartRowIndex() ?? 0);
            $er = (int)($merge->getEndRowIndex() ?? 0);
            $sc = (int)($merge->getStartColumnIndex() ?? 0);
            $ec = (int)($merge->getEndColumnIndex() ?? 0);
            if ($sr < 0 || $sc < 0) continue;
            if ($sr >= $maxRows || $sc >= $maxCols) continue;
            $er = min($er, $maxRows);
            $ec = min($ec, $maxCols);
            $topLeft = $matrix[$sr][$sc] ?? '';
            if ($topLeft === '') continue;
            for ($r = $sr; $r < $er; $r++) {
                for ($c = $sc; $c < $ec; $c++) {
                    if (($matrix[$r][$c] ?? '') === '') {
                        $matrix[$r][$c] = $topLeft;
                    }
                }
            }
        }
    }

    // Trim trailing empty rows/cols for easier parsing
    $lastNonEmptyRow = -1;
    $lastNonEmptyCol = -1;
    for ($r = 0; $r < $maxRows; $r++) {
        for ($c = 0; $c < $maxCols; $c++) {
            if (trim((string)$matrix[$r][$c]) !== '') {
                $lastNonEmptyRow = max($lastNonEmptyRow, $r);
                $lastNonEmptyCol = max($lastNonEmptyCol, $c);
            }
        }
    }
    if ($lastNonEmptyRow < 0 || $lastNonEmptyCol < 0) {
        return [];
    }
    $trimmed = [];
    for ($r = 0; $r <= $lastNonEmptyRow; $r++) {
        $trimmed[$r] = array_slice($matrix[$r], 0, $lastNonEmptyCol + 1);
    }
    return $trimmed;
}

function firstDateForDow(DateTime $from, int $dow): DateTime {
    $d = clone $from;
    while ((int)$d->format('N') !== $dow) {
        $d->modify('+1 day');
    }
    return $d;
}

function rruleUntilUtc(string $toDateYmd, string $timeZone = 'Asia/Colombo'): string {
    // Build UNTIL in UTC for RRULE. Use end-of-day in the given timezone.
    $dt = new DateTime($toDateYmd . ' 23:59:59', new DateTimeZone($timeZone));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Ymd\\THis\\Z');
}

try {
    $halls = $pdo->query("
        SELECT
            h.hall_id,
            h.hall_name,
            h.building_id,
            b.building_name
        FROM hall h
        LEFT JOIN building b ON b.building_id = h.building_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $hallLookup = [];
    foreach ($halls as $h) {
        $buildingName = strtolower(trim((string)($h['building_name'] ?? '')));
        $hallName = strtolower(trim((string)($h['hall_name'] ?? '')));

        // By hall only: "207"
        if ($hallName !== '') {
            $hallLookup[$hallName] = $h;
            $hallLookup[preg_replace('/[^a-z0-9]/i', '', $hallName)] = $h;
        }

        // By building only: "a11" (less specific; keep but don't overwrite existing)
        if ($buildingName !== '' && !isset($hallLookup[$buildingName])) {
            $hallLookup[$buildingName] = $h;
        }

        // Combined: "a11 207" => "a11207"
        if ($buildingName !== '' && $hallName !== '') {
            $combined = $buildingName . ' ' . $hallName;
            $combinedCompact = preg_replace('/[^a-z0-9]/i', '', $combined);
            $hallLookup[$combined] = $h;
            $hallLookup[$combinedCompact] = $h;
            $hallLookup[$buildingName . $hallName] = $h;
        }
    }
} catch (PDOException $e) {
    $halls = [];
    $hallLookup = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && isset($_FILES['timetable']) && $_FILES['timetable']['error'] === UPLOAD_ERR_OK) {
    $fromDate = trim($_POST['from_date'] ?? '');
    $toDate = trim($_POST['to_date'] ?? '');
    if (!$fromDate || !$toDate) {
        $error = 'Please provide start and end date for the reservation period.';
    } else {
        $from = DateTime::createFromFormat('Y-m-d', $fromDate);
        $to = DateTime::createFromFormat('Y-m-d', $toDate);
        if (!$from || !$to || $to < $from) {
            $error = 'Invalid date range.';
        } else {
            $tmpPath = $_FILES['timetable']['tmp_name'];
            try {
                $googleClient = getGoogleClientWithToken();
                if (!($googleClient instanceof Google_Client)) {
                    throw new RuntimeException('Google is not connected. Please complete OAuth first (User -> Google OAuth) and then re-try.');
                }

                $originalName = (string)($_FILES['timetable']['name'] ?? 'timetable.xlsx');
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls'], true)) {
                    throw new RuntimeException('Unsupported file type. Please upload .xlsx or .xls.');
                }

                $uploadMime = $ext === 'xls'
                    ? 'application/vnd.ms-excel'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

                // Drive: upload + convert to Google Sheets
                $drive = new Google_Service_Drive($googleClient);
                $driveFile = new Google_Service_Drive_DriveFile([
                    'name' => 'Timetable Upload - ' . date('Y-m-d H:i:s'),
                    'mimeType' => 'application/vnd.google-apps.spreadsheet',
                ]);
                $created = $drive->files->create($driveFile, [
                    'data' => file_get_contents($tmpPath),
                    'mimeType' => $uploadMime,
                    'uploadType' => 'multipart',
                    'fields' => 'id',
                ]);
                $sheetId = $created->id ?? null;
                if (!$sheetId) {
                    throw new RuntimeException('Drive upload failed (no file id).');
                }

                // Sheets: read grid data + merged regions, then expand merged values
                $sheets = new Google_Service_Sheets($googleClient);
                $values = sheetsGetMatrixWithMergedValues($sheets, $sheetId, 300, 60);
                if (!is_array($values) || count($values) === 0) {
                    throw new RuntimeException('No readable cells found in the uploaded file after conversion.');
                }

                // Parse complex matrix structure (days/time in rows, halls in columns)
                $scheduleEntries = parseTimetableMatrix($values, $hallLookup);
                if (empty($scheduleEntries)) {
                    throw new RuntimeException('No valid timetable entries found. Check hall names (must match DB) and ensure time/day rows are present.');
                }

                // Create table if needed (still used by Fixed Reservations page)
                $pdo->exec("
                        CREATE TABLE IF NOT EXISTS fixed_reservations (
                            id int(11) NOT NULL AUTO_INCREMENT,
                            day_of_week tinyint(1) NOT NULL,
                            building_id int(2) UNSIGNED NOT NULL,
                            hall_id int(2) UNSIGNED NOT NULL,
                            start_time time NOT NULL,
                            end_time time NOT NULL,
                            purpose text NOT NULL,
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

                // Ensure purpose can store full cell text (older installs may be varchar(255)).
                try {
                    $pdo->exec("ALTER TABLE fixed_reservations MODIFY purpose TEXT NOT NULL");
                } catch (Throwable $ex) {}

                $ins = $pdo->prepare("INSERT INTO fixed_reservations (day_of_week, building_id, hall_id, start_time, end_time, purpose, from_date, to_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                // Duplicate = same day_of_week + start_time + end_time + purpose (any hall/date range)
                $dupeCheck = $pdo->prepare("
                    SELECT 1
                    FROM fixed_reservations
                    WHERE day_of_week = ?
                      AND start_time = ?
                      AND end_time = ?
                      AND purpose = ?
                    LIMIT 1
                ");

                $calCount = 0;
                $skippedDupes = 0;
                $calendarService = new Google_Service_Calendar($googleClient);
                $inserted = 0;
                $pdo->beginTransaction();

                $until = rruleUntilUtc($toDate, 'Asia/Colombo');
                foreach ($scheduleEntries as $entry) {
                    $dupeCheck->execute([
                        $entry['day_of_week'],
                        $entry['start_time'],
                        $entry['end_time'],
                        $entry['purpose'],
                    ]);
                    if ($dupeCheck->fetchColumn()) {
                        $skippedDupes++;
                        continue;
                    }

                    $ins->execute([
                        $entry['day_of_week'],
                        $entry['building_id'],
                        $entry['hall_id'],
                        $entry['start_time'],
                        $entry['end_time'],
                        $entry['purpose'],
                        $fromDate,
                        $toDate
                    ]);
                    $inserted++;

                    $first = firstDateForDow($from, (int)$entry['day_of_week']);
                    $startDt = $first->format('Y-m-d') . ' ' . $entry['start_time'];
                    $endDt = $first->format('Y-m-d') . ' ' . $entry['end_time'];

                    try {
                        $summary = $entry['purpose'];
                        if (mb_strlen($summary) > 180) {
                            $summary = mb_substr($summary, 0, 180) . '…';
                        }
                        $event = new Google_Service_Calendar_Event([
                            'summary' => $summary,
                            'description' => 'Fixed Timetable Reservation - ' . ($entry['hall_name'] ?? ''),
                            'start' => ['dateTime' => $startDt, 'timeZone' => 'Asia/Colombo'],
                            'end' => ['dateTime' => $endDt, 'timeZone' => 'Asia/Colombo'],
                            'recurrence' => ["RRULE:FREQ=WEEKLY;UNTIL={$until}"],
                        ]);
                        $calendarService->events->insert('primary', $event);
                        $calCount++;
                    } catch (Throwable $ex) {
                        // keep going
                    }
                }

                $pdo->commit();
                $msg = $inserted . ' fixed reservations created. ' . $skippedDupes . ' duplicates skipped. ' . $calCount . ' recurring calendar events added.';

                // Cleanup: delete converted spreadsheet from Drive (optional; ignore failures)
                try { $drive->files->delete($sheetId); } catch (Throwable $ex) {}
            } catch (Throwable $e) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error parsing Excel: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Timetable</title>
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
                    <a href="upload_timetable.php" class="nav-link active">Upload Timetable</a>
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
    <?php if ($msg): ?><div class="success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="admin-card">
        <h2>Upload Timetable (Excel)</h2>
        <p>Upload the master timetable Excel file (.xlsx or .xls). The system converts it to Google Sheets, expands merged cells, parses the matrix (days/time in rows, halls in columns), and creates fixed reservations for the selected date range.</p>
        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label>Excel File</label>
                <input type="file" name="timetable" accept=".xlsx,.xls" required>
            </div>
            <div class="form-group">
                <label>Reservation From Date</label>
                <input type="date" name="from_date" required>
            </div>
            <div class="form-group">
                <label>Reservation To Date</label>
                <input type="date" name="to_date" required>
            </div>
            <button type="submit" class="btn-primary">Upload & Create Fixed Reservations</button>
        </form>
        <p style="margin-top:16px;font-size:0.9rem;color:#666;">
            Expected structure: a hall header row (often contains “Lecture hall” / hall codes), then day headings (Monday/Tuesday/...) and time slots like “0800h-0850h”. Merged cells are supported.
        </p>
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
