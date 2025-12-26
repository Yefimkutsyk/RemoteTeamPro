<?php
// backend/api/reports/save-report.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();
require_once __DIR__ . '/../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$company_id = $_SESSION['company_id'] ?? 0;
$role = $_SESSION['role'] ?? '';

if (!$user_id || strcasecmp($role, 'Manager') !== 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$project_id = (int)($input['project_id'] ?? 0);
if ($project_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid project ID']);
    exit;
}

try {
    $fields = [
        'description' => $input['description'] ?? null,
        'technology_stack' => $input['technology_stack'] ?? null,
        'status' => $input['status'] ?? null,
        'completion_percentage' => $input['completion_percentage'] ?? null,
        'start_date' => $input['start_date'] ?? null,
        'end_date' => $input['end_date'] ?? null
    ];

    $sql = "UPDATE Projects 
            SET description = :description,
                technology_stack = :stack,
                status = :status,
                completion_percentage = :completion,
                start_date = :start_date,
                end_date = :end_date,
                updated_at = NOW()
            WHERE project_id = :pid 
              AND company_id = :cid 
              AND manager_id = :mid";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':description', $fields['description']);
    $stmt->bindValue(':stack', $fields['technology_stack']);
    $stmt->bindValue(':status', $fields['status']);
    $stmt->bindValue(':completion', $fields['completion_percentage']);
    $stmt->bindValue(':start_date', $fields['start_date']);
    $stmt->bindValue(':end_date', $fields['end_date']);
    $stmt->bindValue(':pid', $project_id, PDO::PARAM_INT);
    $stmt->bindValue(':cid', $company_id, PDO::PARAM_INT);
    $stmt->bindValue(':mid', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Project report updated successfully']);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>
