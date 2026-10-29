'use client';

import { useState } from 'react';
import { fonts, type StyleProperty } from '@/core/schema';
import type { BackgroundValue, BorderValue, BoxValue, ShadowValue, SizeValue } from '@/core/types';
import { isValidMediaUrl } from '@/core/validate';
import { UiIcon } from '../UiIcon';
import { Choose, ColorInput, NumberInput, SelectInput, Switch, TextInput, type Option } from './fields';
import { optionsFor } from './labels';

export interface InputProps<T> {
  prop: StyleProperty;
  styleKey: string;
  value: T | undefined;
  /** The inherited (larger-device) value, shown as placeholder. */
  inherited: T | undefined;
  onChange: (value: T | null) => void;
  label: string;
  control?: string;
}

const unitOptions = (prop: StyleProperty): Option[] => (prop.units ?? []).map((u) => ({ value: u, label: u }));

export function SizeInput({ prop, value, inherited, onChange, label }: InputProps<SizeValue>) {
  const [unit, setUnit] = useState<string>(value?.unit ?? inherited?.unit ?? prop.units?.[0] ?? 'px');
  const current = value?.unit ?? unit;
  return (
    <div className="ed-unit">
      <NumberInput
        ariaLabel={label}
        value={value?.value}
        placeholder={inherited ? String(inherited.value) : ''}
        min={prop.min}
        max={prop.max}
        step={current === 'px' ? 1 : 0.1}
        onChange={(n) => onChange(n === null ? null : { value: n, unit: current })}
      />
      <SelectInput
        ariaLabel={`${label} unit`}
        value={current}
        options={unitOptions(prop)}
        onChange={(u) => {
          if (!u) return;
          setUnit(u);
          if (value) onChange({ value: value.value, unit: u });
        }}
      />
    </div>
  );
}

const SIDES = ['top', 'right', 'bottom', 'left'] as const;
const CORNER_LABELS = { top: 'TL', right: 'TR', bottom: 'BR', left: 'BL' } as const;

export function BoxInput({ prop, value, inherited, onChange, label }: InputProps<BoxValue>) {
  const [linked, setLinked] = useState(false);
  const [unit, setUnit] = useState<string>(value?.unit ?? inherited?.unit ?? prop.units?.[0] ?? 'px');
  const current = value?.unit ?? unit;

  const update = (side: (typeof SIDES)[number], n: number | null) => {
    const base: BoxValue = { ...(value ?? { unit: current }), unit: current };
    const next: BoxValue = linked ? { ...base, top: n, right: n, bottom: n, left: n } : { ...base, [side]: n };
    const empty = SIDES.every((s) => next[s] === null || next[s] === undefined);
    onChange(empty ? null : next);
  };

  return (
    <div className="ed-box">
      {SIDES.map((side) => (
        <label key={side}>
          <NumberInput
            ariaLabel={`${label} ${side}`}
            value={value?.[side] ?? null}
            placeholder={inherited?.[side] !== undefined && inherited?.[side] !== null ? String(inherited[side]) : ''}
            min={prop.min}
            max={prop.max}
            onChange={(n) => update(side, n)}
          />
          {prop.corners ? CORNER_LABELS[side] : side}
        </label>
      ))}
      <button
        type="button"
        className="ed-icon-btn"
        aria-pressed={linked}
        title={linked ? 'Unlink values' : 'Link values'}
        aria-label={`Link ${label} values`}
        onClick={() => setLinked(!linked)}
      >
        <UiIcon name="link" size={14} />
      </button>
      <div style={{ gridColumn: '1 / -1' }}>
        <SelectInput
          ariaLabel={`${label} unit`}
          value={current}
          options={unitOptions(prop)}
          onChange={(u) => {
            if (!u) return;
            setUnit(u);
            if (value) onChange({ ...value, unit: u });
          }}
        />
      </div>
    </div>
  );
}

const BORDER_STYLES: Option[] = ['none', 'solid', 'dashed', 'dotted', 'double', 'groove'].map((s) => ({ value: s, label: s[0]!.toUpperCase() + s.slice(1) }));

export function BorderInput({ value, inherited, onChange, label }: InputProps<BorderValue>) {
  const set = (patch: Partial<BorderValue>) => {
    const next = { ...(value ?? {}), ...patch };
    const empty = !next.style && (next.width === null || next.width === undefined) && !next.color;
    onChange(empty ? null : next);
  };
  return (
    <div className="ed-group">
      <div className="ed-grid2">
        <label>
          Style
          <SelectInput ariaLabel={`${label} style`} value={value?.style} placeholder={inherited?.style ?? 'Default'} options={BORDER_STYLES} onChange={(s) => set({ style: s })} />
        </label>
        <label>
          Width (px)
          <NumberInput ariaLabel={`${label} width`} value={value?.width} placeholder={inherited?.width != null ? String(inherited.width) : ''} min={0} max={100} onChange={(n) => set({ width: n })} />
        </label>
      </div>
      <ColorInput ariaLabel={`${label} color`} value={value?.color} placeholder={inherited?.color} onChange={(c) => set({ color: c })} />
    </div>
  );
}

const DEFAULT_SHADOW: ShadowValue = { x: 0, y: 4, blur: 12, spread: 0, color: '#00000033', inset: false };

export function ShadowInput({ value, inherited, onChange, label }: InputProps<ShadowValue>) {
  if (!value) {
    return (
      <button type="button" className="ed-btn ed-btn--sm" onClick={() => onChange({ ...(inherited ?? DEFAULT_SHADOW) })}>
        <UiIcon name="plus" size={14} /> Add shadow
      </button>
    );
  }
  const set = (patch: Partial<ShadowValue>) => onChange({ ...value, ...patch });
  const num = (key: 'x' | 'y' | 'blur' | 'spread', min: number, max: number) => (
    <label key={key}>
      {key}
      <NumberInput ariaLabel={`${label} ${key}`} value={value[key]} min={min} max={max} onChange={(n) => set({ [key]: n ?? 0 })} />
    </label>
  );
  return (
    <div className="ed-group">
      <div className="ed-grid4">
        {num('x', -200, 200)}
        {num('y', -200, 200)}
        {num('blur', 0, 300)}
        {num('spread', -200, 200)}
      </div>
      <ColorInput ariaLabel={`${label} color`} value={value.color} onChange={(c) => set({ color: c ?? DEFAULT_SHADOW.color })} />
      <div className="ed-field-row">
        <Switch label="Inset" checked={value.inset} onChange={(inset) => set({ inset })} />
        <span style={{ flex: 1 }} />
        <button type="button" className="ed-btn ed-btn--sm ed-btn--ghost ed-btn--danger" onClick={() => onChange(null)}>
          Remove
        </button>
      </div>
    </div>
  );
}

const BACKGROUND_TYPES: Option[] = [
  { value: 'none', label: 'None' },
  { value: 'color', label: 'Color' },
  { value: 'gradient', label: 'Gradient' },
  { value: 'image', label: 'Image' },
];
const SIZES: Option[] = ['cover', 'contain', 'auto'].map((v) => ({ value: v, label: v[0]!.toUpperCase() + v.slice(1) }));
const POSITIONS: Option[] = [
  'center center', 'top center', 'bottom center', 'center left', 'center right', 'top left', 'top right', 'bottom left', 'bottom right',
].map((v) => ({ value: v, label: v }));
const REPEATS: Option[] = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'].map((v) => ({ value: v, label: v }));

export function BackgroundInput({ value, inherited, onChange, label }: InputProps<BackgroundValue>) {
  const set = (patch: Partial<BackgroundValue>) => onChange({ type: value?.type ?? 'color', ...(value ?? {}), ...patch });
  return (
    <div className="ed-group">
      <Choose
        ariaLabel={`${label} type`}
        value={value?.type}
        inherited={inherited?.type}
        options={BACKGROUND_TYPES}
        onChange={(type) => (type ? set({ type: type as BackgroundValue['type'] }) : onChange(null))}
      />
      {value?.type === 'color' || value?.type === 'image' ? (
        <ColorInput ariaLabel={`${label} color`} value={value.color} onChange={(c) => set({ color: c })} />
      ) : null}
      {value?.type === 'gradient' ? (
        <>
          <ColorInput ariaLabel={`${label} gradient start`} value={value.gradientFrom} placeholder="Start color" onChange={(c) => set({ gradientFrom: c })} />
          <ColorInput ariaLabel={`${label} gradient end`} value={value.gradientTo} placeholder="End color" onChange={(c) => set({ gradientTo: c })} />
          <label className="ed-field-hint">
            Angle (deg)
            <NumberInput ariaLabel={`${label} gradient angle`} value={value.gradientAngle} placeholder="180" min={0} max={360} onChange={(n) => set({ gradientAngle: n })} />
          </label>
        </>
      ) : null}
      {value?.type === 'image' ? (
        <>
          <TextInput
            ariaLabel={`${label} image URL`}
            value={value.imageUrl ?? ''}
            placeholder="https://… or /uploads/…"
            validate={(v) => (isValidMediaUrl(v) ? null : 'Use an http(s) or site-relative URL without quotes or parentheses.')}
            onChange={(v) => set({ imageUrl: v })}
          />
          <div className="ed-grid2">
            <label>
              Size
              <SelectInput ariaLabel={`${label} size`} value={value.imageSize ?? 'cover'} options={SIZES} onChange={(v) => set({ imageSize: v })} />
            </label>
            <label>
              Repeat
              <SelectInput ariaLabel={`${label} repeat`} value={value.imageRepeat ?? 'no-repeat'} options={REPEATS} onChange={(v) => set({ imageRepeat: v })} />
            </label>
          </div>
          <SelectInput ariaLabel={`${label} position`} value={value.imagePosition ?? 'center center'} options={POSITIONS} onChange={(v) => set({ imagePosition: v })} />
        </>
      ) : null}
    </div>
  );
}

export function FontInput({ value, inherited, onChange, label }: InputProps<string>) {
  const options = Object.entries(fonts).map(([key, font]) => ({ value: key, label: font.label }));
  const inheritedLabel = inherited ? fonts[inherited]?.label : undefined;
  return <SelectInput ariaLabel={label} value={value} options={options} placeholder={inheritedLabel ? `Inherit (${inheritedLabel})` : 'Default'} onChange={onChange} />;
}

export function EnumInput({ prop, styleKey, value, inherited, onChange, label, control }: InputProps<string>) {
  const options = optionsFor(styleKey, prop.options ?? []);
  if (control === 'choose') return <Choose ariaLabel={label} value={value} inherited={inherited} options={options} onChange={onChange} />;
  const inheritedLabel = inherited ? options.find((o) => o.value === inherited)?.label : undefined;
  return <SelectInput ariaLabel={label} value={value} options={options} placeholder={inheritedLabel ? `Inherit (${inheritedLabel})` : 'Default'} onChange={onChange} />;
}

export function NumberStyleInput({ prop, value, inherited, onChange, label }: InputProps<number>) {
  const fractional = !prop.integer && (prop.max ?? 100) <= 10;
  return (
    <NumberInput
      ariaLabel={label}
      value={value}
      placeholder={inherited !== undefined ? String(inherited) : ''}
      min={prop.min}
      max={prop.max}
      integer={prop.integer}
      step={fractional ? 0.05 : 1}
      onChange={onChange}
    />
  );
}
