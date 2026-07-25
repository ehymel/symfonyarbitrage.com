import { Controller } from '@hotwired/stimulus';

/**
 * @typedef {Object} ExecutionSummary
 * @property {number} totalExecutions
 * @property {number} successful
 * @property {number} totalProfitUsd
 */

/**
 * @typedef {Object} Execution
 * @property {number} id
 * @property {string} pair
 * @property {string} buyExchange
 * @property {string} sellExchange
 * @property {number} buyPrice
 * @property {number} sellPrice
 * @property {?number} buyFilledPrice
 * @property {?number} sellFilledPrice
 * @property {number} actualProfit
 * @property {string} status
 * @property {?number} latencyMs
 * @property {string} createdAt
 * @property {string} buyOrderId
 * @property {string} sellOrderId
 */

/**
 * @typedef {Object} MonitorResponse
 * @property {ExecutionSummary} summary
 * @property {Execution[]} executions
 */

export default class extends Controller {
    static targets = ["tableBody", "totalProfit", "successRate", "totalCount", "spinner", "liveStatus", "modalContent"];
    static values = {
        url: String,
        refreshInterval: { type: Number, default: 3000 }
    }

    connect() {
        this.fetchData();
        this.startPolling();
    }

    disconnect() {
        this.stopPolling();
    }

    startPolling() {
        this.pollingTimer = setInterval(() => {
            this.fetchData();
        }, this.refreshIntervalValue);
    }

    stopPolling() {
        if (this.pollingTimer) {
            clearInterval(this.pollingTimer);
        }
    }

    async fetchData() {
        try {
            this.spinnerTarget.classList.remove('d-none');
            const response = await fetch(this.urlValue);

            if (!response.ok) throw new Error('Failed to fetch execution records');

            /** @type {MonitorResponse} */
            const data = await response.json();
            this.renderSummary(data.summary);
            this.renderTable(data.executions);
        } catch (error) {
            console.error("Dashboard error:", error);
            this.liveStatusTarget.className = "badge bg-danger";
            this.liveStatusTarget.textContent = "Connection Lost";
        } finally {
            this.spinnerTarget.classList.add('d-none');
        }
    }

    /** @param {ExecutionSummary} summary */
    renderSummary(summary) {
        const profit = summary.totalProfitUsd || 0;
        this.totalProfitTarget.textContent = `$${profit.toFixed(4)}`;
        this.totalProfitTarget.className = profit >= 0 ? "h3 mb-0 fw-bold text-success" : "h3 mb-0 fw-bold text-danger";

        const total = summary.totalExecutions || 0;
        const success = summary.successful || 0;
        const rate = total > 0 ? ((success / total) * 100).toFixed(1) : 0;

        this.successRateTarget.textContent = `${rate}%`;
        this.totalCountTarget.textContent = total;
    }

    /** @param {Execution[]} executions */
    renderTable(executions) {
        if (executions.length === 0) {
            this.tableBodyTarget.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-muted">No executions recorded yet.</td></tr>`;
            return;
        }

        this.tableBodyTarget.innerHTML = executions.map(e => {
            const badgeClass = this.getStatusBadgeClass(e.status);
            const profitClass = e.actualProfit >= 0 ? "text-success fw-bold" : "text-danger fw-bold";
            const payload = JSON.stringify(e, null, 2).replace(/"/g, '&quot;');

            return `
                <tr>
                    <td class="small text-muted">${e.createdAt}</td>
                    <td><span class="fw-bold">${e.pair}</span></td>
                    <td>
                        <span class="badge bg-light text-dark border">${e.buyExchange}</span>
                        <i class="bi bi-arrow-right small mx-1"></i>
                        <span class="badge bg-light text-dark border">${e.sellExchange}</span>
                    </td>
                    <td class="small">$${e.buyPrice.toFixed(2)} / $${e.sellPrice.toFixed(2)}</td>
                    <td class="small">
                        ${e.buyFilledPrice ? `$${e.buyFilledPrice.toFixed(2)}` : '—'} /
                        ${e.sellFilledPrice ? `$${e.sellFilledPrice.toFixed(2)}` : '—'}
                    </td>
                    <td class="${profitClass}">$${e.actualProfit.toFixed(4)}</td>
                    <td class="small">${e.latencyMs ? `${e.latencyMs} ms` : 'N/A'}</td>
                    <td><span class="badge ${badgeClass}">${e.status}</span></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-light border"
                                data-payload="${payload}"
                                data-action="click->execution-monitor#inspectExecution">
                            Inspect
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    getStatusBadgeClass(status) {
        switch (status) {
            case 'COMPLETED': return 'bg-success';
            case 'PARTIAL_BUY_UNWOUND':
            case 'PARTIAL_SELL_UNWOUND': return 'bg-warning text-dark';
            case 'FAILED': return 'bg-danger';
            default: return 'bg-secondary';
        }
    }

    inspectExecution(event) {
        const payload = event.currentTarget.getAttribute('data-payload');
        this.modalContentTarget.textContent = JSON.stringify(JSON.parse(payload), null, 2);

        // Use standard Bootstrap 5 Modal API
        const modalElement = document.getElementById('executionDetailModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
    }
}
