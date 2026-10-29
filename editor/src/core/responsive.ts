import type { BoxValue, Device, Responsive } from './types';
import { DEVICES } from './types';

const present = <T>(value: T | null | undefined): value is T => value !== undefined && value !== null;

/** The value set on exactly `device`, if any. */
export function ownValue<T>(responsive: Responsive<T> | undefined, device: Device): T | undefined {
  const value = responsive?.[device];
  return present(value) ? value : undefined;
}

/** The value `device` inherits from larger devices (mobile ← tablet ← desktop), excluding its own. */
export function inheritedValue<T>(responsive: Responsive<T> | undefined, device: Device): T | undefined {
  const index = DEVICES.indexOf(device);
  for (let i = index - 1; i >= 0; i--) {
    const value = responsive?.[DEVICES[i]!];
    if (present(value)) return value;
  }
  return undefined;
}

/** The effective value on `device`: its own value, or the inherited one. */
export function resolveValue<T>(responsive: Responsive<T> | undefined, device: Device): T | undefined {
  return ownValue(responsive, device) ?? inheritedValue(responsive, device);
}

/**
 * Box values cascade per side in CSS (only set sides are emitted), so the effective box on a
 * device merges sides from larger devices.
 */
export function resolveBox(responsive: Responsive<BoxValue> | undefined, device: Device): BoxValue | undefined {
  let result: BoxValue | undefined;
  for (const d of DEVICES.slice(0, DEVICES.indexOf(device) + 1)) {
    const value = responsive?.[d];
    if (!present(value)) continue;
    result = { ...(result ?? { unit: value.unit }) };
    for (const side of ['top', 'right', 'bottom', 'left'] as const) {
      if (present(value[side])) {
        result[side] = value[side];
        result.unit = value.unit;
      }
    }
  }
  return result;
}
