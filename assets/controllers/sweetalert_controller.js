import { Controller } from '@hotwired/stimulus';
import Swal from "sweetalert2";

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        title: String,
        text: String,
        icon: String,
        confirmButtonText: String,
        deleteUrl: String,
        listUrl: String,
        csrfToken: String,
        submitAsync: Boolean,
        method: String,
    };

    remove(event) {
        event.preventDefault();
        const ct = event.currentTarget;

        Swal.fire({
            title: this.titleValue || 'Are you sure?',
            text: this.hasTextValue ? this.textValue : 'This action is not reversible.',
            icon: this.hasIconValue ? this.iconValue : 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545', // Matches standard Bootstrap btn-danger red
            cancelButtonColor: '#6c757d', // Matches standard Bootstrap secondary gray
            confirmButtonText: this.confirmButtonTextValue || 'Yes, delete it!',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                return this.delete(ct);
            },
            allowOutsideClick: () => !Swal.isLoading()
        });
    }

    async delete(ct) {
        // if clicked element provides a different delete URL, use it
        let url = this.deleteUrlValue;
        if (ct && ct.dataset.url) {
            url = ct.dataset.url;
        }

        if (!url) {
            console.error('No delete URL provided');
            return;
        }

        const method = (this.methodValue || (this.submitAsyncValue ? 'DELETE' : 'POST')).toUpperCase();

        if (!this.submitAsyncValue) {
            const form = document.createElement('form');
            form.action = url;

            if (method === 'GET' || method === 'POST') {
                form.method = method;
            } else {
                form.method = 'POST';
                const m = document.createElement('input');
                m.type = 'hidden';
                m.name = '_method';
                m.value = method;
                form.appendChild(m);
            }

            if (this.hasCsrfTokenValue) {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = '_token';
                h.value = this.csrfTokenValue;
                form.appendChild(h);
            }

            document.body.appendChild(form);
            form.submit();
            return;
        }

        // otherwise, submit async
        try {
            const fetchOptions = {
                method: method,
            };

            if (this.hasCsrfTokenValue && method !== 'GET') {
                const formData = new FormData();
                formData.append('_token', this.csrfTokenValue);
                fetchOptions.body = formData;
            }

            const response = await fetch(url, fetchOptions);

            this.dispatch('async:deleted', {
                detail: { response },
            });

            if (response.ok) {
                if (this.listUrlValue) {
                    window.location.replace(this.listUrlValue);
                } else {
                    window.location.reload();
                }
            } else {
                Swal.showValidationMessage(
                    `Request failed: ${response.statusText}`
                );
            }
        } catch (error) {
            Swal.showValidationMessage(
                `Request failed: ${error}`
            );
        }
    }

    cancel() {
        window.location.replace(this.listUrlValue);
    }
}
