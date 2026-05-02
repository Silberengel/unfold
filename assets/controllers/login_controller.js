import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
  static targets = ['error', 'submitButton'];
  static values = {
    noExtensionMessage: String,
    cancelledMessage: String,
    failedMessage: String,
  };

  async initialize() {
    this.component = await getComponent(this.element);
  }

  authLogout() {
    window.dispatchEvent(
      new CustomEvent('unfold:auth-changed', { detail: { loggedIn: false } })
    );
  }

  clearError() {
    if (this.hasErrorTarget) {
      this.errorTarget.hidden = true;
      this.errorTarget.textContent = '';
    }
  }

  showError(message) {
    if (!this.hasErrorTarget || !message) {
      return;
    }
    this.errorTarget.textContent = message;
    this.errorTarget.hidden = false;
  }

  async loginAct() {
    this.clearError();

    if (!window.nostr?.signEvent) {
      this.showError(this.noExtensionMessageValue);
      return;
    }

    const submit = this.hasSubmitButtonTarget ? this.submitButtonTarget : null;
    if (submit) {
      submit.disabled = true;
    }

    try {
      const loginUrl = new URL('/login', window.location.origin).href;
      const tags = [
        ['u', loginUrl],
        ['method', 'POST'],
      ];
      const ev = {
        created_at: Math.floor(Date.now() / 1000),
        kind: 27235,
        tags,
        content: '',
      };

      const signed = await window.nostr.signEvent(ev);

      const response = await fetch('/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Authorization: 'Nostr ' + btoa(JSON.stringify(signed)),
          Accept: 'application/json',
        },
      });

      const raw = await response.text();
      let data = null;
      if (raw) {
        try {
          data = JSON.parse(raw);
        } catch {
          data = null;
        }
      }

      if (response.ok && data?.npub) {
        void this.component.render();
        window.dispatchEvent(
          new CustomEvent('unfold:auth-changed', {
            detail: { loggedIn: true, npub: data.npub },
          })
        );
        return;
      }

      const serverMsg =
        data && typeof data.message === 'string' ? data.message : '';
      this.showError(serverMsg || this.failedMessageValue);
    } catch (e) {
      const name = e && typeof e === 'object' && 'name' in e ? e.name : '';
      if (name === 'AbortError') {
        return;
      }
      this.showError(this.cancelledMessageValue);
    } finally {
      if (submit) {
        submit.disabled = false;
      }
    }
  }
}
