import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';

export default class extends Controller {
    async remove(event) {
        // Prevent the standard form submission and page reload
        event.preventDefault();

        const form = event.currentTarget;
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');

        const result = await Swal.fire({
            title: 'Revoke this passkey?',
            text: "The device associated with this passkey will no longer be able to log into your account; you will have to use your username and password instead.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545', // Matches standard Bootstrap btn-danger red
            cancelButtonColor: '#6c757d', // Matches standard Bootstrap secondary gray
            confirmButtonText: 'Yes, revoke it'
        });

        if (!result.isConfirmed) {
            return;
        }

        try {
            // Disable the button while the request is processing
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerText = 'Removing...';
            }

            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json'
                },
                body: formData
            });

            if (response.ok) {
                // Apply the CSS transition properties
                this.element.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                this.element.style.opacity = '0';
                this.element.style.transform = 'scale(0.95)';

                // Wait for the CSS transition to finish (300ms) before physically removing the DOM node
                setTimeout(() => {
                    this.element.remove();
                }, 300);
            } else {
                const data = await response.json();
                console.error('Failed to remove passkey:', data.error);
                alert('Could not remove the passkey. Please refresh and try again.');

                // Re-enable the button if it failed
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerText = 'Remove';
                }
            }
        } catch (error) {
            console.error('Network error during deletion:', error);
            alert('A network error occurred. Please try again.');

            // Re-enable the button if it failed
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerText = 'Remove';
            }
        }
    }
}
