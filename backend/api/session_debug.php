<?php
session_start();
header('Content-Type: application/json');

echo json_encode([
    'session' => $_SESSION,
    'user_id' => $_SESSION['user_id'] ?? null,
    'company_id' => $_SESSION['company_id'] ?? null,
]);
