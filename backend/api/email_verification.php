<?php
// backend/api/email_verification.php
// FULL file: send & verify OTP for registration and change-email (uses sessions, PHPMailer)

// Set session cookie params for cross-origin requests (development)
// Adjust as needed for production (e.g., use secure cookies with HTTPS)
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '', // set to your domain if needed
    'secure' => false, // set true if using HTTPS
    'httponly' => true,
    'samesite' => 'Lax' // or 'None' if frontend/backend are on different origins and using HTTPS
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
// Debug: Output session and cookie info for troubleshooting
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    echo json_encode([
        'session_id' => session_id(),
        'session_vars' => $_SESSION,
        'cookies' => $_COOKIE,
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
    ]);
    exit;
}
// CORS: allow the requesting origin and allow credentials (cookies) so PHP session persists across fetch calls
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? null);
if ($origin) {
    // In production, validate $origin against a whitelist if needed
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, Cookie");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ---- PHPMailer / SMTP config - EDIT THESE for your environment ----
// Defaults (can be overridden by backend/config/smtp.php)
$SMTP_HOST   = 'smtp.gmail.com';       // e.g. smtp.gmail.com
    $SMTP_USER   = 'examplemail@mail.com'; // SMTP username (placeholder)
    $SMTP_PASS   = 'example password';    // SMTP password placeholder: replace with real app password in production
$SMTP_PORT   = 587;
$SMTP_SECURE = 'tls';                  // or 'ssl'
    $FROM_EMAIL  = 'examplemail@mail.com';
$FROM_NAME   = 'RemoteTeamPro';

// Try to load optional config file to override SMTP settings
$smtp_conf_file = __DIR__ . '/../config/smtp.php';
if (file_exists($smtp_conf_file)) {
    $smtp_cfg = include $smtp_conf_file;
    if (is_array($smtp_cfg)) {
        $SMTP_HOST   = $smtp_cfg['host'] ?? $SMTP_HOST;
        $SMTP_USER   = $smtp_cfg['user'] ?? $SMTP_USER;
        $SMTP_PASS   = $smtp_cfg['pass'] ?? $SMTP_PASS;
        $SMTP_PORT   = $smtp_cfg['port'] ?? $SMTP_PORT;
        $SMTP_SECURE = $smtp_cfg['secure'] ?? $SMTP_SECURE;
        $FROM_EMAIL  = $smtp_cfg['from_email'] ?? $FROM_EMAIL;
        $FROM_NAME   = $smtp_cfg['from_name'] ?? $FROM_NAME;
    }
}
// ------------------------------------------------------------------

$action = $_GET['action'] ?? '';

// Debug log helper (temporary) - writes request and session info to backend/logs/otp_debug.log
function otp_debug_log($msg) {
    $logDir = __DIR__ . '/../logs';
    $logFile = $logDir . '/otp_debug.log';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $time = date('Y-m-d H:i:s');
    $entry = "[{$time}] " . $msg . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// Log incoming request basics for debugging session issues
otp_debug_log("REQUEST URI: " . ($_SERVER['REQUEST_URI'] ?? ''));
otp_debug_log("REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
otp_debug_log("HTTP_ORIGIN: " . ($_SERVER['HTTP_ORIGIN'] ?? ''));
otp_debug_log("COOKIES: " . json_encode($_COOKIE));
otp_debug_log("SESSION ID: " . session_id());
otp_debug_log("SESSION VARS BEFORE: " . json_encode($_SESSION));

function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// --- Database connection (attempt class-based then fallback) ---
$pdo = null;
if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();
} elseif (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php'; // expects $pdo to be defined in this file
    // $pdo should exist now
} else {
    json_error('Database configuration not found on server', 500);
}

// optional: include DB functions if you want logging
if (file_exists(__DIR__ . '/../includes/db_functions.php')) {
    require_once __DIR__ . '/../includes/db_functions.php';
    $db_functions = new DB_Functions($pdo);
}

// include PHPMailer autoload. Prefer composer vendor, fallback to bundled otp_app PHPMailer
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    // Fallback to bundled PHPMailer shipped in otp_app/PHPMailer
    $fallback = realpath(__DIR__ . '/../../otp_app/php/PHPMailer/src');
    if ($fallback && is_dir($fallback)) {
        require_once $fallback . '/Exception.php';
        require_once $fallback . '/PHPMailer.php';
        require_once $fallback . '/SMTP.php';
    } else {
        // No autoloader or bundled PHPMailer found — email send will fail with clear message
        // We'll proceed so error appears in response
    }
}

// Helper: read POST field safely (support both form-data and JSON if needed)
function post_field($name) {
    if (isset($_POST[$name])) return trim($_POST[$name]);
    // try JSON body
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json[$name])) return trim($json[$name]);
    }
    return null;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Only support change-email OTPs now. Registration no longer requires OTP.
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only allow change_email flow (user must be logged in)
    if (!isset($_SESSION['user_id'])) {
        json_error('Not authenticated. OTP for email change requires login.', 403);
    }

    $email = post_field('email');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Invalid email.');
    }

    // Prevent changing to an email already used by another account
    $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing && $existing['user_id'] != $_SESSION['user_id']) {
        json_error('Email already in use by another account', 400);
    }

    // Generate OTP
    try {
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } catch (\Exception $e) {
        $otp = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    $purpose = 'change_email';
    $expiry_dt = date('Y-m-d H:i:s', time() + 300);
    $companyId = $_SESSION['company_id'] ?? null;

    $insertStmt = $pdo->prepare("INSERT INTO userotps (user_id, email, otp_code, purpose, expiry, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $uid = $_SESSION['user_id'];
    $insertStmt->execute([$uid, $email, $otp, $purpose, $expiry_dt]);
    $otpId = $pdo->lastInsertId();

    otp_debug_log("Inserted DB OTP id={$otpId} for user_id={$uid} email={$email}, otp={$otp}, purpose={$purpose}, expiry={$expiry_dt}");

    // Send OTP via PHPMailer
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP_USER;
        $mail->Password = $SMTP_PASS;
        $mail->SMTPSecure = $SMTP_SECURE;
        $mail->Port = $SMTP_PORT;

        // Get the email template
        require_once __DIR__ . '/../../templates/email/otp-email-template.php';
        
        $mail->setFrom($FROM_EMAIL, $FROM_NAME);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Email Change Verification for RemoteTeamPro';
        $mail->Body = getEmailChangeOtpTemplate($otp, $email);
        $mail->AltBody = "Your verification code for email change is: {$otp}\nThis code will expire in 5 minutes.\nFor security, never share this code with anyone.";

        $mail->send();
        otp_debug_log("PHPMailer send() succeeded for change_email to {$email}");
        echo json_encode(['message' => "OTP sent to {$email}"]);
        exit;
    } catch (Exception $e) {
        otp_debug_log("PHPMailer send() failed for change_email to {$email}. Error: " . ($mail->ErrorInfo ?? '') . " Exception: " . $e->getMessage());
        json_error('Failed to send OTP email. Mailer Error: ' . $e->getMessage(), 500);
    }
}

// ACTION = verify (verify OTP for change-email)
if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        json_error('Not authenticated. OTP verification requires login.', 403);
    }

    $email = post_field('email');
    $otp   = post_field('otp');
    if (!$email || !$otp) json_error('Missing email or otp', 400);

    // Find latest unused OTP for this user/email/purpose
    $stmt = $pdo->prepare("SELECT * FROM userotps WHERE user_id = ? AND email = ? AND purpose = 'change_email' AND used = 0 ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$_SESSION['user_id'], $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_error('No OTP found for this email. Please request a new code.', 404);
    }

    // Check expiry
    if (strtotime($row['expiry']) < time()) {
        json_error('OTP expired. Please request a new code.', 400);
    }

    if ($row['otp_code'] !== $otp) {
        json_error('Invalid OTP code.', 400);
    }

    // All good — update user's email and mark OTP used in a transaction
    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare("UPDATE Users SET email = ?, updated_at = NOW() WHERE user_id = ? AND company_id = ?");
        $companyId = $_SESSION['company_id'] ?? null;
        $res = $update->execute([$email, $_SESSION['user_id'], $companyId]);
        if (!$res) {
            $pdo->rollBack();
            json_error('Failed to update email in database.', 500);
        }

        $mark = $pdo->prepare("UPDATE userotps SET used = 1 WHERE otp_id = ?");
        $mark->execute([$row['otp_id']]);

        $pdo->commit();

        // Optional logging
        if (isset($db_functions) && method_exists($db_functions, 'logActivity')) {
            $db_functions->logActivity($_SESSION['user_id'], 'Email Changed', "User changed email to {$email}");
        }

        echo json_encode(['message' => 'Email changed and verified successfully.']);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        otp_debug_log('Error during email change verify: ' . $e->getMessage());
        json_error('Failed to update email in database.', 500);
    }
}

// If action not recognized
json_error('Invalid action or method', 400);
