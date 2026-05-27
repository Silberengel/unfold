import { Controller } from '@hotwired/stimulus';

/**
 * Shows or hides the "Edit magazine" footer link when auth changes without a full page reload.
 */
export default class extends Controller {
  static values = {
    publisherNpub: String,
  };

  connect() {
    this.boundOnAuth ??= this.onAuthChanged.bind(this);
    window.removeEventListener('unfold:auth-changed', this.boundOnAuth);
    window.addEventListener('unfold:auth-changed', this.boundOnAuth);
  }

  disconnect() {
    window.removeEventListener('unfold:auth-changed', this.boundOnAuth);
  }

  onAuthChanged(event) {
    const d = event.detail;
    if (!d) {
      return;
    }
    if (d.loggedIn && d.npub === this.publisherNpubValue) {
      this.element.hidden = false;
    } else {
      this.element.hidden = true;
    }
  }
}
