import { Controller } from '@hotwired/stimulus';

/**
 * Closes the <details> dropdown after a list action (link or button) so the panel does not stay open.
 */
export default class extends Controller {
    stopPropagation(event) {
        event.stopPropagation();
    }

    closeAfterMenuAction(event) {
        const action = event.target.closest('.nostr-share-menu__list .nostr-share-menu__action');
        if (!action || !this.element.contains(action)) {
            return;
        }
        queueMicrotask(() => {
            this.element.removeAttribute('open');
        });
    }
}
