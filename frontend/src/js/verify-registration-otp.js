document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('verifyOtpForm');
    const messageArea = document.getElementById('messageArea');
    const messageText = document.getElementById('message');
    const emailDisplay = document.getElementById('emailDisplay');
    
    function showMessage(message, type) {
        messageArea.classList.remove('hidden');
        messageText.textContent = message;
        if (type === 'error') {
            messageArea.classList.remove('text-green-500');
            messageArea.classList.add('text-red-500');
        } else {
            messageArea.classList.remove('text-red-500');
            messageArea.classList.add('text-green-500');
        }
    }

    // Get email from URL parameters or localStorage
    const urlParams = new URLSearchParams(window.location.search);
    const emailFromUrl = urlParams.get('email');
    const emailFromStorage = localStorage.getItem('registrationEmail');
    const email = emailFromUrl || emailFromStorage;
    
    if (!email) {
        showMessage('No email found. Please start registration again.', 'error');
        setTimeout(() => {
            window.location.href = 'register.html';
        }, 2000);
        return;
    }

    // Display email
    emailDisplay.textContent = `Verification code sent to: ${email}`;

    // Handle OTP verification
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const otp = document.getElementById('otp').value;
        
        if (!otp) {
            showMessage('Please enter the OTP code', 'error');
            return;
        }

        if (otp.length !== 6) {
            showMessage('OTP must be 6 digits', 'error');
            return;
        }

        // Show loading state
        const verifyOtpBtn = document.getElementById('verifyOtpBtn');
        const originalBtnText = verifyOtpBtn.innerHTML;
        verifyOtpBtn.innerHTML = '<span class="button-content">Verifying...<div class="spinner"></div></span>';
        verifyOtpBtn.disabled = true;

        try {
            const response = await fetch('/RemoteTeamPro/backend/api/auth/verify-registration-otp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    email,
                    otp
                })
            });

            const data = await response.json();
            
            if (response.ok && data.success) {
                showMessage('Email verified successfully. Redirecting to login...', 'success');
                
                // Clear stored email
                localStorage.removeItem('registrationEmail');
                
                // Redirect to login page after success message
                setTimeout(() => {
                    window.location.href = 'login.html';
                }, 2000);
            } else {
                verifyOtpBtn.innerHTML = originalBtnText;
                verifyOtpBtn.disabled = false;
                showMessage(data.message || 'Verification failed', 'error');
                document.getElementById('otp').focus();
            }
        } catch (error) {
            verifyOtpBtn.innerHTML = originalBtnText;
            verifyOtpBtn.disabled = false;
            showMessage('An error occurred. Please try again later.', 'error');
            console.error('Verification error:', error);
        }
    });

    // Handle resend OTP
    document.getElementById('resendOtpBtn').addEventListener('click', async () => {
        const resendBtn = document.getElementById('resendOtpBtn');
        const originalBtnText = resendBtn.innerHTML;
        
        // Show loading state
        resendBtn.innerHTML = '<span class="button-content">Sending...<div class="spinner"></div></span>';
        resendBtn.disabled = true;
        
        try {
            const response = await fetch('/RemoteTeamPro/backend/api/auth/resend-registration-otp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email })
            });
            
            const data = await response.json();
            
            if (response.ok && data.success) {
                showMessage('New verification code sent to your email!', 'success');
                
                // If email failed, show OTP for manual entry (for testing)
                if (!data.email_sent && data.otp) {
                    console.log('Email failed, OTP for manual entry:', data.otp);
                    showMessage(`New code sent! (OTP: ${data.otp})`, 'success');
                }
            } else {
                showMessage(data.message || 'Failed to resend code', 'error');
            }
        } catch (error) {
            showMessage('An error occurred. Please try again later.', 'error');
            console.error('Resend error:', error);
        } finally {
            // Reset button state
            resendBtn.innerHTML = originalBtnText;
            resendBtn.disabled = false;
        }
    });

    // Auto-focus OTP input
    document.getElementById('otp').focus();
});
