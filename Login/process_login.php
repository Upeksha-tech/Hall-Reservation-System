<?php
session_start();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: login.html?error=missing');
    exit;
}

$stmt = $pdo->prepare('SELECT user_id, user_name, password_hash, user_role FROM user WHERE user_name = ? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['user_name'];
    $_SESSION['role'] = $user['user_role'];

    if ($user['user_role'] === 'admin'){
        header('Location: ../Admin/index.php');
    }
    else{
        header('Location: ../User/reservation_form.php');
    }
    exit;
}

header('Location: login.html?error=invalid');
exit;
