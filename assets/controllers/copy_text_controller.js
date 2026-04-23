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
                const prev = btn.textContent;
                btn.textContent = 'Copied';
                window.setTimeout(() => {
                    btn.textContent = prev;
                }, 2000);
            }
        } catch (e) {
            console.warn('Copy failed', e);
        }
    }
}
