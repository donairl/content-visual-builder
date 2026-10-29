'use client';

import { createContext, useContext, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { globalColorId, globalColorRef, type GlobalColor } from '@/core/globals';
import type { Device } from '@/core/types';
import { patterns } from '@/core/schema';
import { UiIcon } from '../UiIcon';

export interface Option {
  value: string;
  label: string;
  icon?: string;
}

const DEVICE_LABEL: Record<Device, string> = { desktop: 'Desktop', tablet: 'Tablet', mobile: 'Mobile' };
const NEXT_DEVICE: Record<Device, Device> = { desktop: 'tablet', tablet: 'mobile', mobile: 'desktop' };

export function Field({
  label,
  children,
  device,
  onDevice,
  onReset,
  hint,
  error,
  htmlFor,
}: {
  label: string;
  children: ReactNode;
  /** Shown for responsive controls; clicking switches the editor to the next device. */
  device?: Device;
  onDevice?: (device: Device) => void;
  onReset?: () => void;
  hint?: ReactNode;
  error?: string | null;
  htmlFor?: string;
}) {
  return (
    <div className="ed-field">
      <div className="ed-field-label">
        <label htmlFor={htmlFor}>{label}</label>
        {device && onDevice ? (
          <button
            type="button"
            className="ed-mini-btn"
            data-active={device !== 'desktop'}
            title={`Editing ${DEVICE_LABEL[device]} value — click to switch device`}
            aria-label={`Responsive: ${DEVICE_LABEL[device]}`}
            onClick={() => onDevice(NEXT_DEVICE[device])}
          >
            <UiIcon name={device} size={13} />
          </button>
        ) : null}
        {onReset ? (
          <button type="button" className="ed-mini-btn" title="Reset to inherited/default" aria-label={`Reset ${label}`} onClick={onReset}>
            <UiIcon name="reset" size={13} />
          </button>
        ) : null}
      </div>
      {children}
      {error ? <div className="ed-field-error" role="alert">{error}</div> : hint ? <div className="ed-field-hint">{hint}</div> : null}
    </div>
  );
}

/**
 * Text input that keeps what the user types locally and only reports valid values. Invalid
 * input stays visible with an error instead of being silently dropped.
 */
export function TextInput({
  id,
  value,
  onChange,
  placeholder,
  validate,
  multiline,
  code,
  maxLength,
  ariaLabel,
}: {
  id?: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  validate?: (value: string) => string | null;
  multiline?: boolean;
  /** Monospace multi-line source editor (custom CSS). */
  code?: boolean;
  maxLength?: number;
  ariaLabel?: string;
}) {
  const [draft, setDraft] = useState(value);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    setDraft(value);
    setError(null);
  }, [value]);

  const handle = (next: string) => {
    setDraft(next);
    const problem = validate?.(next) ?? null;
    setError(problem);
    if (!problem) onChange(next);
  };
  const common = {
    id,
    value: draft,
    placeholder,
    maxLength,
    'aria-label': ariaLabel,
    'aria-invalid': error ? true : undefined,
  };
  return (
    <>
      {multiline || code ? (
        <textarea
          className={code ? 'ed-textarea ed-textarea--code' : 'ed-textarea'}
          spellCheck={code ? false : undefined}
          {...common}
          onChange={(e) => handle(e.target.value)}
        />
      ) : (
        <input className="ed-input" type="text" {...common} onChange={(e) => handle(e.target.value)} />
      )}
      {error ? <div className="ed-field-error">{error}</div> : null}
    </>
  );
}

const round3 = (n: number) => Math.round(n * 1000) / 1000;

export function NumberInput({
  value,
  onChange,
  min,
  max,
  step = 1,
  integer,
  placeholder,
  ariaLabel,
  id,
}: {
  value: number | null | undefined;
  onChange: (value: number | null) => void;
  min?: number;
  max?: number;
  step?: number;
  integer?: boolean;
  placeholder?: string;
  ariaLabel?: string;
  id?: string;
}) {
  const text = (n: number | null | undefined) => (n === null || n === undefined ? '' : String(n));
  const normalize = (n: number) => (integer ? Math.round(n) : round3(n));
  const [draft, setDraft] = useState(text(value));
  const [invalid, setInvalid] = useState(false);
  // Follow outside changes (undo, device switch) but never rewrite what the user is typing:
  // "1." and "1" (or "1.2345" and 1.235) are the same value, and reformatting would eat input.
  useEffect(() => {
    setDraft((current) => (current.trim() !== '' && normalize(Number(current)) === value ? current : text(value)));
    setInvalid(false);
  }, [value, integer]);

  const inRange = (n: number) => (min === undefined || n >= min) && (max === undefined || n <= max);

  const handle = (raw: string) => {
    setDraft(raw);
    if (raw.trim() === '') {
      setInvalid(false);
      onChange(null);
      return;
    }
    const parsed = Number(raw);
    // Out-of-range values are flagged while typing ("1" on the way to "1200") and clamped on blur.
    if (!Number.isFinite(parsed) || !inRange(parsed)) {
      setInvalid(true);
      return;
    }
    setInvalid(false);
    onChange(normalize(parsed));
  };

  const commit = () => {
    const parsed = Number(draft);
    if (draft.trim() !== '' && Number.isFinite(parsed) && !inRange(parsed)) {
      const clamped = normalize(Math.min(max ?? parsed, Math.max(min ?? parsed, parsed)));
      setDraft(String(clamped));
      setInvalid(false);
      onChange(clamped);
      return;
    }
    if (!invalid) setDraft(text(value));
  };
  return (
    <input
      id={id}
      className="ed-input"
      type="number"
      inputMode="decimal"
      value={draft}
      min={min}
      max={max}
      step={step}
      placeholder={placeholder}
      aria-label={ariaLabel}
      aria-invalid={invalid || undefined}
      onChange={(e) => handle(e.target.value)}
      onBlur={commit}
      onKeyDown={(e) => {
        if (e.key === 'Enter') commit();
      }}
    />
  );
}

export function SelectInput({
  id,
  value,
  options,
  onChange,
  placeholder,
  ariaLabel,
}: {
  id?: string;
  value: string | null | undefined;
  options: Option[];
  onChange: (value: string | null) => void;
  /** Label of an empty choice meaning "unset/inherit"; omitted when the value is required. */
  placeholder?: string;
  ariaLabel?: string;
}) {
  return (
    <select
      id={id}
      className="ed-select"
      value={value ?? ''}
      aria-label={ariaLabel}
      onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
    >
      {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

export function Choose({
  value,
  options,
  onChange,
  inherited,
  ariaLabel,
}: {
  value: string | null | undefined;
  options: Option[];
  onChange: (value: string | null) => void;
  /** Value inherited from a larger device, shown as the pressed state when nothing is set. */
  inherited?: string | null;
  ariaLabel: string;
}) {
  const effective = value ?? inherited;
  return (
    <div className="ed-choose" role="group" aria-label={ariaLabel}>
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          title={o.label}
          aria-label={o.label}
          aria-pressed={effective === o.value}
          style={value == null && inherited === o.value ? { opacity: 0.65 } : undefined}
          onClick={() => onChange(value === o.value ? null : o.value)}
        >
          {o.icon ? <UiIcon name={o.icon} size={14} /> : o.label}
        </button>
      ))}
    </div>
  );
}

export function Switch({ checked, onChange, label }: { checked: boolean; onChange: (value: boolean) => void; label: string }) {
  const id = useId();
  return (
    <label className="ed-check" htmlFor={id}>
      <input id={id} type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      {label}
    </label>
  );
}

/** Expands #rgb/#rgba to #rrggbb for the native picker (which has no alpha). */
function toPickerHex(color: string | null | undefined): string {
  if (!color || color === 'transparent') return '#000000';
  const hex = color.slice(1);
  if (hex.length === 3 || hex.length === 4) return `#${[...hex.slice(0, 3)].map((c) => c + c).join('')}`.toLowerCase();
  return `#${hex.slice(0, 6)}`.toLowerCase();
}

function alphaSuffix(color: string | null | undefined): string {
  if (!color?.startsWith('#')) return '';
  const hex = color.slice(1);
  if (hex.length === 8) return hex.slice(6);
  if (hex.length === 4) return hex[3]! + hex[3]!;
  return '';
}

/**
 * Global colors offered by every color field (README §9). Empty outside the editor (tests, the
 * global styles panel itself), which hides the picker.
 */
export const GlobalColorsContext = createContext<readonly GlobalColor[]>([]);

export function ColorInput({
  value,
  onChange,
  placeholder,
  ariaLabel,
  allowGlobals = true,
}: {
  value: string | null | undefined;
  onChange: (value: string | null) => void;
  placeholder?: string | null;
  ariaLabel: string;
  /** Global colors hold literal colors only, so their own inputs turn the picker off. */
  allowGlobals?: boolean;
}) {
  const globals = useContext(GlobalColorsContext);
  const offered = allowGlobals ? globals : [];
  const linkedId = globalColorId(value);
  const linked = linkedId === null ? undefined : globals.find((c) => c.id === linkedId);
  const placeholderId = globalColorId(placeholder);
  const placeholderGlobal = placeholderId === null ? undefined : globals.find((c) => c.id === placeholderId);
  // What the swatch shows: a linked global's color, the value, or the inherited one.
  const literal = (color: string | null | undefined) => {
    const id = globalColorId(color);
    return id === null ? (color ?? null) : (globals.find((c) => c.id === id)?.value ?? null);
  };
  const shown = literal(value) ?? literal(placeholder);

  return (
    <div className="ed-color">
      <span className="ed-swatch" title="Pick a color">
        <span style={{ background: shown ?? 'transparent' }} />
        <input
          type="color"
          aria-label={`${ariaLabel} picker`}
          value={toPickerHex(shown)}
          onChange={(e) => onChange(`${e.target.value}${alphaSuffix(literal(value))}`)}
        />
      </span>
      {linkedId !== null ? (
        <span className="ed-color-global" title={linked ? `Global color: ${linked.name}` : 'This global color no longer exists.'}>
          <UiIcon name="globe" size={13} />
          <span>{linked?.name ?? 'Missing global color'}</span>
          <button
            type="button"
            className="ed-mini-btn"
            title="Unlink: keep the color but stop following the global"
            aria-label={`Unlink ${ariaLabel} from the global color`}
            onClick={() => onChange(linked?.value ?? null)}
          >
            <UiIcon name="x" size={12} />
          </button>
        </span>
      ) : (
        <TextInput
          value={value ?? ''}
          placeholder={placeholderGlobal ? `Inherit (${placeholderGlobal.name})` : (placeholder ?? '#rrggbb or #rrggbbaa')}
          ariaLabel={ariaLabel}
          validate={(v) => (v === '' || patterns.color.test(v) ? null : 'Use a hex color like #1d4ed8 or #1d4ed880.')}
          onChange={(v) => onChange(v === '' ? null : v)}
        />
      )}
      {offered.length ? <GlobalColorPicker colors={offered} current={linkedId} label={ariaLabel} onPick={(id) => onChange(globalColorRef(id))} /> : null}
    </div>
  );
}

function GlobalColorPicker({
  colors,
  current,
  label,
  onPick,
}: {
  colors: readonly GlobalColor[];
  current: string | null;
  label: string;
  onPick: (id: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const close = (e: PointerEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    window.addEventListener('pointerdown', close, true);
    return () => window.removeEventListener('pointerdown', close, true);
  }, [open]);
  return (
    <div className="ed-globals-pick" ref={ref}>
      <button
        type="button"
        className="ed-icon-btn"
        title="Use a global color"
        aria-label={`${label}: global colors`}
        aria-expanded={open}
        onClick={() => setOpen(!open)}
      >
        <UiIcon name="globe" size={14} />
      </button>
      {open ? (
        <ul role="listbox" aria-label="Global colors" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
          {colors.map((color) => (
            <li key={color.id}>
              <button
                type="button"
                role="option"
                aria-selected={current === color.id}
                onClick={() => {
                  onPick(color.id);
                  setOpen(false);
                }}
              >
                <i style={{ background: color.value }} />
                <span>{color.name}</span>
                <code>{color.value}</code>
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
