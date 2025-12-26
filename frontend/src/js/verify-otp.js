document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('verifyOtpForm');
    const messageArea = document.getElementById('messageArea');
    const messageText = document.getElementById('message');
    const resendBtn = document.getElementById('resendOtpBtn');
    
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

    // Get email from sessionStorage
    const email = sessionStorage.getItem('resetEmail');
    const emailDisplay = document.getElementById('emailDisplay');
    if (emailDisplay && email) {
        emailDisplay.textContent = `Email: ${email}`;
    }
    if (!email) {
        window.location.href = 'forgot_password.html';
        return;
    }

    // Handle OTP verification
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const otp = document.getElementById('otp').value;
        
        if (!otp) {
            showMessage('Please enter the OTP code', 'error');
            return;
        }

        // Show loading state
        const verifyOtpBtn = document.getElementById('verifyOtpBtn');
        const originalBtnText = verifyOtpBtn.innerHTML;
        verifyOtpBtn.innerHTML = '<span class="button-content">Verifying...<div class="spinner"></div></span>';
        verifyOtpBtn.disabled = true;

        try {
            const response = await fetch('../../../backend/api/auth/verify-otp.php', {
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
                showMessage(data.message || 'OTP verified successfully', 'success');
                
                // Store verified OTP
                sessionStorage.setItem('verifiedOtp', otp);
                
                // Redirect to reset password page
                setTimeout(() => {
                    window.location.href = 'reset_password.html';
                }, 1500);
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
        }
    });

    // Handle resend OTP for forgot password flow
    if (resendBtn) {
        resendBtn.addEventListener('click', async () => {
            // Guard: ensure we have an email to resend to
            if (!email) {
                showMessage('No email found. Please start from Forgot Password.', 'error');
                setTimeout(() => {
                    window.location.href = 'forgot_password.html';
                }, 1500);
                return;
            }

            const originalBtnText = resendBtn.innerHTML;
            // Loading state
            resendBtn.innerHTML = '<span class="button-content">Sending...<div class="spinner"></div></span>';
            resendBtn.disabled = true;

            try {
                const response = await fetch('../../../backend/api/auth/forgot-password.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ email })
                });

                const text = await response.text();
                let data;
                try { data = JSON.parse(text); } catch (e) { data = { success: false, error: 'Unexpected server response' }; }

                if (response.ok && data.success) {
                    showMessage(data.message || 'New reset code sent to your email!', 'success');
                } else {
                    showMessage(data.error || data.message || 'Failed to resend code', 'error');
                }
            } catch (err) {
                showMessage('An error occurred. Please try again later.', 'error');
            } finally {
                resendBtn.innerHTML = originalBtnText;
                resendBtn.disabled = false;
            }
        });
    }
});