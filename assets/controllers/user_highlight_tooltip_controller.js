import { Controller } from '@hotwired/stimulus';

const HIDE_MS = 180;

function el(tag, cls, parent) {
    const e = document.createElement(tag);
    if (cls) {
        e.className = cls;
    }
    if (parent) {
        parent.appendChild(e);
    }
    return e;
}

function shortNpub(n) {
    if (n == null || n.length < 16) {
        return n || '';
    }
    return `${n.slice(0, 12)}…${n.slice(-6)}`;
}

/**
 * In-article highlight marks: hover/focus to show a tooltip of user-badges for everyone
 * who highlighted the same passage (data-hl JSON from {@see \App\Service\ArticleBodyHighlightInjector}).
 */
export default class extends Controller {
    connect() {
        this.tip = el('div', 'user-highlight__tip-popover', document.body);
        this.tip.setAttribute('role', 'tooltip');
        this.tip.setAttribute('hidden', '');

        this.activeMark = null;
        this._hideT = 0;
        this._inTip = false;

        this._onOver = (e) => {
            if (!(e instanceof MouseEvent)) {
                return;
            }
            const t = e.target;
            if (!(t instanceof Node)) {
                return;
            }
            const m =
                t.nodeType === 1
                    ? /** @type {Element} */ (t).closest('mark.user-highlight__marker[data-hl]')
                    : t.parentElement?.closest('mark.user-highlight__marker[data-hl]') ?? null;
            if (m) {
                this._cancelHide();
                this._show(/** @type {HTMLElement} */ (m), e);
            }
        };
        this._onOut = (e) => {
            if (!(e instanceof MouseEvent)) {
                return;
            }
            const t = e.target;
            if (!(t instanceof Node)) {
                return;
            }
            const m =
                t.nodeType === 1
                    ? /** @type {Element} */ (t).closest('mark.user-highlight__marker[data-hl]')
                    : null;
            if (m) {
                const to = e.relatedTarget;
                if (to && (m.contains(to) || (to instanceof Node && this.tip.contains(/** @type {Node} */(to))))) {
                    return;
                }
            }
            this._scheduleHide();
        };

        this.tip.addEventListener('mouseenter', () => {
            this._inTip = true;
            this._cancelHide();
        });
        this.tip.addEventListener('mouseleave', () => {
            this._inTip = false;
            this._scheduleHide();
        });

        this._onFocus = (e) => {
            const t = e.target;
            if (!(t instanceof Element)) {
                return;
            }
            const m = t.closest('mark.user-highlight__marker[data-hl]');
            if (m) {
                this._cancelHide();
                this._show(/** @type {HTMLElement} */ (m), e);
            }
        };
        this._onBlur = (e) => {
            const t = e.target;
            if (!(t instanceof Node)) {
                return;
            }
            const m = t.nodeType === 1 ? t.closest('mark.user-highlight__marker[data-hl]') : null;
            if (m) {
                const to = e.relatedTarget;
                if (to && (m.contains(to) || (to instanceof Node && this.tip.contains(/** @type {Node} */ (to))))) {
                    return;
                }
            }
            this._scheduleHide();
        };

        this.element.addEventListener('mouseover', this._onOver);
        this.element.addEventListener('mouseout', this._onOut);
        this.element.addEventListener('focusin', this._onFocus);
        this.element.addEventListener('focusout', this._onBlur);

        this._onResize = () => {
            if (this.activeMark) {
                this._place(this.activeMark);
            }
        };
        window.addEventListener('resize', this._onResize);

        this._onHashChange = () => {
            this._scrollToHashHighlight();
        };
        window.addEventListener('hashchange', this._onHashChange);
        this._scrollToHashHighlight();
    }

    /**
     * Browsers are inconsistent about scrolling to #highlight-<event id> (inline marks, alias spans,
     * late layout). Mirror native intent after paint.
     */
    _scrollToHashHighlight() {
        const hash = window.location.hash;
        if (!hash?.startsWith('#highlight-')) {
            return;
        }
        const id = decodeURIComponent(hash.slice(1));
        if (!/^highlight-[a-f0-9]{64}$/i.test(id)) {
            return;
        }
        const run = () => {
            const node = document.getElementById(id);
            if (!(node instanceof HTMLElement)) {
                return;
            }
            const next = node.nextElementSibling;
            const target =
                node.classList.contains('user-highlight__fragment-target') &&
                next?.classList?.contains('user-highlight__marker')
                    ? next
                    : node;
            target.scrollIntoView({ block: 'start', inline: 'nearest' });
        };
        requestAnimationFrame(() => {
            requestAnimationFrame(run);
        });
    }

    disconnect() {
        this.element.removeEventListener('mouseover', this._onOver);
        this.element.removeEventListener('mouseout', this._onOut);
        this.element.removeEventListener('focusin', this._onFocus);
        this.element.removeEventListener('focusout', this._onBlur);
        window.removeEventListener('resize', this._onResize);
        if (this._onHashChange) {
            window.removeEventListener('hashchange', this._onHashChange);
        }
        this._cancelHide();
        this.tip.remove();
    }

    _cancelHide() {
        if (this._hideT) {
            clearTimeout(this._hideT);
            this._hideT = 0;
        }
    }

    _scheduleHide() {
        this._cancelHide();
        this._hideT = window.setTimeout(() => {
            this._hideT = 0;
            if (this._inTip) {
                return;
            }
            this._doHide();
        }, HIDE_MS);
    }

    _doHide() {
        this.tip.setAttribute('hidden', '');
        this.tip.replaceChildren();
        this.activeMark = null;
    }

    /**
     * @param {HTMLElement} mark
     * @param {UIEvent} _e
     */
    _show(mark, _e) {
        this.activeMark = mark;
        const raw = mark.getAttribute('data-hl');
        if (raw == null || raw === '') {
            this._doHide();
            return;
        }
        /** @type {Array<{e?: string, n: string, a?: string, p?: string}>} */
        let rows;
        try {
            rows = JSON.parse(raw);
        } catch {
            this._doHide();
            return;
        }
        if (!Array.isArray(rows) || rows.length === 0) {
            this._doHide();
            return;
        }

        this.tip.removeAttribute('hidden');
        this.tip.replaceChildren();

        const head = el('div', 'user-highlight__tip-head', this.tip);
        head.textContent = 'Highlighted by';

        const list = el('ul', 'user-highlight__tip-list', this.tip);
        for (const row of rows) {
            if (!row || typeof row.n !== 'string' || !row.n.startsWith('npub1')) {
                continue;
            }
            const li = el('li', 'user-highlight__tip-item', list);
            const a = el('a', 'user-badge user-badge--in-tip', li);
            a.setAttribute('href', `/p/${encodeURIComponent(row.n)}`);
            const label = (row.a && String(row.a).trim() !== '' ? String(row.a) : shortNpub(row.n)) || shortNpub(row.n);

            const av = el('span', 'user-badge__avatar user-badge__avatar--in-tip', a);
            if (row.p && typeof row.p === 'string' && row.p.length > 0) {
                const im = el('img', 'user-badge__avatar-img', av);
                im.setAttribute('src', row.p);
                im.setAttribute('alt', '');
                im.setAttribute('loading', 'lazy');
                im.addEventListener('error', () => {
                    im.remove();
                    av.setAttribute('aria-hidden', 'true');
                    const dot = el('span', 'user-badge__avatar-fallback--dot', av);
                    dot.textContent = label.charAt(0).toUpperCase() || '…';
                });
            } else {
                av.setAttribute('aria-hidden', 'true');
                const dot = el('span', 'user-badge__avatar-fallback--dot', av);
                dot.textContent = label.charAt(0).toUpperCase() || '…';
            }
            const nm = el('span', 'user-badge__name', a);
            nm.appendChild(document.createTextNode(label));
        }

        requestAnimationFrame(() => {
            this._place(mark);
        });
    }

    /**
     * @param {HTMLElement} mark
     */
    _place(mark) {
        const r = mark.getBoundingClientRect();
        const pad = 8;
        this.tip.style.position = 'fixed';
        this.tip.style.zIndex = '2000';
        this.tip.style.top = `${Math.round(r.bottom + pad)}px`;
        let left = Math.round(r.left);
        const w = this.tip.getBoundingClientRect().width || 300;
        if (left + w + 12 > window.innerWidth) {
            left = Math.max(8, window.innerWidth - w - 8);
        }
        this.tip.style.left = `${left}px`;
    }
}
