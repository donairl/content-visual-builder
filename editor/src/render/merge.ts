import type { Ref, RefCallback } from 'react';

type Props = Record<string, unknown>;

export function composeRefs<T>(...refs: (Ref<T> | undefined)[]): RefCallback<T> {
  return (value: T | null) => {
    for (const ref of refs) {
      if (typeof ref === 'function') ref(value);
      else if (ref && typeof ref === 'object') (ref as { current: T | null }).current = value;
    }
  };
}

/** Merges element props: refs are composed, event handlers both run, classNames are joined. */
export function mergeProps(a: Props, b: Props | null | undefined): Props {
  if (!b) return a;
  const out: Props = { ...a };
  for (const [key, value] of Object.entries(b)) {
    const existing = out[key];
    if (key === 'ref' && existing) {
      out.ref = composeRefs(existing as Ref<unknown>, value as Ref<unknown>);
    } else if (key === 'className' && existing) {
      out.className = `${existing as string} ${value as string}`;
    } else if (/^on[A-Z]/.test(key) && typeof existing === 'function' && typeof value === 'function') {
      out[key] = (...args: unknown[]) => {
        (existing as (...a: unknown[]) => void)(...args);
        (value as (...a: unknown[]) => void)(...args);
      };
    } else {
      out[key] = value;
    }
  }
  return out;
}
