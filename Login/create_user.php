<?php
// create_user.php — create single user or bulk-upload users with retention (expiry) settings.
session_start();
require_once __DIR__ . '/db.php';

$msg = '';
$errors = [];

/**
 * Compute expiry date based on retention selection.
 * @param string $retention '1','2','3','4','never'
 * @return string|null Y-m-d or null for never
 */
function computeExpiryDate(string $retention): ?string {
    if ($retention === 'never') {
        return null;
    }
    if (!in_array($retention, ['1','2','3','4'], true)) {
        return null;
    }
    $now = new DateTime('now', new DateTimeZone('Asia/Colombo'));
    $now->modify('+' . (int)$retention . ' years');
    return $now->format('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'single';

    if ($mode === 'single') {
        // Single user creation
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $email     = trim($_POST['email'] ?? '');
        $retention = $_POST['retention'] ?? 'never';

        if ($username === '' || $password === '') {
            $errors[] = 'Username and password are required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required.';
        }
        if (!in_array($retention, ['1','2','3','4','never'], true)) {
            $errors[] = 'Please select a valid data retention option.';
        }

        if (!$errors) {
            $expiryDate = computeExpiryDate($retention);
            $hash       = password_hash($password, PASSWORD_DEFAULT);

            // NOTE: this assumes your `user` table has columns: user_name, password_hash, email, expiry_date
            $sql  = 'INSERT INTO user (user_name, password_hash, email, expiry_date) VALUES (:name, :hash, :email, :expiry)';
            $stmt = $pdo->prepare($sql);
            try {
                $stmt->execute([
                    ':name'   => $username,
                    ':hash'   => $hash,
                    ':email'  => $email,
                    ':expiry' => $expiryDate,
                ]);
                $msg = 'User created successfully.';
            } catch (PDOException $e) {
                $errors[] = 'Error creating user: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($mode === 'bulk') {
        // Bulk upload CSV
        $retention = $_POST['bulk_retention'] ?? 'never';
        if (!in_array($retention, ['1','2','3','4','never'], true)) {
            $errors[] = 'Please select a valid data retention option for bulk upload.';
        }

        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please choose a CSV file to upload.';
        } elseif ($_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Error uploading CSV file.';
        }

        if (!$errors) {
            $expiryDate = computeExpiryDate($retention);
            $tmpPath    = $_FILES['csv']['tmp_name'];
            $handle     = fopen($tmpPath, 'r');
            if ($handle === false) {
                $errors[] = 'Unable to read uploaded CSV file.';
            } else {
                $inserted = 0;
                $skipped  = 0;
                // Expect header: username,password,email  (case-insensitive)
                $header = fgetcsv($handle);
                if (!$header) {
                    $errors[] = 'CSV file is empty.';
                } else {
                    $header = array_map('strtolower', $header);
                    $uIdx = array_search('username', $header);
                    $pIdx = array_search('password', $header);
                    $eIdx = array_search('email', $header);
                    if ($uIdx === false || $pIdx === false || $eIdx === false) {
                        $errors[] = 'CSV header must contain columns: username, password, email.';
                    } else {
                        $sql  = 'INSERT INTO user (user_name, password_hash, email, expiry_date) VALUES (:name, :hash, :email, :expiry)';
                        $stmt = $pdo->prepare($sql);

                        while (($row = fgetcsv($handle)) !== false) {
                            $username = trim($row[$uIdx] ?? '');
                            $password = $row[$pIdx] ?? '';
                            $email    = trim($row[$eIdx] ?? '');

                            if ($username === '' || $password === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $skipped++;
                                continue;
                            }

                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            try {
                                $stmt->execute([
                                    ':name'   => $username,
                                    ':hash'   => $hash,
                                    ':email'  => $email,
                                    ':expiry' => $expiryDate,
                                ]);
                                $inserted++;
                            } catch (PDOException $e) {
                                $skipped++;
                                continue;
                            }
                        }

                        if ($inserted > 0) {
                            $msg = "Bulk upload complete. Inserted {$inserted} user(s), skipped {$skipped}.";
                        } else {
                            $errors[] = 'No users were inserted from the CSV (all rows invalid or failed).';
                        }
                    }
                }
                fclose($handle);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Create Users</title>
    <style>
        body {
            font-family: Segoe UI, Arial, sans-serif;
            background: #f5f5f7;
            margin: 0;
            padding: 40px 16px;
        }
        .page {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            margin: 0 0 8px;
        }
        .subtitle {
            margin: 0 0 24px;
            color: #555;
        }
        .messages {
            margin-bottom: 16px;
        }
        .msg {
            padding: 10px 12px;
            border-radius: 4px;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        .msg-ok {
            background: #e6ffed;
            border: 1px solid #2e7d32;
            color: #1b5e20;
        }
        .msg-error {
            background: #ffe5e5;
            border: 1px solid #d32f2f;
            color: #b71c1c;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            padding: 18px 20px 20px;
        }
        .card h2 {
            margin: 0 0 8px;
            font-size: 1.1rem;
        }
        .card p {
            margin: 0 0 14px;
            font-size: 0.9rem;
            color: #555;
        }
        label {
            display: block;
            margin-top: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
        }
        input[type="text"],
        input[type="password"],
        input[type="email"],
        select {
            width: 100%;
            box-sizing: border-box;
            padding: 7px 8px;
            margin-top: 4px;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-size: 0.9rem;
        }
        input[type="file"] {
            margin-top: 6px;
            font-size: 0.9rem;
        }
        .help {
            font-size: 0.8rem;
            color: #777;
            margin-top: 2px;
        }
        button {
            margin-top: 14px;
            padding: 8px 16px;
            border-radius: 4px;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            background: #800000;
            color: #fff;
        }
        button:hover {
            background: #4b0000;
        }
    </style>
</head>
<body>
<div class="page">
    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <p><a href="../Admin/index.php" style="color:#800000;font-weight:600;">&larr; Back to Admin Portal</a></p>
    <?php endif; ?>
    <h1>User Management</h1>
    <p class="subtitle">Create single users or upload multiple users from a CSV file, with a retention (expiry) policy.</p>

    <div class="messages">
        <?php if ($msg): ?>
            <div class="msg msg-ok"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
            <div class="msg msg-error"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Create single user</h2>
            <p>Create one user with username, password, email and a data retention period.</p>
            <form method="post">
                <input type="hidden" name="mode" value="single">

                <label for="username">Username</label>
                <input id="username" name="username" type="text" required>

                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>

                <label for="email">Email</label>
                <input id="email" name="email" type="email" required>

                <label for="retention">Data retention (expiry)</label>
                <select id="retention" name="retention" required>
                    <option value="1">1 year</option>
                    <option value="2">2 years</option>
                    <option value="3">3 years</option>
                    <option value="4">4 years</option>
                    <option value="never" selected>Never delete</option>
                </select>
                <div class="help">This controls when this user record will be due for deletion.</div>

                <button type="submit">Create user</button>
            </form>
        </div>

        <div class="card">
            <h2>Bulk upload users</h2>
            <p>Upload a CSV file to create multiple users at once. The same retention setting is applied to all uploaded users.</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="mode" value="bulk">

                <label for="csv">CSV file</label>
                <input id="csv" name="csv" type="file" accept=".csv" required>
                <div class="help">Expected header: <code>username,password,email</code></div>

                <label for="bulk_retention">Data retention (expiry for this upload)</label>
                <select id="bulk_retention" name="bulk_retention" required>
                    <option value="1">1 year</option>
                    <option value="2">2 years</option>
                    <option value="3">3 years</option>
                    <option value="4">4 years</option>
                    <option value="never" selected>Never delete</option>
                </select>
                <div class="help">This controls when all uploaded user records will be due for deletion.</div>

                <button type="submit">Upload users</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
