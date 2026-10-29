'use client';

/**
 * Global styles panel (README §9): the site's color palette and typography presets. Edits show in
 * the canvas immediately but are site-wide, so they are saved explicitly (never autosaved, never
 * in the page's undo history); saving re-renders every published page on the server.
 */
import { useState } from 'react';
import { FONT_KEYS, type GlobalColor, type GlobalFont, type Globals } from '@/core/globals';
import { inheritedValue, ownValue } from '@/core/responsive';
import { config, isResponsiveControl, patterns, styleProperties } from '@/core/schema';
import type { Device, Responsive, SizeValue } from '@/core/types';
import { useDispatch, useEditor } from '../context';
import { useServices } from '../services';
import { globalsDirty } from '../state';
import { ColorInput, Field, TextInput } from './controls/fields';
import { EnumInput, FontInput, NumberStyleInput, SizeInput, type InputProps } from './controls/styleControls';
import { Section } from './LeftPanel';
import { UiIcon } from './UiIcon';

const FONT_LABELS: Record<string, string> = {
  fontFamily: 'Family',
  fontSize: 'Size',
  fontWeight: 'Weight',
  lineHeight: 'Line height',
  letterSpacing: 'Letter spacing',
  textTransform: 'Transform',
  fontStyle: 'Style',
  textDecoration: 'Decoration',
};

/** A new id from a name: lowercase letters, digits and dashes, unique among `taken`. */
export function globalIdFor(name: string, taken: readonly string[], fallback: string): string {
  const base =
    name
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^[^a-z]+|-+$/g, '')
      .slice(0, 24) || fallback;
  let id = base;
  for (let i = 2; taken.includes(id); i++) id = `${base}-${i}`;
  return patterns.globalId.test(id) ? id : fallback;
}

const PREVIOUS: Record<Device, Device | null> = { desktop: null, tablet: 'desktop', mobile: 'tablet' };

export function GlobalsPanel() {
  const globals = useEditor((s) => s.globals);
  const dirty = useEditor(globalsDirty);
  const device = useEditor((s) => s.device);
  const dispatch = useDispatch();
  const services = useServices();
  const canEdit = services.can('edit_theme_options');
  const { colors, fonts } = globals.value;

  const update = (next: Partial<Globals>) => dispatch({ type: 'editGlobals', globals: { ...globals.value, ...next } });
  const setColor = (index: number, patch: Partial<GlobalColor>) =>
    update({ colors: colors.map((c, i) => (i === index ? { ...c, ...patch } : c)) });
  const setFont = (index: number, patch: Partial<GlobalFont>) =>
    update({ fonts: fonts.map((f, i) => (i === index ? { ...f, ...patch } : f)) });

  const addColor = () => {
    const name = `Color ${colors.length + 1}`;
    update({ colors: [...colors, { id: globalIdFor(name, colors.map((c) => c.id), 'color'), name, value: '#000000' }] });
  };
  const addFont = () => {
    const name = `Font ${fonts.length + 1}`;
    update({ fonts: [...fonts, { id: globalIdFor(name, fonts.map((f) => f.id), 'font'), name, style: {} }] });
  };

  return (
    <>
      <div className="ed-side-head">
        <UiIcon name="globe" size={16} />
        <h2>Global styles</h2>
        <button type="button" className="ed-icon-btn" aria-label="Close global styles" onClick={() => dispatch({ type: 'setLeftTab', tab: 'elements' })}>
          <UiIcon name="x" size={14} />
        </button>
      </div>
      <p className="ed-side-note">
        Used across the whole site. Elements linked to a global follow it; saving updates every published page.
        {canEdit ? '' : ' You can try changes here, but only editors and administrators can save them.'}
      </p>

      <Section title={`Global colors (${colors.length})`} defaultOpen>
        {colors.map((color, i) => (
          <div className="ed-global-row" key={color.id}>
            <div className="ed-global-name">
              <TextInput
                ariaLabel={`Name of global color ${i + 1}`}
                value={color.name}
                maxLength={config.limits.maxGlobalNameLength}
                validate={(v) => (v.trim() ? null : 'Enter a name.')}
                onChange={(name) => setColor(i, { name })}
              />
              <RemoveButton label={`Delete global color ${color.name}`} onConfirm={() => update({ colors: colors.filter((_, j) => j !== i) })} />
            </div>
            <ColorInput ariaLabel={`${color.name} value`} value={color.value} allowGlobals={false} onChange={(value) => setColor(i, { value: value ?? '#000000' })} />
            <code className="ed-global-id" title="Elements reference this id">global:{color.id}</code>
          </div>
        ))}
        <button type="button" className="ed-btn ed-btn--sm" disabled={colors.length >= config.limits.maxGlobalColors} onClick={addColor}>
          <UiIcon name="plus" size={14} /> Add color
        </button>
      </Section>

      <Section title={`Global fonts (${fonts.length})`} defaultOpen>
        {fonts.map((font, i) => (
          <FontPreset
            key={font.id}
            font={font}
            index={i}
            device={device}
            onChange={(patch) => setFont(i, patch)}
            onRemove={() => update({ fonts: fonts.filter((_, j) => j !== i) })}
          />
        ))}
        <button type="button" className="ed-btn ed-btn--sm" disabled={fonts.length >= config.limits.maxGlobalFonts} onClick={addFont}>
          <UiIcon name="plus" size={14} /> Add font
        </button>
      </Section>

      <div className="ed-side-foot">
        <button type="button" className="ed-btn ed-btn--sm" disabled={!dirty || globals.saving} onClick={() => void services.reloadGlobals()}>
          Discard
        </button>
        <button
          type="button"
          className="ed-btn ed-btn--sm ed-btn--primary"
          disabled={!dirty || globals.saving || !canEdit}
          onClick={() => void services.saveGlobals()}
        >
          {globals.saving ? 'Saving…' : 'Save global styles'}
        </button>
      </div>
    </>
  );
}

/** Two-step delete: the first click asks, the second deletes. */
function RemoveButton({ label, onConfirm }: { label: string; onConfirm: () => void }) {
  const [armed, setArmed] = useState(false);
  return (
    <button
      type="button"
      className={armed ? 'ed-btn ed-btn--sm ed-btn--danger' : 'ed-icon-btn'}
      title={armed ? 'Elements using it lose this style. Click again to delete.' : 'Delete'}
      aria-label={armed ? `${label}: confirm` : label}
      onBlur={() => setArmed(false)}
      onClick={() => (armed ? onConfirm() : setArmed(true))}
    >
      {armed ? 'Delete?' : <UiIcon name="trash" size={14} />}
    </button>
  );
}

function FontPreset({
  font,
  index,
  device,
  onChange,
  onRemove,
}: {
  font: GlobalFont;
  index: number;
  device: Device;
  onChange: (patch: Partial<GlobalFont>) => void;
  onRemove: () => void;
}) {
  const [open, setOpen] = useState(false);
  const dispatch = useDispatch();
  const setStyle = (key: string, editDevice: Device, value: unknown) => {
    const current: Responsive<unknown> = { ...(font.style[key] ?? {}) };
    if (value === null || value === undefined) delete current[editDevice];
    else current[editDevice] = value;
    const style = { ...font.style };
    if (Object.keys(current).length) style[key] = current;
    else delete style[key];
    onChange({ style });
  };

  return (
    <div className="ed-global-row">
      <div className="ed-global-name">
        <TextInput
          ariaLabel={`Name of global font ${index + 1}`}
          value={font.name}
          maxLength={config.limits.maxGlobalNameLength}
          validate={(v) => (v.trim() ? null : 'Enter a name.')}
          onChange={(name) => onChange({ name })}
        />
        <button type="button" className="ed-icon-btn" aria-expanded={open} aria-label={`Edit ${font.name}`} onClick={() => setOpen(!open)}>
          <UiIcon name={open ? 'chevron-down' : 'chevron-right'} size={14} />
        </button>
        <RemoveButton label={`Delete global font ${font.name}`} onConfirm={onRemove} />
      </div>
      <code className="ed-global-id">{font.id}</code>
      {open ? (
        <div className="ed-group">
          {FONT_KEYS.map((key) => {
            const prop = styleProperties[key];
            if (!prop) return null;
            const responsive = isResponsiveControl({ style: key, label: '' });
            const editDevice: Device = responsive ? device : 'desktop';
            const value = font.style[key];
            const own = ownValue(value, editDevice);
            const inherited = PREVIOUS[editDevice] ? inheritedValue(value, editDevice) : undefined;
            const props = {
              prop,
              styleKey: key,
              value: own,
              inherited,
              label: FONT_LABELS[key] ?? key,
              onChange: (v: unknown) => setStyle(key, editDevice, v),
            };
            let input;
            if (prop.type === 'fontFamily') input = <FontInput {...(props as InputProps<string>)} />;
            else if (prop.type === 'size') input = <SizeInput {...(props as unknown as InputProps<SizeValue>)} />;
            else if (prop.type === 'number') input = <NumberStyleInput {...(props as unknown as InputProps<number>)} />;
            else input = <EnumInput {...(props as InputProps<string>)} />;
            return (
              <Field
                key={key}
                label={props.label}
                device={responsive ? editDevice : undefined}
                onDevice={responsive ? (d) => dispatch({ type: 'setDevice', device: d }) : undefined}
                onReset={own !== undefined ? () => setStyle(key, editDevice, null) : undefined}
              >
                {input}
              </Field>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}
