import { Controller } from '@hotwired/stimulus';

/**
 * Fetches the comment thread HTML after the article shell has rendered (no relay I/O on first paint).
 */
export default class extends Controller {
    static values = {
        url: String,
        preloaded: { type: Boolean, default: false },
    };

    static targets = ['container'];

    connect() {
        this.partialReloads = 0;
        this.boundOnAuth = this.onAuthChanged.bind(this);
        window.addEventListener('unfold:auth-changed', this.boundOnAuth);
        if (!this.hasContainerTarget || !this.urlValue) {
            return;
        }
        if (this.preloadedValue) {
            // Article SSR already included comments. Do not re-fetch: a slow or dropped
            // request would replace working HTML with a generic error. Re-fetch on auth
            // only (reply UI may need fresh permission state).
            return;
        }
        void this.load();
    }

    disconnect() {
        window.removeEventListener('unfold:auth-changed', this.boundOnAuth);
    }

    onAuthChanged() {
        if (!this.hasContainerTarget || !this.urlValue) {
            return;
        }
        void this.load();
    }

    buildFetchUrl() {
        const u = this.urlValue;
        const bust = `cb=${Date.now()}`;
        return u.includes('?') ? `${u}&${bust}` : `${u}?${bust}`;
    }

    async load() {
        const t0 = performance.now();
        const perAttemptMs = 45_000;
        const maxAttempts = 3;
        for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
            const controller = new AbortController();
            const timer = window.setTimeout(() => controller.abort(), perAttemptMs);
            try {
                // Avoid a stale 60s-cached "guest" fragment right after login (see comments fragment headers).
                const res = await fetch(this.buildFetchUrl(), {
                    signal: controller.signal,
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                const html = await res.text();
                if (!this.hasContainerTarget) {
                    window.clearTimeout(timer);
                    return;
                }
                this.containerTarget.innerHTML = html;
                const isPartial = /data-comments-partial="1"/.test(html);
                if (isPartial && this.partialReloads < 2) {
                    this.partialReloads += 1;
                    window.setTimeout(() => {
                        if (this.hasContainerTarget) {
                            void this.load();
                        }
                    }, 1200);
                }
                const ms = Math.round(performance.now() - t0);
                if (attempt > 1) {
                    console.debug(`[article-comments] fragment OK in ${ms}ms (after ${attempt} attempts)`, this.urlValue);
                } else {
                    console.debug(`[article-comments] fragment OK in ${ms}ms`, this.urlValue);
                }
                window.clearTimeout(timer);
                return;
            } catch (err) {
                window.clearTimeout(timer);
                if (attempt < maxAttempts) {
                    const delay = 1_200 * 2 ** (attempt - 1);
                    await new Promise((r) => setTimeout(r, delay));
                    if (!this.hasContainerTarget) {
                        return;
                    }
                    continue;
                }
                const ms = Math.round(performance.now() - t0);
                console.warn(`[article-comments] fragment failed after ${ms}ms`, this.urlValue, err);
                if (this.hasContainerTarget) {
                    this.containerTarget.innerHTML =
                        '<p class="text-subtle">Comments could not be loaded.</p>';
                }
            }
        }
    }
}
