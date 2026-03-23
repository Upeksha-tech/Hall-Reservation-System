<?php
// Returns halls for a given building as JSON
session_start();

require_once __DIR__ . '/../Login/db.php';

header('Content-Type: application/json; charset=utf-8');

$buildingId = isset($_GET['building_id']) ? (int) $_GET['building_id'] : 0;

if ($buildingId <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT hall_id, hall_name FROM hall WHERE building_id = ? ORDER BY hall_name");
    $stmt->execute([$buildingId]);
    $halls = $stmt->fetchAll();
    echo json_encode($halls);
} catch (PDOException $e) {
    echo json_encode([]);
}

