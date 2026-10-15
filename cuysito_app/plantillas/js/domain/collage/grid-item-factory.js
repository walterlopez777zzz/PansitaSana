import { appendOfferIds } from "../cards/card-commands.js";

/**
 * Compatibilidad con el picker lateral legado. La lógica vive en
 * `domain/cards/card-commands.js`.
 *
 * @param {{ store: object, history?: object }} ctx
 * @param {"collage"|"cart"} mode
 * @param {string[]} offerIds
 * @returns {string[]} cardIds creados
 */
export const appendGridItems = (ctx, mode, offerIds) => appendOfferIds(ctx, mode, offerIds);
