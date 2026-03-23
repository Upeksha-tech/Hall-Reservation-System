<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}
$username = htmlspecialchars($_SESSION['username']);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Welcome</title>
  <style>body{font-family:Segoe UI,Arial;padding:40px}</style>
</head>
<body>
  <h1>Welcome, user <?=$username?></h1>
  <p>You are signed in.</p>
  <p><a href="logout.php">Sign out</a></p>
</body>
</html>
