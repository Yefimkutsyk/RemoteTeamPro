<?php
// backend/api/reports/export-pdf.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';


use Dompdf\Dompdf;
use Dompdf\Options;

$db = new Database();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$project_id = (int)($_GET['project_id'] ?? ($_POST['project_id'] ?? 0));
if ($project_id <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'project_id required']);
    exit;
}

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

if (!$user_id) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

try {
    // Fetch project and company
    $stmt = $conn->prepare("SELECT p.*, c.company_name, c.company_email FROM Projects p LEFT JOIN Companies c ON p.company_id = c.company_id WHERE p.project_id = :pid AND p.company_id = :cid LIMIT 1");
    $stmt->execute([':pid'=>$project_id, ':cid'=>$company_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Project not found']); exit; }

    if ($role === 'Manager' && (int)$project['manager_id'] !== $user_id) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }
    if ($role === 'Client' && (int)$project['client_id'] !== $user_id) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }

    // accept payload with charts
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true) ?? $_POST;

    $companyName = htmlspecialchars($project['company_name'] ?? 'Company');
    $projectName = htmlspecialchars($project['project_name'] ?? '');
    $description = nl2br(htmlspecialchars($project['description'] ?? ''));
    $startDate = $project['start_date'] ?? '';
    $deadline = $project['deadline'] ?? '';
    $completion = floatval($project['completion_percentage'] ?? 0);
    $budget = $project['budget_allocated'] !== null ? number_format((float)$project['budget_allocated'],2) : 'N/A';
    $techStack = htmlspecialchars($project['technology_stack'] ?? '');

    // build charts HTML from base64 images
    $chartsHtml = '';
    $charts = $payload['charts'] ?? [];
    if (is_array($charts) && count($charts) > 0) {
        foreach ($charts as $b64) {
            $b64 = preg_replace('#^data:image/\w+;base64,#i', '', $b64);
            $chartsHtml .= "<div style='margin-bottom:12px;text-align:center'><img style='max-width:100%;height:auto' src='data:image/png;base64,{$b64}' /></div>";
        }
    }

    // simple branded header (you can provide logo url in Companies table if available)
    $companyEmail = htmlspecialchars($project['company_email'] ?? 'noreply@remoteteampro.com');

    $html = "
    <html><head><meta charset='utf-8'/><style>
      body{font-family:Arial,Helvetica,sans-serif;color:#222}
      .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
      .brand{font-size:18px;font-weight:700;color:#0b66c3}
      .meta td{padding:6px 4px}
      .footer{position:fixed;bottom:10px;left:0;right:0;text-align:center;font-size:11px;color:#999}
    </style></head><body>
      <div class='header'>
        <div class='brand'>{$companyName} — Project Report</div>
        <div style='text-align:right;font-size:12px;color:#666'>Generated: ".date('Y-m-d H:i')."</div>
      </div>
      <table class='meta' style='width:100%;margin-bottom:12px'>
        <tr><td style='width:50%'><strong>Project:</strong> {$projectName}</td><td style='width:50%'><strong>Company:</strong> {$companyName}</td></tr>
        <tr><td><strong>Start:</strong> {$startDate}</td><td><strong>Deadline:</strong> {$deadline}</td></tr>
        <tr><td><strong>Completion:</strong> {$completion}%</td><td><strong>Budget:</strong> {$budget}</td></tr>
        <tr><td colspan='2'><strong>Tech Stack:</strong> {$techStack}</td></tr>
      </table>
      <div style='margin-bottom:8px'><strong>Description</strong><div style='margin-top:6px'>{$description}</div></div>
      <div><h3>Charts & Visuals</h3>{$chartsHtml}</div>
      <div class='footer'>RemoteTeamPro — {$companyName} — {$companyEmail}</div>
    </body></html>
    ";

    $options = new Options();
    $options->setIsRemoteEnabled(true);
    $options->setChroot(__DIR__ . '/../../');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4','portrait');
    $dompdf->render();
    $pdfOutput = $dompdf->output();

    if (isset($_GET['action']) && $_GET['action'] === 'download') {
        header("Content-Type: application/pdf");
        header("Content-Length: " . strlen($pdfOutput));
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/','_', $projectName);
        header("Content-Disposition: attachment; filename=\"project_report_{$safeName}_{$project_id}.pdf\"");
        echo $pdfOutput;
        exit;
    }

    $pdfBase64 = base64_encode($pdfOutput);
    echo json_encode(['success'=>true,'pdf_base64'=>$pdfBase64,'message'=>'PDF generated']);
    exit;

} catch (Exception $e) {
    error_log("export-pdf error: ".$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
    exit;
}
