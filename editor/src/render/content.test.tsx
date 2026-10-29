import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { sanitizeHtml } from '@/core/sanitize';
import type { PageDocument } from '@/core/types';
import { embedUrl, parseVideoUrl } from '@/core/video';
import { canonicalHtml } from '../../tests/dom';
import { PublishedTree } from './content';

const fixtures = join(import.meta.dirname, '../../../schema/fixtures');
const read = (path: string) => readFileSync(join(fixtures, path), 'utf8');

describe('publish rendering parity', () => {
  it.each(['landing', 'styles', 'edge'])('%s matches the expected DOM', (name) => {
    const doc = JSON.parse(read(`documents/${name}.json`)) as PageDocument;
    const html = renderToStaticMarkup(<PublishedTree doc={doc} />);
    expect(canonicalHtml(html)).toBe(canonicalHtml(read(`documents/${name}.expected.html`)));
  });
});

describe('sanitizeHtml — shared cases', () => {
  const cases = JSON.parse(read('sanitize.json')) as { name: string; input: string; expected: string }[];
  it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
    expect(canonicalHtml(sanitizeHtml(c.input))).toBe(canonicalHtml(c.expected));
  });
});

describe('video urls', () => {
  it.each([
    ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube'],
    ['https://youtu.be/dQw4w9WgXcQ?t=3', 'youtube'],
    ['https://m.youtube.com/shorts/dQw4w9WgXcQ', 'youtube'],
    ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ', 'youtube'],
    ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube'],
    ['https://vimeo.com/76979871', 'vimeo'],
    ['https://player.vimeo.com/video/76979871?h=abc', 'vimeo'],
    ['https://cdn.example.com/a.webm', 'file'],
  ])('%s → %s', (url, kind) => {
    expect(parseVideoUrl(url)?.kind).toBe(kind);
  });

  it.each(['https://example.com/watch', 'https://www.youtube.com/watch?v=short', 'https://evil.example/youtube.com/watch?v=dQw4w9WgXcQ'])(
    'rejects %s',
    (url) => {
      expect(parseVideoUrl(url)).toBeNull();
    },
  );

  it('builds embed parameters in contract order', () => {
    const yt = { kind: 'youtube' as const, id: 'dQw4w9WgXcQ' };
    expect(embedUrl(yt, { autoplay: true, muted: false, loop: true, controls: false })).toBe(
      'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&mute=1&loop=1&playlist=dQw4w9WgXcQ&controls=0',
    );
    expect(embedUrl(yt, { autoplay: false, muted: false, loop: false, controls: true })).toBe(
      'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    );
  });
});
