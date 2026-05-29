import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import {
    activeSignerKind,
    abortNostrConnectWait,
    clearNip46Session,
    connectNip46Bunker,
    createNostrConnectPair,
    hasNip07Extension,
    signEvent,
    waitForNostrConnect,
} from '../nostr/signer.js';

const NIP46_RELAY_STORAGE_KEY = 'unfold.nostr.nip46.client_relays';

export default class extends Controller {
  static targets = [
    'error',
    'submitButton',
    'amberToggleButton',
    'amberPanel',
    'bunkerInput',
    'amberSubmitButton',
    'qrCanvas',
    'relayInput',
    'relayRefreshButton',
    'connectUriInput',
    'copyUriButton',
    'amberStatus',
  ];
  static values = {
    noExtensionMessage: String,
    cancelledMessage: String,
    failedMessage: String,
    amberPromptMessage: String,
    amberInvalidUrlMessage: String,
    amberWaitingMessage: String,
    amberConnectedMessage: String,
    amberRelaysInvalidMessage: String,
    copyUriLabel: String,
    copiedMessage: String,
    nip46Relays: Array,
    siteName: String,
    siteUrl: String,
  };

  connect() {
    this._nostrConnectUri = '';
    this._nostrConnectSecretKey = null;
    this._nostrConnectAbort = null;
    this._liveComponent = null;
  }

  disconnect() {
    this.abortNostrConnectFlow();
  }

  async liveComponent() {
    if (this._liveComponent !== null) {
      return this._liveComponent;
    }
    try {
      this._liveComponent = await getComponent(this.element);
    } catch {
      this._liveComponent = false;
    }
    return this._liveComponent || null;
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

  setAmberStatus(message) {
    if (!this.hasAmberStatusTarget) {
      return;
    }
    if (!message) {
      this.amberStatusTarget.hidden = true;
      this.amberStatusTarget.textContent = '';
      return;
    }
    this.amberStatusTarget.textContent = message;
    this.amberStatusTarget.hidden = false;
  }

  abortNostrConnectFlow() {
    if (this._nostrConnectAbort) {
      this._nostrConnectAbort.abort();
      this._nostrConnectAbort = null;
    }
    abortNostrConnectWait();
  }

  toggleAmberPanel() {
    if (!this.hasAmberPanelTarget) {
      return;
    }
    const opening = this.amberPanelTarget.hidden;
    this.amberPanelTarget.hidden = !opening;
    if (this.hasAmberToggleButtonTarget) {
      this.amberToggleButtonTarget.setAttribute('aria-expanded', opening ? 'true' : 'false');
    }
    if (!opening) {
      this.abortNostrConnectFlow();
      this.setAmberStatus('');
      return;
    }
    this.populateRelayInput();
    void this.startNostrConnectFlow();
  }

  defaultNip46Relays() {
    return Array.isArray(this.nip46RelaysValue) ? this.nip46RelaysValue : [];
  }

  loadStoredNip46Relays() {
    try {
      const raw = localStorage.getItem(NIP46_RELAY_STORAGE_KEY);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) {
        return null;
      }
      const relays = parsed.filter((r) => typeof r === 'string' && r.startsWith('wss://'));
      return relays.length > 0 ? relays : null;
    } catch {
      return null;
    }
  }

  saveNip46Relays(relays) {
    localStorage.setItem(NIP46_RELAY_STORAGE_KEY, JSON.stringify(relays));
  }

  parseRelayList(text) {
    return String(text ?? '')
      .split(/[\s,]+/)
      .map((entry) => entry.trim())
      .filter((entry) => entry.startsWith('wss://'));
  }

  populateRelayInput() {
    if (!this.hasRelayInputTarget) {
      return;
    }
    const relays = this.loadStoredNip46Relays() ?? this.defaultNip46Relays();
    this.relayInputTarget.value = relays.join('\n');
  }

  currentNip46Relays() {
    if (this.hasRelayInputTarget) {
      const fromInput = this.parseRelayList(this.relayInputTarget.value);
      if (fromInput.length > 0) {
        return fromInput;
      }
    }
    return this.loadStoredNip46Relays() ?? this.defaultNip46Relays();
  }

  refreshNostrConnectRelaysAct() {
    if (!this.hasRelayInputTarget) {
      return;
    }
    const relays = this.parseRelayList(this.relayInputTarget.value);
    if (relays.length === 0) {
      this.showError(this.amberRelaysInvalidMessageValue || this.amberInvalidUrlMessageValue);
      return;
    }
    this.saveNip46Relays(relays);
    this.relayInputTarget.value = relays.join('\n');
    void this.startNostrConnectFlow();
  }

  async startNostrConnectFlow() {
    this.clearError();
    this.abortNostrConnectFlow();
    this.setAmberStatus('');

    const relays = this.currentNip46Relays();
    if (relays.length === 0) {
      this.showError(this.amberRelaysInvalidMessageValue || this.amberInvalidUrlMessageValue);
      return;
    }
    this.saveNip46Relays(relays);
    if (this.hasRelayInputTarget) {
      this.relayInputTarget.value = relays.join('\n');
    }

    try {
      const { uri, secretKey } = await createNostrConnectPair(relays, {
        name: this.siteNameValue || undefined,
        url: this.siteUrlValue || undefined,
      });
      this._nostrConnectUri = uri;
      this._nostrConnectSecretKey = secretKey;

      if (this.hasConnectUriInputTarget) {
        this.connectUriInputTarget.value = uri;
      }
      await this.renderQrCode(uri);
      this.setAmberStatus(this.amberWaitingMessageValue);

      this._nostrConnectAbort = new AbortController();
      const signal = this._nostrConnectAbort.signal;
      waitForNostrConnect(secretKey, uri, signal)
        .then(async () => {
          if (signal.aborted) {
            return;
          }
          this.setAmberStatus(this.amberConnectedMessageValue);
          await this.performLogin(async () => true, { prefer: 'nip46' });
        })
        .catch((e) => {
          if (signal.aborted || (e instanceof Error && e.name === 'AbortError')) {
            return;
          }
          const msg = e instanceof Error ? e.message : String(e);
          this.showError(msg || this.amberInvalidUrlMessageValue);
          this.setAmberStatus('');
        })
        .finally(() => {
          if (this._nostrConnectAbort?.signal === signal) {
            this._nostrConnectAbort = null;
          }
        });
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      this.showError(msg || this.amberInvalidUrlMessageValue);
    }
  }

  async renderQrCode(uri) {
    if (!this.hasQrCanvasTarget) {
      return;
    }
    const QRCode = (await import('qrcode')).default;
    await QRCode.toCanvas(this.qrCanvasTarget, uri, {
      width: 220,
      margin: 1,
      errorCorrectionLevel: 'M',
    });
    this.qrCanvasTarget.setAttribute('role', 'img');
    this.qrCanvasTarget.setAttribute('aria-label', 'QR code for Amber remote signer connection');
  }

  async copyConnectUriAct() {
    const t = this._nostrConnectUri || (this.hasConnectUriInputTarget ? this.connectUriInputTarget.value : '');
    if (t === '') {
      return;
    }
    try {
      await navigator.clipboard.writeText(t);
      if (this.hasCopyUriButtonTarget) {
        const btn = this.copyUriButtonTarget;
        const prev = btn.textContent;
        btn.textContent = this.copiedMessageValue || 'Copied';
        window.setTimeout(() => {
          btn.textContent = prev || this.copyUriLabelValue || 'Copy link';
        }, 2000);
      }
    } catch (e) {
      console.warn('Copy failed', e);
    }
  }

  async loginAct() {
    this.abortNostrConnectFlow();
    await this.performLogin(async () => {
      if (!hasNip07Extension()) {
        this.showError(this.noExtensionMessageValue);
        return false;
      }
      return true;
    }, { prefer: 'nip07' });
  }

  async loginWithAmberAct() {
    const url = this.hasBunkerInputTarget ? this.bunkerInputTarget.value.trim() : '';
    if (url === '') {
      this.showError(this.amberPromptMessageValue);
      return;
    }
    if (!/^bunker:\/\//i.test(url) && !/^nostrconnect:\/\//i.test(url)) {
      this.showError(this.amberInvalidUrlMessageValue);
      return;
    }
    const amberBtn = this.hasAmberSubmitButtonTarget ? this.amberSubmitButtonTarget : null;
    if (amberBtn) {
      amberBtn.disabled = true;
    }
    this.abortNostrConnectFlow();
    this.setAmberStatus('');
    try {
      this.clearError();
      await connectNip46Bunker(url);
      await this.performLogin(async () => true, { prefer: 'nip46' });
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      this.showError(msg || this.amberInvalidUrlMessageValue);
    } finally {
      if (amberBtn) {
        amberBtn.disabled = false;
      }
    }
  }

  async performLogin(beforeSign, signOptions = {}) {
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

      const signed = await signEvent(ev, signOptions);

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
        this.abortNostrConnectFlow();
        const component = await this.liveComponent();
        if (component) {
          void component.render();
        }
        window.dispatchEvent(
          new CustomEvent('unfold:auth-changed', {
            detail: {
              loggedIn: true,
              npub: data.npub,
              signer: signOptions.prefer === 'nip07' ? 'nip07' : activeSignerKind(),
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
