'use client';

import { useEffect, useRef, useState } from 'react';
import { sanitizeHtml } from '@/core/sanitize';
import { icons } from '@/core/schema';
import type { ImageValue, LinkValue } from '@/core/types';
import { isValidLinkUrl, isValidMediaUrl, isValidVideoUrl } from '@/core/validate';
import { parseVideoUrl } from '@/core/video';
import { IconSvg } from '@/render/content';
import { ApiError, api } from '../../api';
import { handlePaste, preferParagraphs, runCommand, type RichCommand } from '../richtext';
import { UiIcon } from '../UiIcon';
import { Switch, TextInput } from './fields';

const LINK_ERROR = 'Use https://…, a /relative path, #anchor, mailto: or tel:.';

export function LinkInput({ value, onChange, label }: { value: Partial<LinkValue> | undefined; onChange: (v: LinkValue) => void; label: string }) {
  const link: LinkValue = { url: value?.url ?? '', newTab: value?.newTab ?? false, nofollow: value?.nofollow ?? false };
  return (
    <div className="ed-group">
      <TextInput
        ariaLabel={`${label} URL`}
        value={link.url}
        placeholder="https://example.com"
        validate={(v) => (isValidLinkUrl(v.trim()) ? null : LINK_ERROR)}
        onChange={(url) => onChange({ ...link, url: url.trim() })}
      />
      <div className="ed-field-row">
        <Switch label="Open in new tab" checked={link.newTab} onChange={(newTab) => onChange({ ...link, newTab })} />
      </div>
      <Switch label="Add nofollow" checked={link.nofollow} onChange={(nofollow) => onChange({ ...link, nofollow })} />
    </div>
  );
}

export function ImageInput({ value, onChange, label }: { value: Partial<ImageValue> | undefined; onChange: (v: ImageValue) => void; label: string }) {
  const image: ImageValue = { url: value?.url ?? '', width: value?.width ?? null, height: value?.height ?? null, id: value?.id ?? null };
  const fileRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const upload = async (file: File) => {
    setBusy(true);
    setError(null);
    try {
      const media = await api.uploadMedia(file);
      onChange({ url: media.url, width: media.width, height: media.height, id: media.id });
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Upload failed.');
    } finally {
      setBusy(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  return (
    <div className="ed-group">
      <div className="ed-thumb" style={image.url ? { backgroundImage: `url("${image.url.replace(/"/g, '')}")` } : undefined}>
        {image.url ? null : <UiIcon name="image" size={28} />}
      </div>
      <div className="ed-field-row">
        <button type="button" className="ed-btn ed-btn--sm" disabled={busy} onClick={() => fileRef.current?.click()}>
          <UiIcon name="upload" size={14} /> {busy ? 'Uploading…' : 'Upload'}
        </button>
        {image.url ? (
          <button type="button" className="ed-btn ed-btn--sm ed-btn--ghost ed-btn--danger" onClick={() => onChange({ url: '', width: null, height: null, id: null })}>
            Remove
          </button>
        ) : null}
        <input
          ref={fileRef}
          type="file"
          hidden
          accept="image/jpeg,image/png,image/webp,image/gif"
          aria-label={`${label} file`}
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) void upload(file);
          }}
        />
      </div>
      <TextInput
        ariaLabel={`${label} URL`}
        value={image.url}
        placeholder="…or paste an image URL"
        validate={(v) => (isValidMediaUrl(v.trim()) ? null : 'Use an http(s) or site-relative URL without quotes or parentheses.')}
        onChange={(url) => onChange({ url: url.trim(), width: null, height: null, id: null })}
      />
      {error ? <div className="ed-field-error" role="alert">{error}</div> : null}
    </div>
  );
}

export function VideoUrlInput({ value, onChange, label }: { value: string; onChange: (v: string) => void; label: string }) {
  const source = value ? parseVideoUrl(value) : null;
  return (
    <>
      <TextInput
        ariaLabel={label}
        value={value}
        placeholder="https://www.youtube.com/watch?v=…"
        validate={(v) => (isValidVideoUrl(v.trim()) ? null : 'Use an http(s) URL.')}
        onChange={(v) => onChange(v.trim())}
      />
      <div className="ed-field-hint">
        {value === '' ? 'YouTube, Vimeo, or a direct .mp4/.webm file.' : source ? `Recognized: ${source.kind === 'file' ? 'video file' : source.kind}` : 'Not a recognized video link — nothing will be shown.'}
      </div>
    </>
  );
}

export function IconPicker({ value, onChange, label }: { value: string; onChange: (v: string) => void; label: string }) {
  return (
    <div className="ed-icon-grid" role="group" aria-label={label}>
      {Object.entries(icons).map(([name, icon]) => (
        <button key={name} type="button" title={icon.label} aria-label={icon.label} aria-pressed={value === name} onClick={() => onChange(name)}>
          <IconSvg name={name} />
        </button>
      ))}
    </div>
  );
}

const TOOLS: { command: RichCommand; icon: string; label: string }[] = [
  { command: 'bold', icon: 'bold', label: 'Bold' },
  { command: 'italic', icon: 'italic', label: 'Italic' },
  { command: 'underline', icon: 'underline', label: 'Underline' },
  { command: 'insertUnorderedList', icon: 'list', label: 'Bulleted list' },
  { command: 'insertOrderedList', icon: 'list-ordered', label: 'Numbered list' },
  { command: 'link', icon: 'link', label: 'Link' },
];

export function RichTextTools({ onAfter }: { onAfter?: () => void }) {
  return (
    <>
      {TOOLS.map((tool) => (
        <button
          key={tool.command}
          type="button"
          className="ed-icon-btn"
          title={tool.label}
          aria-label={tool.label}
          // Keep the text selection: act on mousedown and never take focus.
          onMouseDown={(e) => {
            e.preventDefault();
            runCommand(tool.command);
            onAfter?.();
          }}
        >
          <UiIcon name={tool.icon} size={14} />
        </button>
      ))}
    </>
  );
}

/** Panel rich-text editor; mirrors the inline canvas editor for the same prop. */
export function RichTextInput({ value, onChange, label }: { value: string; onChange: (html: string) => void; label: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const last = useRef<string | null>(null);

  useEffect(() => {
    const el = ref.current;
    if (!el || last.current === value) return;
    last.current = value;
    el.innerHTML = sanitizeHtml(value);
  }, [value]);

  const emit = () => {
    const el = ref.current;
    if (!el) return;
    last.current = el.innerHTML;
    onChange(el.innerHTML);
  };

  return (
    <div className="ed-rte">
      <div className="ed-rte-tools">
        <RichTextTools onAfter={emit} />
      </div>
      <div
        ref={ref}
        className="ed-rte-area"
        contentEditable
        suppressContentEditableWarning
        role="textbox"
        aria-multiline="true"
        aria-label={label}
        onFocus={preferParagraphs}
        onInput={emit}
        onPaste={(e) => {
          handlePaste(e, 'html');
          emit();
        }}
      />
    </div>
  );
}
