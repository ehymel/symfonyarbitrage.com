import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ["modal", "modalBody"];
    static values = {
        formUrl: String,
        hideFullPageOpenButton: { type: Boolean, default: false },
    }
    modal = null;

    async open(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const formUrl = button.dataset.formUrl || this.formUrlValue;
        const modalTitle = button.dataset.modalTitle || 'Form';
        const hideFullPage = button.dataset.hideFullPage || this.hideFullPageOpenButtonValue;

        this.modalBodyTarget.innerHTML = 'Loading...';
        this.modalTarget.querySelector('.modal-title').innerText = modalTitle;
        this.modal = new Modal(this.modalTarget);
        this.modal.show();

        this.currentFormUrl = formUrl;

        const url = new URL(formUrl, window.location.origin);
        url.searchParams.set('ajax', 1);

        let response = await fetch(url.toString());
        this.modalBodyTarget.innerHTML = await response.text();

        let fullPageBtn = this.modalTarget.querySelector('button#js-modal-fullpage-btn');
        if (hideFullPage) {
            fullPageBtn.classList.add('d-none');
        } else {
            fullPageBtn.classList.remove('d-none');
        }
    }

    async submitForm(event) {
        event.preventDefault();

        const form = this.modalBodyTarget.getElementsByTagName('form')[0];
        if (!form) {
            return;
        }

        // Only dispatch the submit event if this was NOT triggered by the form's own submit event
        // to avoid infinite recursion.
        if (event.type !== 'submit') {
            const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
            form.dispatchEvent(submitEvent);
            if (submitEvent.defaultPrevented) {
                return;
            }
        }

        let params = new FormData(form);
        params.append('ajax', 1);

        const url = new URL(form.getAttribute('action') || this.currentFormUrl, window.location.origin);
        url.searchParams.set('ajax', 1);

        let response = await fetch(url.toString(), {
            method: 'POST',
            body: params,
        });

        if (response.status !== 422) {
            this.modal.hide();
            this.dispatch('success');
        } else {
            this.modalBodyTarget.innerHTML = await response.text();
        }
    }

    fullPage(event) {
        event.preventDefault();
        this.modal.hide();
        document.location.href = this.currentFormUrl;
    }
}
