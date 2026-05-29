/**
 * Connect Stimulus controllers inside HTML added via innerHTML.
 *
 * Symfony lazy-loads controller modules; application.load() on a subtree throws if any
 * data-controller module is still loading. Connect per-element only when every controller
 * on that element is fully registered.
 *
 * @param {import('@hotwired/stimulus').Application | undefined} application
 * @param {Element | Document | DocumentFragment} root
 */
export function activateControllers(application, root) {
    if (!application?.load || !root?.querySelectorAll) {
        return;
    }

    const router = application.router;
    const isReady = (name) => {
        const mod = router?.modulesByIdentifier?.get?.(name);
        return mod?.controllerConstructor != null;
    };

    root.querySelectorAll('[data-controller]').forEach((el) => {
        const names = (el.getAttribute('data-controller') || '')
            .trim()
            .split(/\s+/)
            .filter(Boolean);
        if (names.length === 0 || !names.every(isReady)) {
            return;
        }
        try {
            application.load(el);
        } catch (err) {
            console.debug('[stimulus] activateControllers element skipped', err);
        }
    });
}
