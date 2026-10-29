import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { NumberInput, TextInput } from './fields';

function Controlled({ onChange, min, max }: { onChange: (v: number | null) => void; min?: number; max?: number }) {
  const [value, setValue] = useState<number | null>(null);
  return (
    <NumberInput
      ariaLabel="n"
      value={value}
      min={min}
      max={max}
      onChange={(v) => {
        onChange(v);
        setValue(v);
      }}
    />
  );
}

describe('NumberInput', () => {
  it('does not clamp while the user is still typing', async () => {
    const onChange = vi.fn();
    render(<Controlled onChange={onChange} min={240} max={3000} />);
    const input = screen.getByLabelText('n');
    await userEvent.type(input, '1200');
    expect((input as HTMLInputElement).value).toBe('1200');
    expect(onChange).toHaveBeenLastCalledWith(1200);
    expect(onChange).not.toHaveBeenCalledWith(240);
  });

  it('clamps out-of-range values on blur', async () => {
    const onChange = vi.fn();
    render(<Controlled onChange={onChange} min={0} max={100} />);
    const input = screen.getByLabelText('n');
    await userEvent.type(input, '150');
    expect(input.getAttribute('aria-invalid')).toBe('true');
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith(100);
    expect((input as HTMLInputElement).value).toBe('100');
  });

  it('rounds to three decimals and clears to null', async () => {
    const onChange = vi.fn();
    render(<Controlled onChange={onChange} />);
    const input = screen.getByLabelText('n');
    await userEvent.type(input, '1.23456');
    expect(onChange).toHaveBeenLastCalledWith(1.235);
    expect((input as HTMLInputElement).value).toBe('1.23456');
    await userEvent.clear(input);
    expect(onChange).toHaveBeenLastCalledWith(null);
  });
});

describe('TextInput', () => {
  it('reports only valid values and shows the error', async () => {
    const onChange = vi.fn();
    render(<TextInput ariaLabel="t" value="" onChange={onChange} validate={(v) => (v.startsWith('#') ? null : 'Use a hex color')} />);
    await userEvent.type(screen.getByLabelText('t'), 'x');
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByText('Use a hex color')).toBeTruthy();
  });
});
