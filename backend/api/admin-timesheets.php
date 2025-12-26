<?php
// backend/api/admin-timesheets.php
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_functions.php';

// show errors during debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = (new Database())->getConnection();

// Require admin login and company
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$company_id = $_SESSION['company_id'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['download']) && $_GET['download'] === 'csv') {
            exportTimesheetsCSV($db, $company_id);
        } else {
            getTimesheets($db, $company_id);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        break;
}

/**
 * Get timesheets for admin's company.
 * Joins Timesheets -> Tasks -> Projects so we can filter by project.company_id
 */
function getTimesheets($db, $company_id)
{
    try {
        $query = "
            SELECT
                ts.timesheet_id,
                CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
                p.project_name,
                t.task_name,
                ts.date,
                ts.hours_logged,
                ts.description,
                ts.status,
                ts.submitted_at,
                ts.approved_by_manager_id,
                ts.approved_at
            FROM Timesheets ts
            INNER JOIN Users u ON ts.user_id = u.user_id
            INNER JOIN Tasks t ON ts.task_id = t.task_id
            INNER JOIN Projects p ON t.project_id = p.project_id
            WHERE p.company_id = :company_id
            ORDER BY ts.date DESC, ts.timesheet_id DESC
        ";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':company_id', $company_id, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "data" => $rows]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}

/**
 * Export CSV (same join/filter)
 */
function exportTimesheetsCSV($db, $company_id)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=timesheets.csv');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        echo "Could not open output stream.";
        exit;
    }

    fputcsv($output, ['Timesheet ID','Employee','Project','Task','Date','Hours Logged','Description','Status','Submitted At','Approved By','Approved At']);

    $query = "
        SELECT
            ts.timesheet_id,
            CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
            p.project_name,
            t.task_name,
            ts.date,
            ts.hours_logged,
            ts.description,
            ts.status,
            ts.submitted_at,
            ts.approved_by_manager_id,
            ts.approved_at
        FROM Timesheets ts
        INNER JOIN Users u ON ts.user_id = u.user_id
        INNER JOIN Tasks t ON ts.task_id = t.task_id
        INNER JOIN Projects p ON t.project_id = p.project_id
        WHERE p.company_id = :company_id
        ORDER BY ts.date DESC, ts.timesheet_id DESC
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':company_id', $company_id, PDO::PARAM_INT);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // If approved_by_manager_id exists, try to resolve name (optional step)
        $approvedBy = $row['approved_by_manager_id'] ?? '';
        if ($approvedBy) {
            // try to fetch approver name (best-effort, avoid extra query per row if many rows)
            $approverStmt = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM Users WHERE user_id = :uid LIMIT 1");
            $approverStmt->bindParam(':uid', $approvedBy, PDO::PARAM_INT);
            if ($approverStmt->execute()) {
                $appr = $approverStmt->fetch(PDO::FETCH_ASSOC);
                if ($appr && !empty($appr['name'])) $approvedBy = $appr['name'];
            }
        }

        fputcsv($output, [
            $row['timesheet_id'] ?? '',
            $row['employee_name'] ?? '',
            $row['project_name'] ?? '',
            $row['task_name'] ?? '',
            $row['date'] ?? '',
            $row['hours_logged'] ?? '',
            $row['description'] ?? '',
            $row['status'] ?? '',
            $row['submitted_at'] ?? '',
            $approvedBy ?? '',
            $row['approved_at'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}
?>
