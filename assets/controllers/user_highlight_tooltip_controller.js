import { Controller } from '@hotwired/stimulus';

/**
 * Scroll to #highlight-<event id> when landing from the home highlights aside.
 * Author avatars are rendered inline in the HTML ({@see ArticleBodyHighlightInjector}).
 */
export default class extends Controller {
    connect() {
        this._onHashChange ??= () => {
            this._scrollToHashHighlight();
        };
        window.removeEventListener('hashchange', this._onHashChange);
        window.addEventListener('hashchange', this._onHashChange);
        this._scrollToHashHighlight();
    }

    disconnect() {
        window.removeEventListener('hashchange', this._onHashChange);
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
}
