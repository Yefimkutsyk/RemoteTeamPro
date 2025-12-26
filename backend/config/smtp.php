<?php
// backend/config/smtp.php

if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', 'examplemail@mail.com');
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', 'example password'); // placeholder removed for security
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);  // Changed to 587 for TLS
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', 'tls');  // Changed to TLS for port 587
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', 'examplemail@mail.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'RemoteTeamPro');
