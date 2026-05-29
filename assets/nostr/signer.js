/**
 * Unified Nostr signing: NIP-07 browser extension or NIP-46 remote signer (e.g. Amber).
 */

import { generateSecretKey, getPublicKey } from 'nostr-tools';
import { bytesToHex, hexToBytes } from '@noble/hashes/utils';

const STORAGE_KEY = 'unfold.nostr.nip46';

/** @type {import('nostr-tools/nip46').BunkerSigner | null} */
let nip46Signer = null;

/** @type {AbortController | null} */
let nostrConnectAbort = null;

function hasNip07() {
    return typeof window.nostr !== 'undefined' && typeof window.nostr.signEvent === 'function';
}

function loadNip46Stored() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        if (
            parsed
            && parsed.type === 'nip46'
            && typeof parsed.secretKey === 'string'
            && typeof parsed.bunkerUrl === 'string'
        ) {
            return parsed;
        }
    } catch {
        // ignore
    }
    return null;
}

function saveNip46Stored(secretKey, bunkerUrl) {
    localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ type: 'nip46', secretKey, bunkerUrl }),
    );
}

function randomConnectSecret() {
    return bytesToHex(crypto.getRandomValues(new Uint8Array(16)));
}

async function getNip46Module() {
    return import('nostr-tools/nip46');
}

function persistConnectedSigner(nip46, secretKey, signer) {
    nip46Signer = signer;
    const bunkerUrl = nip46.toBunkerURL(signer.bp);
    saveNip46Stored(bytesToHex(secretKey), bunkerUrl);
}

export function clearNip46Session() {
    abortNostrConnectWait();
    nip46Signer = null;
    localStorage.removeItem(STORAGE_KEY);
}

export function abortNostrConnectWait() {
    if (nostrConnectAbort) {
        nostrConnectAbort.abort();
        nostrConnectAbort = null;
    }
}

export function hasActiveNip46Session() {
    return loadNip46Stored() !== null;
}

export function hasNip07Extension() {
    return hasNip07();
}

export function canSignEvents() {
    return hasNip07() || hasActiveNip46Session();
}

/**
 * @returns {'nip07'|'nip46'|null}
 */
export function activeSignerKind() {
    if (hasActiveNip46Session()) {
        return 'nip46';
    }
    if (hasNip07()) {
        return 'nip07';
    }
    return null;
}

/**
 * Build a nostrconnect:// URI for Amber to scan (NIP-46 client-initiated flow).
 *
 * @param {string[]} relays
 * @param {{ name?: string, url?: string }} meta
 * @returns {Promise<{ uri: string, secretKey: Uint8Array }>}
 */
export async function createNostrConnectPair(relays, meta = {}) {
    const wssRelays = (relays ?? []).filter((r) => typeof r === 'string' && r.startsWith('wss://'));
    if (wssRelays.length === 0) {
        throw new Error('No wss relays configured for remote signer pairing');
    }
    const nip46 = await getNip46Module();
    const secretKey = generateSecretKey();
    const uri = nip46.createNostrConnectURI({
        clientPubkey: getPublicKey(secretKey),
        relays: wssRelays,
        secret: randomConnectSecret(),
        name: meta.name || undefined,
        url: meta.url || undefined,
    });

    return { uri, secretKey };
}

/**
 * Wait for Amber to scan a nostrconnect:// URI and complete the NIP-46 handshake.
 *
 * @param {Uint8Array} secretKey
 * @param {string} uri
 * @param {AbortSignal} [signal]
 * @returns {Promise<string>} remote signer pubkey (hex)
 */
export async function waitForNostrConnect(secretKey, uri, signal) {
    abortNostrConnectWait();
    const nip46 = await getNip46Module();
    const controller = new AbortController();
    nostrConnectAbort = controller;
    if (signal) {
        if (signal.aborted) {
            controller.abort();
        } else {
            signal.addEventListener('abort', () => controller.abort(), { once: true });
        }
    }
    try {
        const signer = await nip46.BunkerSigner.fromURI(secretKey, uri, {
            skipSwitchRelays: true,
        }, controller.signal);
        persistConnectedSigner(nip46, secretKey, signer);

        return signer.getPublicKey();
    } finally {
        if (nostrConnectAbort === controller) {
            nostrConnectAbort = null;
        }
    }
}

async function openNip46Signer(stored) {
    const nip46 = await getNip46Module();
    const secretKey = hexToBytes(stored.secretKey);
    const trimmed = stored.bunkerUrl.trim();
    if (trimmed.startsWith('nostrconnect://')) {
        throw new Error('Remote signer session expired. Scan the QR code or paste a bunker URL again.');
    }
    const bunkerPointer = await nip46.parseBunkerInput(trimmed);
    if (!bunkerPointer || !bunkerPointer.pubkey) {
        throw new Error('Invalid bunker URL');
    }
    if (!Array.isArray(bunkerPointer.relays) || bunkerPointer.relays.length === 0) {
        throw new Error('Bunker URL must include at least one relay');
    }
    const signer = nip46.BunkerSigner.fromBunker(secretKey, bunkerPointer);
    await signer.connect();
    nip46Signer = signer;

    return signer;
}

/**
 * Connect Amber / another NIP-46 bunker using a pasted bunker:// or nostrconnect:// URL.
 *
 * @param {string} bunkerUrl
 */
export async function connectNip46Bunker(bunkerUrl) {
    abortNostrConnectWait();
    const trimmed = (bunkerUrl ?? '').trim();
    if (trimmed === '') {
        throw new Error('Bunker URL is required');
    }
    const secretKey = generateSecretKey();
    const nip46 = await getNip46Module();
    let signer;
    if (trimmed.startsWith('nostrconnect://')) {
        signer = await nip46.BunkerSigner.fromURI(secretKey, trimmed, { skipSwitchRelays: true });
    } else {
        const bunkerPointer = await nip46.parseBunkerInput(trimmed);
        if (!bunkerPointer || !bunkerPointer.pubkey) {
            throw new Error('Could not parse bunker URL');
        }
        signer = nip46.BunkerSigner.fromBunker(secretKey, bunkerPointer);
        await signer.connect();
    }
    persistConnectedSigner(nip46, secretKey, signer);

    return signer.getPublicKey();
}

async function resolveSigner() {
    if (nip46Signer) {
        return nip46Signer;
    }
    const stored = loadNip46Stored();
    if (stored) {
        return openNip46Signer(stored);
    }
    if (hasNip07()) {
        return null;
    }
    throw new Error('No Nostr signer available');
}

/**
 * @param {Record<string, unknown>} unsigned
 * @param {{ prefer?: 'auto' | 'nip07' | 'nip46' }} [options]
 * @returns {Promise<Record<string, unknown>>}
 */
export async function signEvent(unsigned, options = {}) {
    const prefer = options.prefer ?? 'auto';

    if (prefer === 'nip07') {
        if (!hasNip07()) {
            throw new Error('No Nostr extension available');
        }

        return window.nostr.signEvent(unsigned);
    }

    if (prefer === 'nip46') {
        const signer = await resolveSigner();
        if (!signer) {
            throw new Error('No remote Nostr signer available');
        }

        return signer.signEvent(unsigned);
    }

    const stored = loadNip46Stored();
    if (stored) {
        const signer = await resolveSigner();

        return signer.signEvent(unsigned);
    }
    if (hasNip07()) {
        return window.nostr.signEvent(unsigned);
    }
    throw new Error('No Nostr signer available');
}
