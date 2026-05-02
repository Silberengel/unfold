import { Controller } from '@hotwired/stimulus';

const KIND_PUBLICATION_INDEX = 30040;
const KIND_LONGFORM = 30023;
const KIND_LONGFORM_DRAFT = 30024;

/**
 * Owner-only magazine hierarchy: build kind-30040 tags from fieldsets, NIP-07 sign each, POST batch.
 * Only signs nodes that differ from the page load snapshot, plus any other index required for
 * graph closure (root + every nested kind-30040 `a` reachable from those events).
 */
export default class extends Controller {
    static targets = ['status', 'node'];

    static values = {
        publishUrl: String,
        csrf: String,
        ownerHex: String,
        rootDTag: String,
    };

    connect() {
        this.nodeBaseline = new WeakMap();
        this.captureBaselines();
    }

    captureBaselines() {
        for (const el of this.nodeTargets) {
            this.nodeBaseline.set(el, snapshotFromElement(el));
        }
    }

    publish() {
        void this._publish();
    }

    async _publish() {
        this.setStatus('');
        if (!this.hasNip07()) {
            this.setStatus('Install a Nostr extension (NIP-07) to sign index events.');
            return;
        }
        const ownerHex = (this.ownerHexValue || '').toLowerCase().trim();
        if (ownerHex.length !== 64) {
            this.setStatus('Missing owner pubkey.');
            return;
        }
        const rootD = (this.rootDTagValue || '').trim();
        if (rootD === '') {
            this.setStatus('Missing root index #d.');
            return;
        }

        const nodes = this.nodeTargets;
        if (nodes.length === 0) {
            this.setStatus('Nothing to publish.');
            return;
        }

        const dirty = nodes.filter((el) => this.isNodeDirty(el));
        if (dirty.length === 0) {
            this.setStatus('No changes to publish.');
            return;
        }

        let publishSet;
        try {
            publishSet = await this.expandPublishSet(rootD, dirty, ownerHex);
        } catch (err) {
            this.setStatus(err instanceof Error ? err.message : String(err));
            return;
        }

        const ordered = nodes.filter((el) => publishSet.has(readDTag(el)));
        if (ordered.length === 0) {
            this.setStatus('Nothing to publish.');
            return;
        }

        const baseTime = Math.floor(Date.now() / 1000);
        const signedEvents = [];

        this.setStatus(
            ordered.length === dirty.length
                ? 'Signing…'
                : `Signing ${ordered.length} index event(s) (${dirty.length} edited, ${ordered.length - dirty.length} required for nested links)…`,
        );

        for (let i = 0; i < ordered.length; i++) {
            const el = ordered[i];
            const dTag = readDTag(el);
            const title = el.querySelector('[data-magazine-hierarchy-editor-target="title"]')?.value ?? '';
            const summary = el.querySelector('[data-magazine-hierarchy-editor-target="summary"]')?.value ?? '';
            const content = el.querySelector('[data-magazine-hierarchy-editor-target="content"]')?.value ?? '';
            const aText = el.querySelector('[data-magazine-hierarchy-editor-target="aLines"]')?.value ?? '';
            const preservedRaw = el.querySelector('[data-magazine-hierarchy-editor-target="preservedJson"]')?.value ?? '[]';

            let preserved;
            try {
                preserved = JSON.parse(preservedRaw);
            } catch {
                this.setStatus(`Invalid preserved-tags JSON for #d ${dTag}.`);
                return;
            }
            if (!Array.isArray(preserved)) {
                this.setStatus(`Preserved tags must be a JSON array for #d ${dTag}.`);
                return;
            }

            let tags;
            try {
                tags = await this.buildTags(dTag, preserved, title, summary, aText, ownerHex);
            } catch (err) {
                this.setStatus(err instanceof Error ? err.message : String(err));
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
                this.setStatus(`Signing failed (#d ${dTag}): ${err instanceof Error ? err.message : String(err)}`);
                return;
            }
            signedEvents.push(signed);
        }

        this.setStatus('Publishing…');
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
            this.setStatus(`Network error: ${err instanceof Error ? err.message : String(err)}`);
            return;
        }

        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            this.setStatus(typeof data.error === 'string' ? data.error : `HTTP ${res.status}`);
            return;
        }
        const n = Number(data.published);
        for (const el of ordered) {
            this.nodeBaseline.set(el, snapshotFromElement(el));
        }
        this.setStatus(Number.isFinite(n) ? `Published and stored ${n} index event(s).` : 'Published.');
    }

    isNodeDirty(el) {
        const cur = snapshotFromElement(el);
        const base = this.nodeBaseline.get(el);
        if (!base) {
            return true;
        }
        return (
            cur.title !== base.title ||
            cur.summary !== base.summary ||
            cur.content !== base.content ||
            cur.aText !== base.aText ||
            cur.preservedRaw !== base.preservedRaw
        );
    }

    /**
     * Minimal set of #d tags that must appear in the POST body: dirty nodes, the root, and every
     * kind-30040 child referenced (transitively) from those events' `a` tags — matches server
     * {@see MagazineHierarchyPublishService::validateGraphClosure}.
     *
     * @param {HTMLElement} dirtyFieldsets
     * @returns {Promise<Set<string>>}
     */
    async expandPublishSet(rootD, dirtyFieldsets, ownerHex) {
        /** @type {Map<string, HTMLElement>} */
        const dToEl = new Map();
        for (const el of this.nodeTargets) {
            const d = readDTag(el);
            if (d) {
                dToEl.set(d, el);
            }
        }

        const W = new Set();
        for (const el of dirtyFieldsets) {
            const d = readDTag(el);
            if (d) {
                W.add(d);
            }
        }
        W.add(rootD);

        let growing = true;
        let guard = 0;
        while (growing && guard < 256) {
            guard += 1;
            growing = false;
            const snapshot = [...W];
            for (const d of snapshot) {
                const el = dToEl.get(d);
                if (!el) {
                    continue;
                }
                const { dTag, title, summary, content, aText, preserved } = readFieldsForBuild(el);
                const tags = await this.buildTags(dTag, preserved, title, summary, aText, ownerHex);
                for (const childD of ownedNested30040DsFromTags(tags, ownerHex)) {
                    if (!W.has(childD)) {
                        W.add(childD);
                        growing = true;
                    }
                }
            }
        }

        return W;
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
            tags.push(norm);
        }
        tags.push(['title', title]);
        tags.push(['summary', summary]);

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

    setStatus(msg) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = msg;
        }
    }

    hasNip07() {
        return typeof window.nostr !== 'undefined' && typeof window.nostr.signEvent === 'function';
    }
}

/**
 * @param {string[][]} tags
 * @param {string} ownerHex
 * @returns {Set<string>}
 */
function ownedNested30040DsFromTags(tags, ownerHex) {
    const out = new Set();
    const oh = ownerHex.toLowerCase();
    for (const row of tags) {
        if (row.length < 2 || String(row[0]).toLowerCase() !== 'a') {
            continue;
        }
        const coord = String(row[1]).trim();
        const parts = splitThree(coord);
        if (!parts) {
            continue;
        }
        const kind = parseInt(parts.kind, 10);
        if (kind !== KIND_PUBLICATION_INDEX) {
            continue;
        }
        const pk = parts.pubkey.toLowerCase();
        if (pk !== oh) {
            continue;
        }
        const id = parts.identifier.trim();
        if (id !== '') {
            out.add(id);
        }
    }
    return out;
}

/**
 * @param {HTMLElement} el
 */
function readDTag(el) {
    return (el.querySelector('[data-magazine-hierarchy-editor-target="dTag"]')?.value || '').trim();
}

/**
 * @param {HTMLElement} el
 */
function snapshotFromElement(el) {
    return {
        title: el.querySelector('[data-magazine-hierarchy-editor-target="title"]')?.value ?? '',
        summary: el.querySelector('[data-magazine-hierarchy-editor-target="summary"]')?.value ?? '',
        content: el.querySelector('[data-magazine-hierarchy-editor-target="content"]')?.value ?? '',
        aText: el.querySelector('[data-magazine-hierarchy-editor-target="aLines"]')?.value ?? '',
        preservedRaw: el.querySelector('[data-magazine-hierarchy-editor-target="preservedJson"]')?.value ?? '[]',
    };
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
    const aText = el.querySelector('[data-magazine-hierarchy-editor-target="aLines"]')?.value ?? '';
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
