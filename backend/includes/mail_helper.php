<?php
// backend/includes/mail_helper.php

require_once __DIR__ . '/../config/smtp.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function setupMailer() {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        
        // Set default sender
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        return $mail;
    } catch (Exception $e) {
        error_log("Mailer setup error: " . $e->getMessage());
        throw $e;
    }
}

function sendOTPEmail($email, $firstName, $lastName, $otp) {
    try {
        $mail = setupMailer();
        
        $mail->addAddress($email, $firstName . ' ' . $lastName);
        $mail->isHTML(true);
        $mail->Subject = 'Email Verification - RemoteTeamPro';
        
        // Get email template
        ob_start();
        require __DIR__ . '/../../templates/email/registration-otp-template.php';
        $mail->Body = $emailContent;
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        throw $e;
    }
}