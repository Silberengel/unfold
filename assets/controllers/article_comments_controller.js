import { Controller } from '@hotwired/stimulus';

/**
 * Two-phase comment loading:
 *
 * Phase 1 — fires ?cached=1 immediately, shows whatever is in the server-side cache
 *            without touching any Nostr relays (< 100 ms on a warm cache).
 *
 * Phase 2 — fires the full URL in parallel, which does relay I/O.  When it resolves
 *            it replaces the Phase-1 content.  If Phase 2 finishes first (e.g. the
 *            cached response was held up by DNS), Phase 1 is silently discarded.
 *
 * Result: readers always see something quickly; fresh relay data appears when ready.
 */
export default class extends Controller {
    static values = {
        url: String,
        preloaded: { type: Boolean, default: false },
    };

    static targets = ['container'];

    connect() {
        this.partialReloads = 0;
        this._loadGeneration = 0;
        // Stable reference across reconnects: rebinding each connect() would strand old listeners.
        this.boundOnAuth ??= this.onAuthChanged.bind(this);
        this.boundOnCommentPublished ??= this.onCommentPublished.bind(this);
        window.removeEventListener('unfold:auth-changed', this.boundOnAuth);
        window.addEventListener('unfold:auth-changed', this.boundOnAuth);
        window.removeEventListener('unfold:comment-published', this.boundOnCommentPublished);
        window.addEventListener('unfold:comment-published', this.boundOnCommentPublished);
        if (!this.hasContainerTarget || !this.urlValue) {
            return;
        }
        if (this.preloadedValue) {
            // Article SSR already included comments (cache hit at render time).  Do not re-fetch;
            // a slow relay request would only replace working HTML.  Auth changes may still reload.
            return;
        }
        void this.load();
    }

    disconnect() {
        if (this.boundOnAuth) {
            window.removeEventListener('unfold:auth-changed', this.boundOnAuth);
        }
        if (this.boundOnCommentPublished) {
            window.removeEventListener('unfold:comment-published', this.boundOnCommentPublished);
        }
    }

    onCommentPublished(event) {
        const detail = event?.detail;
        if (!detail || !this.hasContainerTarget || !this.urlValue) {
            return;
        }
        const coord = typeof detail.coordinate === 'string' ? detail.coordinate : '';
        if (coord !== '') {
            try {
                const pageCoord = new URL(this.urlValue, window.location.origin).searchParams.get('coordinate');
                if (pageCoord !== null && pageCoord !== coord) {
                    return;
                }
            } catch {
                if (!this.urlValue.includes(coord)) {
                    return;
                }
            }
        }
        // Abort an in-flight relay fetch so it cannot overwrite the freshly merged thread.
        this._loadGeneration += 1;
        this._fullFetchDone = true;
        if (detail.merged === true) {
            void this._showCachedVersion();
            return;
        }
        void this.load();
    }

    onAuthChanged() {
        if (!this.hasContainerTarget || !this.urlValue) {
            return;
        }
        void this.load();
    }

    /** Append ?cb=<timestamp> (and optional extras) to bust HTTP caches. */
    buildFetchUrl(extra = '') {
        const u = this.urlValue;
        const parts = [`cb=${Date.now()}`, extra].filter(Boolean);
        const qs = parts.join('&');
        return u.includes('?') ? `${u}&${qs}` : `${u}?${qs}`;
    }

    async load(isPartialRetry = false) {
        const generation = ++this._loadGeneration;
        // Track whether Phase 2 has already written to the DOM so Phase 1 never clobbers it.
        this._fullFetchDone = false;
        // Only reset the partial-retry counter on a fresh top-level load, not on retries triggered
        // by a partial result — otherwise the counter resets every call and the retry loop never stops.
        if (!isPartialRetry) {
            this.partialReloads = 0;
        }

        // Phase 1: fire a cache-only request in the background — completes in < 100 ms.
        // Skip on partial retries: the container already has content; Phase 1 would overwrite it.
        if (!isPartialRetry) {
            void this._showCachedVersion();
        }

        // Phase 2: full relay fetch — replaces Phase 1 output when it resolves.
        const t0 = performance.now();
        const perAttemptMs = 45_000;
        const maxAttempts = 3;
        for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
            const controller = new AbortController();
            const timer = window.setTimeout(() => controller.abort(), perAttemptMs);
            try {
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
                window.clearTimeout(timer);
                if (!this.hasContainerTarget || generation !== this._loadGeneration) {
                    return;
                }
                this._fullFetchDone = true;
                this.containerTarget.innerHTML = html;
                const isPartial = /data-comments-partial="1"/.test(html);
                if (isPartial && this.partialReloads < 2) {
                    this.partialReloads += 1;
                    window.setTimeout(() => {
                        if (this.hasContainerTarget) {
                            void this.load(true);
                        }
                    }, 1200);
                }
                const ms = Math.round(performance.now() - t0);
                console.debug(
                    `[article-comments] relay fetch OK in ${ms}ms${attempt > 1 ? ` (attempt ${attempt})` : ''}`,
                    this.urlValue,
                );
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
                console.warn(`[article-comments] relay fetch failed after ${ms}ms`, this.urlValue, err);
                // Only show the error if Phase 1 hasn't already displayed something useful.
                if (this.hasContainerTarget && !this._fullFetchDone) {
                    this.containerTarget.innerHTML =
                        '<p class="text-subtle">Comments could not be loaded.</p>';
                }
            }
        }
    }

    /** Phase 1: return the server's cached copy immediately, without doing any relay I/O. */
    async _showCachedVersion() {
        try {
            const res = await fetch(this.buildFetchUrl('cached=1'), {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok || !this.hasContainerTarget || this._fullFetchDone) {
                return;
            }
            const html = await res.text();
            // Re-check: Phase 2 may have landed while we were awaiting the body.
            if (!this.hasContainerTarget || this._fullFetchDone) {
                return;
            }
            this.containerTarget.innerHTML = html;
        } catch {
            // Ignore; Phase 2 will fill the container regardless.
        }
    }
}
