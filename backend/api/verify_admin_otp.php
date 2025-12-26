<?php
// backend/api/verify_admin_otp.php
// Expects POST: user_id, otp_code
// Activates the user if OTP matches and not expired. Returns JSON.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

function json_exit($arr) {
    echo json_encode($arr);
    exit;
}

try {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $otp_code = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';

    if ($user_id <= 0 || $otp_code === '') {
        json_exit(['success'=>false, 'message'=>'Missing user_id or otp_code.']);
    }

    // ✅ FIXED: Correct table name (userotps)
    $stmt = $pdo->prepare("
        SELECT * 
        FROM userotps 
        WHERE user_id = ? 
          AND otp_code = ? 
          AND purpose = 'register' 
          AND used = 0 
        LIMIT 1
    ");
    $stmt->execute([$user_id, $otp_code]);
    $otp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$otp) {
        json_exit(['success'=>false, 'message'=>'Invalid OTP.']);
    }

    $now = new DateTime();
    $expiry = new DateTime($otp['expiry']);
    if ($now > $expiry) {
        json_exit(['success'=>false, 'message'=>'OTP has expired.']);
    }

    $pdo->beginTransaction();

    // ✅ Mark OTP as used
    $stmt = $pdo->prepare("UPDATE userotps SET used = 1 WHERE otp_id = ?");
    $stmt->execute([$otp['otp_id']]);

    // ✅ Activate admin in users table
    $stmt = $pdo->prepare("UPDATE users SET status = 'Active', email_verified = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);

    $pdo->commit();

    json_exit(['success'=>true, 'message'=>'OTP verified successfully! Admin account activated.']);

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log("verify_admin_otp.php error: " . $e->getMessage());
    json_exit(['success'=>false, 'message'=>'Server error: ' . $e->getMessage()]);
}
?>
