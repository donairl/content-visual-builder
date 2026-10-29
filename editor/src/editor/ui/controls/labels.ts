import type { Option } from './fields';

/** Human labels (and icons) for enum values; keys are style or prop names. */
const LABELS: Record<string, Record<string, Omit<Option, 'value'>>> = {
  textAlign: {
    left: { label: 'Left', icon: 'align-left' },
    center: { label: 'Center', icon: 'align-center' },
    right: { label: 'Right', icon: 'align-right' },
    justify: { label: 'Justify', icon: 'align-justify' },
  },
  flexDirection: {
    column: { label: 'Column', icon: 'arrow-down' },
    row: { label: 'Row', icon: 'arrow-right' },
    'column-reverse': { label: 'Column reversed', icon: 'arrow-up' },
    'row-reverse': { label: 'Row reversed', icon: 'arrow-left' },
  },
  justifyContent: {
    'flex-start': { label: 'Start' },
    center: { label: 'Center' },
    'flex-end': { label: 'End' },
    'space-between': { label: 'Between' },
    'space-around': { label: 'Around' },
    'space-evenly': { label: 'Evenly' },
  },
  alignItems: {
    stretch: { label: 'Stretch' },
    'flex-start': { label: 'Start' },
    center: { label: 'Center' },
    'flex-end': { label: 'End' },
    baseline: { label: 'Baseline' },
  },
  alignSelf: {
    auto: { label: 'Auto' },
    'flex-start': { label: 'Start' },
    center: { label: 'Center' },
    'flex-end': { label: 'End' },
    stretch: { label: 'Stretch' },
  },
  flexWrap: { nowrap: { label: 'No wrap' }, wrap: { label: 'Wrap' } },
  contentWidth: { boxed: { label: 'Boxed' }, full: { label: 'Full width' } },
  size: { sm: { label: 'Small' }, md: { label: 'Medium' }, lg: { label: 'Large' } },
  fontWeight: {
    '100': { label: '100 Thin' }, '200': { label: '200 Extra light' }, '300': { label: '300 Light' },
    '400': { label: '400 Normal' }, '500': { label: '500 Medium' }, '600': { label: '600 Semi bold' },
    '700': { label: '700 Bold' }, '800': { label: '800 Extra bold' }, '900': { label: '900 Black' },
  },
};

const titleCase = (value: string) =>
  value.replace(/[-_]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export function optionsFor(key: string, values: string[]): Option[] {
  return values.map((value) => ({ value, ...(LABELS[key]?.[value] ?? { label: titleCase(value) }) }));
}
