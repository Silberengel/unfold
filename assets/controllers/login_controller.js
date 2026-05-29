import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import {
    activeSignerKind,
    canSignEvents,
    clearNip46Session,
    connectNip46Bunker,
    signEvent,
} from '../nostr/signer.js';

export default class extends Controller {
  static targets = [
    'error',
    'submitButton',
    'amberPanel',
    'bunkerInput',
    'amberSubmitButton',
  ];
  static values = {
    noExtensionMessage: String,
    cancelledMessage: String,
    failedMessage: String,
    amberPromptMessage: String,
    amberInvalidUrlMessage: String,
    amberConnectingMessage: String,
  };

  async initialize() {
    this.component = await getComponent(this.element);
  }

  authLogout() {
    clearNip46Session();
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

  toggleAmberPanel() {
    if (!this.hasAmberPanelTarget) {
      return;
    }
    const hidden = this.amberPanelTarget.hidden;
    this.amberPanelTarget.hidden = !hidden;
    if (!this.amberPanelTarget.hidden && this.hasBunkerInputTarget) {
      this.bunkerInputTarget.focus();
    }
  }

  async loginAct() {
    await this.performLogin(async () => {
      if (!canSignEvents()) {
        this.showError(this.noExtensionMessageValue);
        return false;
      }
      return true;
    });
  }

  async loginWithAmberAct() {
    const url = this.hasBunkerInputTarget ? this.bunkerInputTarget.value.trim() : '';
    if (url === '') {
      this.showError(this.amberPromptMessageValue);
      return;
    }
    const amberBtn = this.hasAmberSubmitButtonTarget ? this.amberSubmitButtonTarget : null;
    if (amberBtn) {
      amberBtn.disabled = true;
    }
    try {
      this.clearError();
      await connectNip46Bunker(url);
      await this.performLogin(async () => true);
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      this.showError(msg || this.amberInvalidUrlMessageValue);
    } finally {
      if (amberBtn) {
        amberBtn.disabled = false;
      }
    }
  }

  async performLogin(beforeSign) {
    this.clearError();

    const ok = await beforeSign();
    if (!ok) {
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

      const signed = await signEvent(ev);

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
            detail: {
              loggedIn: true,
              npub: data.npub,
              signer: activeSignerKind(),
            },
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
};
