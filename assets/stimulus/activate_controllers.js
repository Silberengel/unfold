/**
 * Connect Stimulus controllers inside HTML added via innerHTML.
 *
 * Symfony lazy-loads controller modules; application.load() walks the entire subtree
 * and throws if any nested data-controller module is missing. Only activate outermost
 * elements whose full descendant controller tree is registered.
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

    const controllerNamesOn = (el) =>
        (el.getAttribute('data-controller') || '')
            .trim()
            .split(/\s+/)
            .filter(Boolean);

    const isSubtreeReady = (el) => {
        const nodes = [el, ...el.querySelectorAll('[data-controller]')];
        for (const node of nodes) {
            const names = controllerNamesOn(node);
            if (names.length === 0 || !names.every(isReady)) {
                return false;
            }
        }
        return true;
    };

    const hasReadyControllerAncestor = (el) => {
        let parent = el.parentElement;
        while (parent && parent !== root) {
            if (parent.hasAttribute('data-controller') && isSubtreeReady(parent)) {
                return true;
            }
            parent = parent.parentElement;
        }
        return false;
    };

    const candidates = [...root.querySelectorAll('[data-controller]')].filter(
        (el) => isSubtreeReady(el) && !hasReadyControllerAncestor(el),
    );

    for (const el of candidates) {
        try {
            application.load(el);
        } catch (err) {
            console.debug('[stimulus] activateControllers element skipped', err);
        }
    }
}
