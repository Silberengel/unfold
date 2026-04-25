import { Controller } from '@hotwired/stimulus';

const LOADING_HTML = `<div class="nostr-preview__loading text-center my-2"><span class="nostr-preview__spinner" role="status" aria-label="Loading"></span><span class="nostr-preview__loading-text ms-2">Loading preview…</span></div>`;
const UNAVAILABLE_HTML = `<div class="alert alert-warning my-2" role="status">Preview unavailable.</div>`;

export default class extends Controller {
    static values = {
        identifier: String,
        type: String,
        decoded: String,
        fullMatch: String,
    };

    static targets = ['container'];

    connect() {
        this.fetchPreview();
    }

    async fetchPreview() {
        if (!this.hasContainerTarget) {
            return;
        }
        this.containerTarget.innerHTML = LOADING_HTML;
        try {
            if (this.typeValue === 'url' && this.fullMatchValue) {
                const res = await fetch('/og-preview/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ url: this.fullMatchValue }),
                });
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                this.containerTarget.innerHTML = await res.text();
                return;
            }
            const res = await fetch('/preview/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    identifier: this.identifierValue,
                    type: this.typeValue,
                    decoded: this.decodedValue,
                }),
            });
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            this.containerTarget.innerHTML = await res.text();
        } catch (e) {
            // NetworkError / offline: avoid console.error noise; one inline fallback per block
            console.debug('nostr_preview: fetch failed', e);
            this.containerTarget.innerHTML = this.typeValue === 'url' && this.fullMatchValue
                ? `<div class="alert alert-warning my-2" role="status">Unable to load link preview for ${this.fullMatchValue}.</div>`
                : UNAVAILABLE_HTML;
        }
    }
}
