#!/usr/bin/env node
/**
 * Renders Asciidoc from stdin to HTML on stdout. Used by PHP ArticleBodyAsciidocRenderer.
 */
import asciidoctorFactory from '@asciidoctor/core';

const input = await new Promise((resolve, reject) => {
    const chunks = [];
    process.stdin.on('data', (c) => chunks.push(c));
    process.stdin.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    process.stdin.on('error', reject);
});

const asciidoctor = asciidoctorFactory();
const html = asciidoctor.convert(input, {
    safe: 'secure',
    attributes: {
        showtitle: true,
        icons: 'font',
        sectanchors: true,
        sectlinks: true,
    },
});

process.stdout.write(typeof html === 'string' ? html : String(html));
