/**
 * Connect Stimulus controllers inside HTML added via innerHTML.
 *
 * Symfony lazy-loads controller modules; application.load() throws if it hits a
 * data-controller whose module is not registered yet. The bundle's
 * MutationObserver loads those modules moments later — a failed load here is safe.
 *
 * @param {import('@hotwired/stimulus').Application | undefined} application
 * @param {Element | Document | DocumentFragment} root
 */
export function activateControllers(application, root) {
    if (!application?.load || !root) {
        return;
    }
    try {
        application.load(root);
    } catch (err) {
        console.debug('[stimulus] activateControllers skipped', err);
    }
}
