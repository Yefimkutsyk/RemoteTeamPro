<?php
// backend/api/verify_admin_otp_page.php
// Displays OTP input page for admin verification

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$email   = isset($_GET['email']) ? htmlspecialchars($_GET['email']) : '';

if ($user_id <= 0 || empty($email)) {
    die("<h3 style='color:red; text-align:center; margin-top:20%;'>Invalid verification link or missing details.</h3>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify OTP - RemoteTeamPro</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #0f0f10;
      color: #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
      margin: 0;
    }
    .otp-container {
      background: #1f1f23;
      padding: 30px;
      border-radius: 12px;
      box-shadow: 0 5px 15px rgba(0,0,0,0.3);
      width: 380px;
      text-align: center;
    }
    h2 { color: #a855f7; margin-bottom: 8px; }
    p { color: #9ca3af; margin-bottom: 20px; }
    input[type="text"] {
      width: 80%;
      padding: 10px;
      font-size: 18px;
      letter-spacing: 3px;
      text-align: center;
      border: 1px solid #6b46c1;
      border-radius: 6px;
      background-color: #2a2a30;
      color: white;
      outline: none;
    }
    button {
      margin-top: 15px;
      padding: 10px 20px;
      background-color: #6b46c1;
      color: white;
      border: none;
      border-radius: 6px;
      font-size: 16px;
      cursor: pointer;
    }
    button:hover { background-color: #553c9a; }
    #message {
      margin-top: 15px;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <div class="otp-container">
    <h2>Email Verification</h2>
    <p>We sent a 6-digit code to <b><?php echo $email; ?></b>.</p>

    <form id="otpForm">
      <input type="text" id="otp_code" name="otp_code" maxlength="6" placeholder="Enter OTP" required>
      <br>
      <button type="submit">Verify OTP</button>
    </form>

    <div id="message"></div>
  </div>

  <script>
    document.getElementById('otpForm').addEventListener('submit', async (e) => {
      e.preventDefault();

      const otp = document.getElementById('otp_code').value.trim();
      const msg = document.getElementById('message');

      if (otp.length !== 6 || isNaN(otp)) {
        msg.textContent = "Please enter a valid 6-digit OTP.";
        msg.style.color = "red";
        return;
      }

      msg.textContent = "Verifying...";
      msg.style.color = "#bbb";

      try {
        const response = await fetch('/RemoteTeamPro/backend/api/verify_admin_otp.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            user_id: "<?php echo $user_id; ?>",
            otp_code: otp
          })
        });

        const data = await response.json();

        if (data.success) {
          msg.textContent = "✅ " + data.message;
          msg.style.color = "limegreen";
          setTimeout(() => {
            window.location.href = "/RemoteTeamPro/frontend/src/pages/login.html"; // Redirect after success
          }, 2000);
        } else {
          msg.textContent = "❌ " + data.message;
          msg.style.color = "red";
        }

      } catch (error) {
        msg.textContent = "Server error. Please try again.";
        msg.style.color = "red";
        console.error(error);
      }
    });
  </script>
</body>
</html>
