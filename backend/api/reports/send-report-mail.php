<?php
// backend/api/reports/send-report-mail.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php'; // for PHPMailer via Composer

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
$project_id = (int)($input['project_id'] ?? 0);

if ($project_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid project ID']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            p.project_name,
            p.description,
            p.status,
            p.completion_percentage,
            p.start_date,
            p.end_date,
            p.technology_stack,
            p.budget_allocated AS budget,
            u.email AS client_email,
            u.first_name,
            u.last_name
        FROM Projects p
        LEFT JOIN Users u ON p.client_id = u.user_id
        WHERE p.project_id = :pid 
          AND p.company_id = :cid
          AND p.manager_id = :mid
        LIMIT 1
    ");
    $stmt->execute([
        ':pid' => $project_id,
        ':cid' => $company_id,
        ':mid' => $user_id
    ]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit;
    }

    // Prepare email
    $to = $project['client_email'];
    $subject = "Project Report: " . $project['project_name'];

    $budgetFormatted = '₹' . number_format((float)$project['budget'], 2);

    $body = "
        <h2 style='color:#4F46E5;'>Project Report</h2>
        <p><strong>Project:</strong> {$project['project_name']}</p>
        <p><strong>Status:</strong> {$project['status']}</p>
        <p><strong>Completion:</strong> {$project['completion_percentage']}%</p>
        <p><strong>Technology Stack:</strong> {$project['technology_stack']}</p>
        <p><strong>Budget:</strong> {$budgetFormatted}</p>
        <p><strong>Start Date:</strong> {$project['start_date']}</p>
        <p><strong>End Date:</strong> {$project['end_date']}</p>
        <p><strong>Description:</strong><br>" . nl2br(htmlspecialchars($project['description'])) . "</p>
        <br>
        <p>Regards,<br>Project Manager</p>
    ";

    // === Setup PHPMailer ===
    $mail = new PHPMailer(true);
    try {
        // SMTP config (use your credentials below)
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // or your mail server
        $mail->SMTPAuth = true;
        $mail->Username = 'examplemail@mail.com'; // 🔹 Replace with your sender email
        $mail->Password = 'rpwf swpk znws sodg'; // 🔹 Use App Password (NOT Gmail login password)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('examplemail@mail.com', 'RemoteTeamPro Reports');
        $mail->addAddress($to, $project['first_name'] . ' ' . $project['last_name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        echo json_encode(['success' => true, 'message' => 'Report email sent successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Mail Error: ' . $mail->ErrorInfo]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}
?>
