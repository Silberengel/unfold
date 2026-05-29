import { Controller } from '@hotwired/stimulus';

/**
 * Publication reader: dropdown + go button for full HTML view or file download.
 */
export default class extends Controller {
    static targets = ['select'];

    static values = {
        publicationUrl: String,
        downloadUrl: String,
    };

    go() {
        if (!this.hasSelectTarget) {
            return;
        }
        const action = this.selectTarget.value;
        if (action === 'view') {
            const url = new URL(this.publicationUrlValue, window.location.origin);
            url.searchParams.set('view', 'full');
            url.searchParams.delete('section');
            window.location.assign(url.toString());
            return;
        }
        const url = new URL(this.downloadUrlValue, window.location.origin);
        url.searchParams.set('format', action);
        window.location.assign(url.toString());
    }
}
