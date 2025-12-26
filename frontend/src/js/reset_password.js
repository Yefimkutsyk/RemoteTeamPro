document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('resetPasswordForm');
    const messageArea = document.getElementById('messageArea');
    const messageText = document.getElementById('message');

    function showMessage(message, type) {
        messageArea.classList.remove('hidden');
        messageArea.classList.remove('text-green-500');
        messageArea.classList.remove('text-red-500');
        messageText.textContent = message;
        if (type === 'error') {
            messageArea.classList.add('text-red-500');
        } else {
            messageArea.classList.add('text-green-500');
        }
    }

    // Get email and OTP from sessionStorage
    const email = sessionStorage.getItem('resetEmail');
    const verifiedOtp = sessionStorage.getItem('verifiedOtp');

    if (!email || !verifiedOtp) {
        window.location.href = 'forgot_password.html';
        return;
    }

    // Eye toggle handlers
    function toggleVisibility(inputId, btnId) {
        const input = document.getElementById(inputId);
        const btn = document.getElementById(btnId);
        btn.addEventListener('click', () => {
            const isPwd = input.type === 'password';
            input.type = isPwd ? 'text' : 'password';
            btn.querySelector('i').className = isPwd ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }
    toggleVisibility('newPassword', 'toggleNewPwd');
    toggleVisibility('confirmPassword', 'toggleConfirmPwd');

    // Handle form submission
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const newPassword = document.getElementById('newPassword').value.trim();
        const confirmPassword = document.getElementById('confirmPassword').value.trim();

        messageArea.classList.add('hidden');

        if (newPassword.length < 8) {
            showMessage('Password must be at least 8 characters long', 'error');
            return;
        }

        if (newPassword !== confirmPassword) {
            showMessage('Passwords do not match', 'error');
            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        const originalButtonText = submitButton.innerHTML;
        submitButton.innerHTML = '<span class="button-content">Changing Password...<div class="spinner"></div></span>';
        submitButton.disabled = true;

        try {
            const response = await fetch('../../../backend/api/auth/reset-password.php?t=' + Date.now(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, otp: verifiedOtp, newPassword })
            });

            const raw = await response.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                console.error('Non-JSON response:', raw);
                throw new Error('Invalid server response');
            }

            if (response.ok && data.success) {
                showMessage(data.message || 'Password reset successfully', 'success');
                document.getElementById('newPassword').disabled = true;
                document.getElementById('confirmPassword').disabled = true;
                submitButton.disabled = true;
                sessionStorage.removeItem('resetEmail');
                sessionStorage.removeItem('verifiedOtp');
                setTimeout(() => { window.location.href = 'login.html'; }, 1500);
            } else {
                submitButton.innerHTML = originalButtonText;
                submitButton.disabled = false;
                showMessage(data.message || 'Failed to reset password', 'error');
            }
        } catch (error) {
            submitButton.innerHTML = originalButtonText;
            submitButton.disabled = false;
            console.error('Reset error:', error);
            showMessage(error.message || 'An error occurred. Please try again later.', 'error');
        }
    });
});