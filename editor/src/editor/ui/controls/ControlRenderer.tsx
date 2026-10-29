'use client';

import { useEffect, useState } from 'react';
import { api } from '../../api';
import { useServices } from '../../services';
import { propOf } from '@/core/document';
import { FONT_KEYS } from '@/core/globals';
import { inheritedValue, ownValue, resolveBox, resolveValue } from '@/core/responsive';
import { controlsConfig, isResponsiveControl, styleProperties, widgets, type ControlSpec } from '@/core/schema';
import type {
  Advanced,
  BackgroundValue,
  BorderValue,
  BoxValue,
  Device,
  ElementNode,
  ImageValue,
  LinkValue,
  Responsive,
  ShadowValue,
  SizeValue,
} from '@/core/types';
import { customCssError } from '@/core/customCss';
import { codePointLength, isValidAdvanced } from '@/core/validate';
import { config, patterns } from '@/core/schema';
import { DEVICES } from '@/core/types';
import { useDispatch, useEditor, useSite } from '../../context';
import { UiIcon } from '../UiIcon';
import { Choose, ColorInput, Field, NumberInput, SelectInput, Switch, TextInput } from './fields';
import { optionsFor } from './labels';
import { IconPicker, ImageInput, LinkInput, RichTextInput, VideoUrlInput } from './propControls';
import {
  BackgroundInput,
  BorderInput,
  BoxInput,
  EnumInput,
  FontInput,
  NumberStyleInput,
  ShadowInput,
  SizeInput,
  type InputProps,
} from './styleControls';

const PREVIOUS: Record<Device, Device | null> = { desktop: null, tablet: 'desktop', mobile: 'tablet' };

const GROUP_LABELS: Record<string, string> = {
  globalFont: 'Global font',
  fontFamily: 'Family',
  fontSize: 'Size',
  fontWeight: 'Weight',
  lineHeight: 'Line height',
  letterSpacing: 'Letter spacing',
  textTransform: 'Transform',
  fontStyle: 'Style',
  textDecoration: 'Decoration',
};

export function Control({ node, spec, device }: { node: ElementNode; spec: ControlSpec; device: Device }) {
  if (spec.showIf && propOf(node, spec.showIf.prop) !== spec.showIf.equals) return null;
  if (spec.group) return <GroupControl node={node} spec={spec} device={device} />;
  if (spec.prop) return <PropControl node={node} propKey={spec.prop} label={spec.label} control={spec.control} />;
  if (spec.style) {
    return (
      <StyleControl
        node={node}
        styleKey={spec.style}
        label={spec.label}
        control={spec.control}
        responsive={isResponsiveControl(spec)}
        device={device}
      />
    );
  }
  if (spec.advanced) return <AdvancedControl node={node} field={spec.advanced} label={spec.label} />;
  return null;
}

function GroupControl({ node, spec, device }: { node: ElementNode; spec: ControlSpec; device: Device }) {
  const [open, setOpen] = useState(false);
  const keys = controlsConfig.groups[spec.group!] ?? [];
  const setCount = keys.filter((k) => node.style[k] !== undefined).length;
  return (
    <div className="ed-field">
      <button type="button" className="ed-btn ed-btn--sm" aria-expanded={open} onClick={() => setOpen(!open)} style={{ justifyContent: 'space-between' }}>
        <span>
          {spec.label}
          {setCount ? ` (${setCount})` : ''}
        </span>
        <UiIcon name={open ? 'chevron-down' : 'chevron-right'} size={14} />
      </button>
      {open ? (
        <div className="ed-group">
          {keys.map((key) => (
            <StyleControl
              key={key}
              node={node}
              styleKey={key}
              label={GROUP_LABELS[key] ?? key}
              responsive={isResponsiveControl({ style: key, label: '' })}
              device={device}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}

export function StyleControl({
  node,
  styleKey,
  label,
  control,
  responsive,
  device,
}: {
  node: ElementNode;
  styleKey: string;
  label: string;
  control?: string;
  responsive: boolean;
  device: Device;
}) {
  const dispatch = useDispatch();
  const site = useSite();
  const prop = styleProperties[styleKey];
  if (!prop) return null;
  const editDevice: Device = responsive ? device : 'desktop';
  const responsiveValue = node.style[styleKey] as Responsive<unknown> | undefined;
  const own = ownValue(responsiveValue, editDevice);
  const previous = PREVIOUS[editDevice];
  // Typography from the node's global font preset shows as the inherited value (README §9).
  const presetId = (node.style.globalFont as Responsive<string> | undefined)?.desktop;
  const preset = FONT_KEYS.includes(styleKey) && presetId ? site.fonts.get(presetId)?.[styleKey] : undefined;
  const inherited = preset
    ? presetFallback(responsiveValue, preset, editDevice)
    : previous
      ? prop.type === 'box'
        ? resolveBox(responsiveValue as Responsive<BoxValue> | undefined, previous)
        : inheritedValue(responsiveValue, editDevice)
      : undefined;

  const onChange = (value: unknown) =>
    dispatch({ type: 'setStyle', id: node.id, key: styleKey, device: editDevice, value, mergeKey: `style:${node.id}:${styleKey}:${editDevice}` });

  // The stored value was validated against this property's type, so each input gets its own shape.
  const common = <T,>() => ({ prop, styleKey, value: own, inherited, onChange, label, control }) as unknown as InputProps<T>;
  let input;
  switch (prop.type) {
    case 'size':
      input = <SizeInput {...common<SizeValue>()} />;
      break;
    case 'box':
      input = <BoxInput {...common<BoxValue>()} />;
      break;
    case 'border':
      input = <BorderInput {...common<BorderValue>()} />;
      break;
    case 'shadow':
      input = <ShadowInput {...common<ShadowValue>()} />;
      break;
    case 'background':
      input = <BackgroundInput {...common<BackgroundValue>()} />;
      break;
    case 'fontFamily':
      input = <FontInput {...common<string>()} />;
      break;
    case 'enum':
      input = <EnumInput {...common<string>()} />;
      break;
    case 'number':
      input = <NumberStyleInput {...common<number>()} />;
      break;
    case 'color': {
      input = <ColorField value={own} inherited={inherited} onChange={onChange} label={label} />;
      break;
    }
    case 'globalFont':
      input = <GlobalFontInput value={typeof own === 'string' ? own : undefined} onChange={onChange} label={label} />;
      break;
  }

  return (
    <Field
      label={label}
      device={responsive ? editDevice : undefined}
      onDevice={responsive ? (d) => dispatch({ type: 'setDevice', device: d }) : undefined}
      onReset={own !== undefined ? () => onChange(null) : undefined}
    >
      {input}
    </Field>
  );
}

/** The value `device` shows without its own: larger devices, then the preset, per device. */
function presetFallback(own: Responsive<unknown> | undefined, preset: Responsive<unknown>, device: Device): unknown {
  const merged: Responsive<unknown> = {};
  for (const d of DEVICES) merged[d] = d === device ? preset[d] : (own?.[d] ?? preset[d]);
  return resolveValue(merged, device);
}

function GlobalFontInput({ value, onChange, label }: { value: string | undefined; onChange: (v: unknown) => void; label: string }) {
  const fonts = useEditor((s) => s.globals.value.fonts);
  const options = fonts.map((font) => ({ value: font.id, label: font.name }));
  if (value && !fonts.some((f) => f.id === value)) options.push({ value, label: `Missing (${value})` });
  return <SelectInput ariaLabel={label} value={value} options={options} placeholder="None" onChange={(v) => onChange(v ?? null)} />;
}

function ColorField({ value, inherited, onChange, label }: { value: unknown; inherited: unknown; onChange: (v: unknown) => void; label: string }) {
  return (
    <ColorInput
      ariaLabel={label}
      value={typeof value === 'string' ? value : null}
      placeholder={typeof inherited === 'string' ? inherited : null}
      onChange={onChange}
    />
  );
}

/** A component instance: which component it shows, and the ways out (edit it, detach). */
function ComponentControl({ node, label }: { node: ElementNode; label: string }) {
  const dispatch = useDispatch();
  const services = useServices();
  const componentId = typeof node.props.componentId === 'number' ? node.props.componentId : 0;
  const entry = useEditor((s) => s.components[componentId]);
  const [options, setOptions] = useState<{ value: string; label: string }[]>([]);
  useEffect(() => {
    let cancelled = false;
    api
      .listComponents()
      .then((r) => !cancelled && setOptions(r.items.map((c) => ({ value: String(c.id), label: c.name }))))
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, []);
  const name = typeof entry === 'object' ? entry.name : entry === 'loading' ? 'Loading…' : 'Missing component';
  return (
    <Field label={label} hint="Changes made to the component show everywhere it is used. Detach to edit this copy on its own.">
      <SelectInput
        ariaLabel={label}
        value={String(componentId)}
        options={options.some((o) => o.value === String(componentId)) ? options : [{ value: String(componentId), label: name }, ...options]}
        onChange={(v) => v && dispatch({ type: 'setProp', id: node.id, key: 'componentId', value: Number(v) })}
      />
      <div className="ed-field-row">
        <button type="button" className="ed-btn ed-btn--sm" disabled={!componentId} onClick={() => services.editComponent(componentId)}>
          <UiIcon name="external" size={12} /> Edit component
        </button>
        <button type="button" className="ed-btn ed-btn--sm" disabled={typeof entry !== 'object'} onClick={() => services.detachComponent(node.id)}>
          Detach
        </button>
      </div>
    </Field>
  );
}

function PropControl({ node, propKey, label, control }: { node: ElementNode; propKey: string; label: string; control?: string }) {
  const dispatch = useDispatch();
  if (control === 'component') return <ComponentControl node={node} label={label} />;
  const spec = widgets[node.type]?.props[propKey];
  if (!spec) return null;
  const value = propOf<unknown>(node, propKey);
  const onChange = (next: unknown) => dispatch({ type: 'setProp', id: node.id, key: propKey, value: next, mergeKey: `prop:${node.id}:${propKey}` });

  let input;
  switch (spec.type) {
    case 'text':
      input = (
        <TextInput
          ariaLabel={label}
          value={typeof value === 'string' ? value : ''}
          multiline={control === 'textarea'}
          validate={(v) => (spec.maxLength !== undefined && codePointLength(v) > spec.maxLength ? `Keep it under ${spec.maxLength} characters.` : null)}
          onChange={onChange}
        />
      );
      break;
    case 'richtext':
      input = <RichTextInput label={label} value={typeof value === 'string' ? value : ''} onChange={onChange} />;
      break;
    case 'enum': {
      const options = optionsFor(propKey, spec.options ?? []);
      input =
        control === 'choose' ? (
          <Choose ariaLabel={label} value={value as string} options={options} onChange={(v) => v && onChange(v)} />
        ) : (
          <SelectInput ariaLabel={label} value={value as string} options={options} onChange={(v) => v && onChange(v)} />
        );
      break;
    }
    case 'bool':
      return <Switch label={label} checked={value === true} onChange={onChange} />;
    case 'number':
      input = <NumberInput ariaLabel={label} value={value as number} min={spec.min} max={spec.max} integer={spec.integer} onChange={(n) => onChange(n ?? spec.default)} />;
      break;
    case 'link':
      input = <LinkInput label={label} value={value as Partial<LinkValue>} onChange={onChange} />;
      break;
    case 'image':
      input = <ImageInput label={label} value={value as Partial<ImageValue>} onChange={onChange} />;
      break;
    case 'videoUrl':
      input = <VideoUrlInput label={label} value={typeof value === 'string' ? value : ''} onChange={onChange} />;
      break;
    case 'icon':
      input = <IconPicker label={label} value={value as string} onChange={onChange} />;
      break;
  }
  return <Field label={label}>{input}</Field>;
}

function AdvancedControl({ node, field, label }: { node: ElementNode; field: keyof Advanced; label: string }) {
  const dispatch = useDispatch();
  const set = (value: Advanced[keyof Advanced]) =>
    dispatch({ type: 'setAdvanced', id: node.id, key: field, value, mergeKey: `advanced:${node.id}:${field}` });

  if (field === 'hideOn') {
    const hidden = node.advanced.hideOn ?? [];
    const toggle = (device: Device, on: boolean) =>
      set((['desktop', 'tablet', 'mobile'] as Device[]).filter((d) => (d === device ? on : hidden.includes(d))));
    return (
      <Field label={label} hint="Hidden elements stay visible (faded) in the editor.">
        <Switch label="Hide on desktop" checked={hidden.includes('desktop')} onChange={(on) => toggle('desktop', on)} />
        <Switch label="Hide on tablet" checked={hidden.includes('tablet')} onChange={(on) => toggle('tablet', on)} />
        <Switch label="Hide on mobile" checked={hidden.includes('mobile')} onChange={(on) => toggle('mobile', on)} />
      </Field>
    );
  }
  if (field === 'customCss') {
    return (
      <Field label={label} hint={CUSTOM_CSS_HINT}>
        <CustomCssInput
          ariaLabel={label}
          value={node.advanced.customCss ?? ''}
          maxLength={config.limits.maxCustomCssLength}
          placeholder={'color: #333;\n&:hover { opacity: .8 }\na { color: inherit }'}
          onChange={set}
        />
      </Field>
    );
  }
  const pattern = field === 'cssId' ? patterns.cssId : patterns.cssClasses;
  return (
    <Field label={label} hint={field === 'cssId' ? 'Letters, digits, - and _; must start with a letter.' : 'Space-separated class names.'}>
      <TextInput
        ariaLabel={label}
        value={node.advanced[field] ?? ''}
        validate={(v) => (v === '' || (pattern.test(v) && isValidAdvanced(field, v)) ? null : 'Not a valid value.')}
        onChange={set}
      />
    </Field>
  );
}

const CUSTOM_CSS_HINT = 'Applies to this element only. Write declarations directly, use & for the element itself (&:hover) and plain selectors for elements inside it.';

export function CustomCssInput({
  value,
  onChange,
  maxLength,
  ariaLabel,
  placeholder,
}: {
  value: string;
  onChange: (value: string) => void;
  maxLength: number;
  ariaLabel: string;
  placeholder?: string;
}) {
  return (
    <TextInput
      code
      ariaLabel={ariaLabel}
      value={value}
      placeholder={placeholder}
      validate={(v) =>
        codePointLength(v) > maxLength ? `At most ${maxLength} characters.` : customCssError(v)
      }
      onChange={onChange}
    />
  );
}
