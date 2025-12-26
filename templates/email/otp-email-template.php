<?php
// backend/templates/email/otp-email-template.php

function getEmailChangeOtpTemplate($otp, $newEmail) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>RemoteTeamPro Email Change Verification</title>
        <style>
            /* Reset styles */
            body, html {
                margin: 0;
                padding: 0;
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333333;
                background-color: #f0f2f5;
            }
            * {
                box-sizing: border-box;
            }

            /* Main container */
            .email-container {
                max-width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                border-radius: 10px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                overflow: hidden;
            }

            /* Header styles */
            .email-header {
                background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
                padding: 30px 20px;
                text-align: center;
            }
            .company-logo {
                color: #ffffff;
                font-size: 28px;
                font-weight: bold;
                margin: 0;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            /* Content styles */
            .email-content {
                padding: 40px 30px;
                background-color: #ffffff;
            }
            .greeting {
                font-size: 24px;
                font-weight: bold;
                color: #1F2937;
                margin-bottom: 20px;
            }
            .message {
                color: #4B5563;
                font-size: 16px;
                margin-bottom: 30px;
            }
            .highlight {
                background-color: #F0F9FF;
                border: 1px solid #BAE6FD;
                border-radius: 6px;
                padding: 10px 15px;
                color: #0369A1;
                font-weight: 500;
                display: inline-block;
                margin: 5px 0;
            }

            /* OTP Box styles */
            .otp-container {
                background: linear-gradient(to bottom, #F9FAFB, #F3F4F6);
                border: 1px solid #E5E7EB;
                border-radius: 12px;
                padding: 25px;
                text-align: center;
                margin: 30px 0;
            }
            .otp-code {
                font-size: 36px;
                font-weight: bold;
                color: #8B5CF6;
                letter-spacing: 8px;
                padding: 10px;
                background-color: #ffffff;
                border-radius: 8px;
                border: 2px solid #E5E7EB;
                margin: 10px 0;
                text-align: center;
            }
            .otp-expiry {
                color: #6B7280;
                font-size: 14px;
                margin-top: 10px;
            }

            /* Security Warning styles */
            .security-warning {
                background-color: #FEF2F2;
                border-left: 4px solid #EF4444;
                padding: 15px;
                margin: 20px 0;
                border-radius: 0 8px 8px 0;
            }
            .warning-icon {
                color: #EF4444;
                font-size: 20px;
                margin-right: 10px;
            }
            .warning-text {
                color: #991B1B;
                font-size: 14px;
                font-weight: 500;
            }

            /* Footer styles */
            .email-footer {
                padding: 20px;
                text-align: center;
                background-color: #F9FAFB;
                border-top: 1px solid #E5E7EB;
            }
            .footer-text {
                color: #6B7280;
                font-size: 12px;
                margin: 5px 0;
            }
            .divider {
                height: 1px;
                background-color: #E5E7EB;
                margin: 15px 0;
            }

            /* Responsive design */
            @media only screen and (max-width: 600px) {
                .email-container {
                    width: 100%;
                    border-radius: 0;
                }
                .email-content {
                    padding: 20px 15px;
                }
                .otp-code {
                    font-size: 30px;
                    letter-spacing: 6px;
                }
            }
        </style>
    </head>
    <body>
        <div style="padding: 20px;">
            <div class="email-container">
                <!-- Header -->
                <div class="email-header">
                    <h1 class="company-logo">RemoteTeamPro</h1>
                </div>

                <!-- Content -->
                <div class="email-content">
                    <div class="greeting">Email Change Verification</div>
                    
                    <div class="message">
                        <p>Hello,</p>
                        <p>You have requested to change your email address to: <span class="highlight">' . htmlspecialchars($newEmail) . '</span></p>
                        <p>To verify this new email address, please use the verification code below:</p>
                    </div>

                    <!-- OTP Box -->
                    <div class="otp-container">
                        <div class="otp-code">' . $otp . '</div>
                        <div class="otp-expiry">⏰ This code will expire in 5 minutes</div>
                    </div>

                    <!-- Security Warning -->
                    <div class="security-warning">
                        <span class="warning-icon">⚠️</span>
                        <span class="warning-text">
                            For your security:
                            <ul style="margin: 5px 0;">
                                <li>Never share this code with anyone</li>
                                <li>Our team will never ask for your verification code</li>
                                <li>If you did not request this change, please contact support immediately</li>
                            </ul>
                        </span>
                    </div>

                    <div class="message">
                        <p>If you did not request to change your email address, please ignore this message and ensure your account password is secure.</p>
                    </div>
                </div>

                <!-- Footer -->
                <div class="email-footer">
                    <p class="footer-text">This is an automated message. Please do not reply to this email.</p>
                    <div class="divider"></div>
                    <p class="footer-text">&copy; ' . date("Y") . ' RemoteTeamPro. All rights reserved.</p>
                    <p class="footer-text">Secure • Professional • Reliable</p>
                </div>
            </div>
        </div>
    </body>
    </html>';
}

function getOtpEmailTemplate($otp) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>RemoteTeamPro Security Verification</title>
        <style>
            /* Reset styles */
            body, html {
                margin: 0;
                padding: 0;
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333333;
                background-color: #f0f2f5;
            }
            * {
                box-sizing: border-box;
            }

            /* Main container */
            .email-container {
                max-width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                border-radius: 10px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                overflow: hidden;
            }

            /* Header styles */
            .email-header {
                background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
                padding: 30px 20px;
                text-align: center;
            }
            .company-logo {
                color: #ffffff;
                font-size: 28px;
                font-weight: bold;
                margin: 0;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            /* Content styles */
            .email-content {
                padding: 40px 30px;
                background-color: #ffffff;
            }
            .greeting {
                font-size: 24px;
                font-weight: bold;
                color: #1F2937;
                margin-bottom: 20px;
            }
            .message {
                color: #4B5563;
                font-size: 16px;
                margin-bottom: 30px;
            }

            /* OTP Box styles */
            .otp-container {
                background: linear-gradient(to bottom, #F9FAFB, #F3F4F6);
                border: 1px solid #E5E7EB;
                border-radius: 12px;
                padding: 25px;
                text-align: center;
                margin: 30px 0;
            }
            .otp-code {
                font-size: 36px;
                font-weight: bold;
                color: #8B5CF6;
                letter-spacing: 8px;
                padding: 10px;
                background-color: #ffffff;
                border-radius: 8px;
                border: 2px solid #E5E7EB;
                margin: 10px 0;
                text-align: center;
            }
            .otp-expiry {
                color: #6B7280;
                font-size: 14px;
                margin-top: 10px;
            }

            /* Security Warning styles */
            .security-warning {
                background-color: #FEF2F2;
                border-left: 4px solid #EF4444;
                padding: 15px;
                margin: 20px 0;
                border-radius: 0 8px 8px 0;
            }
            .warning-icon {
                color: #EF4444;
                font-size: 20px;
                margin-right: 10px;
            }
            .warning-text {
                color: #991B1B;
                font-size: 14px;
                font-weight: 500;
            }

            /* Footer styles */
            .email-footer {
                padding: 20px;
                text-align: center;
                background-color: #F9FAFB;
                border-top: 1px solid #E5E7EB;
            }
            .footer-text {
                color: #6B7280;
                font-size: 12px;
                margin: 5px 0;
            }
            .divider {
                height: 1px;
                background-color: #E5E7EB;
                margin: 15px 0;
            }

            /* Responsive design */
            @media only screen and (max-width: 600px) {
                .email-container {
                    width: 100%;
                    border-radius: 0;
                }
                .email-content {
                    padding: 20px 15px;
                }
                .otp-code {
                    font-size: 30px;
                    letter-spacing: 6px;
                }
            }
        </style>
    </head>
    <body>
        <div style="padding: 20px;">
            <div class="email-container">
                <!-- Header -->
                <div class="email-header">
                    <h1 class="company-logo">RemoteTeamPro</h1>
                </div>

                <!-- Content -->
                <div class="email-content">
                    <div class="greeting">Security Verification Required</div>
                    
                    <div class="message">
                        <p>Hello,</p>
                        <p>A login attempt was made to your RemoteTeamPro account. To ensure your account security, please use the verification code below to complete the login process:</p>
                    </div>

                    <!-- OTP Box -->
                    <div class="otp-container">
                        <div class="otp-code">' . $otp . '</div>
                        <div class="otp-expiry"> This code will expire in 5 minutes</div>
                    </div>

                    <!-- Security Warning -->
                    <div class="security-warning">
                        <span class="warning-icon"></span>
                        <span class="warning-text">
                            For your security:
                            <ul style="margin: 5px 0;">
                                <li>Never share this code with anyone</li>
                                <li>Our team will never ask for your verification code</li>
                                <li>Ignore this email if you did not request this code</li>
                            </ul>
                        </span>
                    </div>

                    <div class="message">
                        <p>If you did not attempt to log in to your account, please review your account security and consider changing your password.</p>
                    </div>
                </div>

                <!-- Footer -->
                <div class="email-footer">
                    <p class="footer-text">This is an automated message. Please do not reply to this email.</p>
                    <div class="divider"></div>
                    <p class="footer-text">&copy; ' . date('Y') . ' RemoteTeamPro. All rights reserved.</p>
                    <p class="footer-text">Secure • Professional • Reliable</p>
                </div>
            </div>
        </div>
    </body>
    </html>';
}