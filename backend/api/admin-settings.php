<?php
// backend/api/admin-settings.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();

// DB + SMTP config
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/smtp.php';

// Autoload (works with your vendor/compat autoloader)
require_once __DIR__ . '/../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$database = new Database();
$conn = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

// 🔹 Get company ID helper
function getCompanyId($conn) {
    if (isset($_SESSION['company_id']) && (int)$_SESSION['company_id'] > 0) {
        return (int)$_SESSION['company_id'];
    }
    $stmt = $conn->prepare("SELECT company_id FROM Companies ORDER BY company_id LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['company_id'] : 0;
}

// 🔹 Notify all admins if admin key changes
function notifyAdminsOfKeyChange($conn, $company_id, $newKey) {
    $stmt = $conn->prepare("SELECT email, first_name, last_name FROM Users WHERE company_id = :cid AND role = 'Admin' AND status = 'Active'");
    $stmt->execute([':cid' => $company_id]);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$admins) return false;

    $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
    $smtpUser = defined('SMTP_USERNAME') ? SMTP_USERNAME : (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '');
    $smtpPass = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
    $smtpPort = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $smtpSecure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
    $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : $smtpUser;
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'RemoteTeamPro';

    $htmlBody = "
      <div style='font-family:Arial,Helvetica,sans-serif;color:#222'>
        <h2 style='color:#2b2b2b'>RemoteTeamPro — Admin Key Updated</h2>
        <p>The admin key for your company has been updated by an administrator.</p>
        <p><strong>New Admin Key:</strong></p>
        <pre style='background:#f6f6f6;padding:10px;border-radius:6px;color:#111'>{$newKey}</pre>
        <p>If you did not request or expect this change, please contact your system administrator immediately.</p>
        <p style='color:#666;font-size:12px'>This is an automated security notification from RemoteTeamPro.</p>
      </div>
    ";
    $plainBody = "RemoteTeamPro — Admin Key Updated\nNew Admin Key: {$newKey}\nIf this was not expected, contact your administrator.";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = $smtpSecure;
        $mail->Port = $smtpPort;
        $mail->setFrom($fromEmail, $fromName);
        $mail->isHTML(true);
        $mail->Subject = " RemoteTeamPro — Admin Key Updated";
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;

        foreach ($admins as $a) {
            if (!empty($a['email'])) {
                $mail->addAddress($a['email'], trim($a['first_name'].' '.$a['last_name']));
            }
        }

        // ✅ Enable debug log to backend/logs/mail_debug.log
        $logPath = __DIR__ . '/../logs/mail_debug.log';
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) use ($logPath) {
            file_put_contents($logPath, date('[Y-m-d H:i:s] ') . $str . "\n", FILE_APPEND);
        };

        $sent = $mail->send();
        file_put_contents($logPath, date('[Y-m-d H:i:s] ') . "Mail send() result: " . ($sent ? "Success" : "Failed") . "\n", FILE_APPEND);
        return $sent;
    } catch (Exception $e) {
        $errorLog = __DIR__ . '/../logs/mail_error.log';
        file_put_contents($errorLog, date('[Y-m-d H:i:s] ') . "Mailer Error: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

// 🔹 GET — fetch company info
if ($method === 'GET') {
    $company_id = getCompanyId($conn);
    if ($company_id <= 0) {
        echo json_encode(['success'=>false,'message'=>'No company found']);
        exit;
    }

    $stmt = $conn->prepare("SELECT company_id, company_name, company_email, company_phone, company_address, services, admin_key FROM Companies WHERE company_id = :cid LIMIT 1");
    $stmt->execute([':cid' => $company_id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        echo json_encode(['success'=>false,'message'=>'Company not found']);
        exit;
    }

    $services = [];
    if (!empty($company['services'])) {
        $decoded = json_decode($company['services'], true);
        $services = is_array($decoded) ? $decoded : array_map('trim', explode(',', $company['services']));
    }

    $company['services'] = $services;
    echo json_encode(['success'=>true,'company'=>$company]);
    exit;
}

// 🔹 POST — update company info & admin key
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $company_id = getCompanyId($conn);

    if ($company_id <= 0) {
        echo json_encode(['success'=>false,'message'=>'No company found to update']);
        exit;
    }

    $stmt = $conn->prepare("SELECT admin_key, services FROM Companies WHERE company_id = :cid LIMIT 1");
    $stmt->execute([':cid' => $company_id]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);

    $prevKey = $prev['admin_key'] ?? null;
    $name = $input['company_name'] ?? null;
    $email = $input['company_email'] ?? null;
    $phone = $input['company_phone'] ?? null;
    $address = $input['company_address'] ?? null;
    $newKey = $input['admin_key'] ?? null;
    $servicesArr = $input['services'] ?? [];

    if (!is_array($servicesArr)) $servicesArr = [];
    $servicesJson = json_encode(array_values(array_filter(array_map('trim', $servicesArr))));

    if ($newKey !== null && $newKey !== '') {
        if (!preg_match('/^(?=.*\d)[A-Za-z\d-]{12,}$/', $newKey)) {
            echo json_encode(['success'=>false,'message'=>'Admin key invalid. Must be at least 12 characters and include a number. Example: qwe123-rty-456']);
            exit;
        }
    } else {
        $newKey = $prevKey;
    }

    try {
        $stmt = $conn->prepare("UPDATE Companies 
            SET company_name = :name, company_email = :email, company_phone = :phone, 
                company_address = :address, services = :services, admin_key = :admin_key, 
                updated_at = NOW() WHERE company_id = :cid");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':address' => $address,
            ':services' => $servicesJson,
            ':admin_key' => $newKey,
            ':cid' => $company_id
        ]);

        $changedKey = ($prevKey !== $newKey);
        if ($changedKey) notifyAdminsOfKeyChange($conn, $company_id, $newKey);

        echo json_encode(['success'=>true,'message'=>'Company updated'.($changedKey ? ' — admin key changed; admins emailed.' : '')]);
    } catch (Exception $e) {
        error_log("admin-settings save error: ".$e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid request']);
exit;
