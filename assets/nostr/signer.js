/**
 * Unified Nostr signing: NIP-07 browser extension or NIP-46 remote signer (e.g. Amber).
 */

import { generateSecretKey } from 'nostr-tools';
import { bytesToHex, hexToBytes } from '@noble/hashes/utils';

const STORAGE_KEY = 'unfold.nostr.nip46';

/** @type {import('nostr-tools/nip46').BunkerSigner | null} */
let nip46Signer = null;

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

export function clearNip46Session() {
    nip46Signer = null;
    localStorage.removeItem(STORAGE_KEY);
}

export function hasActiveNip46Session() {
    return loadNip46Stored() !== null;
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

async function getNip46Module() {
    return import('nostr-tools/nip46');
}

async function openNip46Signer(stored) {
    const nip46 = await getNip46Module();
    const secretKey = hexToBytes(stored.secretKey);
    const bunkerPointer = await nip46.parseBunkerInput(stored.bunkerUrl.trim());
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
    nip46Signer = signer;
    saveNip46Stored(bytesToHex(secretKey), trimmed);
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
 * @returns {Promise<Record<string, unknown>>}
 */
export async function signEvent(unsigned) {
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
