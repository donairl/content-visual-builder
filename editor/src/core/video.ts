/**
 * Video URL recognition and embed URLs (schema/README.md §7). Regexes are copied verbatim from
 * the contract so the backend's VideoEmbed recognizes exactly the same URLs.
 */

const YOUTUBE =
  /^https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:[^#]*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/|youtube-nocookie\.com\/embed\/)([A-Za-z0-9_-]{11})(?:[?&#/].*)?$/i;
const VIMEO = /^https?:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?([0-9]{1,12})(?:[?#/].*)?$/i;
const FILE = /^https?:\/\/[^?#]+\.(?:mp4|webm|ogv|ogg)(?:[?#].*)?$/i;

export type VideoSource =
  | { kind: 'youtube'; id: string }
  | { kind: 'vimeo'; id: string }
  | { kind: 'file'; url: string };

export interface PlayerOptions {
  autoplay: boolean;
  muted: boolean;
  loop: boolean;
  controls: boolean;
}

export function parseVideoUrl(url: string): VideoSource | null {
  let match = YOUTUBE.exec(url);
  if (match) return { kind: 'youtube', id: match[1]! };
  match = VIMEO.exec(url);
  if (match) return { kind: 'vimeo', id: match[1]! };
  if (FILE.test(url)) return { kind: 'file', url };
  return null;
}

export function embedUrl(source: { kind: 'youtube' | 'vimeo'; id: string }, options: PlayerOptions): string {
  const params: string[] = [];
  const muted = options.muted || options.autoplay;
  if (source.kind === 'youtube') {
    if (options.autoplay) params.push('autoplay=1');
    if (muted) params.push('mute=1');
    if (options.loop) params.push(`loop=1&playlist=${source.id}`);
    if (!options.controls) params.push('controls=0');
    return `https://www.youtube-nocookie.com/embed/${source.id}${params.length ? `?${params.join('&')}` : ''}`;
  }
  if (options.autoplay) params.push('autoplay=1');
  if (muted) params.push('muted=1');
  if (options.loop) params.push('loop=1');
  if (!options.controls) params.push('controls=0');
  return `https://player.vimeo.com/video/${source.id}${params.length ? `?${params.join('&')}` : ''}`;
}
