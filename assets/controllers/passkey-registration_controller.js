import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';

export default class extends Controller {
    static targets = ['form', 'attestation'];

    connect() {
        // 1. Hijack the programmatic form submission triggered by the WebAuthn package
        this.formTarget.submit = this.sendPasskeyPayload.bind(this);

        // 2. Set up the native browser API interceptor
        this.setupNativeWebAuthnInterceptor();
    }

    disconnect() {
        // Clean up the interceptor when leaving the page
        this.teardownNativeWebAuthnInterceptor();
    }

    setupNativeWebAuthnInterceptor() {
        // Store the browser's original hardware API method
        this.originalCredentialsCreate = navigator.credentials.create;

        const self = this;

        // Override the method to act as a middleman
        navigator.credentials.create = async function(options) {
            try {
                // Pass the options to the real browser API and wait for the hardware
                return await self.originalCredentialsCreate.call(navigator.credentials, options);
            } catch (error) {
                // We caught the error before the Stimulus package can swallow it!
                self.handleNativeError(error);

                // Re-throw the error so the underlying package doesn't break
                throw error;
            }
        };
    }

    teardownNativeWebAuthnInterceptor() {
        // Restore the original browser API to prevent memory leaks or cross-page interference
        if (this.originalCredentialsCreate) {
            navigator.credentials.create = this.originalCredentialsCreate;
        }
    }

    handleNativeError(error) {
        // console.error('Natively Intercepted WebAuthn Error:', error);
        // console.log('Error Name:', error.name);

        // InvalidStateError (Chrome/YubiKey) or NotAllowedError (Safari/Apple)
        if (error.name === 'InvalidStateError' || error.name === 'NotAllowedError') {
            Swal.fire({
                title: 'Already Registered',
                text: 'This device is already registered as a passkey for your account!',
                icon: 'warning',
                confirmButtonColor: '#0d6efd'
            });
        } else if (error.name !== 'AbortError') {
            Swal.fire({
                title: 'Authenticator Error',
                text: 'An error occurred with your authenticator. Please try again.',
                icon: 'error',
                confirmButtonColor: '#0d6efd'
            });
        }
    }

    async sendPasskeyPayload(event) {
        // If triggered by a standard submit event, stop the HTML POST
        if (event && event.preventDefault) {
            event.preventDefault();
        }

        const attestationPayload = this.attestationTarget.value;

        // Only intercept if the WebAuthn package has populated the hidden field
        if (!attestationPayload) {
            return;
        }

        // Trigger a loading spinner while the server verifies the cryptography
        Swal.fire({
            title: 'Saving Passkey...',
            text: 'Please wait while we secure your credential.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        try {
            const response = await fetch(this.formTarget.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: attestationPayload
            });

            if (response.ok) {
                // Show a success checkmark, wait 1.5 seconds, then reload the page
                Swal.fire({
                    title: 'Success!',
                    text: 'Your passkey has been securely saved.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    window.location.reload();
                });
            } else {
                console.error('Passkey save failed', await response.text());
                Swal.fire({
                    title: 'Save Failed',
                    text: 'There was an error saving your passkey to the server.',
                    icon: 'error',
                    confirmButtonColor: '#0d6efd'
                });
            }
        } catch (error) {
            console.error('Network error during passkey registration:', error);
            Swal.fire({
                title: 'Network Error',
                text: 'A network error occurred. Please check your connection and try again.',
                icon: 'error',
                confirmButtonColor: '#0d6efd'
            });
        }
    }
}
