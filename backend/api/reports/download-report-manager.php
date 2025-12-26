<?php
// backend/api/reports/download-report-manager.php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();

$db = new Database();
$conn = $db->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$company_id = $_SESSION['company_id'] ?? 0;
$role = $_SESSION['role'] ?? '';

if (!$user_id || strcasecmp($role, 'Manager') !== 0) {
    http_response_code(403);
    echo "Unauthorized access";
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$project_id = $input['project_id'] ?? 0;
$chart = $input['chart'] ?? '';

if (!$project_id) {
    http_response_code(400);
    echo "Missing project ID";
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            p.project_id,
            p.project_name,
            p.description,
            p.status,
            p.technology_stack,
            p.completion_percentage,
            p.start_date,
            p.end_date,
            p.budget_allocated AS budget,
            u.first_name AS client_first,
            u.last_name AS client_last
        FROM Projects p
        LEFT JOIN Users u ON p.client_id = u.user_id
        WHERE p.company_id = :cid AND p.manager_id = :mid AND p.project_id = :pid
        LIMIT 1
    ");
    $stmt->bindValue(':cid', $company_id, PDO::PARAM_INT);
    $stmt->bindValue(':mid', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':pid', $project_id, PDO::PARAM_INT);
    $stmt->execute();
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        http_response_code(404);
        echo "Project not found.";
        exit;
    }

    // Clean output buffer to prevent PDF corruption
    if (ob_get_length()) ob_end_clean();

    // Format Budget safely
    $formattedBudget = isset($p['budget']) ? '₹' . number_format((float)$p['budget'], 2) : 'N/A';

    // Build HTML
    $html = '
    <html>
    <head>
      <meta charset="UTF-8">
      <title>Project Report</title>
      <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; margin: 25px; }
        h1 { color: #4F46E5; text-align: center; font-size: 22px; margin-bottom: 10px; }
        h3 { text-align: center; color: #555; font-weight: normal; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; font-size: 12px; vertical-align: top; }
        th { background-color: #eef2ff; text-align: left; width: 30%; }
        .chart { text-align: center; margin-top: 20px; }
        .footer { text-align: center; font-size: 11px; margin-top: 40px; color: #666; }
      </style>
    </head>
    <body>
      <h1>Project Report</h1>
      <h3>' . htmlspecialchars($p['project_name']) . '</h3>

      <table>
        <tr><th>Client</th><td>' . htmlspecialchars(trim($p['client_first'] . ' ' . $p['client_last'])) . '</td></tr>
        <tr><th>Status</th><td>' . htmlspecialchars($p['status']) . '</td></tr>
        <tr><th>Completion</th><td>' . htmlspecialchars($p['completion_percentage']) . '%</td></tr>
        <tr><th>Technology Stack</th><td>' . htmlspecialchars($p['technology_stack']) . '</td></tr>
        <tr><th>Budget</th><td>' . $formattedBudget . '</td></tr>
        <tr><th>Start Date</th><td>' . htmlspecialchars($p['start_date']) . '</td></tr>
        <tr><th>End Date</th><td>' . htmlspecialchars($p['end_date']) . '</td></tr>
        <tr><th>Description</th><td>' . nl2br(htmlspecialchars($p['description'])) . '</td></tr>
      </table>';

    if (!empty($chart)) {
        $html .= '<div class="chart"><img src="' . htmlspecialchars($chart) . '" width="250"></div>';
    }

    $html .= '
      <div class="footer">
        Generated on ' . date('d M Y, h:i A') . '<br>
        Manager ID: ' . htmlspecialchars($user_id) . '
      </div>
    </body>
    </html>';

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $fileName = 'Project_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $p['project_name']) . '_Report.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo $dompdf->output();
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo "Database Error: " . $e->getMessage();
    exit;
}
?>
