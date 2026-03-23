<?php
/**
 * Admin auth guard - ensures only user_role = 'admin' can access Admin pages.
 * Include at top of every Admin page.
 */
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ../Login/login.html');
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../User/reservation_form.php');
    exit;
}
