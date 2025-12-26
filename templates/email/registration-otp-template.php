<?php
$emailContent = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email Verification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .otp-box { 
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            font-size: 24px;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Email Verification</h2>
        <p>Hello ' . $firstName . ' ' . $lastName . ',</p>
        <p>Thank you for registering with RemoteTeamPro. To complete your registration, please use the following verification code:</p>
        
        <div class="otp-box">
            <strong>' . $otp . '</strong>
        </div>
        
        <p>This code will expire in 10 minutes.</p>
        <p>If you did not request this verification code, please ignore this email.</p>
        
        <p>Best regards,<br>RemoteTeamPro Team</p>
    </div>
</body>
</html>
';
?>