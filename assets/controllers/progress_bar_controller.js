// Top-of-page progress: indeterminate while navigating; completes on the next page’s load.
import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'unfold_pb';

export default class extends Controller {
  static targets = ['bar'];

  connect() {
    this.boundHandleInteraction = this.handleInteraction.bind(this);
    this.boundPageShow = this.onPageShow.bind(this);
    document.addEventListener('click', this.boundHandleInteraction);
    document.addEventListener('touchstart', this.handleTouchStart);
    document.addEventListener('touchend', this.handleTouchEnd);
    window.addEventListener('pageshow', this.boundPageShow);
    this.resumeIfPending();
  }

  disconnect() {
    document.removeEventListener('click', this.boundHandleInteraction);
    document.removeEventListener('touchstart', this.handleTouchStart);
    document.removeEventListener('touchend', this.handleTouchEnd);
    window.removeEventListener('pageshow', this.boundPageShow);
    if (this.loadListener) {
      window.removeEventListener('load', this.loadListener);
      this.loadListener = null;
    }
  }

  onPageShow(event) {
    if (event.persisted) {
      sessionStorage.removeItem(STORAGE_KEY);
      this.resetBar();
    }
  }

  /**
   * After a same-tab navigation, finish the bar as soon as the new document is fully loaded
   * (or immediately if the load event already happened).
   */
  resumeIfPending() {
    if (sessionStorage.getItem(STORAGE_KEY) !== '1' || !this.hasBarTarget) {
      return;
    }
    this.barTarget.classList.add('pb-indeterminate');
    const finish = () => {
      this.completeToDone();
    };
    if (document.readyState === 'complete') {
      requestAnimationFrame(finish);
    } else {
      this.loadListener = finish;
      window.addEventListener('load', finish, { once: true });
    }
  }

  completeToDone() {
    if (sessionStorage.getItem(STORAGE_KEY) !== '1' || !this.hasBarTarget) {
      return;
    }
    if (this.loadListener) {
      window.removeEventListener('load', this.loadListener);
      this.loadListener = null;
    }
    this.barTarget.classList.remove('pb-indeterminate');
    this.barTarget.style.transition = 'width 0.18s ease-out';
    this.barTarget.style.width = '100%';
    window.setTimeout(() => {
      this.barTarget.style.transition = 'none';
      this.barTarget.style.width = '0';
      this.barTarget.style.removeProperty('transition');
      sessionStorage.removeItem(STORAGE_KEY);
    }, 220);
  }

  resetBar() {
    if (!this.hasBarTarget) {
      return;
    }
    this.barTarget.classList.remove('pb-indeterminate');
    this.barTarget.style.width = '0';
  }

  handleTouchStart = (event) => {
    const touch = event.changedTouches[0];
    this.touchStartX = touch.screenX;
    this.touchStartY = touch.screenY;
  };

  handleTouchEnd = (event) => {
    const touch = event.changedTouches[0];
    const dx = Math.abs(touch.screenX - this.touchStartX);
    const dy = Math.abs(touch.screenY - this.touchStartY);
    if (dx < 10 && dy < 10) {
      this.handleInteraction(event);
    }
  };

  handleInteraction(event) {
    const link = event.target.closest('a');
    if (!link || link.hasAttribute('data-no-progress')) {
      return;
    }
    if (event.ctrlKey || event.metaKey || event.shiftKey) {
      return;
    }
    const t = link.getAttribute('target');
    if (t && t !== '' && t !== '_self') {
      return;
    }
    if (link.hasAttribute('download')) {
      return;
    }
    const href = link.getAttribute('href');
    if (!href || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) {
      return;
    }
    let url;
    try {
      url = new URL(href, window.location.href);
    } catch {
      return;
    }
    if (url.origin !== window.location.origin) {
      return;
    }
    if (url.href.split('#')[0] === window.location.href.split('#')[0] && url.hash) {
      return;
    }
    this.start();
  }

  start() {
    if (!this.hasBarTarget) {
      return;
    }
    sessionStorage.setItem(STORAGE_KEY, '1');
    this.barTarget.style.transition = 'none';
    this.barTarget.style.width = '0';
    this.barTarget.classList.add('pb-indeterminate');
  }
}
