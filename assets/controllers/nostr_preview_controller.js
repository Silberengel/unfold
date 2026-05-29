import { Controller } from '@hotwired/stimulus';

const LOADING_HTML = `<div class="nostr-preview__loading text-center my-2"><span class="nostr-preview__spinner" role="status" aria-label="Loading"></span><span class="nostr-preview__loading-text ms-2">Loading preview…</span></div>`;
const UNAVAILABLE_HTML = `<div class="alert alert-warning my-2" role="status">Preview unavailable.</div>`;

/**
 * @param {HTMLElement} el
 * @param {string} type
 * @param {string} decodedStr
 * @returns {boolean}
 */
function isPreviewForSameArticleOnPage(el, type, decodedStr) {
    const root =
        el.closest('[data-nostr-page-article-coordinate]') ||
        el.closest('[data-nostr-page-publication-coordinate]');
    if (!root) {
        return false;
    }
    const pageCoord =
        root.getAttribute('data-nostr-page-article-coordinate') ||
        root.getAttribute('data-nostr-page-publication-coordinate') ||
        '';
    const pageEid = (root.getAttribute('data-nostr-page-article-event-id') || '').toLowerCase();
    const pagePubHex = (
        root.getAttribute('data-nostr-page-article-pubkey-hex') ||
        root.getAttribute('data-nostr-page-publication-pubkey-hex') ||
        ''
    ).toLowerCase();
    const pageNpub =
        root.getAttribute('data-nostr-page-article-npub') ||
        root.getAttribute('data-nostr-page-publication-npub') ||
        '';
    if (!pageCoord) {
        return false;
    }
    let d;
    try {
        d = JSON.parse(decodedStr);
    } catch {
        return false;
    }
    if (type === 'naddr' && d && d.pubkey != null) {
        const identRaw = d.identifier != null ? d.identifier : (d.specifier != null ? d.specifier : null);
        if (identRaw == null) {
            return false;
        }
        const k = d.kind != null ? parseInt(String(d.kind), 10) : 30023;
        const ident = String(identRaw);
        let pk = String(d.pubkey);
        if (/^[0-9a-fA-F]{64}$/.test(pk)) {
            pk = pk.toLowerCase();
        } else if (pk.startsWith('npub1') && pageNpub) {
            if (pk !== pageNpub) {
                return false;
            }
            pk = pagePubHex;
        } else {
            return false;
        }
        if (!pk || pk.length !== 64) {
            return false;
        }
        const candidate = `${k}:${pk}:${ident}`;

        return candidate === pageCoord;
    }
    if (type === 'nevent' && d && d.id && pageEid) {
        return String(d.id).toLowerCase() === pageEid;
    }

    return false;
}

export default class extends Controller {
    static values = {
        identifier: String,
        type: String,
        decoded: String,
        fullMatch: String,
    };

    static targets = ['container'];

    connect() {
        if (this.typeValue === 'naddr' || this.typeValue === 'nevent') {
            if (isPreviewForSameArticleOnPage(this.element, this.typeValue, this.decodedValue)) {
                this.element.setAttribute('hidden', '');
                this.element.setAttribute('data-nostr-preview-suppressed', 'same-page-article');
                return;
            }
        }
        void this.fetchPreview();
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
                if (res.status === 204) {
                    this.setPreviewHtml('');
                    return;
                }
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                this.setPreviewHtml(await res.text());
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
            this.setPreviewHtml(await res.text());
        } catch (e) {
            console.debug('nostr_preview: fetch failed', e);
            if (this.typeValue === 'url') {
                this.setPreviewHtml('');
                return;
            }
            this.containerTarget.innerHTML = UNAVAILABLE_HTML;
        }
    }

  /**
   * @param {string} html
   */
    setPreviewHtml(html) {
        if (!html || !String(html).trim()) {
            this.element.setAttribute('hidden', '');
            this.element.setAttribute('data-nostr-preview-suppressed', 'no-preview');
            if (this.hasContainerTarget) {
                this.containerTarget.innerHTML = '';
            }
            return;
        }
        this.element.removeAttribute('hidden');
        this.containerTarget.innerHTML = html;
        this.element.classList.add('nostr-preview--loaded');
        const fallbackLink = this.element.querySelector(':scope > .nostr-preview-link');
        if (fallbackLink) {
            fallbackLink.remove();
        }
        if (this.application?.load) {
            this.application.load(this.containerTarget);
        }
    }
}
