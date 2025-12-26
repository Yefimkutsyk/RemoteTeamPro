// Function to display the custom message box
function showMessageBox(message) {
    const overlay = document.getElementById('messageBoxOverlay');
    const messageText = document.getElementById('messageBoxText');
    messageText.textContent = message; // Sets the message content
    overlay.classList.add('visible'); // Makes the message box visible
}

// Function to hide the custom message box
function hideMessageBox() {
    const overlay = document.getElementById('messageBoxOverlay');
    overlay.classList.remove('visible'); // Hides the message box
}

// Event listener for when the entire DOM content is loaded
document.addEventListener('DOMContentLoaded', () => {
    // Get references to interactive elements by their IDs
    const learnMoreBtn = document.getElementById('learnMoreBtn');
    const messageBoxCloseBtn = document.getElementById('messageBoxCloseBtn');
    const searchFaqBtn = document.getElementById('searchFaqBtn');

    // Event listener for the "Get Started" button (if you add one to index.js)
    // const getStartedBtn = document.getElementById('getStartedBtn');
    // if (getStartedBtn) {
    //     getStartedBtn.addEventListener('click', () => {
    //         window.location.href = 'register.html'; // Redirects to the registration page
    //     });
    // }

    // Event listener for the "Learn More" button
    // When clicked, it smoothly scrolls the user to the "Features" section.
    if (learnMoreBtn) {
        learnMoreBtn.addEventListener('click', () => {
            const featuresSection = document.getElementById('features');
            if (featuresSection) {
                featuresSection.scrollIntoView({ behavior: 'smooth' }); // Smooth scroll effect
            }
        });
    }

    // Event listener for the "Search FAQs" button in the Contact section
    // When clicked, it smoothly scrolls the user to the "FAQ" section.
    if (searchFaqBtn) {
        searchFaqBtn.addEventListener('click', () => {
            const faqSection = document.getElementById('faq');
            if (faqSection) {
                faqSection.scrollIntoView({ behavior: 'smooth' }); // Smooth scroll effect
            }
        });
    }

    // Event listener for the message box close button
    if (messageBoxCloseBtn) {
        messageBoxCloseBtn.addEventListener('click', hideMessageBox); // Hides the message box when clicked
    }

    // --- FAQ Accordion Logic ---
    // Selects all FAQ question buttons
    const faqQuestions = document.querySelectorAll('.faq-question');

    // Iterates over each FAQ question to attach a click event listener
    faqQuestions.forEach(question => {
        question.addEventListener('click', () => {
            // Selects the answer associated with the clicked question
            const answer = question.nextElementSibling;

            // Check if the answer is currently open
            const isOpen = answer.classList.contains('open');

            // Close all other open FAQ answers
            faqQuestions.forEach(otherQuestion => {
                const otherAnswer = otherQuestion.nextElementSibling;
                if (otherQuestion !== question && otherAnswer.classList.contains('open')) {
                    otherQuestion.classList.remove('active');
                    otherAnswer.style.maxHeight = "0";
                    otherAnswer.classList.remove('open');
                }
            });

            // Toggle the 'active' class on the clicked question button
            question.classList.toggle('active');

            // Toggle the 'open' class and manage max-height for the clicked answer
            if (!isOpen) {
                // If it's not open, open it
                answer.classList.add('open');
                // Set max-height to scrollHeight to allow smooth expansion
                answer.style.maxHeight = answer.scrollHeight + "px";
            } else {
                // If it's open, close it
                answer.style.maxHeight = "0";
                answer.classList.remove('open');
            }
        });
    });

    // --- Intersection Observer for Scroll-Based Animations ---
    // Triggers entrance animations when elements enter the viewport.
    const animatedElements = document.querySelectorAll('.animate-on-scroll'); // Selects all elements marked for animation

    // Creates a new Intersection Observer instance
    const observer = new IntersectionObserver((entries, observer) => {
        // Iterates over each observed entry (element)
        entries.forEach(entry => {
            // If the element is currently intersecting (visible in viewport)
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in'); // Adds 'animate-in' class to trigger animation
                // Optional: Stop observing once animated if you only want the animation to play once
                // observer.unobserve(entry.target);
            } else {
                // Optionally, remove 'animate-in' when element leaves viewport
                // This allows the animation to replay if the user scrolls back up
                 entry.target.classList.remove('animate-in');
            }
        });
    }, {
        threshold: 0.1 // Triggers when 10% of the element is visible
    });

    // Observes each animated element to apply the animation logic
    animatedElements.forEach(element => {
        observer.observe(element);
    });
});
// --- CONTACT FORM SUBMISSION ---
const form = document.getElementById('contactForm');
if (form) {
    // Create a div for response messages if not exists
    let responseDiv = document.getElementById('responseMessage');
    if (!responseDiv) {
        responseDiv = document.createElement('div');
        responseDiv.id = 'responseMessage';
        responseDiv.style.marginTop = '1rem';
        responseDiv.style.fontWeight = 'bold';
        form.appendChild(responseDiv);
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(form);

        responseDiv.textContent = 'Submitting...';
        responseDiv.style.color = 'black';

        try {
            const res = await fetch('http://localhost/RemoteTeamPro/backend/api/contact_handler.php', {
                method: 'POST',
                body: formData
            });

            const result = await res.json();
            responseDiv.textContent = result.message;
            responseDiv.style.color = result.success ? 'green' : 'red';

            if (result.success) form.reset();
        } catch (err) {
            responseDiv.textContent = 'Something went wrong. Please try again.';
            responseDiv.style.color = 'red';
            console.error('Fetch error:', err);
        }
    });
}



