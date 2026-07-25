import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['widget', 'submit']
    static values = {
        sitekey: String
    }

    connect() {
        this.disableSubmit();
        this.render();
    }

    render() {
        const widgetElement = this.hasWidgetTarget ? this.widgetTarget : this.element;

        if (typeof window.turnstile !== 'undefined') {
            if (!this.widgetId) {
                this.widgetId = window.turnstile.render(widgetElement, {
                    sitekey: this.sitekeyValue,
                    callback: () => this.enableSubmit(),
                    'expired-callback': () => this.disableSubmit(),
                    'error-callback': () => this.disableSubmit(),
                });
            }
        } else {
            // Load the script if not already present
            if (!document.querySelector('script[src*="challenges.cloudflare.com"]')) {
                const script = document.createElement('script');
                script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                script.async = true;
                script.defer = true;
                script.onload = () => this.render();
                document.head.appendChild(script);
            } else {
                // Script is present but window.turnstile is not yet available
                this.checkInterval = setInterval(() => {
                    if (typeof window.turnstile !== 'undefined') {
                        clearInterval(this.checkInterval);
                        this.render();
                    }
                }, 100);

                // Safety timeout
                setTimeout(() => clearInterval(this.checkInterval), 5000);
            }
        }
    }

    enableSubmit() {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = false;
        }
    }

    disableSubmit() {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = true;
        }
    }
}
