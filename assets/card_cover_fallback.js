/**
 * Broken article hero URLs → author picture / site default (same as empty cover).
 *
 * @param {HTMLImageElement} img
 */
export function cardCoverFallback(img) {
    const container = img.closest(
        '.card-header, .curation-article-display__media, .featured-tile__picture, .featured-tile__media, .article__image',
    );
    const fallback = img.dataset.cardCoverFallback ?? '';
    const siteDefault = img.dataset.cardCoverDefault ?? '';

    if (!img.dataset.coverRetried && fallback !== '') {
        img.dataset.coverRetried = '1';
        img.src = fallback;
        container?.classList.add('card-header--no-cover');
        return;
    }
    if (!img.dataset.coverDefaulted && siteDefault !== '') {
        img.dataset.coverDefaulted = '1';
        img.src = siteDefault;
        container?.classList.add('card-header--no-cover');
        return;
    }
    img.onerror = null;
}

if (typeof window !== 'undefined') {
    window.unfoldCardCoverFallback = cardCoverFallback;
}
