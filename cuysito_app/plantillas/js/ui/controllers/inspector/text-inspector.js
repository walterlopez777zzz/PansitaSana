import { resolveConversionRate as resolveRate } from "../../../domain/pricing/conversion-rate.js";

export function createTextInspector({ store: e, selectedLayerId: t, onInput: i, setFieldValue: o, normalizeHexWhiteDefault: s, isValidHex6: n, normalizeDiscountConfig: l, computeDiscountedPrices: r, inspectorColorPickerEl: a, inspectorColorHexEl: c, inspectorOpacityEl: u, inspectorMaxWidthEl: d, inspectorFontScaleEl: m, inspectorLineHeightEl: p, inspectorTextRotationEl: f, inspectorTextAlignEl: S, inspectorVAnchorEl: v, clamp: g, colorPickerService: b, setSwatchColor: x }) {
  return a && b && a.addEventListener("click", () => {
    const i = e.getState()?.ui?.selectedElement;
    i && !t(i) && b.open(a, c?.value || "#FFFFFF", t => {
      const o = s(t);
      if (c && (c.value = o), "solesOffer" === i) {
        const t = e.getState(),
          s = t?.template || {},
          n = t?.ui || {},
          a = Number(s.vbucks || 0),
          c = resolveRate(s.conversionRate),
          u = !1 !== n.pricesLocked,
          d = l(s.individualDiscount ?? s.discount),
          m = r({ baseOfferVbucks: a, conversionRate: c, baseListSoles: u ? null : s.soles, discount: d }),
          p = m.active && null !== m.listSoles && void 0 !== m.listSoles && m.listSoles !== m.offerSoles ? "discountColor" : "color";
        return void e.update(`template.textStyles.${i}.${p}`, o);
      }
      e.update(`template.textStyles.${i}.color`, o);
    });
  }),
    c && c.addEventListener("input", i(i => {
      const o = e.getState()?.ui?.selectedElement;
      if (!o || t(o)) return;
      if (!n(i.target.value)) return;
      const c = s(i.target.value);
      if (x(a, c), "solesOffer" === o) {
        const t = e.getState(),
          i = t?.template || {},
          s = t?.ui || {},
          n = Number(i.vbucks || 0),
          a = resolveRate(i.conversionRate),
          u = !1 !== s.pricesLocked,
          d = l(i.individualDiscount ?? i.discount),
          m = r({ baseOfferVbucks: n, conversionRate: a, baseListSoles: u ? null : i.soles, discount: d }),
          p = m.active && null !== m.listSoles && void 0 !== m.listSoles && m.listSoles !== m.offerSoles ? "discountColor" : "color";
        return void e.update(`template.textStyles.${o}.${p}`, c);
      }
      e.update(`template.textStyles.${o}.color`, c);
    })),
    u && u.addEventListener("input", i(i => {
      const o = e.getState()?.ui?.selectedElement;
      if (!o || t(o)) return;
      const s = Number(i.target.value);
      e.update(`template.textStyles.${o}.opacity`, Number.isFinite(s) ? s : 0);
    })),
    d && d.addEventListener("input", i(i => {
      const o = e.getState()?.ui?.selectedElement;
      if (!o || t(o)) return;
      const s = Number(i.target.valueAsNumber),
        n = Math.max(.35, Math.min(1, Number.isFinite(s) ? s : 1));
      e.update(`template.textStyles.${o}.maxWidth`, n);
    })),
    m && m.addEventListener("input", i(i => {
      const o = e.getState()?.ui?.selectedElement;
      if (!o || t(o)) return;
      const s = Number(i.target.valueAsNumber),
        n = Math.max(.6, Math.min(1.4, Number.isFinite(s) ? s : 1));
      e.update(`template.textStyles.${o}.fontScale`, n);
    })),
    p && p.addEventListener("input", i(i => {
      const o = e.getState()?.ui?.selectedElement;
      if (!o || t(o)) return;
      const s = Number(i.target.valueAsNumber),
        n = Math.max(.6, Math.min(1.8, Number.isFinite(s) ? s : .92));
      e.update(`template.textStyles.${o}.lineHeight`, n);
    })),
    f && f.addEventListener("input", i(i => {
      const o = e.getState()?.ui?.selectedElement;
      if (!o || t(o)) return;
      const s = Number(i.target.valueAsNumber),
        n = g(Number.isFinite(s) ? s : 0, -180, 180);
      e.update(`template.textStyles.${o}.rotation`, n);
    })),
    S && S.addEventListener("change", i(i => {
      const o = e.getState()?.ui?.selectedElement;
      if (!o || t(o)) return;
      const s = String(i.target.value || "").trim().toLowerCase(),
        n = "left" === s || "center" === s || "right" === s ? s : "left";
      e.update(`template.textStyles.${o}.align`, n);
    })),
    v && v.addEventListener("change", i(i => {
      const o = e.getState()?.ui?.selectedElement;
      if (!o || t(o)) return;
      const s = String(i.target.value || "").trim().toLowerCase(),
        n = "top" === s || "middle" === s || "bottom" === s ? s : "top";
      e.update(`template.textStyles.${o}.vAnchor`, n);
    })),
    Object.freeze({
      apply: (e, { selectedId: t, forceHydrate: i } = {}) => {
        const n = String(t || "");
        if (!n) return;
        const g = e?.template?.textColor || "#FFFFFF",
          b = e?.template?.textOpacity ?? 1,
          E = e?.template?.textStyles?.[n] || {};
        let F = E.color || g;
        if ("solesOffer" === n || "soles" === n) {
          const t = e?.template || {},
            i = e?.ui || {},
            o = Number(t.vbucks || 0),
            a = resolveRate(t.conversionRate),
            c = !1 !== i.pricesLocked,
            u = l(t.individualDiscount ?? t.discount),
            d = r({ baseOfferVbucks: o, conversionRate: a, baseListSoles: c ? null : t.soles, discount: u });
          d.active && null !== d.listSoles && void 0 !== d.listSoles && d.listSoles !== d.offerSoles && "solesOffer" === n && (F = s(E.discountColor || "#55FF55"));
        }
        if (x(a, s(F)), c) {
          const e = s(F),
            t = String(c.value || "").trim().toUpperCase();
          o(c, e, { force: i || t !== e });
        }
        u && o(u, E.opacity ?? b, { force: i }),
          d && o(d, E.maxWidth ?? 1, { force: i }),
          m && o(m, E.fontScale ?? 1, { force: i }),
          p && o(p, E.lineHeight ?? .92, { force: i }),
          f && o(f, E.rotation ?? 0, { force: i }),
          S && (S.value = String(E.align || "").trim().toLowerCase() || "left"),
          v && (v.value = String(E.vAnchor || "").trim().toLowerCase() || "top");
      }
    });
}