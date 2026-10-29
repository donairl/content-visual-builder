import { act, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { createStore, useSelector, type Store } from './store';

interface State {
  selected: string;
  items: Record<string, number>;
  other: number;
}
type Action = { type: 'select'; id: string } | { type: 'bump' };

const reducer = (s: State, a: Action): State =>
  a.type === 'select' ? { ...s, selected: a.id } : { ...s, other: s.other + 1 };

let renders = 0;

/** Second selector depends on the first one's result, like useNode(selectedId). */
function Selected({ store }: { store: Store<State, Action> }) {
  renders++;
  const id = useSelector(store, (s) => s.selected);
  const value = useSelector(store, (s) => s.items[id]);
  return <span data-testid="value">{`${id}=${value}`}</span>;
}

describe('useSelector', () => {
  it('re-selects with the new selector when its input changes in the same update', () => {
    const store = createStore(reducer, { selected: 'a', items: { a: 1, b: 2 }, other: 0 });
    render(<Selected store={store} />);
    expect(screen.getByTestId('value').textContent).toBe('a=1');
    act(() => store.dispatch({ type: 'select', id: 'b' }));
    expect(screen.getByTestId('value').textContent).toBe('b=2');
    act(() => store.dispatch({ type: 'select', id: 'a' }));
    expect(screen.getByTestId('value').textContent).toBe('a=1');
  });

  it('does not re-render for unrelated state changes', () => {
    const store = createStore(reducer, { selected: 'a', items: { a: 1 }, other: 0 });
    render(<Selected store={store} />);
    const before = renders;
    act(() => store.dispatch({ type: 'bump' }));
    expect(renders).toBe(before);
  });
});
