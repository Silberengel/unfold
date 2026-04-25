import { Controller } from '@hotwired/stimulus';

/**
 * NIP-22 kind-1111 reply: optional collapsed panel (Reply button), sign with NIP-07, POST, refresh thread.
 */
export default class extends Controller {
    static targets = ['hint', 'panel', 'toggleBtn'];

    static values = {
        publishUrl: String,
        csrf: String,
        expectedCoordinate: String,
        articleEventId: String,
        fragmentUrl: String,
        refreshAfter: { type: Boolean, default: true },
        expectedTags: Array,
        parentKind: Number,
        parentId: String,
        authorPubkey: String,
    };

    connect() {
        this._tags = this.expectedTagsValue;
        if (!Array.isArray(this._tags)) {
            const raw = this.element.getAttribute('data-comment-reply-expected-tags-value');
            try {
                this._tags = raw ? JSON.parse(raw) : [];
            } catch {
                this._tags = [];
            }
        }
    }

    togglePanel() {
        if (!this.hasPanelTarget) {
            return;
        }
        const hidden = this.panelTarget.classList.toggle('comment-reply__panel--hidden');
        const open = !hidden;
        if (this.hasToggleBtnTarget) {
            this.toggleBtnTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (open) {
            const ta = this.panelTarget.querySelector('textarea[name="body"]');
            requestAnimationFrame(() => ta?.focus());
        }
    }

    /**
     * @param {Event} ev
     */
    async publish(ev) {
        ev.preventDefault();
        if (!this.hasNip07()) {
            this.setHint('Install a Nostr extension (NIP-07) to sign comments.');
            return;
        }
        const root = this.hasPanelTarget ? this.panelTarget : this.element;
        const ta = root.querySelector('textarea[name="body"]');
        const text = (ta?.value ?? '').trim();
        if (!text) {
            this.setHint('Write something first.');
            return;
        }
        if (this._tags.length === 0) {
            this.setHint('Missing NIP-22 tag template.');
            return;
        }
        this.setHint('Preparing event…');
        const unsigned = {
            kind: 1111,
            created_at: Math.floor(Date.now() / 1000),
            tags: this._tags,
            // Keep user-authored content clean; reply context is encoded in NIP-22 tags.
            content: text,
        };
        let signed;
        try {
            signed = await window.nostr.signEvent(unsigned);
        } catch (err) {
            this.setHint(`Signing failed: ${err instanceof Error ? err.message : String(err)}`);
            return;
        }
        this.setHint('Publishing…');
        const payload = {
            event: signed,
            expected_coordinate: this.expectedCoordinateValue,
            parent_kind: parseInt(String(this.parentKindValue), 10),
            parent_id: this.parentIdValue,
            parent_author_pubkey: this.authorPubkeyValue,
            article_event_id: this.articleEventIdValue || null,
            csrf: this.csrfValue,
        };
        let res;
        try {
            res = await fetch(this.publishUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.csrfValue,
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
        } catch (err) {
            this.setHint(`Network error: ${err instanceof Error ? err.message : String(err)}`);
            return;
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const msg = data.error || `HTTP ${res.status}`;
            this.setHint(msg);
            this.showToast(msg, 'error');
            return;
        }
        const okRelaysRaw = Number(data.ok_relays);
        const totalRelaysRaw = Number(data.total_relays);
        const okRelays = Number.isFinite(okRelaysRaw) ? okRelaysRaw : null;
        const totalRelays = Number.isFinite(totalRelaysRaw) ? totalRelaysRaw : null;
        const successMsg =
            okRelays !== null && totalRelays !== null
                ? `Published to ${okRelays}/${totalRelays} relays.`
                : 'Published.';
        this.setHint(successMsg);
        this.showToast(successMsg, 'success');
        // Keep form content until the success toast is visible.
        await new Promise((r) => window.setTimeout(r, 180));
        if (ta) {
            ta.value = '';
        }
        if (this.hasPanelTarget) {
            this.panelTarget.classList.add('comment-reply__panel--hidden');
            if (this.hasToggleBtnTarget) {
                this.toggleBtnTarget.setAttribute('aria-expanded', 'false');
            }
        }
        if (this.refreshAfterValue) {
            const publishedId =
                typeof data.id === 'string' && data.id ? data.id.toLowerCase() : '';
            void this.refreshThread(publishedId);
        }
    }

    hasNip07() {
        return typeof window.nostr !== 'undefined' && typeof window.nostr.signEvent === 'function';
    }

    /**
     * Reload the section HTML from the article comments fragment. After publishing, relays can lag;
     * if `expectedEventIdHex` is set, re-fetch with backoff until the new note appears (or a cap is hit).
     *
     * Only sets `innerHTML` once the response actually contains the new `data-event-id`. Assigning on
     * every poll replaced the whole subtree each time and re-instantiated every Stimulus `comment-reply`
     * (connect/disconnect storms) while relays were still behind.
     *
     * @param {string} [expectedEventIdHex] lowercase 64-char hex
     */
    async refreshThread(expectedEventIdHex = '') {
        const wrap = this.element.closest('[data-article-comments-wrapper]');
        const url =
            wrap?.getAttribute('data-article-comments-url-value') ||
            document.querySelector('[data-article-comments-wrapper]')?.getAttribute('data-article-comments-url-value');
        const container =
            wrap?.querySelector('[data-article-comments-target="container"]') ||
            document.querySelector('[data-article-comments-target="container"]');
        if (!url || !container) {
            window.location.reload();
            return;
        }
        const wantId =
            expectedEventIdHex && /^[0-9a-f]{64}$/.test(expectedEventIdHex) ? expectedEventIdHex : '';
        const maxRounds = wantId ? 14 : 1;
        for (let round = 0; round < maxRounds; round += 1) {
            if (round > 0) {
                const delay = Math.min(1400, 200 * 2 ** (round - 1));
                await new Promise((r) => setTimeout(r, delay));
            }
            const bust = `cb=${Date.now()}`;
            const u = url.includes('?') ? `${url}&${bust}` : `${url}?${bust}`;
            try {
                const res = await fetch(u, {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) {
                    throw new Error(String(res.status));
                }
                const html = await res.text();
                if (!wantId) {
                    container.innerHTML = html;
                    return;
                }
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                if (parsed.querySelector(`[data-event-id="${wantId}"]`)) {
                    container.innerHTML = html;
                    return;
                }
            } catch {
                if (round === maxRounds - 1) {
                    window.location.reload();
                }
            }
        }
        if (wantId) {
            window.location.reload();
        }
    }

    /**
     * @param {string} msg
     */
    setHint(msg) {
        if (this.hasHintTarget) {
            this.hintTarget.textContent = msg;
        }
    }

    showToast(message, tone = 'success') {
        const el = document.createElement('div');
        el.className = `reply-toast reply-toast--${tone === 'error' ? 'error' : 'success'}`;
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.textContent = message;
        document.body.appendChild(el);
        window.setTimeout(() => {
            el.classList.add('reply-toast--visible');
        }, 10);
        window.setTimeout(() => {
            el.classList.remove('reply-toast--visible');
            window.setTimeout(() => el.remove(), 220);
        }, 2600);
    }
}
