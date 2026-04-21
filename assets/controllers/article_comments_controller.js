import { Controller } from '@hotwired/stimulus';

/**
 * Fetches the comment thread HTML after the article shell has rendered (no relay I/O on first paint).
 */
export default class extends Controller {
    static values = {
        url: String,
    };

    static targets = ['container'];

    connect() {
        if (!this.hasContainerTarget || !this.urlValue) {
            return;
        }
        void this.load();
    }

    async load() {
        const t0 = performance.now();
        try {
            const res = await fetch(this.urlValue, {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const html = await res.text();
            this.containerTarget.innerHTML = html;
            const ms = Math.round(performance.now() - t0);
            console.info(`[article-comments] fragment OK in ${ms}ms`, this.urlValue);
        } catch (err) {
            const ms = Math.round(performance.now() - t0);
            console.warn(`[article-comments] fragment failed after ${ms}ms`, this.urlValue, err);
            this.containerTarget.innerHTML =
                '<p class="text-subtle">Comments could not be loaded.</p>';
        }
    }
}
