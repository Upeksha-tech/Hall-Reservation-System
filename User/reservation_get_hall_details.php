<?php
// Returns hall type and capacity for a given hall as JSON
session_start();

require_once __DIR__ . '/../Login/db.php';

header('Content-Type: application/json; charset=utf-8');

$hallId = isset($_GET['hall_id']) ? (int) $_GET['hall_id'] : 0;

if ($hallId <= 0) {
    echo json_encode(null);
    exit;
}

try {
    // Map DB columns to the keys expected by the frontend (hall_type, capacity)
    $stmt = $pdo->prepare("SELECT hall_type, hall_capacity AS capacity FROM hall WHERE hall_id = ?");
    $stmt->execute([$hallId]);
    $hall = $stmt->fetch();
    if ($hall) {
        echo json_encode($hall);
    } else {
        echo json_encode(null);
    }
} catch (PDOException $e) {
    echo json_encode(null);
}

