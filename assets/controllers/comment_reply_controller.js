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
        blurbLabel: String,
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
        // `nostr-tools` entry pulls @noble/curves (bare spec → breaks in AssetMapper). NIP-19 only needs bech32 helpers.
        const { naddrEncode, neventEncode } = await import('nostr-tools/nip19');
        const link = this.buildParentBech32(naddrEncode, neventEncode);
        const blurb = `> Replying to **${this.blurbLabelValue}** — [view parent](nostr:${link})\n\n`;
        const unsigned = {
            kind: 1111,
            created_at: Math.floor(Date.now() / 1000),
            tags: this._tags,
            content: blurb + text,
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
            this.setHint(data.error || `HTTP ${res.status}`);
            return;
        }
        this.setHint('Published.');
        if (ta) {
            ta.value = '';
        }
        if (this.hasPanelTarget) {
            this.panelTarget.classList.add('comment-reply__panel--hidden');
            if (this.hasToggleBtnTarget) {
                this.toggleBtnTarget.setAttribute('aria-expanded', 'false');
            }
        }
        if (this.refreshAfterValue && this.fragmentUrlValue) {
            this.refreshThread();
        }
    }

    hasNip07() {
        return typeof window.nostr !== 'undefined' && typeof window.nostr.signEvent === 'function';
    }

    /**
     * @param {function(object): string} naddrEncode
     * @param {function(object): string} neventEncode
     */
    buildParentBech32(naddrEncode, neventEncode) {
        const allZero = /^0{64}$/.test(this.parentIdValue);
        const parts = (this.expectedCoordinateValue || '').split(':');
        const k = parts[0] ? parseInt(parts[0], 10) : 30023;
        const pub = parts[1] || this.authorPubkeyValue;
        const d = parts[2] || '';
        if (allZero && d !== '') {
            return naddrEncode({ kind: k, pubkey: pub, identifier: d, relays: [] });
        }
        return neventEncode({
            id: this.parentIdValue,
            kind: this.parentKindValue,
            pubkey: this.authorPubkeyValue,
            relays: [],
        });
    }

    refreshThread() {
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
        const bust = `cb=${Date.now()}`;
        const u = url.includes('?') ? `${url}&${bust}` : `${url}?${bust}`;
        void fetch(u, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => (r.ok ? r.text() : Promise.reject(new Error(String(r.status)))))
            .then((html) => {
                container.innerHTML = html;
            })
            .catch(() => {
                window.location.reload();
            });
    }

    /**
     * @param {string} msg
     */
    setHint(msg) {
        if (this.hasHintTarget) {
            this.hintTarget.textContent = msg;
        }
    }
}
