<?php
require_once(__DIR__ . '/../../config/database.php');
$conn = (new Database())->connect();

// For each company, auto-checkout employees who didn’t check out yet
$companies = $conn->query("SELECT company_id, auto_checkout_time FROM Companies WHERE auto_checkout_time IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$today = date('Y-m-d');

foreach ($companies as $c) {
    $cid = $c['company_id'];
    $checkout_time = $c['auto_checkout_time'];

    $sql = "UPDATE Attendance a
            JOIN Employees e ON a.employee_id = e.employee_id
            SET a.check_out_time = ?, 
                a.hours_worked = TIMESTAMPDIFF(MINUTE, a.check_in_time, ?)/60
            WHERE a.attendance_date = ? 
              AND e.company_id = ?
              AND a.check_in_time IS NOT NULL 
              AND a.check_out_time IS NULL";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$checkout_time, $checkout_time, $today, $cid]);
}

echo json_encode(["success" => true, "message" => "Auto checkout completed"]);
?>
