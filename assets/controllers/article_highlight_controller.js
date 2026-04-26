import { Controller } from '@hotwired/stimulus';

const MARK_SEL = 'mark.article-body-highlight';

/**
 * In-article NIP-84 marks: show popover on hover / click; #h-<64-hex> scrolls and focuses the mark.
 */
export default class extends Controller {
    static targets = ['article', 'meta', 'popover', 'popoverInner'];

    connect() {
        this._metaById = {};
        if (this.hasMetaTarget) {
            try {
                this._metaById = JSON.parse(this.metaTarget.textContent || '{}');
            } catch {
                this._metaById = {};
            }
        }
        this._pinnedId = null;
        this._openId = null;
        this._hoverLeaveTimer = null;
        this._onHash = () => {
            this._scrollToHash();
        };
        this._onScrollReposition = () => {
            if (this._openId && this.hasPopoverTarget) {
                const a = this._getMarkByEventId(this._openId);
                if (a) {
                    this._placePopover(a);
                }
            }
        };
        this._onPopoverPointerEnter = () => {
            this._clearHoverLeaveTimer();
        };
        this._onPopoverPointerLeave = () => {
            if (!this._pinnedId) {
                this._hoverLeaveTimer = setTimeout(() => {
                    this._hoverLeaveTimer = null;
                    this._hideUnpinnedPopover();
                }, 200);
            }
        };

        this._onMarkPointerEnter = (e) => {
            const a = e.target && e.target.closest && e.target.closest(MARK_SEL);
            if (!a) {
                return;
            }
            this._clearHoverLeaveTimer();
            this._openForMark(a, false);
        };
        this._onMarkPointerLeave = (e) => {
            const a = e.target && e.target.closest && e.target.closest(MARK_SEL);
            if (!a) {
                return;
            }
            this._onMarkLeave(a);
        };
        this._onMarkClick = (e) => {
            const a = e.target && e.target.closest && e.target.closest(MARK_SEL);
            if (!a) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            const id = (a.getAttribute('data-event-id') || '').toLowerCase();
            if (this._pinnedId === id) {
                this._closePinned();
                this._hideUnpinnedPopover();
                return;
            }
            this._openForMark(a, true);
        };
        this._onMarkFocus = (e) => {
            const t = e.target;
            if (!t || !t.classList || !t.classList.contains('article-body-highlight')) {
                return;
            }
            this._openForMark(t, false);
        };
        this._onMarkKeydown = (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            const t = e.target;
            if (!t || !t.classList || !t.classList.contains('article-body-highlight')) {
                return;
            }
            e.preventDefault();
            this._openForMark(t, true);
        };

        if (this.hasArticleTarget) {
            this.articleTarget.addEventListener('pointerenter', this._onMarkPointerEnter, true);
            this.articleTarget.addEventListener('pointerleave', this._onMarkPointerLeave, true);
            this.articleTarget.addEventListener('click', this._onMarkClick, true);
            this.articleTarget.addEventListener('focusin', this._onMarkFocus, true);
            this.articleTarget.addEventListener('keydown', this._onMarkKeydown, true);
        }
        if (this.hasPopoverTarget) {
            this.popoverTarget.addEventListener('pointerenter', this._onPopoverPointerEnter);
            this.popoverTarget.addEventListener('pointerleave', this._onPopoverPointerLeave);
        }
        window.addEventListener('scroll', this._onScrollReposition, true);
        window.addEventListener('resize', this._onScrollReposition);
        this._scrollToHash();
        window.addEventListener('hashchange', this._onHash);
    }

    disconnect() {
        window.removeEventListener('hashchange', this._onHash);
        window.removeEventListener('scroll', this._onScrollReposition, true);
        window.removeEventListener('resize', this._onScrollReposition);
        if (this.hasArticleTarget) {
            this.articleTarget.removeEventListener('pointerenter', this._onMarkPointerEnter, true);
            this.articleTarget.removeEventListener('pointerleave', this._onMarkPointerLeave, true);
            this.articleTarget.removeEventListener('click', this._onMarkClick, true);
            this.articleTarget.removeEventListener('focusin', this._onMarkFocus, true);
            this.articleTarget.removeEventListener('keydown', this._onMarkKeydown, true);
        }
        if (this.hasPopoverTarget) {
            this.popoverTarget.removeEventListener('pointerenter', this._onPopoverPointerEnter);
            this.popoverTarget.removeEventListener('pointerleave', this._onPopoverPointerLeave);
        }
        this._clearHoverLeaveTimer();
    }

    closeOnOutside(event) {
        if (!this.hasPopoverTarget) {
            return;
        }
        if (!this._pinnedId && !this._openId) {
            return;
        }
        const t = event.target;
        if (this.popoverTarget.contains(t)) {
            return;
        }
        if (t && t.closest && t.closest('mark.article-body-highlight')) {
            return;
        }
        if (this._pinnedId) {
            this._closePinned();
        } else {
            this._hideUnpinnedPopover();
        }
    }

    onKeydown(event) {
        if (event.key === 'Escape') {
            this._closePinned();
            this._hideUnpinnedPopover();
        }
    }

    closePopover() {
        this._closePinned();
        this._hideUnpinnedPopover();
    }

    _onMarkLeave(mark) {
        this._clearHoverLeaveTimer();
        if (this._pinnedId) {
            return;
        }
        this._hoverLeaveTimer = setTimeout(() => {
            this._hoverLeaveTimer = null;
            if (this._openId === (mark.getAttribute('data-event-id') || '').toLowerCase()) {
                this._hideUnpinnedPopover();
            }
        }, 200);
    }

    _clearHoverLeaveTimer() {
        if (this._hoverLeaveTimer) {
            clearTimeout(this._hoverLeaveTimer);
            this._hoverLeaveTimer = null;
        }
    }

    _getMarkByEventId(id) {
        if (!id) {
            return null;
        }
        return (
            this.element.querySelector(`#highlight-${id}`) ||
            this.element.querySelector(`mark.article-body-highlight[data-event-id="${CSS.escape(id)}"]`)
        );
    }

    _openForMark(mark, pin) {
        if (!this.hasPopoverTarget || !this.hasPopoverInnerTarget) {
            return;
        }
        const id = (mark.getAttribute('data-event-id') || '').toLowerCase();
        if (!id || 64 !== id.length) {
            return;
        }
        const meta = this._metaById[id];
        if (!meta) {
            return;
        }
        this._openId = id;
        if (pin) {
            this._pinnedId = id;
        } else {
            this._clearHoverLeaveTimer();
        }
        const head = meta.headHtml || '';
        const body = (meta.bodyHtml || '').trim();
        this.popoverInnerTarget.innerHTML =
            head + (body !== '' ? `<div class="article-body-highlight__body user-highlight__body">${body}</div>` : '');
        this._placePopover(mark);
        this.popoverTarget.hidden = false;
    }

    _placePopover(anchor) {
        if (!this.hasPopoverTarget) {
            return;
        }
        const p = this.popoverTarget;
        p.style.position = 'fixed';
        p.style.zIndex = '200';
        p.style.left = '0';
        p.style.top = '0';
        const r = anchor.getBoundingClientRect();
        p.hidden = false;
        const pr = p.getBoundingClientRect();
        let left = r.left + r.width / 2 - pr.width / 2;
        let top = r.bottom + 8;
        if (left < 8) {
            left = 8;
        }
        if (left + pr.width > window.innerWidth - 8) {
            left = Math.max(8, window.innerWidth - 8 - pr.width);
        }
        if (top + pr.height > window.innerHeight - 8) {
            top = Math.max(8, r.top - 8 - pr.height);
        }
        p.style.left = `${Math.round(left)}px`;
        p.style.top = `${Math.round(top)}px`;
    }

    _hideUnpinnedPopover() {
        this._openId = null;
        this._clearHoverLeaveTimer();
        if (this._pinnedId) {
            return;
        }
        if (this.hasPopoverTarget) {
            this.popoverTarget.hidden = true;
        }
        if (this.hasPopoverInnerTarget) {
            this.popoverInnerTarget.innerHTML = '';
        }
    }

    _closePinned() {
        this._pinnedId = null;
        if (this.hasPopoverTarget) {
            this.popoverTarget.hidden = true;
        }
        if (this.hasPopoverInnerTarget) {
            this.popoverInnerTarget.innerHTML = '';
        }
        this._openId = null;
    }

    _scrollToHash() {
        const raw = window.location.hash || '';
        if (!raw.startsWith('#h-')) {
            return;
        }
        const id = raw.slice(3).toLowerCase().replace(/[^0-9a-f]/g, '');
        if (id.length !== 64) {
            return;
        }
        const el = this.element.querySelector(`#highlight-${id}`) || this._getMarkByEventId(id);
        if (!el) {
            return;
        }
        el.classList.remove('article-body-highlight--target');
        void el.offsetWidth;
        el.classList.add('article-body-highlight--target');
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        this._openForMark(el, false);
    }
}
