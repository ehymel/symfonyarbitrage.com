import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';
import { generateCsrfHeaders } from './csrf_protection_controller.js';

export default class extends Controller {
    static targets = [
        'form', 'stepEmail', 'stepPasskey', 'stepPassword', 'displayEmail',
        'email', 'password', 'continueBtn', 'assertion', 'rememberMe', 'rememberMeDiv', 'error'
    ];

    static values = {
        checkEmailUrl: String,
        webauthnResultUrl: String
    };

    connect() {
        // Hijack programmatic form submissions triggered by the WebAuthn controller
        const originalSubmit = HTMLFormElement.prototype.submit;
        const self = this;

        this.formTarget.submit = function() {
            if (self.hasAssertionTarget && self.assertionTarget.value) {
                self.submitPasskeyPayload();
            } else {
                originalSubmit.call(this);
            }
        };
    }

    async checkEmail(event) {
        if (event) {
            event.preventDefault();

            // Yield the main thread for 50 milliseconds.
            // This allows the browser's password manager to finish injecting the autofill data.
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        const email = this.emailTarget.value.trim();
        const password = this.passwordTarget.value;

        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('d-none');
        }

        if (!email) {
            Swal.fire({
                title: 'Email Address Required',
                text: 'Please enter your email address to continue.',
                icon: 'warning',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }

        if (this.stepPasswordTarget.classList.contains('d-none') === false && !password) {
            Swal.fire({
                title: 'Password Required',
                text: 'Please enter your password to login.',
                icon: 'warning',
                confirmButtonColor: '#0d6efd'
            });
            return;
        }

        if (this.hasDisplayEmailTarget) {
            this.displayEmailTargets.forEach(el => {
                el.innerText = email;
            });
        }

        this.continueBtnTarget.disabled = true;
        this.continueBtnTarget.innerText = 'Checking...';

        try {
            const response = await fetch(this.checkEmailUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...generateCsrfHeaders(this.formTarget)
                },
                body: JSON.stringify({ email: email })
            });

            const data = await response.json();

            // Transition UI
            this.stepEmailTarget.classList.add('d-none');

            if (data.hasPasskey) {
                this.stepPasskeyTarget.classList.remove('d-none');
                this.rememberMeDivTarget.classList.remove('d-none');
            } else {
                this.stepPasswordTarget.classList.remove('d-none');
                this.rememberMeDivTarget.classList.remove('d-none');
                this.passwordTarget.focus();
            }
        } catch (error) {
            console.error('Error checking email:', error);
            // this.stepEmailTarget.classList.remove('d-none');
            this.stepPasswordTarget.classList.remove('d-none');
        }
    }

    showPasswordFallback(event) {
        if (event) event.preventDefault();
        this.stepPasskeyTarget.classList.add('d-none');
        this.stepPasswordTarget.classList.remove('d-none');
        this.rememberMeDivTarget.classList.remove('d-none');
        this.passwordTarget.focus();
    }

    onSubmit(event) {
        // Catch standard form submissions triggered by autofill or hitting Enter
        if (this.hasAssertionTarget && this.assertionTarget.value) {
            event.preventDefault();
            this.submitPasskeyPayload();
        }
    }

    async submitPasskeyPayload() {
        const assertion = this.assertionTarget.value;
        let targetUrl = this.webauthnResultUrlValue;

        if (this.hasRememberMeTarget && this.rememberMeTarget.checked) {
            targetUrl += '?_remember_me=1';
        }

        // 1. Trigger the loading spinner while the JSON is verified
        Swal.fire({
            title: 'Authenticating...',
            text: 'Verifying your passkey.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        try {
            const response = await fetch(targetUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...generateCsrfHeaders(this.formTarget)
                },
                body: assertion
            });

            if (response.ok) {
                const data = await response.json();

                // 2. Show the success checkmark, wait 1 second, then redirect to the dashboard
                Swal.fire({
                    title: 'Success!',
                    text: 'Logging you in...',
                    icon: 'success',
                    timer: 1000,
                    showConfirmButton: false
                }).then(() => {
                    window.location.assign(data.redirect);
                });
            } else {
                console.error('Passkey login failed');

                // 3. Show a clean error modal if the backend rejects the key
                Swal.fire({
                    title: 'Authentication Failed',
                    text: 'Invalid passkey. Please try again or use your password.',
                    icon: 'error',
                    confirmButtonColor: '#0d6efd'
                });
                this.assertionTarget.value = '';            }
        } catch (error) {
            console.error('Network error during passkey login:', error);

            Swal.fire({
                title: 'Network Error',
                text: 'A network error occurred. Please check your connection and try again.',
                icon: 'error',
                confirmButtonColor: '#0d6efd'
            });
            this.assertionTarget.value = '';
        }
    }

    resetForm(event) {
        if (event) event.preventDefault();

        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('d-none');
        }

        this.stepPasskeyTarget.classList.add('d-none');
        this.stepPasswordTarget.classList.add('d-none');
        this.stepEmailTarget.classList.remove('d-none');

        this.continueBtnTarget.disabled = false;
        this.continueBtnTarget.innerText = 'Continue';

        this.passwordTarget.value = '';
        this.emailTarget.value = '';
        this.emailTarget.focus();
    }
}
