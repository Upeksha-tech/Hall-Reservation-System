<?php
session_start();

require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/google-client.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../Login/login.php');
    exit;
}

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo 'Invalid payment link.';
    exit;
}

$userId = (int) $_SESSION['user_id'];
$error = '';
$success = '';
$reservation = null;
$paymentRow = null;
$buildingName = '';
$hallName = '';

try {
    $stmt = $pdo->prepare("
        SELECT r.*, b.building_name AS bname, h.hall_name AS hname
        FROM reservations r
        LEFT JOIN building b ON r.building_id = b.building_id
        LEFT JOIN hall h ON r.hall_id = h.hall_id
        WHERE r.approval_token = :token AND r.user_id = :user_id
    ");
    $stmt->execute([':token' => $token, ':user_id' => $userId]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        http_response_code(404);
        echo 'Reservation not found or you do not have access to it.';
        exit;
    }

    if ($reservation['status'] !== 'pending_payment') {
        $error = 'This reservation is not awaiting payment. Current status: ' . htmlspecialchars($reservation['status']);
    } else {
        $buildingName = $reservation['bname'] ?? ('Building #' . $reservation['building_id']);
        $hallName = $reservation['hname'] ?? ('Hall #' . $reservation['hall_id']);

        // Check if hall is in payment table
        $payStmt = $pdo->prepare("SELECT hall_id, refundable_amount, non_refundable_amount FROM payment WHERE hall_id = :hall_id");
        $payStmt->execute([':hall_id' => $reservation['hall_id']]);
        $paymentRow = $payStmt->fetch();
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Error loading reservation.';
    exit;
}

$durationHours = null;
$nonRefundablePerSlot = null;
$nonRefundableTotal = null;
$slots3h = null;
$refundableAmount = null;
$totalAmount = null;

if ($reservation && $paymentRow) {
    $startTs = strtotime($reservation['start_datetime'] ?? '');
    $endTs   = strtotime($reservation['end_datetime'] ?? '');
    if ($startTs && $endTs && $endTs > $startTs) {
        $durationMinutes = max(0, (int)round(($endTs - $startTs) / 60));
        $durationHours = $durationMinutes / 60;
        // Non-refundable amount in payment table is for one 3-hour slot.
        $slots3h = max(1, (int)ceil($durationMinutes / (3 * 60)));
        $nonRefundablePerSlot = (float)$paymentRow['non_refundable_amount'];
        $nonRefundableTotal   = $nonRefundablePerSlot * $slots3h;
        $refundableAmount     = (float)$paymentRow['refundable_amount'];
        $totalAmount          = $refundableAmount + $nonRefundableTotal;
    } else {
        // Fallback: if something is wrong with times, use original single-slot amounts.
        $refundableAmount     = (float)$paymentRow['refundable_amount'];
        $nonRefundablePerSlot = (float)$paymentRow['non_refundable_amount'];
        $nonRefundableTotal   = $nonRefundablePerSlot;
        $totalAmount          = $refundableAmount + $nonRefundableTotal;
    }
}

/**
 * Send email to Dean and set reservation to pending_dean (call after payment slip submit or confirm when hall not in payment table).
 */
function sendDeanApprovalEmail(PDO $pdo, array $reservation): void {
    $token = $reservation['approval_token'];
    $now = (new DateTime())->format('Y-m-d H:i:s');

    $updateSql = "
        UPDATE reservations
        SET status = 'pending_dean',
            updated_at = :updated_at
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([
        ':updated_at' => $now,
        ':id'         => $reservation['id'],
    ]);

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $approveUrl = $baseUrl . "/reservation_approve.php?token=" . urlencode($token) . "&step=dean&action=approve";
    $rejectUrl  = $baseUrl . "/reservation_approve.php?token=" . urlencode($token) . "&step=dean&action=reject";

    $deanFormUrl = null;
    $googleClient = getGoogleClientWithToken();
    if ($googleClient instanceof Google_Client) {
        try {
            $formsService = new Google_Service_Forms($googleClient);
            $form = new Google_Service_Forms_Form([
                'info' => [
                    'title'       => 'Hall Reservation Approval (Dean)',
                    'description' => "Requested by: {$reservation['name']}\n"
                                   . "Purpose: {$reservation['purpose']}\n"
                                   . "HOD Approved At: {$reservation['hod_approved_at']}\n\n"
                                   . "Use the button at the end of this form to confirm that you have reviewed this request. "
                                   . "The system approval links are also provided in the email.",
                ],
            ]);
            $created = $formsService->forms->create($form);
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
            $formGet = $formsService->forms->get($created->getFormId());
            $deanFormUrl = $formGet->getResponderUri();
        } catch (Throwable $e) {
            $deanFormUrl = null;
        }
    }

    $deanStmt = $pdo->prepare("SELECT email FROM user WHERE user_role = 'dean' LIMIT 1");
    $deanStmt->execute();
    $dean = $deanStmt->fetch();

    if ($dean && !empty($dean['email'])) {
        // Fetch building/hall display names for full details
        $buildingName = (string)($reservation['building_id'] ?? '');
        $hallName = (string)($reservation['hall_id'] ?? '');
        try {
            $bStmt = $pdo->prepare("SELECT building_name FROM building WHERE building_id = ? LIMIT 1");
            $bStmt->execute([(int)$reservation['building_id']]);
            if ($b = $bStmt->fetch()) {
                $buildingName = $b['building_name'];
            }
            $hStmt = $pdo->prepare("SELECT hall_name FROM hall WHERE hall_id = ? LIMIT 1");
            $hStmt->execute([(int)$reservation['hall_id']]);
            if ($h = $hStmt->fetch()) {
                $hallName = $h['hall_name'];
            }
        } catch (PDOException $e) {
            // ignore
        }
        $startDt = !empty($reservation['start_datetime']) ? (new DateTime($reservation['start_datetime']))->format('Y-m-d H:i') : '';
        $endDt = !empty($reservation['end_datetime']) ? (new DateTime($reservation['end_datetime']))->format('Y-m-d H:i') : '';

        $subjectDean = "Hall Reservation Approval Request (Dean)";
        $bodyDean = "Dear Dean,\n\n"
                  . "A hall reservation request has been approved by HOD/Senior Treasurer and the user has completed the payment step. It now needs your approval.\n\n"
                  . "Name: {$reservation['name']}\n"
                  . "Student No: " . (($reservation['student_no'] ?? '') ?: 'N/A') . "\n"
                  . "Mobile: {$reservation['mobile']}\n"
                  . "Department/Association: {$reservation['department']}\n"
                  . "Purpose: {$reservation['purpose']}\n"
                  . "Building: {$buildingName}\n"
                  . "Hall: {$hallName}\n"
                  . "Start: {$startDt}\n"
                  . "End: {$endDt}\n"
                  . "HOD Approved At: {$reservation['hod_approved_at']}\n\n";

        if ($deanFormUrl) {
            $bodyDean .= "Google Form (details and confirmation): {$deanFormUrl}\n\n";
        }

        $bodyDean .= "Approve in system: {$approveUrl}\n"
                   . "Reject in system:  {$rejectUrl}\n\n";

        sendGmail($dean['email'], $subjectDean, $bodyDean);
    }
}

// --- Handle form submissions ---

if ($reservation && $reservation['status'] === 'pending_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($paymentRow) {
        // Hall is in payment table: require slip upload
        $file = $_FILES['payment_slip'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a valid payment slip.';
        } else {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($mime, $allowed, true)) {
                $error = 'Invalid file type. Allowed: JPEG, PNG, GIF, PDF.';
            } else {
                $uploadDir = __DIR__ . '/uploads';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $safeName = 'slip_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $path = $uploadDir . '/' . $safeName;
                if (move_uploaded_file($file['tmp_name'], $path)) {
                    $relativePath = 'uploads/' . $safeName;
                    $now = (new DateTime())->format('Y-m-d H:i:s');
                    $upd = $pdo->prepare("
                        UPDATE reservations
                        SET payment_slip_path = :path, updated_at = :updated_at
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':path'       => $relativePath,
                        ':updated_at' => $now,
                        ':id'         => $reservation['id'],
                    ]);
                    sendDeanApprovalEmail($pdo, array_merge($reservation, ['payment_slip_path' => $relativePath]));
                    $success = 'Payment slip submitted successfully. Your request has been sent to the Dean for approval.';
                    $reservation = null; // hide form
                } else {
                    $error = 'Failed to save the uploaded file.';
                }
            }
        }
    } else {
        // Hall not in payment table: confirm only (no slip)
        $confirm = trim($_POST['confirm'] ?? '');
        if ($confirm !== 'yes') {
            $error = 'Please confirm that you have read the message and wish to proceed.';
        } else {
            sendDeanApprovalEmail($pdo, $reservation);
            $success = 'Your request has been sent to the Dean for approval. You will be notified once processed.';
            $reservation = null;
        }
    }
}

$showPaymentForm = $reservation && $reservation['status'] === 'pending_payment' && $paymentRow && !$success;
$showConfirmForm = $reservation && $reservation['status'] === 'pending_payment' && !$paymentRow && !$success;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment – Hall Reservation</title>
    <style>
        :root { --maroon: #800000; --yellow: #ffc107; }
        body { font-family: Arial, sans-serif; background: #fdf7f2; margin: 0; padding: 0; }
        .container { max-width: 640px; margin: 40px auto; background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }
        header { background: linear-gradient(90deg, var(--maroon), #4b0000); color: #fff; padding: 16px 24px; }
        header h1 { margin: 0; font-size: 1.4rem; }
        main { padding: 20px 24px 24px; }
        .message { margin-bottom: 12px; padding: 8px 10px; border-left: 4px solid var(--yellow); background: rgba(255,193,7,0.08); }
        .error { border-left-color: #d32f2f; background: #ffe5e5; color: #b71c1c; }
        .success { border-left-color: #2e7d32; background: #e6ffed; color: #1b5e20; }
        .bill { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin: 16px 0; background: #fafafa; }
        .bill table { width: 100%; }
        .bill td { padding: 6px 0; }
        .bill .total { font-weight: bold; font-size: 1.1rem; border-top: 1px solid #ccc; padding-top: 8px; }
        .note { font-size: 0.9rem; color: #555; margin: 12px 0; padding: 10px; background: #f5f5f5; border-radius: 4px; }
        .btn { display: inline-block; margin-top: 10px; background: var(--maroon); color: #fff; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; }
        .btn:hover { opacity: 0.9; }
        .link { color: var(--maroon); text-decoration: none; font-weight: 600; }
        .link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>Payment – Hall Reservation</h1>
    </header>
    <main>
        <p><a href="my_reservations.php" class="link">&larr; My Reservations</a></p>

        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <p><a href="my_reservations.php" class="btn">Back to My Reservations</a></p>
        <?php endif; ?>

        <?php if ($reservation && $reservation['status'] !== 'pending_payment' && !$success): ?>
            <p>This reservation is not in "Pending Payment" status. <a href="my_reservations.php" class="link">View my reservations</a>.</p>
        <?php endif; ?>

        <?php if ($showPaymentForm): ?>
            <p><strong>HOD/Senior Treasurer has approved your request.</strong> Please make the payment to confirm your reservation.</p>

            <div class="bill">
                <table>
                    <?php if ($durationHours !== null): ?>
                        <tr>
                            <td>Hours booked</td>
                            <td><?php echo number_format($durationHours, 2); ?> hours</td>
                        </tr>
                        <tr>
                            <td>3-hour slots charged</td>
                            <td><?php echo (int)$slots3h; ?> × 3 hours</td>
                        </tr>
                        <tr>
                            <td>Non-refundable (per 3-hour slot)</td>
                            <td>LKR <?php echo number_format($nonRefundablePerSlot, 2); ?></td>
                        </tr>
                        <tr>
                            <td>Non-refundable (for booked time)</td>
                            <td>LKR <?php echo number_format($nonRefundableTotal, 2); ?></td>
                        </tr>
                        <tr>
                            <td>Refundable (deposit)</td>
                            <td>LKR <?php echo number_format($refundableAmount, 2); ?></td>
                        </tr>
                        <tr>
                            <td class="total">Total</td>
                            <td class="total">LKR <?php echo number_format($totalAmount, 2); ?></td>
                        </tr>
                    <?php else: ?>
                        <tr><td>Refundable amount</td><td>LKR <?php echo number_format((float)$paymentRow['refundable_amount'], 2); ?></td></tr>
                        <tr><td>Non-refundable amount</td><td>LKR <?php echo number_format((float)$paymentRow['non_refundable_amount'], 2); ?></td></tr>
                        <tr><td class="total">Total</td><td class="total">LKR <?php echo number_format((float)$paymentRow['refundable_amount'] + (float)$paymentRow['non_refundable_amount'], 2); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>

            <div class="note">
                <strong>Refund process:</strong> The refundable charge may be refunded after the event if no damage/loss is reported. Submit a formal written request for refund within one week after the event, addressed to the Dean, with the endorsement of the relevant Senior Treasurer. Non-refundable charges are for maintenance and are not refundable.
            </div>

            <form method="post" enctype="multipart/form-data">
                <label>Upload payment slip (image or PDF):</label><br>
                <input type="file" name="payment_slip" accept=".jpg,.jpeg,.png,.gif,.pdf" required><br>
                <button type="submit" class="btn">Submit payment slip</button>
            </form>
        <?php endif; ?>

        <?php if ($showConfirmForm): ?>
            <p><strong>HOD/Senior Treasurer has approved your request.</strong></p>
            <p>This hall is not listed in the payment table. If you have already made payment or have made other arrangements with the administration, you may confirm below to send your request to the Dean for approval. Otherwise, please contact the administration before confirming.</p>

            <div class="note">
                By confirming, you acknowledge that your request has been approved by HOD and you are requesting Dean approval. If payment is required for this hall, please contact the administration for instructions.
            </div>

            <form method="post">
                <label><input type="checkbox" name="confirm" value="yes" required> I have read the above and wish to proceed to request Dean approval.</label><br>
                <button type="submit" class="btn">Confirm and send to Dean</button>
            </form>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
