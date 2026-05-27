import { Controller } from '@hotwired/stimulus';

const KIND_PUBLICATION_INDEX = 30040;
const KIND_LONGFORM = 30023;
const KIND_LONGFORM_DRAFT = 30024;

/**
 * Owner-only magazine hierarchy: build kind-30040 tags from fieldsets, NIP-07 sign each, POST batch.
 * Only signs nodes that differ from the page load snapshot (unchanged indices are not re-signed).
 */
const DTAG_PATTERN = /^[a-zA-Z0-9_-]+$/;

export default class MagazineHierarchyEditorController extends Controller {
    static targets = ['status', 'publishBtn', 'node', 'nodes', 'newNodeTemplate', 'aRowTemplate'];

    static values = {
        publishUrl: String,
        csrf: String,
        ownerHex: String,
        rootDTag: String,
        defaultPreservedJson: { type: String, default: '[]' },
        /** Site-owned NIP-56 `client` name; foreign `client` rows are dropped before signing. */
        clientTag: { type: String, default: '' },
    };

    connect() {
        this.nodeBaseline = new WeakMap();
        this.captureBaselines();
        /**
         * Clicks: `document` capture + `this.element.contains(target)` so we still run when bubble
         * never reaches the panel (e.g. `stopPropagation` from another listener) or fieldset/legend
         * hit-testing is odd.
         */
        this._onDocClickCapture = this._onDocClickCapture.bind(this);
        this._onPanelFocusOut = this._onPanelFocusOut.bind(this);
        document.addEventListener('click', this._onDocClickCapture, true);
        this.element.addEventListener('focusout', this._onPanelFocusOut);
    }

    disconnect() {
        document.removeEventListener('click', this._onDocClickCapture, true);
        this.element.removeEventListener('focusout', this._onPanelFocusOut);
    }

    /**
     * @param {MouseEvent} ev
     */
    _onDocClickCapture(ev) {
        if (!(ev.target instanceof Node)) {
            return;
        }
        if (!this.element.contains(ev.target)) {
            return;
        }
        this._onPanelClick(ev);
    }

    /**
     * @param {MouseEvent} ev
     */
    _onPanelClick(ev) {
        if (!(ev.target instanceof Element)) {
            return;
        }
        const t = ev.target;
        if (t.closest('[data-mag-editor-cmd="publish"]')) {
            void this._publish();
            return;
        }
        if (t.closest('[data-mag-editor-cmd="add-top-category"]')) {
            this.addTopLevelCategory();
            return;
        }
        const addSubCmd = t.closest('[data-mag-editor-cmd="add-subcategory"]');
        if (addSubCmd instanceof HTMLElement) {
            this.addSubcategory(ev, addSubCmd);
            return;
        }
        const rmCatCmd = t.closest('[data-mag-editor-cmd="remove-category"]');
        if (rmCatCmd instanceof HTMLElement) {
            this.removeCategory(ev, rmCatCmd);
            return;
        }
        const addArticle = t.closest('[data-mag-a-add]');
        if (addArticle instanceof HTMLElement) {
            this.addALine(ev, addArticle);
            return;
        }
        const rmArticle = t.closest('.magazine-editor__a-remove-icon');
        if (rmArticle instanceof HTMLElement) {
            this.removeALine(ev, rmArticle);
            return;
        }
        const sub = t.closest('.magazine-editor__add-sub');
        if (sub instanceof HTMLElement) {
            this.addSubcategory(ev, sub);
            return;
        }
        const rmCat = t.closest('.magazine-editor__remove-node');
        if (rmCat instanceof HTMLElement) {
            this.removeCategory(ev, rmCat);
            return;
        }
    }

    /**
     * @param {FocusEvent} ev
     */
    _onPanelFocusOut(ev) {
        const inp = ev.target;
        if (!(inp instanceof HTMLInputElement)) {
            return;
        }
        if (!inp.matches('[data-magazine-hierarchy-editor-target="dTag"]')) {
            return;
        }
        this.commitDTag(ev);
    }

    captureBaselines() {
        if (!this.hasNodesTarget) {
            return;
        }
        for (const el of queryEditorNodeFieldsets(this.nodesTarget)) {
            this.nodeBaseline.set(el, snapshotFromElement(el));
        }
    }

    /**
     * @param {Event} [event]
     */
    publish(event) {
        event?.preventDefault?.();
        void this._publish();
    }

    /**
     * @param {Event} event
     * @param {HTMLElement} [triggerEl]
     */
    addALine(event, triggerEl) {
        const trigger =
            triggerEl instanceof HTMLElement
                ? triggerEl
                : event.currentTarget instanceof HTMLElement && event.currentTarget.hasAttribute('data-mag-a-add')
                  ? event.currentTarget
                  : event.target instanceof Element
                    ? event.target.closest('[data-mag-a-add]')
                    : null;
        if (!(trigger instanceof HTMLElement)) {
            return;
        }
        const list = trigger.closest('[data-mag-a-list]');
        if (!list || !this.hasARowTemplateTarget) {
            return;
        }
        const addBtn = list.querySelector('[data-mag-a-add]');
        if (!addBtn) {
            return;
        }
        const frag = this.aRowTemplateTarget.content.cloneNode(true);
        const row = frag.querySelector('[data-mag-a-row]');
        if (!row) {
            return;
        }
        list.insertBefore(row, addBtn);
    }

    /**
     * @param {Event} event
     * @param {HTMLElement} [triggerEl]
     */
    removeALine(event, triggerEl) {
        const trigger =
            triggerEl instanceof HTMLElement
                ? triggerEl
                : event.currentTarget instanceof HTMLElement && event.currentTarget.classList.contains('magazine-editor__a-remove-icon')
                  ? event.currentTarget
                  : event.target instanceof Element
                    ? event.target.closest('.magazine-editor__a-remove-icon')
                    : null;
        if (!(trigger instanceof HTMLElement)) {
            return;
        }
        const row = trigger.closest('[data-mag-a-row]');
        row?.remove();
    }

    addTopLevelCategory() {
        this.setStatus('');
        const rootD = (this.rootDTagValue || '').trim();
        if (rootD === '') {
            this.setStatus('Missing root index #d.');
            return;
        }
        this.insertNewNode(rootD, 1);
    }

    /**
     * @param {Event} event
     * @param {HTMLElement} [triggerEl]
     */
    addSubcategory(event, triggerEl) {
        this.setStatus('');
        const btn =
            triggerEl instanceof HTMLElement
                ? triggerEl
                : event.currentTarget instanceof HTMLElement && event.currentTarget.classList.contains('magazine-editor__add-sub')
                  ? event.currentTarget
                  : event.target instanceof Element
                    ? event.target.closest('.magazine-editor__add-sub')
                    : null;
        if (!(btn instanceof HTMLElement)) {
            return;
        }
        const parentEl = btn.closest('[data-magazine-hierarchy-editor-target="node"]');
        if (!parentEl) {
            return;
        }
        const rootD = (this.rootDTagValue || '').trim();
        const placed = (parentEl.dataset.magPlacedD || '').trim();
        const parentD = (placed !== '' ? placed : readDTag(parentEl)).trim();
        if (parentD === '' || parentD === rootD) {
            this.setStatus('Use “Add top-level category” for indices linked from the root.');
            return;
        }
        const depth = parseInt(parentEl.dataset.magDepth || '1', 10);
        this.insertNewNode(parentD, depth + 1);
    }

    /**
     * Blur on Index #d (new or existing category / subcategory).
     * @param {Event} event
     */
    commitDTag(event) {
        const t =
            event.target instanceof HTMLElement
                ? event.target
                : event.currentTarget instanceof HTMLElement
                  ? event.currentTarget
                  : null;
        if (!(t instanceof HTMLElement)) {
            return;
        }
        const el = t.closest('[data-magazine-hierarchy-editor-target="node"]');
        if (!el || isRootFieldset(el)) {
            return;
        }
        if (el.dataset.isNewNode === '1') {
            this.applySlugChange(el);
        } else {
            this.commitExistingDTagRename(el);
        }
    }

    /**
     * @param {Event} event
     * @param {HTMLElement} [triggerEl]
     */
    removeCategory(event, triggerEl) {
        this.setStatus('');
        const btn =
            triggerEl instanceof HTMLElement
                ? triggerEl
                : event.currentTarget instanceof HTMLElement && event.currentTarget.classList.contains('magazine-editor__remove-node')
                  ? event.currentTarget
                  : event.target instanceof Element
                    ? event.target.closest('.magazine-editor__remove-node')
                    : null;
        if (!(btn instanceof HTMLElement)) {
            return;
        }
        const fs = btn.closest('[data-magazine-hierarchy-editor-target="node"]');
        if (!fs || isRootFieldset(fs)) {
            return;
        }
        const ownerHex = (this.ownerHexValue || '').toLowerCase().trim();
        if (ownerHex.length !== 64) {
            this.setStatus('Missing owner pubkey.');
            return;
        }

        if (fs.dataset.isNewNode === '1') {
            const parentD = (fs.dataset.magParentD || '').trim();
            const placed = (fs.dataset.placedSlug || '').trim();
            if (parentD !== '' && placed !== '') {
                const parentFs = this.findFieldsetByDTag(parentD);
                if (parentFs) {
                    this.remove30040ChildLine(parentFs, placed, ownerHex);
                }
            }
            fs.remove();
            return;
        }

        const sub = collectSubtreeFieldsets(fs, this.nodesTarget);
        sub.sort((a, b) => parseInt(b.dataset.magDepth || '0', 10) - parseInt(a.dataset.magDepth || '0', 10));
        for (const n of sub) {
            const p = (n.dataset.magParentD || '').trim();
            const d = readDTag(n);
            if (p === '' || d === '') {
                continue;
            }
            const pFs = this.findFieldsetByDTag(p);
            if (pFs) {
                this.remove30040ChildLine(pFs, d, ownerHex);
            }
        }
        for (const n of sub) {
            n.remove();
        }
    }

    insertNewNode(parentD, depth) {
        if (!this.hasNewNodeTemplateTarget || !this.hasNodesTarget) {
            this.setStatus('Editor template is incomplete; reload the page.');
            return;
        }
        const ownerHex = (this.ownerHexValue || '').toLowerCase().trim();
        if (ownerHex.length !== 64) {
            this.setStatus('Missing owner pubkey.');
            return;
        }
        const parentDTrim = parentD.trim();
        if (parentDTrim === '') {
            return;
        }

        const frag = this.newNodeTemplateTarget.content.cloneNode(true);
        const fs = frag.querySelector('fieldset[data-magazine-hierarchy-editor-target="node"]');
        if (!fs) {
            this.setStatus('Could not clone new node template.');
            return;
        }

        fs.dataset.magParentD = parentDTrim;
        fs.dataset.isNewNode = '1';
        fs.dataset.magDepth = String(depth);
        const slug = `mag-new-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        fs.dataset.placedSlug = slug;

        fs.style.setProperty('--mag-node-depth', String(depth));
        fs.style.marginLeft = `calc(max(0, var(--mag-node-depth) - 1) * 1.15rem)`;
        if (depth > 1) {
            fs.classList.add('magazine-editor__node--nested');
        }

        const lab = fs.querySelector('[data-new-node-legend-label]');
        if (lab) {
            lab.textContent = depth <= 1 ? 'New category' : 'New subcategory';
        }

        let preservedStr = '[]';
        try {
            preservedStr = JSON.stringify(JSON.parse(this.defaultPreservedJsonValue || '[]'));
        } catch {
            preservedStr = '[]';
        }
        const preservedEl = fs.querySelector('[data-magazine-hierarchy-editor-target="preservedJson"]');
        if (preservedEl) {
            preservedEl.value = preservedStr;
        }

        const dIn = fs.querySelector('[data-magazine-hierarchy-editor-target="dTag"]');
        if (dIn && 'value' in dIn) {
            dIn.value = slug;
        }

        if (!this.append30040ChildLine(parentDTrim, slug, ownerHex)) {
            this.setStatus(
                `Could not add the nested kind-30040 link to the parent’s <code>a</code> list (parent #d “${parentDTrim}”). Reload the page if this persists.`,
            );
            return;
        }
        const parentFs = this.findFieldsetByDTag(parentDTrim);
        const anchor = parentFs ? lastDescendantFieldsetAmongNodes(parentFs, this.nodesTarget) : null;
        if (anchor?.parentElement === this.nodesTarget) {
            anchor.insertAdjacentElement('afterend', fs);
        } else {
            this.nodesTarget.appendChild(fs);
        }
        this.nodeBaseline.set(fs, snapshotFromElement(fs));
    }

    /**
     * @param {HTMLElement} el
     */
    applySlugChange(el) {
        const ownerHex = (this.ownerHexValue || '').toLowerCase().trim();
        const parentD = (el.dataset.magParentD || '').trim();
        const placed = (el.dataset.placedSlug || '').trim();
        const next = readDTag(el).trim();
        if (placed === next) {
            return;
        }
        if (parentD === '' || ownerHex.length !== 64) {
            return;
        }
        const parentFs = this.findFieldsetByDTag(parentD);
        if (!parentFs) {
            return;
        }
        if (placed !== '') {
            this.remove30040ChildLine(parentFs, placed, ownerHex);
        }
        if (next !== '') {
            if (!this.append30040ChildLine(parentD, next, ownerHex)) {
                if (placed !== '') {
                    this.append30040ChildLine(parentD, placed, ownerHex);
                }
                this.setStatus(
                    `Could not add #d “${next}” to the parent’s <code>a</code> list (parent “${parentD}”).`,
                );
                return;
            }
        }
        el.dataset.placedSlug = next;
    }

    /**
     * @param {HTMLElement} el Fieldset (not root, not new-node).
     */
    commitExistingDTagRename(el) {
        const ownerHex = (this.ownerHexValue || '').toLowerCase().trim();
        if (ownerHex.length !== 64) {
            return;
        }
        const dIn = el.querySelector('[data-magazine-hierarchy-editor-target="dTag"]');
        const oldD = (el.dataset.magPlacedD || '').trim();
        const next = readDTag(el).trim();
        if (oldD === next) {
            return;
        }
        if (next === '') {
            this.setStatus('#d cannot be empty.');
            if (dIn instanceof HTMLInputElement) {
                dIn.value = oldD;
            }
            return;
        }
        if (!DTAG_PATTERN.test(next)) {
            this.setStatus(`Invalid #d "${next}" (use letters, digits, underscores, and hyphens only).`);
            if (dIn instanceof HTMLInputElement) {
                dIn.value = oldD;
            }
            return;
        }
        const rootD = (this.rootDTagValue || '').trim();
        if (next === rootD) {
            this.setStatus('A category #d cannot equal the root magazine identifier.');
            if (dIn instanceof HTMLInputElement) {
                dIn.value = oldD;
            }
            return;
        }
        for (const other of queryEditorNodeFieldsets(this.nodesTarget)) {
            if (other === el) {
                continue;
            }
            if (readDTag(other) === next) {
                this.setStatus(`Duplicate #d "${next}".`);
                if (dIn instanceof HTMLInputElement) {
                    dIn.value = oldD;
                }
                return;
            }
        }
        const parentD = (el.dataset.magParentD || '').trim();
        if (parentD === '') {
            return;
        }
        const parentFs = this.findFieldsetByDTag(parentD);
        if (!parentFs) {
            return;
        }
        const list = parentFs.querySelector('[data-mag-a-list]');
        if (!list) {
            return;
        }
        if (!rewrite30040ChildLineInList(list, ownerHex, oldD, next)) {
            this.setStatus(`Could not find parent link for old #d "${oldD}"; reload the page.`);
            if (dIn instanceof HTMLInputElement) {
                dIn.value = oldD;
            }
            return;
        }
        el.dataset.magPlacedD = next;
        this.setStatus('');
    }

    /**
     * @param {string} d
     * @returns {HTMLElement | null}
     */
    findFieldsetByDTag(d) {
        const want = d.trim();
        if (!this.hasNodesTarget) {
            return null;
        }
        for (const el of queryEditorNodeFieldsets(this.nodesTarget)) {
            if (readDTag(el).trim() === want) {
                return el;
            }
        }

        return null;
    }

    /**
     * @param {string} parentD Parent index #d (root or category).
     * @param {string} childD Nested kind-30040 child #d.
     * @param {string} ownerHex
     */
    /**
     * @returns {boolean} false if the parent fieldset or a-list could not be updated
     */
    append30040ChildLine(parentD, childD, ownerHex) {
        const parentFs = this.findFieldsetByDTag(parentD.trim());
        if (!parentFs) {
            return false;
        }
        const list = parentFs.querySelector('[data-mag-a-list]');
        const addBtn = list?.querySelector('[data-mag-a-add]');
        if (!list || !addBtn || !this.hasARowTemplateTarget) {
            return false;
        }
        const oh = ownerHex.toLowerCase();
        const cd = childD.trim();
        const want = `${KIND_PUBLICATION_INDEX}:${oh}:${cd}`;
        for (const line of collectALineValuesFromList(list)) {
            if (lineMatches30040Child(line, oh, cd)) {
                return true;
            }
        }
        const frag = this.aRowTemplateTarget.content.cloneNode(true);
        const row = frag.querySelector('[data-mag-a-row]');
        const inp = row?.querySelector('[data-mag-a-line]');
        if (!row || !(inp instanceof HTMLInputElement)) {
            return false;
        }
        inp.value = want;
        list.insertBefore(row, addBtn);
        return true;
    }

    /**
     * @param {HTMLElement} parentFs
     * @param {string} childD
     * @param {string} ownerHex
     */
    remove30040ChildLine(parentFs, childD, ownerHex) {
        const list = parentFs.querySelector('[data-mag-a-list]');
        if (!list) {
            return;
        }
        const oh = ownerHex.toLowerCase();
        const cd = childD.trim();
        for (const row of list.querySelectorAll('[data-mag-a-row]')) {
            const inp = row.querySelector('[data-mag-a-line]');
            if (!(inp instanceof HTMLInputElement)) {
                continue;
            }
            if (lineMatches30040Child(inp.value, oh, cd)) {
                row.remove();

                return;
            }
        }
    }

    /**
     * @param {string} rootD
     * @returns {string | null}
     */
    validateAllNodes(rootD) {
        const seen = new Set();
        if (!this.hasNodesTarget) {
            return 'Editor is missing the nodes list.';
        }
        for (const el of queryEditorNodeFieldsets(this.nodesTarget)) {
            const d = readDTag(el).trim();
            if (d === '') {
                return 'Every magazine index must have a non-empty #d identifier.';
            }
            if (isRootFieldset(el)) {
                if (d !== rootD) {
                    return 'Root fieldset #d does not match the configured magazine root.';
                }
            } else {
                if (!DTAG_PATTERN.test(d)) {
                    return `Invalid #d "${d}" (use letters, digits, underscores, and hyphens only).`;
                }
                if (d === rootD) {
                    return 'A category #d cannot equal the root magazine identifier.';
                }
            }
            if (seen.has(d)) {
                return `Duplicate #d identifier: ${d}.`;
            }
            seen.add(d);
        }

        return null;
    }

    /**
     * @param {HTMLElement} el
     */
    finalizeNewNodeFieldset(el) {
        const d = readDTag(el).trim();
        const depth = parseInt(el.dataset.magDepth || '1', 10);
        el.querySelector('[data-new-node-d-row]')?.removeAttribute('data-new-node-d-row');

        const legend = el.querySelector('legend.magazine-editor__legend');
        if (legend) {
            const kindLabel = depth > 1 ? 'Subcategory' : 'Category';
            legend.replaceChildren();
            legend.className = 'magazine-editor__legend magazine-editor__legend--row';
            const main = document.createElement('span');
            main.className = 'magazine-editor__legend-main';
            main.append(`${kindLabel}`);
            legend.appendChild(main);
            const actions = document.createElement('span');
            actions.className = 'magazine-editor__legend-actions';
            const btnSub = document.createElement('button');
            btnSub.type = 'button';
            btnSub.className = 'btn btn-secondary btn-sm magazine-editor__add-sub';
            btnSub.setAttribute('data-mag-editor-cmd', 'add-subcategory');
            btnSub.textContent = 'Add subcategory';
            actions.appendChild(btnSub);
            const btnRm = document.createElement('button');
            btnRm.type = 'button';
            btnRm.className = 'btn btn-secondary btn-sm magazine-editor__remove-node';
            btnRm.setAttribute('data-mag-editor-cmd', 'remove-category');
            btnRm.setAttribute('aria-label', 'Remove category');
            btnRm.textContent = 'Remove';
            actions.appendChild(btnRm);
            legend.appendChild(actions);
        }

        el.dataset.magPlacedD = d;
        el.classList.remove('magazine-editor__node--new');
        el.removeAttribute('data-is-new-node');
        el.removeAttribute('data-placed-slug');
    }

    async _publish() {
        if (this._publishInFlight) {
            return;
        }
        this._publishInFlight = true;
        this.setPublishBusy(true, 'Checking…');
        this.setStatus('Checking for changes…', { tone: 'info', scroll: true });

        try {
            if (!this.hasNip07()) {
                this.setStatus('Install a Nostr extension (NIP-07) to sign index events.', { tone: 'error', scroll: true });
                return;
            }
            const ownerHex = (this.ownerHexValue || '').toLowerCase().trim();
            if (ownerHex.length !== 64) {
                this.setStatus('Missing owner pubkey.', { tone: 'error', scroll: true });
                return;
            }
            const rootD = (this.rootDTagValue || '').trim();
            if (rootD === '') {
                this.setStatus('Missing root index #d.', { tone: 'error', scroll: true });
                return;
            }

            if (!this.hasNodesTarget) {
                this.setStatus('Editor is missing the nodes list.', { tone: 'error', scroll: true });
                return;
            }
            const nodes = queryEditorNodeFieldsets(this.nodesTarget);
            if (nodes.length === 0) {
                this.setStatus('Nothing to publish.', { tone: 'error', scroll: true });
                return;
            }

            for (const el of nodes) {
                if (el.dataset.isNewNode === '1') {
                    this.applySlugChange(el);
                }
            }

            const graphErr = this.validateAllNodes(rootD);
            if (graphErr !== null) {
                this.setStatus(graphErr, { tone: 'error', scroll: true });
                return;
            }

            const ordered = nodes.filter((el) => this.isNodeDirty(el));
            if (ordered.length === 0) {
                this.setStatus('No changes to publish.', { tone: 'info', scroll: true });
                return;
            }

            const baseTime = Math.floor(Date.now() / 1000);
            const signedEvents = [];

            const signingLabel =
                ordered.length === 1
                    ? 'Signing 1 event in extension…'
                    : `Signing ${ordered.length} events in extension…`;
            this.setPublishBusy(true, 'Waiting for extension…');
            this.setStatus(signingLabel, { tone: 'info', scroll: true });

            for (let i = 0; i < ordered.length; i++) {
                const el = ordered[i];
                const dTag = readDTag(el);
                const title = el.querySelector('[data-magazine-hierarchy-editor-target="title"]')?.value ?? '';
                const summary = el.querySelector('[data-magazine-hierarchy-editor-target="summary"]')?.value ?? '';
                const content = el.querySelector('[data-magazine-hierarchy-editor-target="content"]')?.value ?? '';
                const aText = readJoinedALinesFromNode(el);
                const preservedRaw =
                    el.querySelector('[data-magazine-hierarchy-editor-target="preservedJson"]')?.value ?? '[]';

                let preserved;
                try {
                    preserved = JSON.parse(preservedRaw);
                } catch {
                    this.setStatus(`Invalid preserved-tags JSON for #d ${dTag}.`, { tone: 'error', scroll: true });
                    return;
                }
                if (!Array.isArray(preserved)) {
                    this.setStatus(`Preserved tags must be a JSON array for #d ${dTag}.`, { tone: 'error', scroll: true });
                    return;
                }

                let tags;
                try {
                    tags = await this.buildTags(dTag, preserved, title, summary, aText, ownerHex);
                } catch (err) {
                    this.setStatus(err instanceof Error ? err.message : String(err), { tone: 'error', scroll: true });
                    return;
                }

                const unsigned = {
                    kind: KIND_PUBLICATION_INDEX,
                    created_at: baseTime + i,
                    tags,
                    content: content ?? '',
                };

                let signed;
                try {
                    signed = await window.nostr.signEvent(unsigned);
                } catch (err) {
                    this.setStatus(`Signing failed (#d ${dTag}): ${err instanceof Error ? err.message : String(err)}`, {
                        tone: 'error',
                        scroll: true,
                    });
                    return;
                }
                signedEvents.push(signed);
            }

            this.setPublishBusy(true, 'Publishing…');
            this.setStatus('Publishing to relays and saving…', { tone: 'info', scroll: true });
            let res;
            try {
                res = await fetch(this.publishUrlValue, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ csrf: this.csrfValue, events: signedEvents }),
                });
            } catch (err) {
                this.setStatus(`Network error: ${err instanceof Error ? err.message : String(err)}`, {
                    tone: 'error',
                    scroll: true,
                });
                return;
            }

            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                this.setStatus(typeof data.error === 'string' ? data.error : `HTTP ${res.status}`, {
                    tone: 'error',
                    scroll: true,
                });
                return;
            }
            const n = Number(data.published);
            const ingested = Number(data.longform_ingest_addresses);
            for (const el of ordered) {
                if (el.dataset.isNewNode === '1') {
                    this.finalizeNewNodeFieldset(el);
                }
                this.nodeBaseline.set(el, snapshotFromElement(el));
            }
            if (Number.isFinite(n) && Number.isFinite(ingested) && ingested > 0) {
                this.setStatus(
                    `Published and stored ${n} index event(s); synced ${ingested} long-form address(es) from relays.`,
                    { tone: 'success', scroll: true },
                );
            } else {
                this.setStatus(Number.isFinite(n) ? `Published and stored ${n} index event(s).` : 'Published.', {
                    tone: 'success',
                    scroll: true,
                });
            }
        } finally {
            this._publishInFlight = false;
            this.setPublishBusy(false);
        }
    }

    isNodeDirty(el) {
        if (el.dataset.isNewNode === '1') {
            return true;
        }
        const cur = snapshotFromElement(el);
        const base = this.nodeBaseline.get(el);
        if (!base) {
            return true;
        }
        return (
            cur.dTag !== base.dTag ||
            cur.title !== base.title ||
            cur.summary !== base.summary ||
            cur.content !== base.content ||
            cur.aText !== base.aText ||
            cur.preservedRaw !== base.preservedRaw
        );
    }

    /**
     * @param {string} dTag
     * @param {unknown[]} preserved
     * @param {string} title
     * @param {string} summary
     * @param {string} aText
     * @param {string} ownerHex
     * @returns {Promise<string[][]>}
     */
    async buildTags(dTag, preserved, title, summary, aText, ownerHex) {
        if (!dTag) {
            throw new Error('Every node must have a #d value.');
        }
        /** @type {string[][]} */
        const tags = [['d', dTag]];
        for (const row of preserved) {
            if (!Array.isArray(row) || row.length === 0) {
                continue;
            }
            const norm = row.map((c) => String(c));
            if (norm[0].toLowerCase() === 'd') {
                continue;
            }
            if (norm[0].toLowerCase() === 'client') {
                continue;
            }
            tags.push(norm);
        }
        tags.push(['title', title]);
        tags.push(['summary', summary]);
        const clientName = (this.clientTagValue || '').trim();
        if (clientName !== '') {
            tags.push(['client', clientName]);
        }

        const lines = aText
            .split('\n')
            .map((l) => l.trim())
            .filter((l) => l.length > 0);
        for (const line of lines) {
            const canonical = await normalizeAddressableCoordinate(line);
            this.assertValidCoordinate(canonical, ownerHex);
            tags.push(['a', canonical]);
        }
        return tags;
    }

    /**
     * @param {string} coord
     * @param {string} ownerHex
     */
    assertValidCoordinate(coord, ownerHex) {
        const parts = splitThree(coord);
        if (!parts) {
            throw new Error(`Invalid coordinate (use kind:pubkey:identifier): ${coord}`);
        }
        const kind = parseInt(parts.kind, 10);
        const pk = parts.pubkey.toLowerCase();
        const id = parts.identifier.trim();
        if (id.length === 0 || pk.length !== 64 || !/^[0-9a-f]+$/.test(pk)) {
            throw new Error(`Invalid pubkey or identifier in: ${coord}`);
        }
        if (kind === KIND_PUBLICATION_INDEX && pk !== ownerHex) {
            throw new Error(`Nested 30040 address must use magazine owner pubkey: ${coord}`);
        }
        if (kind !== KIND_PUBLICATION_INDEX && kind !== KIND_LONGFORM && kind !== KIND_LONGFORM_DRAFT) {
            throw new Error(`Only kinds 30040, 30023, 30024 allowed in a tag: ${coord}`);
        }
    }

    /**
     * @param {string} msg
     * @param {{ tone?: 'info' | 'success' | 'error', scroll?: boolean }} [opts]
     */
    setStatus(msg, opts = {}) {
        if (!this.hasStatusTarget) {
            return;
        }
        const el = this.statusTarget;
        const tone = opts.tone ?? 'info';
        const trimmed = msg.trim();
        el.textContent = trimmed;
        el.hidden = trimmed === '';
        el.classList.remove(
            'magazine-editor__status--info',
            'magazine-editor__status--success',
            'magazine-editor__status--error',
        );
        if (trimmed !== '') {
            el.classList.add(`magazine-editor__status--${tone}`);
            if (opts.scroll) {
                el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }
    }

    /**
     * @param {boolean} busy
     * @param {string} [label]
     */
    setPublishBusy(busy, label) {
        if (!this.hasPublishBtnTarget) {
            return;
        }
        const btn = this.publishBtnTarget;
        if (busy) {
            if (!this._publishBtnDefaultLabel) {
                this._publishBtnDefaultLabel = btn.textContent?.trim() ?? 'Sign and publish all changed events';
            }
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            if (label) {
                btn.textContent = label;
            }
        } else {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            if (this._publishBtnDefaultLabel) {
                btn.textContent = this._publishBtnDefaultLabel;
            }
        }
    }

    hasNip07() {
        return typeof window.nostr !== 'undefined' && typeof window.nostr.signEvent === 'function';
    }
}

/**
 * Index fieldsets that are direct children of the nodes list (excludes `<template>` fragments).
 * @param {HTMLElement} nodesContainer
 * @returns {HTMLElement[]}
 */
function queryEditorNodeFieldsets(nodesContainer) {
    return [...nodesContainer.querySelectorAll(':scope > [data-magazine-hierarchy-editor-target="node"]')];
}

/**
 * @param {HTMLElement} parentFs
 * @param {HTMLElement} nodesContainer
 */
function lastDescendantFieldsetAmongNodes(parentFs, nodesContainer) {
    const nodes = queryEditorNodeFieldsets(nodesContainer);
    const idx = nodes.indexOf(parentFs);
    if (idx === -1) {
        return parentFs;
    }
    const pd = parseInt(parentFs.dataset.magDepth || '0', 10);
    let last = parentFs;
    for (let i = idx + 1; i < nodes.length; i++) {
        const nd = parseInt(nodes[i].dataset.magDepth || '0', 10);
        if (nd <= pd) {
            break;
        }
        last = nodes[i];
    }
    return last;
}

/**
 * `rootFs` first, then following fieldsets with strictly greater depth (subtree in DOM order).
 * @param {HTMLElement} rootFs
 * @param {HTMLElement} nodesContainer
 * @returns {HTMLElement[]}
 */
function collectSubtreeFieldsets(rootFs, nodesContainer) {
    const nodes = queryEditorNodeFieldsets(nodesContainer);
    const idx = nodes.indexOf(rootFs);
    if (idx === -1) {
        return [rootFs];
    }
    const pd = parseInt(rootFs.dataset.magDepth || '0', 10);
    const out = [rootFs];
    for (let i = idx + 1; i < nodes.length; i++) {
        const nd = parseInt(nodes[i].dataset.magDepth || '0', 10);
        if (nd <= pd) {
            break;
        }
        out.push(nodes[i]);
    }
    return out;
}

/**
 * @param {HTMLElement} list
 * @param {string} ownerHex
 * @param {string} oldD
 * @param {string} newD
 * @returns {boolean}
 */
function rewrite30040ChildLineInList(list, ownerHex, oldD, newD) {
    const oh = ownerHex.toLowerCase();
    const o = oldD.trim();
    const n = newD.trim();
    for (const row of list.querySelectorAll('[data-mag-a-row]')) {
        const inp = row.querySelector('[data-mag-a-line]');
        if (!(inp instanceof HTMLInputElement)) {
            continue;
        }
        if (lineMatches30040Child(inp.value, oh, o)) {
            inp.value = `${KIND_PUBLICATION_INDEX}:${oh}:${n}`;
            return true;
        }
    }
    return false;
}

/**
 * @param {HTMLElement} el
 */
function readDTag(el) {
    const inp = el.querySelector('input[data-magazine-hierarchy-editor-target="dTag"]');
    return inp instanceof HTMLInputElement ? (inp.value || '').trim() : '';
}

/**
 * @param {HTMLElement} el
 */
function snapshotFromElement(el) {
    return {
        dTag: readDTag(el),
        title: el.querySelector('[data-magazine-hierarchy-editor-target="title"]')?.value ?? '',
        summary: el.querySelector('[data-magazine-hierarchy-editor-target="summary"]')?.value ?? '',
        content: el.querySelector('[data-magazine-hierarchy-editor-target="content"]')?.value ?? '',
        aText: readJoinedALinesFromNode(el),
        preservedRaw: el.querySelector('[data-magazine-hierarchy-editor-target="preservedJson"]')?.value ?? '[]',
    };
}

/**
 * @param {HTMLElement} list
 * @returns {string[]}
 */
function collectALineValuesFromList(list) {
    const out = [];
    for (const inp of list.querySelectorAll('[data-mag-a-line]')) {
        if (inp instanceof HTMLInputElement) {
            const v = inp.value.trim();
            if (v !== '') {
                out.push(v);
            }
        }
    }

    return out;
}

/**
 * @param {HTMLElement} el Fieldset for one index node.
 */
function readJoinedALinesFromNode(el) {
    const list = el.querySelector('[data-mag-a-list]');
    if (!list) {
        return '';
    }

    return collectALineValuesFromList(list).join('\n');
}

/**
 * @param {HTMLElement} el
 */
function isRootFieldset(el) {
    return el.dataset.magazineNodeIsRoot === '1';
}

/**
 * @param {string} line
 * @param {string} ownerHexLower
 * @param {string} childD
 */
function lineMatches30040Child(line, ownerHexLower, childD) {
    const s = stripNostrScheme(line);
    const parts = splitThree(s);
    if (!parts) {
        return false;
    }
    const kind = parseInt(parts.kind, 10);
    if (kind !== KIND_PUBLICATION_INDEX) {
        return false;
    }
    if (parts.pubkey.toLowerCase() !== ownerHexLower.toLowerCase()) {
        return false;
    }

    return parts.identifier.trim() === childD;
}

/**
 * @param {HTMLElement} el
 * @returns {{ dTag: string, title: string, summary: string, content: string, aText: string, preserved: unknown[] }}
 */
function readFieldsForBuild(el) {
    const dTag = readDTag(el);
    const title = el.querySelector('[data-magazine-hierarchy-editor-target="title"]')?.value ?? '';
    const summary = el.querySelector('[data-magazine-hierarchy-editor-target="summary"]')?.value ?? '';
    const content = el.querySelector('[data-magazine-hierarchy-editor-target="content"]')?.value ?? '';
    const aText = readJoinedALinesFromNode(el);
    const preservedRaw = el.querySelector('[data-magazine-hierarchy-editor-target="preservedJson"]')?.value ?? '[]';
    let preserved;
    try {
        preserved = JSON.parse(preservedRaw);
    } catch {
        throw new Error(`Invalid preserved-tags JSON for #d ${dTag}.`);
    }
    if (!Array.isArray(preserved)) {
        throw new Error(`Preserved tags must be a JSON array for #d ${dTag}.`);
    }
    return { dTag, title, summary, content, aText, preserved };
}

/**
 * @param {string} coord
 * @returns {{ kind: string, pubkey: string, identifier: string } | null}
 */
function splitThree(coord) {
    const s = coord.trim();
    const i = s.indexOf(':');
    if (i < 1) {
        return null;
    }
    const j = s.indexOf(':', i + 1);
    if (j <= i) {
        return null;
    }
    return {
        kind: s.slice(0, i),
        pubkey: s.slice(i + 1, j),
        identifier: s.slice(j + 1),
    };
}

/** @type {Promise<(s: string) => unknown> | null} */
let nip19DecodeLoader = null;

/**
 * @returns {Promise<(s: string) => unknown>}
 */
function loadNip19Decode() {
    if (!nip19DecodeLoader) {
        nip19DecodeLoader = import('nostr-tools/nip19').then((m) => m.decode);
    }
    return nip19DecodeLoader;
}

/**
 * Strips optional `nostr:` scheme and surrounding whitespace.
 * @param {string} raw
 */
function stripNostrScheme(raw) {
    let s = raw.trim();
    if (/^nostr:/i.test(s)) {
        s = s.slice(6).trim();
    }
    return s;
}

/**
 * Accepts `kind:pubkey:identifier` or NIP-19 `naddr1…` (optional `nostr:` prefix).
 * @param {string} raw
 * @returns {Promise<string>}
 */
async function normalizeAddressableCoordinate(raw) {
    const s = stripNostrScheme(raw);
    if (s === '') {
        throw new Error('Empty `a` line.');
    }
    if (/^naddr1[0-9a-z]+$/i.test(s)) {
        let decode;
        try {
            decode = await loadNip19Decode();
        } catch (e) {
            throw new Error(`Could not load NIP-19 decoder: ${e instanceof Error ? e.message : String(e)}`);
        }
        let decoded;
        try {
            decoded = decode(s);
        } catch (e) {
            throw new Error(`Invalid naddr: ${e instanceof Error ? e.message : String(e)}`);
        }
        if (decoded.type !== 'naddr' || !decoded.data || typeof decoded.data !== 'object') {
            throw new Error('Decoded value is not an naddr.');
        }
        const { kind, pubkey, identifier } = decoded.data;
        if (typeof kind !== 'number' || !Number.isFinite(kind)) {
            throw new Error('naddr is missing kind.');
        }
        if (typeof pubkey !== 'string' || pubkey.length !== 64 || !/^[0-9a-fA-F]+$/.test(pubkey)) {
            throw new Error('naddr is missing valid author pubkey hex.');
        }
        if (typeof identifier !== 'string' || identifier.trim() === '') {
            throw new Error('naddr is missing identifier (#d).');
        }
        return `${kind}:${pubkey.toLowerCase()}:${identifier}`;
    }
    const parts = splitThree(s);
    if (!parts) {
        throw new Error(
            `Invalid line (use kind:pubkey:identifier or naddr1…): ${raw.length > 120 ? raw.slice(0, 120) + '…' : raw}`,
        );
    }
    const kind = parseInt(parts.kind, 10);
    if (!Number.isFinite(kind)) {
        throw new Error(`Invalid kind in coordinate: ${parts.kind}`);
    }
    return `${kind}:${parts.pubkey.toLowerCase()}:${parts.identifier}`;
}
