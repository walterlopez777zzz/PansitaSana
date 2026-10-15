import { computeGridRows } from "../../../domain/collage/grid-layout.js";
import { moveCard } from "../../../domain/cards/card-commands.js";

/**
 * Botones «mover» del inspector de tarjeta (←, →, ↑, ↓).
 *
 * El destino se calcula sobre la rejilla actual (`computeGridRows`), pero la
 * mutación se delega a `moveCard`, que identifica la tarjeta por `cardId`:
 * la selección viaja con la tarjeta, no con el índice que ocupaba.
 */

/**
 * Índice de la tarjeta de `row` que solapa el centro horizontal de una
 * tarjeta que arranca en la columna `col` y ocupa `units` columnas.
 * @param {number} col
 * @param {number} units
 * @param {ReadonlyArray<{ index: number, units: number }>} row
 */
export const findVerticalTarget = (col, units, row) => {
  const center = col + units / 2;
  let cursor = 0;
  for (const cell of row) {
    const end = cursor + cell.units;
    if (center >= cursor && center < end) return cell.index;
    cursor = end;
  }
  return row[row.length - 1].index;
};

function locateInRows(rows, index) {
  for (let rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
    let col = 0;
    for (const cell of rows[rowIndex]) {
      if (cell.index === index) return { rowIndex, col, units: cell.units };
      col += cell.units;
    }
  }
  return null;
}

/**
 * @param {object} deps
 * @param {object} deps.store
 * @param {object} [deps.history]
 * @param {() => "collage"|"cart"} deps.activeSliceKey
 * @param {(state: object) => { items?: unknown[] }|null|undefined} deps.activeSlice
 * @param {() => { index: number|null, kind: string, cardId: string|null }|null} deps.getSelectedCollageTarget
 */
export function createCardMovementController({
  store,
  history,
  activeSliceKey,
  activeSlice,
  getSelectedCollageTarget,
  inspectorCollageCardMoveLeftEl,
  inspectorCollageCardMoveRightEl,
  inspectorCollageCardMoveUpEl,
  inspectorCollageCardMoveDownEl,
}) {
  const ctx = { store, history };

  const selectedCard = () => {
    const target = getSelectedCollageTarget();
    if (!target || target.kind !== "card" || !target.cardId || target.index === null) return null;
    const slice = activeSlice(store.getState());
    const items = Array.isArray(slice?.items) ? slice.items : [];
    return { ...target, items };
  };

  const moveTo = (target, toIndex) => {
    if (toIndex < 0 || toIndex === target.index) return;
    moveCard(ctx, activeSliceKey(), target.cardId, toIndex, { kind: target.kind });
  };

  const moveHorizontally = (delta) => {
    const target = selectedCard();
    if (!target) return;
    const toIndex = target.index + delta;
    if (toIndex >= target.items.length) return;
    moveTo(target, toIndex);
  };

  const moveVertically = (rowDelta) => {
    const target = selectedCard();
    if (!target || target.items.length < 2) return;
    const rows = computeGridRows(target.items);
    const position = locateInRows(rows, target.index);
    if (!position) return;
    const destinationRow = rows[position.rowIndex + rowDelta];
    if (!destinationRow) return;
    moveTo(target, findVerticalTarget(position.col, position.units, destinationRow));
  };

  inspectorCollageCardMoveLeftEl?.addEventListener("click", () => moveHorizontally(-1));
  inspectorCollageCardMoveRightEl?.addEventListener("click", () => moveHorizontally(1));
  inspectorCollageCardMoveUpEl?.addEventListener("click", () => moveVertically(-1));
  inspectorCollageCardMoveDownEl?.addEventListener("click", () => moveVertically(1));
}
