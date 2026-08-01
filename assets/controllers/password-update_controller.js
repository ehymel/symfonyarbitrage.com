import { Controller } from '@hotwired/stimulus';

/**
 * Password Update Controller
 * * Intercepts password change/reset forms to execute Web Crypto ceremonies.
 * * Handles local decryption and re-encryption of private keys, or regeneration of brand-new identities.
 */
export default class extends Controller {
    static targets = [
        'form',
        'currentPasswordInput',
        'newPasswordInput',
        'confirmPasswordInput',
        'submitButton',
        'statusIndicator'
    ];

    static values = {
        encryptedPrivateKey: String, // Stored encrypted identity of the logged-in user
        mode: String // 'change' (logged-in re-encryption) or 'reset' (forgot key generation)
    };

    /**
     * Coordinates the form interception before standard dispatch.
     */
    async executeCeremony(event) {
        event.preventDefault();

        // Check if we have the inputs needed. If it's a Symfony form, they might be nested.
        const newPassword = this.newPasswordInputTarget.value;
        const confirmPassword = this.confirmPasswordInputTarget.value;

        if (newPassword.length < 8) {
            this.updateStatus('Your password must be at least 8 characters long.', 'danger');
            return;
        }

        if (newPassword !== confirmPassword) {
            this.updateStatus('New password and confirmation fields do not match.', 'danger');
            return;
        }

        this.formTarget.submit();
    }

    // --- UI State Helpers ---

    lockUI(statusText) {
        this.submitButtonTarget.disabled = true;
        this.updateStatus(statusText, 'info');
    }

    unlockUI() {
        this.submitButtonTarget.disabled = false;
    }

    updateStatus(message, type) {
        this.statusIndicatorTarget.classList.remove('d-none');
        this.statusIndicatorTarget.className = `alert alert-${type}`;
        this.statusIndicatorTarget.textContent = message;
    }
}
