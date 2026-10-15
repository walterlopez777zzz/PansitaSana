import { resolveConversionRate as resolveRate } from "../../domain/pricing/conversion-rate.js";

export function createSelectionMeta(e = {}) {
  const {
    parseCollageSelection: o,
    selectedLayerId: t,
    normalizeCollageItem: i,
    normalizeMode: n,
    createDefaultCollageItemDiscount: s,
    resolveDiscountConfig: l,
    computeDiscountedPrices: r,
    formatSoles: a,
    normalizeDiscountConfig: c,
  } = e;

  const u = (e) => {
    const o = "function" == typeof n ? n(e?.ui?.mode) : "collage";
    return "cart" === o ? e?.cart || {} : "coupon" === o ? e?.coupon || {} : e?.collage || {};
  };

  return {
    titleForSelection: (e, n) => {
      const d = o(e);
      if (d) {
        if ("title" === d.kind) return "Collage: Título de Sección";
        if ("branding" === d.kind) return "Branding Bar";
        if ("cartTotal" === d.kind) return "Carrito: Encabezado Total";
        if ("recipient" === d.kind) return `Carrito: Para: (${(d.index ?? 0) + 1})`;
        if ("image" === d.kind) return `Collage: Imagen (${d.index + 1})`;
        if ("name" === d.kind) return `Collage: Nombre (${d.index + 1})`;
        if ("price" === d.kind) return `Collage: Precio (${d.index + 1})`;
        if ("solesList" === d.kind) return `Collage: Soles Lista (${d.index + 1})`;
        if ("solesOffer" === d.kind) {
          const e = Array.isArray(u(n)?.items) ? u(n).items : [],
            o = i(e[d.index]),
            t = (Array.isArray(n?.shop?.items) ? n.shop.items : []).find(
              (e) => String(e?.offerId || "") === String(o?.offerId || "")
            ) || {},
            c = Number(t?.vbucks || 0),
            f = resolveRate(n?.template?.conversionRate),
            g = o?.discount && "object" == typeof o.discount ? o.discount : s(),
            m = l({
              globalDiscount: n?.template?.collageDiscount ?? n?.template?.discount,
              useGlobalDiscount: !1 !== g.useGlobalDiscount,
              localDiscount: g.config,
            }),
            S = r({ baseOfferVbucks: c, conversionRate: f, discount: m }),
            b = S.active && null !== S.listSoles && void 0 !== S.listSoles && S.listSoles !== S.offerSoles ? a(S.listSoles) : "";
          return String(o?.style?.solesList?.text || "").trim() || b ? `Collage: Soles Oferta (${d.index + 1})` : `Collage: Soles (${d.index + 1})`;
        }
        return "outDate" === d.kind ? `Carrito: Fecha de Salida (${d.index + 1})` : "banner" === d.kind ? `Collage: Banner (${d.index + 1})` : `Collage: Tarjeta (${d.index + 1})`;
      }
      const f = t(e);
      if (f) return `Imagen (${f})`;
      if ("name" === e) return "Nombre";
      if ("vbucks" === e) return "V-Bucks";
      if ("solesList" === e) return "Soles (Lista)";
      if ("soles" === e || "solesOffer" === e) {
        const e = n?.template || {},
          o = n?.ui || {},
          t = Number(e.vbucks || 0),
          i = resolveRate(e.conversionRate),
          s = !1 !== o.pricesLocked,
          l = c(e.individualDiscount ?? e.discount),
          a = r({ baseOfferVbucks: t, conversionRate: i, baseListSoles: s ? null : e.soles, discount: l });
        return a.active && null !== a.listSoles && void 0 !== a.listSoles && a.listSoles !== a.offerSoles ? "Soles (Oferta)" : "Soles";
      }
      return "type" === e ? "Tipo" : "rarity" === e ? "Rareza" : "date" === e ? "Fecha" : "label" === e ? "Etiqueta" : "Propiedades";
    },
    isHiddenSelection: (e, o) => {
      const t = String(e || "");
      return !!o.has(t) || !("solesOffer" !== t && "solesList" !== t || !o.has("soles"));
    },
  };
}