<?php
session_start();

require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/google-client.php';

$token  = $_GET['token']  ?? '';
$step   = $_GET['step']   ?? '';
$action = $_GET['action'] ?? '';

$validSteps   = ['hod', 'dean', 'admin'];
$validActions = ['approve', 'reject'];

if (!$token || !in_array($step, $validSteps, true) || !in_array($action, $validActions, true)) {
    http_response_code(400);
    echo "Invalid approval link.";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE approval_token = :token");
    $stmt->execute([':token' => $token]);
    $reservation = $stmt->fetch();
    if (!$reservation) {
        http_response_code(404);
        echo "Reservation not found or link expired.";
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo "Error loading reservation.";
    exit;
}

// If already finally approved/rejected, stop here
if (in_array($reservation['status'], ['approved', 'rejected'], true)) {
    echo "This reservation has already been " . htmlspecialchars($reservation['status']) . ".";
    exit;
}

// Ensure the approval step matches current status (for GET approve)
$stepStatus = ['hod' => 'pending_hod', 'dean' => 'pending_dean', 'admin' => 'pending_admin'];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'approve' && isset($stepStatus[$step])) {
    if ($reservation['status'] !== $stepStatus[$step]) {
        echo "This link is not valid for the current reservation status (" . htmlspecialchars($reservation['status']) . ").";
        exit;
    }
}

$error = '';
$message = '';

// Handle rejection (needs reason)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reject') {
    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        $error = 'Rejection reason is required.';
    } else {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        try {
            $pdo->beginTransaction();

            $status = 'rejected';
            $updateSql = "
                UPDATE reservations
                SET status = :status,
                    rejected_reason = :reason,
                    updated_at = :updated_at
            ";

            if ($step === 'hod') {
                $updateSql .= ", hod_approved_at = :now";
            } elseif ($step === 'dean') {
                $updateSql .= ", dean_approved_at = :now";
            } elseif ($step === 'admin') {
                $updateSql .= ", admin_approved_at = :now";
            }

            $updateSql .= " WHERE id = :id";

            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                ':status'     => $status,
                ':reason'     => $reason,
                ':updated_at' => $now,
                ':now'        => $now,
                ':id'         => $reservation['id'],
            ]);

            $pdo->commit();

            // Notify user by email with rejection reason
            $userEmail = $reservation['user_email'];
            if (empty($userEmail)) {
                $userStmt = $pdo->prepare("SELECT email FROM user WHERE user_id = ? LIMIT 1");
                $userStmt->execute([$reservation['user_id']]);
                $u = $userStmt->fetch();
                $userEmail = $u['email'] ?? null;
            }
            if (!empty($userEmail) && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $subject = "Hall Reservation Rejected";
                $body = "Dear " . $reservation['name'] . ",\n\n"
                      . "Your hall reservation request has been rejected.\n\n"
                      . "Reason: {$reason}\n\n"
                      . "Thank you.";
                sendGmail($userEmail, $subject, $body);
            }

            $message = 'Reservation has been rejected.';
            if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && $step === 'admin') {
                $adminUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/Admin/index.php';
                header('Location: ' . $adminUrl . '?msg=' . urlencode($message));
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo "<pre>";
            echo $e->getMessage();
            echo "</pre>";
            exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'approve') {
    // Handle approval for each step
    $now = (new DateTime())->format('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        if ($step === 'hod') {
            // HOD approved: if payment is required (hall exists in payment table) -> pending_payment.
            // Otherwise skip payment and forward to Dean immediately.
            $payStmt = $pdo->prepare("SELECT hall_id FROM payment WHERE hall_id = ? LIMIT 1");
            $payStmt->execute([(int)$reservation['hall_id']]);
            $paymentRequired = (bool)$payStmt->fetch();

            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

            $userEmail = $reservation['user_email'] ?? '';
            if ($userEmail === '' || $userEmail === null) {
                $uStmt = $pdo->prepare("SELECT email FROM user WHERE user_id = ? LIMIT 1");
                $uStmt->execute([(int)$reservation['user_id']]);
                $u = $uStmt->fetch();
                $userEmail = $u['email'] ?? '';
            }

            if ($paymentRequired) {
                $updateSql = "
                    UPDATE reservations
                    SET status = 'pending_payment',
                        hod_approved_at = :now,
                        updated_at = :updated_at
                    WHERE id = :id
                ";
                $stmt = $pdo->prepare($updateSql);
                $stmt->execute([
                    ':now'        => $now,
                    ':updated_at' => $now,
                    ':id'         => $reservation['id'],
                ]);

                // Send users to login first (they'll access payment after login)
                $paymentUrl = "https://hall-reserve.ct.ws/project/Login/login.html";

                // Email user: payment required
                if (!empty($userEmail) && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    $subjectUser = "HOD/Senior Treasurer Approved – Payment Required";
                    $bodyUser = "Dear " . $reservation['name'] . ",\n\n"
                              . "Your hall reservation request has been approved by the HOD/Senior Treasurer.\n\n"
                              . "Payment is required for this hall to proceed to the Dean approval stage.\n"
                              . "Please log in to the system to make the payment and upload the slip using the link below:\n\n"
                              . "Login page: {$paymentUrl}\n\n"
                              . "Thank you.";
                    sendGmail($userEmail, $subjectUser, $bodyUser);
                }

                $message = 'Reservation approved by HOD. Payment is required; user has been emailed the payment link.';
            } else {
                // Skip payment: forward to Dean now
                $updateSql = "
                    UPDATE reservations
                    SET status = 'pending_dean',
                        hod_approved_at = :now,
                        updated_at = :updated_at
                    WHERE id = :id
                ";
                $stmt = $pdo->prepare($updateSql);
                $stmt->execute([
                    ':now'        => $now,
                    ':updated_at' => $now,
                    ':id'         => $reservation['id'],
                ]);

                // Fetch building/hall names for email
                $buildingName = (string)$reservation['building_id'];
                $hallName = (string)$reservation['hall_id'];
                try {
                    $bStmt = $pdo->prepare("SELECT building_name FROM building WHERE building_id = ? LIMIT 1");
                    $bStmt->execute([(int)$reservation['building_id']]);
                    if ($b = $bStmt->fetch()) $buildingName = $b['building_name'];
                    $hStmt = $pdo->prepare("SELECT hall_name FROM hall WHERE hall_id = ? LIMIT 1");
                    $hStmt->execute([(int)$reservation['hall_id']]);
                    if ($h = $hStmt->fetch()) $hallName = $h['hall_name'];
                } catch (PDOException $e) {}

                $startDt = (new DateTime($reservation['start_datetime']))->format('Y-m-d H:i');
                $endDt = (new DateTime($reservation['end_datetime']))->format('Y-m-d H:i');

                $deanStmt = $pdo->prepare("SELECT email FROM user WHERE user_role = 'dean' LIMIT 1");
                $deanStmt->execute();
                $dean = $deanStmt->fetch();

                $approveUrl = $baseUrl . "/reservation_approve.php?token=" . urlencode($token) . "&step=dean&action=approve";
                $rejectUrl  = $baseUrl . "/reservation_approve.php?token=" . urlencode($token) . "&step=dean&action=reject";

                if ($dean && !empty($dean['email'])) {
                    $subjectDean = "Hall Reservation Approval Request (Dean)";
                    $bodyDean = "Dear Dean,\n\n"
                              . "A hall reservation request has been approved by the HOD/Senior Treasurer and does not require a payment step.\n\n"
                              . "Name: {$reservation['name']}\n"
                              . "Student No: " . ($reservation['student_no'] ?: 'N/A') . "\n"
                              . "Mobile: {$reservation['mobile']}\n"
                              . "Department/Association: {$reservation['department']}\n"
                              . "Purpose: {$reservation['purpose']}\n"
                              . "Building: {$buildingName}\n"
                              . "Hall: {$hallName}\n"
                              . "Start: {$startDt}\n"
                              . "End: {$endDt}\n"
                              . "HOD Approved At: {$now}\n\n"
                              . "Approve: {$approveUrl}\n"
                              . "Reject:  {$rejectUrl}\n\n";
                    sendGmail($dean['email'], $subjectDean, $bodyDean);
                }

                if (!empty($userEmail) && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    $subjectUser = "HOD/Senior Treasurer Approved – Forwarded to Dean";
                    $bodyUser = "Dear " . $reservation['name'] . ",\n\n"
                              . "Your hall reservation request has been approved by the HOD/Senior Treasurer.\n\n"
                              . "This hall does not require a payment step. Your request has been forwarded to the Dean for approval.\n\n"
                              . "Thank you.";
                    sendGmail($userEmail, $subjectUser, $bodyUser);
                }

                $message = 'Reservation approved by HOD and forwarded to Dean (no payment required).';
            }
        } elseif ($step === 'dean') {
            // Move to admin approval
            $updateSql = "
                UPDATE reservations
                SET status = 'pending_admin',
                    dean_approved_at = :now,
                    updated_at = :updated_at
                WHERE id = :id
            ";
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                ':now'        => $now,
                ':updated_at' => $now,
                ':id'         => $reservation['id'],
            ]);

            // Send email to all admins
            $adminStmt = $pdo->prepare("SELECT email FROM user WHERE user_role = 'admin'");
            $adminStmt->execute();
            $admins = $adminStmt->fetchAll();

            if ($admins) {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $approveUrl = $baseUrl . "/reservation_approve.php?token=" . urlencode($token) . "&step=admin&action=approve";
                $rejectUrl  = $baseUrl . "/reservation_approve.php?token=" . urlencode($token) . "&step=admin&action=reject";

                foreach ($admins as $admin) {
                    if (empty($admin['email'])) {
                        continue;
                    }
                    $subjectAdmin = "Hall Reservation Final Approval Required";
                    $bodyAdmin = "Dear Admin,\n\n"
                               . "A hall reservation request has been approved by HOD/Senior Treasurer and Dean.\n\n"
                               . "Requested by: {$reservation['name']}\n"
                               . "Purpose: {$reservation['purpose']}\n"
                               . "HOD Approved At: {$reservation['hod_approved_at']}\n"
                               . "Dean Approved At: {$now}\n\n"
                               . "Approve: {$approveUrl}\n"
                               . "Reject:  {$rejectUrl}\n\n";

                    sendGmail($admin['email'], $subjectAdmin, $bodyAdmin);
                }
            }

            $message = 'Reservation approved by Dean and forwarded to Admin.';
        } elseif ($step === 'admin') {
            // Final approval – only now treat as fully booked & "stored"
            $updateSql = "
                UPDATE reservations
                SET status = 'approved',
                    admin_approved_at = :now,
                    updated_at = :updated_at
                WHERE id = :id
            ";
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                ':now'        => $now,
                ':updated_at' => $now,
                ':id'         => $reservation['id'],
            ]);

            // Create Google Calendar event now that reservation is fully approved
            $googleClient = getGoogleClientWithToken();
            if ($googleClient instanceof Google_Client) {
                try {
                    $calendarService = new Google_Service_Calendar($googleClient);
                    $event = new Google_Service_Calendar_Event([
                        'summary' => 'Hall Reservation - ' . $reservation['name'],
                        'description' => "Purpose: {$reservation['purpose']}",
                        'start' => [
                            'dateTime' => (new DateTime($reservation['start_datetime']))->format(DateTime::RFC3339),
                            'timeZone' => 'Asia/Colombo',
                        ],
                        'end' => [
                            'dateTime' => (new DateTime($reservation['end_datetime']))->format(DateTime::RFC3339),
                            'timeZone' => 'Asia/Colombo',
                        ],
                    ]);
                    $calendarService->events->insert('primary', $event);
                } catch (Throwable $e) {
                    // If Calendar insert fails, continue; DB remains source of truth
                }
            }

            // Email user about approval with full reservation details
            $userEmail = $reservation['user_email'];
            if (empty($userEmail)) {
                $userStmt = $pdo->prepare("SELECT email FROM user WHERE user_id = ? LIMIT 1");
                $userStmt->execute([$reservation['user_id']]);
                $u = $userStmt->fetch();
                $userEmail = $u['email'] ?? null;
            }
            if (!empty($userEmail) && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $bName = $reservation['building_id'];
                $hName = $reservation['hall_id'];
                try {
                    $bStmt = $pdo->prepare("SELECT building_name FROM building WHERE building_id = ?");
                    $bStmt->execute([$reservation['building_id']]);
                    if ($row = $bStmt->fetch()) $bName = $row['building_name'];
                    $hStmt = $pdo->prepare("SELECT hall_name FROM hall WHERE hall_id = ?");
                    $hStmt->execute([$reservation['hall_id']]);
                    if ($row = $hStmt->fetch()) $hName = $row['hall_name'];
                } catch (PDOException $e) {}
                $startDt = (new DateTime($reservation['start_datetime']))->format('Y-m-d H:i');
                $endDt = (new DateTime($reservation['end_datetime']))->format('Y-m-d H:i');
                $subject = "Hall Reservation Confirmed";
                $body = "Dear " . $reservation['name'] . ",\n\n"
                      . "Your hall reservation has been approved and confirmed.\n\n"
                      . "Reservation Details:\n"
                      . "-------------------\n"
                      . "Building: {$bName}\n"
                      . "Hall: {$hName}\n"
                      . "Purpose: {$reservation['purpose']}\n"
                      . "Start: {$startDt}\n"
                      . "End: {$endDt}\n"
                      . "Department: {$reservation['department']}\n"
                      . "Mobile: {$reservation['mobile']}\n\n"
                      . "Thank you for using the Hall Reservation System.";
                sendGmail($userEmail, $subject, $body);
            }

            $message = 'Reservation has been fully approved by Admin.';
        }

        $pdo->commit();

        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && $step === 'admin') {
            $adminUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/Admin/index.php';
            header('Location: ' . $adminUrl . '?msg=' . urlencode($message));
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Show the actual database / runtime error so we can diagnose why the update failed.
        echo "<pre>";
        echo htmlspecialchars($e->getMessage());
        echo "</pre>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reservation Approval</title>
    <style>
        :root {
            --maroon: #800000;
            --yellow: #ffc107;
        }
        body {
            font-family: Arial, sans-serif;
            background: #fdf7f2;
            margin: 0;
            padding: 0;
        }
        .wrapper {
            max-width: 640px;
            margin: 40px auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        header {
            background: linear-gradient(90deg, var(--maroon), #4b0000);
            color: #fff;
            padding: 16px 24px;
        }
        header h1 {
            margin: 0;
            font-size: 1.4rem;
        }
        main {
            padding: 20px 24px 24px;
        }
        .message {
            margin-bottom: 12px;
            padding: 8px 10px;
            border-left: 4px solid var(--yellow);
            background: rgba(255,193,7,0.08);
        }
        .error {
            border-left-color: #d32f2f;
            background: #ffe5e5;
            color: #b71c1c;
        }
        .success {
            border-left-color: #2e7d32;
            background: #e6ffed;
            color: #1b5e20;
        }
        form textarea {
            width: 100%;
            min-height: 90px;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }
        button {
            margin-top: 10px;
            background: var(--maroon);
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <header>
        <h1>Hall Reservation Approval</h1>
    </header>
    <main>
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($action === 'reject' && !$message): ?>
            <p>Please provide a reason for rejecting this reservation:</p>
            <form method="post">
                <textarea name="reason" required></textarea>
                <br>
                <button type="submit">Submit Rejection</button>
            </form>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <p style="margin-top:16px;"><a href="../Admin/index.php" style="color:#800000;font-weight:600;">&larr; Back to Admin Portal</a></p>
            <?php endif; ?>
        <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <p><a href="../Admin/index.php" style="color:#800000;font-weight:600;">&larr; Return to Admin Portal</a></p>
        <?php else: ?>
            <p>You can close this window.</p>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

