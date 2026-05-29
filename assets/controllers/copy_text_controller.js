import { Controller } from '@hotwired/stimulus';

/**
 * Copies data-copy-text-text-value to the clipboard (e.g. full payment URI for wallets).
 */
export default class extends Controller {
    static values = { text: String };

    static targets = ['button'];

    async copy() {
        const t = this.textValue ?? '';
        if (t === '') {
            return;
        }
        try {
            await navigator.clipboard.writeText(t);
            const btn = this.hasButtonTarget ? this.buttonTarget : this.element.querySelector('button');
            if (btn) {
                const icon = btn.querySelector('.author-profile__copy-icon, svg');
                if (icon) {
                    const prevLabel = btn.getAttribute('aria-label') ?? 'Copy';
                    btn.setAttribute('aria-label', 'Copied');
                    btn.classList.add('is-copied');
                    window.setTimeout(() => {
                        btn.setAttribute('aria-label', prevLabel);
                        btn.classList.remove('is-copied');
                    }, 2000);
                } else {
                    const prev = btn.textContent;
                    btn.textContent = 'Copied';
                    window.setTimeout(() => {
                        btn.textContent = prev;
                    }, 2000);
                }
            }
        } catch (e) {
            console.warn('Copy failed', e);
        }
    }
}
