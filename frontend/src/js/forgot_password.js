document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('forgotPasswordForm');
    const messageArea = document.getElementById('messageArea');
    const messageText = document.getElementById('message');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const email = document.getElementById('email').value;
        const button = form.querySelector('button[type="submit"]');
        const originalButtonText = button.innerHTML;
        
        try {
            console.log('Form submitted with email:', email); // Debug log
            
            // Show loading state
            button.innerHTML = `<span class="button-content">Sending... <div class="spinner"></div></span>`;
            button.disabled = true;

            const response = await fetch('../../../backend/api/auth/forgot-password.php?t=' + Date.now(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email })
            });

            const textResponse = await response.text();
            console.log('Server response:', textResponse); // Debug log
            
            let data = JSON.parse(textResponse);
            
            // Reset button state
            button.innerHTML = originalButtonText;
            button.disabled = false;

            messageArea.classList.remove('hidden', 'text-green-500', 'text-red-500');
            
            if (response.ok && data.success) {
                // First store the email
                sessionStorage.setItem('resetEmail', email);

                // Show success message
                messageArea.classList.add('text-green-500');
                messageText.textContent = data.message || 'OTP sent successfully! Redirecting...';

                // Redirect to verify OTP page with cache-busting param
                setTimeout(() => {
                    window.location.href = 'verify-otp.html?ts=' + Date.now();
                }, 800);
            } else {
                messageArea.classList.add('text-red-500');
                messageText.textContent = data.error || 'Failed to send reset code. Please try again.';
            }
        } catch (error) {
            console.error('Error:', error);
            messageArea.classList.remove('hidden', 'text-green-500');
            messageArea.classList.add('text-red-500');
            messageText.textContent = 'An error occurred. Please try again later.';
        }
    });
});