import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'unfold-color-scheme';

export default class extends Controller {
    static targets = ['moon', 'sun'];

    connect() {
        this.link = document.getElementById('theme-magazine-stylesheet');
        this._syncFromDom();
        this._refreshIcons();
    }

    toggle() {
        const cur = document.documentElement.getAttribute('data-color-scheme') || 'light';
        const next = cur === 'dark' ? 'light' : 'dark';
        this.apply(next, true);
    }

    /**
     * @param {'light'|'dark'} scheme
     * @param {boolean} persist
     */
    apply(scheme, persist) {
        const siteDefault = document.documentElement.getAttribute('data-color-scheme-default') || 'dark';
        if (scheme !== 'dark' && scheme !== 'light') {
            scheme = siteDefault;
        }
        const darkHref = this.link?.getAttribute('data-href-dark');
        if (scheme === 'dark' && !darkHref) {
            scheme = 'light';
        }
        document.documentElement.setAttribute('data-color-scheme', scheme);
        if (persist) {
            try {
                localStorage.setItem(STORAGE_KEY, scheme);
            } catch (_) {
                /* private mode */
            }
        }
        if (this.link) {
            const light = this.link.getAttribute('data-href-light');
            const href = scheme === 'dark' && darkHref ? darkHref : light;
            // getAttribute returns null when the attribute is absent; passing null to setAttribute
            // would coerce it to the string "null", producing a broken stylesheet URL.
            if (href !== null) {
                this.link.setAttribute('href', href);
            }
        }
        this._refreshIcons();
    }

    _syncFromDom() {
        /* Link href was set by inline script; icons follow current scheme. */
        this._refreshIcons();
    }

    _refreshIcons() {
        const dark = document.documentElement.getAttribute('data-color-scheme') === 'dark';
        if (this.hasMoonTarget) {
            this.moonTarget.hidden = dark;
        }
        if (this.hasSunTarget) {
            this.sunTarget.hidden = !dark;
        }
        const btn = this.element.querySelector('.color-scheme-toggle');
        if (btn) {
            btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            btn.setAttribute('title', dark ? 'Light mode' : 'Dark mode');
        }
    }
}
