<?php
// backend/api/client-dashboard.php
header("Content-Type: application/json; charset=UTF-8");
session_start();
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) {
  echo json_encode(["error" => "Unauthorized"]);
  exit;
}

// 🧠 Fetch project statistics
try {
    // Total projects requested by client
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_requests,
            SUM(status = 'Approved') AS approved,
            SUM(status IN ('Pending','Under Review')) AS pending,
            SUM(status = 'Rejected') AS rejected
        FROM ClientRequests
        WHERE client_id = ?
    ");
    $stmt->execute([$user_id]);
    $req_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // 🕓 Requests over time (by week)
    $stmt2 = $conn->prepare("
        SELECT 
            DATE_FORMAT(requested_date, '%b %d') AS week_label,
            COUNT(*) AS request_count
        FROM ClientRequests
        WHERE client_id = ?
        GROUP BY WEEK(requested_date)
        ORDER BY requested_date DESC
        LIMIT 6
    ");
    $stmt2->execute([$user_id]);
    $requests_over_time = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "stats" => [
            "total_requests" => (int) $req_stats['total_requests'],
            "approved" => (int) $req_stats['approved'],
            "pending" => (int) $req_stats['pending'],
            "rejected" => (int) $req_stats['rejected'],
        ],
        "requests" => $requests_over_time
    ]);

} catch (PDOException $e) {
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
