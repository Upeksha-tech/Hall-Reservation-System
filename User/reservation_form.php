<?php
session_start();

// TODO: adjust this path to where your db.php actually lives
require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/google-client.php';

// Simple auth guard – assume you have a login system that sets these
if (!isset($_SESSION['user_id'])) {
    header('Location: /../Login/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Fetch buildings for dropdown
try {
    $stmt = $pdo->query("SELECT building_id, building_name FROM building ORDER BY building_name");
    $buildings = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    echo "Error loading buildings.";
    exit;
}

// Handle form submission
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name'] ?? '');
    $studentNo      = trim($_POST['student_no'] ?? '');
    $mobile         = trim($_POST['mobile'] ?? '');
    $department     = trim($_POST['department'] ?? '');
    $hodEmail       = trim($_POST['hod_email'] ?? '');
    $purpose        = trim($_POST['purpose'] ?? '');
    $buildingId     = (int) ($_POST['building_id'] ?? 0);
    $hallId         = (int) ($_POST['hall_id'] ?? 0);
    $startDateTime  = trim($_POST['start_datetime'] ?? '');
    $endDateTime    = trim($_POST['end_datetime'] ?? '');
    $termsAccepted  = isset($_POST['terms']);

    // Basic validations
    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if ($mobile === '') {
        $errors[] = 'Mobile number is required.';
    }
    if ($department === '') {
        $errors[] = 'Department / Association is required.';
    }
    if (!filter_var($hodEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid HOD / Senior Treasurer email is required.';
    }
    if ($purpose === '') {
        $errors[] = 'Purpose of request is required.';
    }
    if ($buildingId <= 0) {
        $errors[] = 'Please select a building.';
    }
    if ($hallId <= 0) {
        $errors[] = 'Please select a hall.';
    }
    if ($startDateTime === '' || $endDateTime === '') {
        $errors[] = 'Start and end date/time are required.';
    }
    if (!$termsAccepted) {
        $errors[] = 'You must agree to the terms and conditions.';
    }

    // Parse and validate date/time
    $start = DateTime::createFromFormat('Y-m-d\TH:i', $startDateTime);
    $end   = DateTime::createFromFormat('Y-m-d\TH:i', $endDateTime);

    if (!$start || !$end) {
        $errors[] = 'Invalid date/time format.';
    } elseif ($end <= $start) {
        $errors[] = 'End time must be after start time.';
    } else {
        // Must be between 8:00 and 22:00, all 7 days
        $allowedStartHour = 8;
        $allowedEndHour   = 22;

        if ((int)$start->format('H') < $allowedStartHour || (int)$end->format('H') >= $allowedEndHour + 1) {
            $errors[] = 'Reservations are allowed only between 8:00 a.m. and 10:00 p.m.';
        }
    }

    // Public holiday: no longer blocks submission; client shows a confirmation dialog instead.

    // Payment slip is submitted later via payment page after HOD approval (not required on initial submit).

    // Block double booking and insert reservation
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Check for overlapping reservations (including pending) for same hall (DB-level blocking)
            $overlapSql = "
                SELECT COUNT(*) AS cnt
                FROM reservations
                WHERE hall_id = :hall_id
                  AND status NOT IN ('cancelled', 'rejected')
                  AND (
                        (start_datetime < :end_dt AND end_datetime > :start_dt)
                      )
            ";
            $overlapStmt = $pdo->prepare($overlapSql);
            $overlapStmt->execute([
                ':hall_id'  => $hallId,
                ':start_dt' => $start->format('Y-m-d H:i:s'),
                ':end_dt'   => $end->format('Y-m-d H:i:s'),
            ]);
            $overlap = $overlapStmt->fetch();
            if ($overlap && (int)$overlap['cnt'] > 0) {
                $pdo->rollBack();
                $errors[] = 'Selected time slot is already reserved or pending approval.';
            } else {
                // Block fixed reservations (weekly rules stored in DB)
                try {
                    $dow = (int)$start->format('N'); // 1=Mon..7=Sun
                    $dateYmd = $start->format('Y-m-d');
                    $frStmt = $pdo->prepare("
                        SELECT COUNT(*) AS cnt
                        FROM fixed_reservations
                        WHERE hall_id = :hall_id
                          AND day_of_week = :dow
                          AND from_date <= :d
                          AND to_date >= :d
                          AND start_time < :end_t
                          AND end_time > :start_t
                    ");
                    $frStmt->execute([
                        ':hall_id' => $hallId,
                        ':dow' => $dow,
                        ':d' => $dateYmd,
                        ':start_t' => $start->format('H:i:s'),
                        ':end_t' => $end->format('H:i:s'),
                    ]);
                    $fr = $frStmt->fetch();
                    if ($fr && (int)$fr['cnt'] > 0) {
                        $pdo->rollBack();
                        $errors[] = 'Selected time slot is already blocked by a fixed timetable reservation.';
                    }
                } catch (Throwable $e) {
                    // ignore if fixed_reservations doesn't exist
                }

                // Additionally, check Google Calendar free/busy on the connected account (primary calendar)
                $googleClient = getGoogleClientWithToken();
                if ($googleClient instanceof Google_Client) {
                    try {
                        $calendarService = new Google_Service_Calendar($googleClient);
                        $freeBusyRequest = new Google_Service_Calendar_FreeBusyRequest([
                            'timeMin' => $start->format(DateTime::RFC3339),
                            'timeMax' => $end->format(DateTime::RFC3339),
                            'items'   => [['id' => 'primary']],
                        ]);
                        $freeBusy = $calendarService->freebusy->query($freeBusyRequest);
                        $calendars = $freeBusy->getCalendars();
                        if (isset($calendars['primary'])) {
                            $busySlots = $calendars['primary']['busy'] ?? [];
                            if (!empty($busySlots)) {
                                $pdo->rollBack();
                                $errors[] = 'Selected time slot is already busy on the central calendar.';
                            }
                        }
                    } catch (Throwable $e) {
                        // If Google Calendar fails, continue with DB-based blocking only
                    }
                }
            }

            if (empty($errors)) {
                $insertSql = "
                    INSERT INTO reservations
                        (user_id, user_email, name, student_no, mobile, department, hod_email, purpose, building_id, hall_id,
                         start_datetime, end_datetime, payment_slip_path, status, hod_approved_at, dean_approved_at, admin_approved_at, created_at)
                    VALUES
                        (:user_id, :user_email, :name, :student_no, :mobile, :department, :hod_email, :purpose, :building_id, :hall_id,
                         :start_dt, :end_dt, NULL, 'pending_hod', NULL, NULL, NULL, NOW())
                ";
                $ins = $pdo->prepare($insertSql);
                $ins->execute([
                    ':user_id'          => $userId,
                    ':user_email'       => ($_SESSION['user_email'] ?? null),
                    ':name'             => $name,
                    ':student_no'       => $studentNo ?: null,
                    ':mobile'           => $mobile,
                    ':department'       => $department,
                    ':hod_email'        => $hodEmail,
                    ':purpose'          => $purpose,
                    ':building_id'      => $buildingId,
                    ':hall_id'          => $hallId,
                    ':start_dt'         => $start->format('Y-m-d H:i:s'),
                    ':end_dt'           => $end->format('Y-m-d H:i:s'),
                ]);

                $reservationId = (int) $pdo->lastInsertId();

                // Generate a simple approval token
                $token = bin2hex(random_bytes(16));
                $tokenStmt = $pdo->prepare("UPDATE reservations SET approval_token = :token WHERE id = :id");
                $tokenStmt->execute([':token' => $token, ':id' => $reservationId]);

                $pdo->commit();

                // Send emails (user confirmation + HOD approval request)
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

                // User confirmation
                $userEmail = $_SESSION['user_email'] ?? null;
                if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    $subject = "Reservation Submitted - Pending Approval";
                    $body    = "Dear {$name},\n\n"
                             . "Your hall reservation request has been submitted and is pending approval.\n"
                             . "Hall ID: {$hallId}\n"
                             . "Start: " . $start->format('Y-m-d H:i') . "\n"
                             . "End: "   . $end->format('Y-m-d H:i')   . "\n\n"
                             . "You can check the status on the reservations portal.\n\n"
                             . "Thank you.";
                    sendGmail($userEmail, $subject, $body);
                }

                // HOD / Senior Treasurer Google Form + approval link
                $googleClient = getGoogleClientWithToken();
                $hodFormUrl = null;
                if ($googleClient instanceof Google_Client) {
                    try {
                        $formsService = new Google_Service_Forms($googleClient);
                        // Create a basic form summarising the reservation
                        $form = new Google_Service_Forms_Form([
                            'info' => [
                                'title'       => 'Hall Reservation Approval (HOD/Senior Treasurer)',
                                'description' => "Requested by: {$name}\n"
                                               . "Department/Association: {$department}\n"
                                               . "Purpose: {$purpose}\n"
                                               . "Start: " . $start->format('Y-m-d H:i') . "\n"
                                               . "End: "   . $end->format('Y-m-d H:i') . "\n\n"
                                               . "Use the button at the end of this form to confirm that you have reviewed this request."
                            ],
                        ]);
                        $created = $formsService->forms->create($form);

                        // Add a final section with an 'approval' multiple-choice question
                        $requests = [
                            new Google_Service_Forms_Request([
                                'createItem' => [
                                    'item' => [
                                        'title' => 'Decision',
                                        'questionItem' => [
                                            'question' => [
                                                'required' => true,
                                                'choiceQuestion' => [
                                                    'type' => 'RADIO',
                                                    'options' => [
                                                        ['value' => 'Approve'],
                                                        ['value' => 'Reject'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'location' => ['index' => 0],
                                ],
                            ]),
                        ];
                        $batchUpdateReq = new Google_Service_Forms_BatchUpdateFormRequest(['requests' => $requests]);
                        $formsService->forms->batchUpdate($created->getFormId(), $batchUpdateReq);

                        // Get responder URL
                        $formGet = $formsService->forms->get($created->getFormId());
                        $hodFormUrl = $formGet->getResponderUri();
                    } catch (Throwable $e) {
                        $hodFormUrl = null;
                    }
                }

                // HOD email: include all details EXCEPT HOD email address. Get building and hall names for display.
                $buildingName = $buildingId;
                $hallName = $hallId;
                try {
                    $bStmt = $pdo->prepare("SELECT building_name FROM building WHERE building_id = ?");
                    $bStmt->execute([$buildingId]);
                    if ($row = $bStmt->fetch()) {
                        $buildingName = $row['building_name'];
                    }
                    $hStmt = $pdo->prepare("SELECT hall_name FROM hall WHERE hall_id = ?");
                    $hStmt->execute([$hallId]);
                    if ($row = $hStmt->fetch()) {
                        $hallName = $row['hall_name'];
                    }
                } catch (PDOException $e) {
                    // keep ids if names not found
                }

                // Approval URLs in our system
                $approveUrl = $baseUrl . "/reservation_approve.php?token=" . urlencode($token) . "&step=hod&action=approve";
                $rejectUrl  = $baseUrl . "/reservation_approve.php?token=" . urlencode($token) . "&step=hod&action=reject";

                $subjectHod = "Hall Reservation Approval Request";
                $bodyHod    = "Dear HOD / Senior Treasurer,\n\n"
                            . "A hall reservation request needs your approval.\n\n"
                            . "Name: {$name}\n"
                            . "Student No: " . ($studentNo ?: 'N/A') . "\n"
                            . "Mobile: {$mobile}\n"
                            . "Department/Association: {$department}\n"
                            . "Purpose: {$purpose}\n"
                            . "Building: {$buildingName}\n"
                            . "Hall: {$hallName}\n"
                            . "Start: " . $start->format('Y-m-d H:i') . "\n"
                            . "End: "   . $end->format('Y-m-d H:i')   . "\n\n"
                            . "(HOD/Senior Treasurer email is not included in this summary.)\n\n";

                if ($hodFormUrl) {
                    $bodyHod .= "Google Form (details and approval): {$hodFormUrl}\n\n";
                }

                $bodyHod   .= "Approve in system: {$approveUrl}\n"
                            . "Reject in system:  {$rejectUrl}\n\n";

                sendGmail($hodEmail, $subjectHod, $bodyHod);

                $successMessage = 'Reservation submitted successfully. You will receive a confirmation email shortly.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'An error occurred while saving your reservation.';
        }
    }
}

// Handle GET parameters for pre-filling form from free slots
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $startDateTime = trim($_GET['start'] ?? '');
    $endDateTime = trim($_GET['end'] ?? '');
    $buildingId = (int)($_GET['building_id'] ?? 0);
    $hallId = (int)($_GET['hall_id'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Hall Reservation Request</title>
    <link rel="stylesheet" href="reservation_form.css">
</head>
<body>
<div class="container">
    <header>
        <div class="header-main">
            <h1>Hall Reservation Request</h1>
            <p>Faculty of Science – University of Kelaniya.</p>
        </div>
        <div class="header-actions" style = "margin-top: 20px; margin-bottom: 0px; padding-bottom: 0px;">
            <button type="button" class="btn-secondary" onclick="window.location.href='my_reservations.php'">View My Reservations</button>
            <button type="button" class="btn-secondary" onclick="window.location.href='free_slots.php'">View Free Slots</button>
            <button type="button" class="btn-secondary" onclick="window.location.href='../Login/logout.php'">Sign out</button>
        </div>
    </header>
    <form method="post">
        <?php if (!empty($errors)): ?>
            <div class="errors">
                <strong>Please fix the following issues:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="success">
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>

        <div class="section-title">Applicant Details</div>
        <div class="grid">
            <div>
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required
                       value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
            </div>
            <div>
                <label for="student_no">Student No. (if a student)</label>
                <input type="text" id="student_no" name="student_no"
                       value="<?php echo isset($studentNo) ? htmlspecialchars($studentNo) : ''; ?>">
            </div>
            <div>
                <label for="mobile">Mobile Number</label>
                <input type="tel" id="mobile" name="mobile" required
                       value="<?php echo isset($mobile) ? htmlspecialchars($mobile) : ''; ?>">
            </div>
            <div>
                <label for="department">Department / Association</label>
                <input type="text" id="department" name="department" required
                       value="<?php echo isset($department) ? htmlspecialchars($department) : ''; ?>">
            </div>
            <div>
                <label for="hod_email">HOD / Senior Treasurer Email</label>
                <input type="email" id="hod_email" name="hod_email" required
                       value="<?php echo isset($hodEmail) ? htmlspecialchars($hodEmail) : ''; ?>">
            </div>
        </div>

        <div class="section-title">Reservation Details</div>
        <div class="grid">
            <div style="grid-column: 1 / -1;">
                <label for="purpose">Purpose of Request</label>
                <textarea id="purpose" name="purpose" required><?php echo isset($purpose) ? htmlspecialchars($purpose) : ''; ?></textarea>
            </div>
        </div>

        <div class="note">
            only SE students can reserve hall A11 301.
        </div>

        <div class="hall-layout">
            <div>
                <div class="grid">
                    <div>
                        <label for="building_id">Building</label>
                        <select id="building_id" name="building_id" required
                                data-initial-building="<?php echo isset($buildingId) && $buildingId ? (int)$buildingId : ''; ?>">
                            <option value="">Select building</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?php echo (int)$b['building_id']; ?>"
                                    <?php echo isset($buildingId) && (int)$buildingId === (int)$b['building_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['building_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="hall_id">Hall</label>
                        <select id="hall_id" name="hall_id" required
                                data-initial-hall="<?php echo isset($hallId) && $hallId ? (int)$hallId : ''; ?>">
                            <option value="">Select hall</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="hall-details" id="hallDetails">
                <h4>Hall Details</h4>
                <p id="hallPrompt">Select a hall to view hall type and capacity.</p>
                <p id="hallType"></p>
                <p id="hallCapacity"></p>
            </div>
        </div>

        <div class="section-title">Date &amp; Time</div>
        <div class="note">
            Reservations are allowed on all 7 days from 8:00 a.m. to 10:00 p.m. If your date falls on a public holiday, you will be asked to confirm before submitting.
        </div>
        <div class="grid">
            <div>
                <label for="start_datetime">Start (Date &amp; Time)</label>
                <input type="datetime-local" id="start_datetime" name="start_datetime" required
                       value="<?php echo isset($startDateTime) ? htmlspecialchars($startDateTime) : htmlspecialchars($_GET['start'] ?? ''); ?>">
            </div>
            <div>
                <label for="end_datetime">End (Date &amp; Time)</label>
                <input type="datetime-local" id="end_datetime" name="end_datetime" required
                       value="<?php echo isset($endDateTime) ? htmlspecialchars($endDateTime) : htmlspecialchars($_GET['end'] ?? ''); ?>">
            </div>
        </div>
        <br>
        <div style="margin-left: 35px;">
            <p><strong >Charge Details.</strong></p>
            <ul>
                <li>The refundable charge must be paid for all the halls.</li>
                <li>Based on the purpose of the request, the refundable charges may be exempted.</li>
                <li>The non-refundable charges are for 03-hour slots.</li>
                <li>The refundable charge is to compensate for the cost of damage/losses, if any, caused to equipment/fittings/decorations of the lecture halls or otherwise during the period of use.</li>
                <li>Non-refundable charges will be used for the regular maintenance of equipment/fittings/decorations in the lecture halls.</li>
            </ul>
            <p><strong >Refund Process for Refundable Deposits.</strong></p>
            <ul>
                <li> 
                    A formal written request should be submitted requesting the refundable payment within one week after the completion of the event, addressed to the Dean, with the endorsement of the relevant Senior Treasurer</li>
                <li>
                  Refunds are subject to no damage or loss during use.   
                </li>

            </ul>
        </div>

        <p class="note">If payment is required for the selected hall, after HOD/Senior Treasurer approval you will receive an email with a link to the login page to complete payment and upload the slip.</p>

        <div class="terms">
            <label>
                <input type="checkbox" name="terms" value="1" <?php echo !empty($termsAccepted) ? 'checked' : ''; ?>>
                I agree to all the terms and conditions of the hall reservation policy.
            </label>
        </div>

        <div class="actions">
            <button type="submit" class="btn-primary">Submit Reservation</button>
        </div>
    </form>
</div>

<script>
const buildingSelect = document.getElementById('building_id');
const hallSelect = document.getElementById('hall_id');
const hallTypeEl = document.getElementById('hallType');
const hallCapacityEl = document.getElementById('hallCapacity');
const hallPromptEl = document.getElementById('hallPrompt');
const formEl = document.querySelector('form');

function clearHallDetails() {
    hallTypeEl.textContent = '';
    hallCapacityEl.textContent = '';
    if (hallPromptEl) {
        hallPromptEl.style.display = 'block';
    }
}

function loadHallsForBuilding(buildingId, thenSelectHallId) {
    hallSelect.innerHTML = '<option value="">Select hall</option>';
    clearHallDetails();
    if (!buildingId) return;
    fetch('reservation_get_halls.php?building_id=' + encodeURIComponent(buildingId))
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data)) return;
            data.forEach(h => {
                const opt = document.createElement('option');
                opt.value = h.hall_id;
                opt.textContent = h.hall_name;
                hallSelect.appendChild(opt);
            });
            if (thenSelectHallId) {
                hallSelect.value = thenSelectHallId;
                hallSelect.dispatchEvent(new Event('change'));
            }
        })
        .catch(() => {});
}

buildingSelect.addEventListener('change', function () {
    loadHallsForBuilding(this.value);
});

hallSelect.addEventListener('change', function () {
    const hallId = this.value;
    clearHallDetails();
    if (!hallId) return;
    fetch('reservation_get_hall_details.php?hall_id=' + encodeURIComponent(hallId))
        .then(r => r.json())
        .then(data => {
            if (!data) return;
            if (hallPromptEl) {
                hallPromptEl.style.display = 'none';
            }
            hallTypeEl.textContent = data.hall_type ? ('Hall type: ' + data.hall_type) : '';
            hallCapacityEl.textContent = data.capacity ? ('Capacity: ' + data.capacity) : '';
        })
        .catch(() => {});
});

// On page load: if we have initial building/hall (e.g. after failed submit or from free slots), load halls and restore selection
(function init() {
    const initialBuilding = buildingSelect.dataset.initialBuilding || '';
    const initialHall = hallSelect.dataset.initialHall || '';
    if (initialBuilding) {
        loadHallsForBuilding(initialBuilding, initialHall || null);
    }
    
    // If we have both building and hall from URL parameters, ensure hall details are loaded
    if (initialBuilding && initialHall) {
        // Small delay to ensure halls are loaded before selecting
        setTimeout(() => {
            if (hallSelect.value === initialHall) {
                // Trigger change event to load hall details
                hallSelect.dispatchEvent(new Event('change'));
            }
        }, 100);
    }
})();

// Form submit: check public holiday and show confirmation if needed
formEl.addEventListener('submit', function (e) {
    const startInput = document.getElementById('start_datetime');
    if (!startInput || !startInput.value) return; // let normal validation handle it

    const startVal = startInput.value; // e.g. "2026-03-25T14:00"
    const datePart = startVal.split('T')[0];
    if (!datePart) return;

    e.preventDefault();

    fetch('reservation_check_holiday.php?date=' + encodeURIComponent(datePart))
        .then(r => r.json())
        .then(data => {
            if (data.isHoliday) {
                const msg = 'This is a Public Holiday, So your request can be rejected. Do you still want to continue?';
                if (!confirm(msg)) {
                    return;
                }
            }
            formEl.submit();
        })
        .catch(() => {
            formEl.submit();
        });
});
</script>
</body>
</html>

