import { Controller } from '@hotwired/stimulus';
import { activateControllers } from '../stimulus/activate_controllers.js';

/**
 * Two-phase comment loading with progressive merge:
 *
 * Phase 1 — polls ?cached=1 while relays are queried; shows partial threads as soon as the server
 *            writes them to cache (relay batches during incremental fetch).
 *
 * Phase 2 — full fragment URL; merges new comment cards by event id instead of replacing the list.
 */
export default class extends Controller {
    static values = {
        url: String,
        preloaded: { type: Boolean, default: false },
        error: { type: String, default: 'Comments could not be loaded.' },
        empty: { type: String, default: 'No comments yet.' },
    };

    static targets = ['container', 'loadingTemplate'];

    connect() {
        this.partialReloads = 0;
        this._loadGeneration = 0;
        this._cachePollTimer = null;
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
            activateControllers(this.application, this.containerTarget);
            return;
        }
        void this.load();
    }

    disconnect() {
        this.stopCachePolling();
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
        this._loadGeneration += 1;
        this._fullFetchDone = true;
        this.stopCachePolling();
        if (detail.merged === true) {
            void this._pollCachedVersion();
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

    buildFetchUrl(extra = '') {
        const u = this.urlValue;
        const parts = [`cb=${Date.now()}`, extra].filter(Boolean);
        const qs = parts.join('&');
        return u.includes('?') ? `${u}&${qs}` : `${u}?${qs}`;
    }

    isDisplayableCommentsHtml(html) {
        if (!html || !String(html).trim()) {
            return false;
        }
        if (/class="card comment\b/.test(html)) {
            return true;
        }
        if (/class="superchats\b/.test(html)) {
            return true;
        }
        if (/comments__empty/.test(html)) {
            return true;
        }
        if (/data-comments-partial="1"/.test(html)) {
            return false;
        }
        return /class="comments\b/.test(html);
    }

    showLoading() {
        if (!this.hasContainerTarget) {
            return;
        }
        if (this.containerTarget.querySelector('.card.comment[data-event-id]')) {
            return;
        }
        const markup = this.hasLoadingTemplateTarget
            ? this.loadingTemplateTarget.innerHTML
            : '<p class="text-subtle">Looking for comments…</p>';
        this.containerTarget.innerHTML = markup;
        this.containerTarget.classList.add('comments--pending');
    }

    applyCommentsHtml(html) {
        this.containerTarget.innerHTML = html;
        this.containerTarget.classList.remove('comments--pending');
        this.activateInjectedComments(this.containerTarget);
    }

    /** Wire Stimulus controllers in HTML injected via innerHTML (nostr previews, reply forms, …). */
    activateInjectedComments(root) {
        activateControllers(this.application, root);
    }

    /**
     * Merge new comment cards (and superchats) into the live DOM by data-event-id.
     *
     * @returns {number} count of newly inserted comment nodes
     */
    mergeCommentsHtml(html) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;

        const incomingRoot = wrapper.querySelector('.comments');
        if (!incomingRoot) {
            if (this.isDisplayableCommentsHtml(html)) {
                this.applyCommentsHtml(html);
            }
            return 0;
        }

        let root = this.containerTarget.querySelector('.comments');
        if (!root) {
            this.applyCommentsHtml(html);
            return incomingRoot.querySelectorAll('.card.comment[data-event-id]').length;
        }

        const emptyMsg = root.querySelector('.comments__empty');
        if (emptyMsg) {
            emptyMsg.remove();
        }

        let added = 0;
        const existingIds = new Set(
            [...root.querySelectorAll('.card.comment[data-event-id]')].map((el) =>
                (el.getAttribute('data-event-id') || '').toLowerCase(),
            ),
        );

        incomingRoot.querySelectorAll('.card.comment[data-event-id]').forEach((node) => {
            const id = (node.getAttribute('data-event-id') || '').toLowerCase();
            if (id === '' || existingIds.has(id)) {
                return;
            }
            existingIds.add(id);
            const clone = node.cloneNode(true);
            clone.classList.add('comment--arriving');
            root.appendChild(clone);
            window.setTimeout(() => clone.classList.remove('comment--arriving'), 600);
            added += 1;
        });

        const incomingSuperchats = wrapper.querySelector('.superchats');
        if (incomingSuperchats) {
            let scBlock = this.containerTarget.querySelector('.superchats');
            if (!scBlock) {
                this.containerTarget.insertBefore(incomingSuperchats.cloneNode(true), root);
            }
        }

        const isPartial = incomingRoot.getAttribute('data-comments-partial') === '1';
        root.setAttribute('data-comments-partial', isPartial ? '1' : '0');

        if (added > 0 || root.querySelector('.card.comment')) {
            this.containerTarget.classList.remove('comments--pending');
        }

        if (added > 0) {
            this.activateInjectedComments(this.containerTarget);
        }

        return added;
    }

    /**
     * @returns {boolean} true when the thread is complete (not partial)
     */
    ingestCommentsHtml(html) {
        const isPartial = /data-comments-partial="1"/.test(html);
        const hasExisting = Boolean(this.containerTarget.querySelector('.card.comment[data-event-id]'));

        if (!this.isDisplayableCommentsHtml(html)) {
            return !isPartial;
        }

        if (hasExisting) {
            this.mergeCommentsHtml(html);
        } else {
            this.applyCommentsHtml(html);
        }

        return !isPartial;
    }

    startCachePolling(generation) {
        this.stopCachePolling();
        this._cachePollTimer = window.setInterval(() => {
            if (generation !== this._loadGeneration || this._fullFetchDone) {
                this.stopCachePolling();
                return;
            }
            void this._pollCachedVersion();
        }, 1500);
    }

    stopCachePolling() {
        if (this._cachePollTimer !== null) {
            window.clearInterval(this._cachePollTimer);
            this._cachePollTimer = null;
        }
    }

    markRelayFetchComplete() {
        this._fullFetchDone = true;
        this._loadGeneration += 1;
        this.stopCachePolling();
    }

    async load(isPartialRetry = false) {
        const generation = ++this._loadGeneration;
        this._fullFetchDone = false;
        if (!isPartialRetry) {
            this.partialReloads = 0;
            this.showLoading();
            this.startCachePolling(generation);
        }

        const t0 = performance.now();
        const perAttemptMs = 20_000;
        const maxAttempts = 2;
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

                const complete = this.ingestCommentsHtml(html);
                if (complete) {
                    this.markRelayFetchComplete();
                    const ms = Math.round(performance.now() - t0);
                    console.debug(
                        `[article-comments] relay fetch complete in ${ms}ms${attempt > 1 ? ` (attempt ${attempt})` : ''}`,
                        this.urlValue,
                    );
                    return;
                }

                if (this.partialReloads < 4) {
                    this.partialReloads += 1;
                    const ms = Math.round(performance.now() - t0);
                    console.debug(
                        `[article-comments] partial thread (${ms}ms), waiting for more relays`,
                        this.urlValue,
                    );
                    window.setTimeout(() => {
                        if (this.hasContainerTarget && generation === this._loadGeneration && !this._fullFetchDone) {
                            void this.load(true);
                        }
                    }, 2000);
                    return;
                }

                this._fullFetchDone = true;
                this.stopCachePolling();
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
                this.stopCachePolling();
                if (this.hasContainerTarget && !this._fullFetchDone && !this.containerTarget.querySelector('.card.comment')) {
                    this.containerTarget.innerHTML = `<p class="text-subtle">${this.errorValue}</p>`;
                    this.containerTarget.classList.remove('comments--pending');
                }
            }
        }
    }

    async _pollCachedVersion() {
        try {
            const res = await fetch(this.buildFetchUrl('cached=1'), {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (res.status === 204 || !res.ok || !this.hasContainerTarget || this._fullFetchDone) {
                return;
            }
            const html = await res.text();
            if (!this.hasContainerTarget || this._fullFetchDone) {
                return;
            }
            const complete = this.ingestCommentsHtml(html);
            if (complete) {
                this.markRelayFetchComplete();
            }
        } catch {
            // Ignore; relay fetch or next poll will continue.
        }
    }
}
