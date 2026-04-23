import { Controller } from '@hotwired/stimulus';

/**
 * Builds a NIP-22 kind-1111 event (blurb + body), signs with NIP-07, POSTs to /comment/publish.
 */
export default class extends Controller {
    static targets = ['hint'];

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

    /**
     * @param {Event} ev
     */
    async publish(ev) {
        ev.preventDefault();
        if (!this.hasNip07()) {
            this.setHint('Install a Nostr extension (NIP-07) to sign comments.');
            return;
        }
        const ta = this.element.querySelector('textarea[name="body"]');
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
        const { nip19 } = await import('nostr-tools');
        const link = this.buildParentBech32(nip19);
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
        this.setHint('Published. It may take a short time to show on all relays.');
        if (ta) {
            ta.value = '';
        }
        if (this.refreshAfterValue && this.fragmentUrlValue) {
            this.refreshThread();
        }
    }

    hasNip07() {
        return typeof window.nostr !== 'undefined' && typeof window.nostr.signEvent === 'function';
    }

    /**
     * @param {import('nostr-tools').nip19} nip19
     */
    buildParentBech32(nip19) {
        const allZero = /^0{64}$/.test(this.parentIdValue);
        const parts = (this.expectedCoordinateValue || '').split(':');
        const k = parts[0] ? parseInt(parts[0], 10) : 30023;
        const pub = parts[1] || this.authorPubkeyValue;
        const d = parts[2] || '';
        if (allZero && d !== '') {
            return nip19.naddrEncode({ kind: k, pubkey: pub, identifier: d, relays: [] });
        }
        return nip19.neventEncode({
            id: this.parentIdValue,
            kind: this.parentKindValue,
            pubkey: this.authorPubkeyValue,
            relays: [],
        });
    }

    refreshThread() {
        const el = document.querySelector('[data-article-comments-url-value]');
        const u = el?.getAttribute('data-article-comments-url-value');
        const container = document.querySelector('[data-article-comments-target="container"]');
        if (!u || !container) {
            window.location.reload();
            return;
        }
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
