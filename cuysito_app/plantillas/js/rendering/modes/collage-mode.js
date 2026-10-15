import { PriceRenderer as e } from "../painters/price-renderer.js";

import { unwrapWeservUrl as t } from "../assets/asset-manager.js";

import { computeLayerFadeSpec as o, computeLayerFilterString as a } from "../assets/asset-layer-effects.js";

import { roundedRect as l } from "../painters/geometry.js";

import { drawSingleLineTextWithMetrics as r, drawPriceStrikeBar as n, wrapText as s } from "../painters/text-engine.js";

import { computeDiscountedPrices as i, resolveDiscountConfig as c } from "../../domain/pricing/discounts.js";

import { computeGradientStops as m } from "../painters/gradient-stops.js";

import { COLLAGE_BASE_CARD_W as f, DEFAULT_COLLAGE_GLOBAL_GRADIENT_COLORS as u, SECONDARY_LABEL_COLOR as g, DEFAULT_COLLAGE_BACKGROUND_COLOR as d } from "../../constants/defaults.js";

import { normalizeCollageItem as p, normalizeCollageItemStyle as b } from "../../domain/collage/collage-item.js";

import { buildSectionBaseStyleMap as h, normalizeLayoutId as y } from "../../domain/shop/shop-colors.js";

import { resolveConversionRate as Rr } from "../../domain/pricing/conversion-rate.js";

import { clamp as x } from "../../utils/number-utils.js";

import { safeHex as S, isFullyTransparent as w } from "../../utils/color-utils.js";

import { formatSoles as N, roundSoles as I, formatSolesExact } from "../../domain/pricing/money-format.js";

import { isSolesPricing, resolveSolesAmount, resolveSolesListAmount } from "../../domain/cards/card-pricing.js";

import { isCoverMedia } from "../../domain/cards/card-media.js";

import { effectiveCardId } from "../../domain/cards/card-index.js";

import { cardSelectionId } from "../../domain/cards/card-selection.js";

import { parseCollageSelection, collageSelectionIdFor } from "../../ui/selection/selection-ids.js";

import { resolveCardSubject } from "../painters/card-subject.js";

import { drawViaCuentaBadges } from "../painters/via-cuenta-badges.js";

import { createMemoizedComputeLayout as v, createLayoutPositionCache as C } from "../../domain/collage/grid-layout.js";

import { resolveCollageGlobalDesign as A, createCollageStyleResolver as O } from "../../domain/collage/collage-style-resolver.js";

import { rotatedAabb as T, cardTransformAabb as F, buildCardMatrix as M } from "../card-geometry.js";

import { internBurbankSmall as B, internBurbankBig as D, internHeadingNowCard as HN, internFont as L } from "../painters/font-cache.js";

import { drawOutDatePill as j } from "../painters/date-badge.js";

import { createBrandingFooterPainter as k } from "../painters/branding-renderer.js";

import { parseDateDDMM as z, isLeavingCurrentCycle as $ } from "../../utils/date-utils.js";

import { CARD_IMAGE_FRAMING_STORE, CARD_IMAGE_TOP_OVERFLOW_RATIO, computeFramedImageDraw, resolveCardImageFraming } from "../../domain/collage/card-image-framing.js";

import { CARD_TEXT_PADDING, CARD_TITLE_PRICING_GAP, CARD_TITLE_FONT_SIZE, CARD_TITLE_WIDE_SCALE, CARD_TITLE_LINE_HEIGHT, CARD_TITLE_MAX_LINES, CARD_VBUCKS_FONT_SIZE, CARD_VBUCKS_ICON_SIZE, CARD_VBUCKS_ICON_GAP, CARD_SOLES_FONT_SIZE, CARD_SOLES_DISCOUNT_COLOR, CARD_PRICE_ROW_GAP, CARD_STRIKE_TEXT_COLOR, CARD_STRIKE_TEXT_OPACITY, CARD_STRIKE_BAR_THICKNESS, CARD_STRIKE_BAR_OPACITY, CARD_STRIKE_BAR_ANGLE_DEG, CARD_STRIKE_BAR_PADDING_X } from "../../domain/collage/card-text-layout.js";

export function createCollageModeRenderer({imageCache: p, fadeCache: L, contentBounds: Y, collageBitmaps: X, workCtx: R, workCanvas: P, ctx: U, canvas: E, onStatus: G, isTokenValid: W} = {}) {
  const V = e => "number" != typeof e || "function" != typeof W || W(e), {drawBrandingFooter: H} = k(), q = (e, t, o, a, l, r) => {
    if (!t) return;
    const n = Number(t.width || 0), s = Number(t.height || 0);
    if (!n || !s) return;
    const i = Math.max(l / n, r / s), c = l / i, m = r / i, f = (n - c) / 2, u = (s - m) / 2;
    e.drawImage(t, f, u, c, m, o, a, l, r);
  }, K = Object.freeze({
    zoom: 1,
    offsetX: 0,
    offsetY: 0
  }), Q = Object.freeze({
    zoom: .7,
    offsetX: 0,
    offsetY: -100
  }), J = v(), Z = C(), _ = O(), {resolveCollageCardGradientColors: ee, resolveCollageCardGradientStopsHidden: te, resolveCollageCardAccentSpec: oe} = _;
  // Ids de hitbox por tarjeta: estables (`card:<cardId>[:kind]`) cuando el item
  // tiene cardId, con fallback legacy por índice para items sin migrar.
  const buildCardHitIds = (items, index) => {
    const cardId = effectiveCardId(items[index], index);
    return {
      card: cardSelectionId(cardId, "card"),
      image: cardSelectionId(cardId, "image"),
      name: cardSelectionId(cardId, "name"),
      price: cardSelectionId(cardId, "price"),
      solesOffer: cardSelectionId(cardId, "solesOffer"),
      solesList: cardSelectionId(cardId, "solesList"),
      banner: cardSelectionId(cardId, "banner"),
      outdate: cardSelectionId(cardId, "outdate")
    };
  };
  let le = null, re = null;
  const ne = e => {
    if (e === re && le) return le;
    re = e, le = new Array(e.length);
    for (let t = 0; t < e.length; t++) le[t] = buildCardHitIds(e, t);
    return le;
  }, resolveSelectionHitKey = (selection, hitBoxes, items) => {
    if (!selection) return null;
    if (hitBoxes[selection]) return selection;
    const parsed = parseCollageSelection(selection, items);
    if (!parsed || parsed.index === null) return null;
    const key = collageSelectionIdFor(parsed.index, parsed.kind, items);
    return hitBoxes[key] ? key : null;
  }, se = {
    layoutId: "",
    sectionStyles: null,
    baseMap: null,
    globalDesign: null
  };
  let ie = new Map, ce = 0;
  const fe = async (u, {x: d, y: b, w: h, h: y, item: w, style: v, discount: C, globalDiscount: A, conversionRate: O, token: k, forExport: R, baseCardW: P, globalDesign: U, layoutId: E, sectionContext: G}) => {
    const W = v, V = (Number(P) || f) / f, H = 32 * V, q = CARD_TEXT_PADDING * V, J = !1 !== W?.card?.linkChildrenTransforms, Z = x(Number(W?.card?.scale ?? 1), .5, 2), _ = Number(W?.card?.rotation || 0) * Math.PI / 180, ae = d + h / 2, le = b + y / 2, re = 1 !== Z || 0 !== _, ne = "function" == typeof u.getTransform ? u.getTransform() : new DOMMatrix, me = re ? M(ne, ae, le, Z, _) : null, solesPricing = isSolesPricing(w?.pricing) ? w.pricing : null, fe = Math.min(.34 * y, 220 * V), ue = {
      x: d,
      y: b,
      w: h,
      h: y
    };
    let ge = {
      x: d,
      y: b,
      w: h,
      h: Math.max(1, y - fe)
    }, de = null, pe = null, be = null, he = null, ye = null, xe = null, Se = !0, we = !0, Ne = !0, Ie = !0, ve = !0;
    const overflowTopPx = !0 === W?.image?.overflowTop ? CARD_IMAGE_TOP_OVERFLOW_RATIO * y : 0;
    let clipTightened = overflowTopPx <= 0;
    u.save();
    try {
      re && me && u.setTransform(me), l(u, d, b - overflowTopPx, h, y + overflowTopPx, H), 
      u.clip(), se.layoutId = E, se.sectionStyles = G?.sectionStyles, se.baseMap = G?.baseMap, 
      se.globalDesign = U;
      const f = ee(W, se), F = te(W, se), M = f ? m(f, F) : [], P = X.getGradientBitmap(M);
      if (P) {
        u.save();
        try {
          l(u, d, b, h, y, H), u.clip(), u.drawImage(P, d, b, h, y);
        } finally {
          u.restore();
        }
      }
      !J && re && u.setTransform(ne), Se = !1 !== W?.image?.visible;
      const Z = String(W?.image?.url || w?.imageUrl || "").trim(), _ = R ? t(Z) : Z;
      if (_ && Se) {
        const e = R ? await p.load(_) : p.request(_);
        if (e) {
          u.imageSmoothingEnabled = !0, u.imageSmoothingQuality = "high";
          const t = o(W.image), l = t.enabled && t.height > 0;
          let r = e;
          if (l) {
            const o = Y?.getContentBottomY?.(e) ?? 1, a = L.makeFadeCacheKey(_, e, t, o), l = R ? await L.getFadedSource({
              cacheKey: a,
              img: e,
              fade: t,
              token: k,
              contentBottomY: o
            }) : L.requestFadedSource({
              cacheKey: a,
              img: e,
              fade: t,
              contentBottomY: o
            });
            l?.source && (r = l.source);
          }
          const n = (e => e?.isWrap ? Q : K)(w), s = v?.image && "object" == typeof v.image ? v.image : null, framingSpec = CARD_IMAGE_FRAMING_STORE === String(s?.framing || "").trim() ? resolveCardImageFraming(w?.typeCategory, w?.typeLabel, W?.card?.widthUnits, !0 === w?.isCarOffer, !0 === w?.isDynamicBundle) : null, i = x(Number(s?.zoom ?? n.zoom), .1, 5), c = Number(W?.image?.rotation || 0) * Math.PI / 180, m = Number(s?.offsetX ?? n.offsetX), f = Number(s?.offsetY ?? n.offsetY), g = x(Number(W?.image?.opacity ?? 1), 0, 1), p = u.globalAlpha, S = u.filter;
          u.globalAlpha = p * g, u.filter = a(W.image);
          const N = Number(r?.width || 0), I = Number(r?.height || 0);
          if (N > 0 && I > 0) if (framingSpec) {
            const e = computeFramedImageDraw(framingSpec, {
              cardW: h,
              cardH: y,
              imgW: N,
              imgH: I,
              zoom: i
            });
            u.translate(d + e.cx + m, b + e.cy + f), u.rotate(c), u.drawImage(r, -e.w / 2, -e.h / 2, e.w, e.h);
          } else {
            u.translate(d + h / 2, b + y / 2), u.rotate(c);
            const e = Math.max(h / N, y / I) * i, t = N * e, o = I * e;
            u.drawImage(r, -t / 2 + m, -o / 2 + f, t, o);
          }
          re && me ? u.setTransform(me) : u.setTransform(ne), !J && re && u.setTransform(ne), 
          u.globalAlpha = p, u.filter = S;
        }
      }
      if (!clipTightened) {
        re && me ? u.setTransform(me) : u.setTransform(ne), l(u, d, b, h, y, H), u.clip(), 
        !J && re && u.setTransform(ne), clipTightened = !0;
      }
      {
        const e = oe(W, se);
        if (e && !0 !== e.accentHidden) {
          const t = X.getAccentBitmap(e);
          t && u.drawImage(t, d, b, h, y);
        }
      }
      const ae = String(w?.displayName || "Artículo").trim(), le = String(W?.name?.text || "").trim() || ae, fe = solesPricing ? 0 : Math.max(0, Math.round(Number(w?.vbucks || 0))), ue = Rr(O), ge = C?.useGlobalDiscount, Ce = C?.config, Ae = ((e, t, o, a) => {
        a !== ce && (ie.clear(), ce = a);
        const l = `${e}|${t}|${o.active}|${o.value}|${o.manualListPrice}|${o.manualOfferPrice}`;
        let r = ie.get(l);
        return void 0 !== r || (r = i({
          baseOfferVbucks: e,
          conversionRate: t,
          discount: o
        }), ie.set(l, r)), r;
      })(fe, ue, c({
        globalDiscount: A,
        useGlobalDiscount: ge,
        localDiscount: Ce
      }), k), Oe = null !== Ae.offerSoles && void 0 !== Ae.offerSoles ? Ae.offerSoles : I(fe * ue), Te = b + y - q, Fe = solesPricing ? 0 : CARD_VBUCKS_ICON_SIZE * V, Me = x(Number(W?.solesOffer?.fontSize ?? CARD_SOLES_FONT_SIZE), 10, 200) * V, Be = x(Number(W?.solesList?.fontSize ?? CARD_SOLES_FONT_SIZE), 10, 200) * V, solesOfferAmount = solesPricing ? resolveSolesAmount(solesPricing) : null, solesListAmount = solesPricing ? resolveSolesListAmount(solesPricing) : null, Le = String(W?.price?.text || "").trim() || (solesPricing ? formatSolesExact(solesOfferAmount) : e.formatThousands(fe)), je = "", ke = solesPricing ? 0 : CARD_VBUCKS_ICON_GAP * V, ze = x(Number(W?.price?.fontSize ?? CARD_VBUCKS_FONT_SIZE), 10, 200) * V, $e = Te + Number(W?.price?.offsetY || 0), Ye = d + q + Number(W?.price?.offsetX || 0), Xe = Number(W?.price?.rotation || 0) * Math.PI / 180;
      Ne = !1 !== W?.price?.visible && !w?.noPricing;
      const Re = S(W?.price?.color) || "#FFFFFF", Pe = x(Number(W?.price?.opacity ?? 1), 0, 1);
      {
        const t = u.globalAlpha;
        u.globalAlpha = t * Pe, pe = e.renderCollageVbucksPrice(u, {
          leftX: Ye,
          bottomY: $e,
          offerText: Le,
          listText: je,
          fontSize: ze,
          font: HN(ze),
          iconSize: Fe,
          gapX: ke,
          offerColor: Re,
          listColor: g,
          rotation: Xe,
          draw: Ne
        }), u.globalAlpha = t;
      }
      // Con precio directo en soles (Vía Cuenta / tarjeta libre) el importe va en
      // la fila principal; la columna derecha solo muestra la lista tachada si
      // hay oferta activa.
      const Ue = String(W?.solesOffer?.text || "").trim(), Ee = String(W?.solesList?.text || "").trim(), Ge = solesPricing ? Ue : Ue || N(Oe), We = solesPricing ? Ee || (null !== solesListAmount ? formatSolesExact(solesListAmount) : "") : Ee || (Ae.active && null !== Ae.listSoles && void 0 !== Ae.listSoles && Ae.listSoles !== Ae.offerSoles ? N(Ae.listSoles) : ""), Ve = d + h - q, He = Number(W?.solesOffer?.offsetX || 0), qe = Number(W?.solesOffer?.offsetY || 0), Ke = Number(W?.solesList?.offsetX || 0), Qe = Number(W?.solesList?.offsetY || 0), Je = S(W?.solesOffer?.color) || "#FFFFFF", Ze = S(W?.solesOffer?.discountColor) || CARD_SOLES_DISCOUNT_COLOR, _e = We ? Ze : Je, et = S(W?.solesList?.color) || CARD_STRIKE_TEXT_COLOR, tt = x(Number(W?.solesOffer?.opacity ?? 1), 0, 1), ot = x(Number(W?.solesList?.opacity ?? CARD_STRIKE_TEXT_OPACITY), 0, 1), at = Number(W?.solesOffer?.rotation || 0), lt = Number(W?.solesList?.rotation || 0);
      u.font = HN(Me);
      const it = Ge ? u.measureText(String(Ge)) : null, mt = solesPricing ? We : We || Ge || "S/ 0";
      u.font = HN(Be);
      const gt = mt ? u.measureText(String(mt)) : null, wOf = it ? it.width : 0, wLs = We && gt ? gt.width : 0, rowGap = wLs > 0 ? CARD_PRICE_ROW_GAP * V : 0, blockL = Ve - (wOf + wLs + rowGap), nt = blockL + wLs + rowGap + He, st = Te + qe, ct = blockL + Ke, dt = gt && Number.isFinite(gt.actualBoundingBoxAscent) ? gt.actualBoundingBoxAscent : Be, ft = gt && Number.isFinite(gt.actualBoundingBoxDescent) ? gt.actualBoundingBoxDescent : 0, pt = Te + Qe;
      if (Ie = !1 !== W?.solesOffer?.visible && !w?.noPricing, Ge) {
        const e = u.globalAlpha;
        u.globalAlpha = e * tt;
        const t = r(u, {
          text: Ge,
          x: nt,
          y: st,
          fontSize: Me,
          font: HN(Me),
          fillStyle: _e,
          align: "left",
          vAnchor: "bottom",
          rotation: at,
          draw: Ie
        });
        t && (be = t), u.globalAlpha = e;
      }
      if (ve = !1 !== W?.solesList?.visible, mt) {
        const e = !!We && ve, t = u.globalAlpha;
        u.globalAlpha = t * ot;
        const o = r(u, {
          text: mt,
          x: ct,
          y: pt,
          fontSize: Be,
          font: HN(Be),
          fillStyle: et,
          align: "left",
          vAnchor: "bottom",
          rotation: lt,
          draw: e
        });
        o && (he = o, e && n(u, {
          x: ct,
          y: pt - (dt + ft),
          w: gt ? gt.width : 0,
          h: dt + ft,
          thickness: CARD_STRIKE_BAR_THICKNESS * V,
          opacity: CARD_STRIKE_BAR_OPACITY,
          angleDeg: CARD_STRIKE_BAR_ANGLE_DEG,
          paddingX: CARD_STRIKE_BAR_PADDING_X * V,
          rotation: lt,
          originX: ct,
          originY: pt
        })), u.globalAlpha = t;
      }
      const bt = Math.max(1, (h - 2 * q) * x(Number(W?.name?.maxWidth ?? 1), .35, 1)), ht = x(Number(W?.name?.fontSize ?? (Math.round(Number(W?.card?.widthUnits || 1)) > 1 ? CARD_TITLE_FONT_SIZE * CARD_TITLE_WIDE_SCALE : CARD_TITLE_FONT_SIZE)), 10, 200) * V, yt = x(Number(W?.name?.lineHeight ?? CARD_TITLE_LINE_HEIGHT), .6, 1.8), xt = (e => "small" === e || "big" === e ? e : "shop")(String(W?.name?.typography || "").trim().toLowerCase());
      u.font = "small" === xt ? B(ht) : "big" === xt ? D(ht) : HN(ht);
      const St = s(u, le, bt).slice(0, CARD_TITLE_MAX_LINES), wt = ht * yt, Nt = St.length > 0 ? ht + (St.length - 1) * wt : 0, It = S(W?.name?.color) || "#FFFFFF", vt = x(Number(W?.name?.opacity ?? 1), 0, 1), Ct = Number(W?.name?.rotation || 0) * Math.PI / 180, At = d + q + Number(W?.name?.offsetX || 0), Ot = Te - Math.max(Fe, ze, Me, We ? Be : 0) - CARD_TITLE_PRICING_GAP * V + Number(W?.name?.offsetY || 0) - Nt;
      if (we = !1 !== W?.name?.visible, St.length > 0) {
        if (we) {
          const e = u.globalAlpha;
          u.globalAlpha = e * vt, u.fillStyle = It, u.textAlign = "left", u.textBaseline = "top", 
          u.translate(At, Ot), Ct && u.rotate(Ct);
          for (let e = 0; e < St.length; e += 1) u.fillText(St[e], 0, e * wt);
          !J && re ? u.setTransform(ne) : re && me ? u.setTransform(me) : u.setTransform(ne), 
          u.globalAlpha = e;
        }
        let e = 0;
        for (let t = 0; t < St.length; t++) {
          const o = u.measureText(St[t]).width;
          o > e && (e = o);
        }
        de = T({
          x: At,
          y: Ot,
          w: e,
          h: Nt
        }, Ct, At, Ot);
      }
      if (w?.newBannerText) {
        const e = W?.banner || {};
        ye = ((e, {nameX: t, nameY: o, bannerText: a, bannerVariant: bv, layoutScale: r, customStyle: n}) => {
          const s = n || {};
          if (!1 === s.visible) return null;
          const i = String(s.content || "").trim().toUpperCase() || a;
          if (!i) return null;
          const _defBg = "offer" === bv ? "#FFFFFF" : "#FFFF11", _defTxt = "#000000";
          const c = Math.round(32 * r), m = Math.round(18 * r), f = Math.round(16 * r), u = Math.round(30 * r), g = S(s.bgColor) || _defBg, d = x(Number(s.bgOpacity ?? 1), 0, 1), p = S(s.textColor) || _defTxt, b = x(Number(s.textOpacity ?? 1), 0, 1), h = Number(s.rotation || 0) * Math.PI / 180, y = Number(s.offsetX || 0), w = Number(s.offsetY || 0);
          e.save();
          try {
            e.font = `italic 800 ${Math.round(c)}px "Burbank Small", system-ui, sans-serif`;
            const a = 1.25 * e.measureText(i).width, n = a + 2 * m, s = c + 2 * f, x = s / 2, S = t + y, N = o - u - s + w;
            h && (e.translate(S + n / 2, N + s / 2), e.rotate(h), e.translate(-(S + n / 2), -(N + s / 2)));
            const I = e.globalAlpha;
            e.save();
            try {
              e.globalAlpha = I * d, e.fillStyle = g, l(e, S, N, n, s, x), e.fill();
            } finally {
              e.restore();
            }
            const v = S + m + a / 2, C = N + s / 2 + Math.round(2 * r);
            e.save();
            try {
              e.globalAlpha = I * b, e.translate(v, C), e.scale(1.25, .88), e.fillStyle = p, e.textAlign = "center", 
              e.textBaseline = "middle", e.fillText(i, 0, 0);
            } finally {
              e.restore();
            }
            return {
              x: S,
              y: N,
              w: n,
              h: s
            };
          } finally {
            e.restore();
          }
        })(u, {
          nameX: At,
          nameY: Ot,
          bannerText: w.newBannerText,
          bannerVariant: w.bannerVariant || "info",
          layoutScale: V,
          customStyle: e
        });
      }
      {
        const e = w?.leavingDateIso || null, t = z(e), o = W?.outDate || {};
        (t || String(o.content || "").trim()) && (xe = j(u, {
          cardX: d,
          cardY: b,
          dateText: t || "",
          layoutScale: V,
          urgent: $(e),
          customStyle: o
        }));
      }
      w?.badges && drawViaCuentaBadges(u, {
        cardX: d,
        cardY: b,
        cardW: h,
        layoutScale: V,
        badges: w.badges
      });
    } finally {
      u.restore();
    }
    return Se || (ge = null), we || (de = null), Ne || (pe = null), Ie || (be = null), 
    ve || (he = null), {
      card: re ? F(ue, ae, le, Z, _, !0) : ue,
      image: J && re ? F(ge, ae, le, Z, _, !0) : ge,
      name: J && re ? F(de, ae, le, Z, _, !0) : de,
      price: J && re ? F(pe, ae, le, Z, _, !0) : pe,
      solesOffer: J && re ? F(be, ae, le, Z, _, !0) : be,
      solesList: J && re ? F(he, ae, le, Z, _, !0) : he,
      banner: J && re ? F(ye, ae, le, Z, _, !0) : ye,
      outDate: J && re ? F(xe, ae, le, Z, _, !0) : xe
    };
  };
  let ue = null, ge = null, de = new Map, pe = new Map, be = {
    sectionStyles: {},
    baseMap: {}
  };
  const he = (e, t) => {
    const o = e !== ue;
    o && (ue = e, de = new Map(e.map(e => [ String(e?.offerId || ""), e ])), pe = h(e, {
      fallbackColors: u
    })), (o || t !== ge) && (ge = t, be = Object.freeze({
      sectionStyles: t || {},
      baseMap: pe
    }));
  }, ye = (e, t) => {
    if (t) {
      e.save();
      try {
        e.lineWidth = 4, e.strokeStyle = "rgba(255,255,255,0.32)", e.setLineDash([ 10, 8 ]), 
        l(e, t.x - 12, t.y - 12, t.w + 24, t.h + 24, 18), e.stroke();
      } finally {
        e.restore();
      }
    }
  };
  return Object.freeze({
    computeLayout: J,
    parseSelectionIndex: (selection, items) => parseCollageSelection(selection, items)?.index ?? null,
    drawScene: async (e, t, {layout: o, token: a, forExport: l, excludeIndex: r, selectedElementOverride: n, recordHitBoxesOverride: s, includeSelectionOverride: i, clipTopY: c, clipBottomY: m} = {}) => {
      const f = S(t?.collage?.background) || d, u = A(t), g = Rr(t?.template?.conversionRate), b = t?.template?.collageDiscount ?? t?.template?.discount, h = Array.isArray(t?.collage?.items) ? t.collage.items : [], N = Array.isArray(t?.shop?.items) ? t.shop.items : [];
      he(N, t?.collage?.sectionStyles);
      const I = de, v = be, C = "string" == typeof n ? n : String(t?.ui?.selectedElement || "").trim(), O = "boolean" == typeof i ? i : !l && (C.startsWith("collage:") || C.startsWith("card:")), T = "boolean" == typeof s ? s : !l, F = {}, M = [], B = T ? ne(h) : null, L = "number" == typeof c && "number" == typeof m;
      e.save();
      try {
        const n = !(L || l || e !== R || !U || !P), s = () => {
          n && (U.setTransform(1, 0, 0, 1, 0, 0), U.globalAlpha = 1, U.globalCompositeOperation = "source-over", 
          U.imageSmoothingEnabled = !0, U.imageSmoothingQuality = "high", U.clearRect(0, 0, E.width, E.height), 
          U.drawImage(P, 0, 0));
        }, i = "undefined" != typeof performance && "function" == typeof performance.now, d = 10;
        let S = i ? performance.now() : 0;
        const N = "function" == typeof e.getTransform ? e.getTransform() : null;
        if (N && e.canvas ? (e.setTransform(1, 0, 0, 1, 0, 0), e.clearRect(0, 0, e.canvas.width, e.canvas.height), 
        e.setTransform(N.a, N.b, N.c, N.d, N.e, N.f)) : e.clearRect(0, 0, o.width, o.height), 
        e.globalAlpha = 1, e.globalCompositeOperation = "source-over", e.filter = "none", 
        e.shadowColor = "rgba(0,0,0,0)", e.shadowBlur = 0, e.shadowOffsetX = 0, e.shadowOffsetY = 0, 
        w(f) || (e.fillStyle = f, e.fillRect(0, 0, o.width, o.height)), u.backgroundImageUrl) {
          const t = l ? await p.load(u.backgroundImageUrl) : p.request(u.backgroundImageUrl);
          if (!V(a)) return null;
          if (t) {
            e.save();
            try {
              e.globalAlpha = 1, q(e, t, 0, 0, o.width, o.height);
            } finally {
              e.restore();
            }
          }
        }
        if (e.imageSmoothingEnabled = !0, e.imageSmoothingQuality = "high", o.sectionName) {
          const t = o.sectionName.toUpperCase(), a = o.titleStyle || {}, l = o.titleFontSize, r = String(a.color || "#FFFFFF"), n = x(Number(a.opacity ?? 1), 0, 1), s = Number(a.rotation || 0), i = Number(a.offsetX || 0), c = Number(a.offsetY || 0), m = o.width / 2 + i, f = o.padding + c;
          e.save();
          try {
            e.font = D(l), e.fillStyle = r, e.globalAlpha = n, e.textAlign = "center", e.textBaseline = "top", 
            s ? (e.translate(m, f + l / 2), e.rotate(s * Math.PI / 180), e.fillText(t, 0, -l / 2)) : e.fillText(t, m, f);
          } finally {
            e.restore();
          }
          if (T) {
            const o = e.font;
            e.font = D(l);
            const a = e.measureText(t).width;
            F["collage:title"] = {
              x: m - a / 2,
              y: f,
              w: a,
              h: l
            }, e.font = o;
          }
        }
        const A = Z(h, o), j = "number" == typeof r ? Math.max(0, Math.floor(r)) : -1, k = L && Z.getVisibleIndices ? Z.getVisibleIndices(c, m) : null, z = k ? k.length : A.length;
        for (let t = 0; t < z; t += 1) {
          const r = k ? k[t] : t, f = A[r];
          if (!f) continue;
          const {x: p, y: w, w: N, h: C, st: O, normalized: D} = f, z = x(Number(O?.card?.scale ?? 1), .5, 2);
          if (L && (w + C * z < c || w - CARD_IMAGE_TOP_OVERFLOW_RATIO * C > m)) continue;
          if (r === j) continue;
          const X = resolveCardSubject(D, I), R = y(D.layoutId || D.layoutName || X.layoutId || X.layoutName || ""), P = await fe(e, {
            x: p,
            y: w,
            w: N,
            h: C,
            item: X,
            style: O,
            discount: D.discount,
            globalDiscount: b,
            conversionRate: g,
            token: a,
            forExport: l,
            baseCardW: o.cardW,
            globalDesign: u,
            layoutId: R,
            sectionContext: v
          });
          if (T && P && B && B[r]) {
            const e = B[r];
            F[e.card] = P.card, P.image && (F[e.image] = P.image), P.name && (F[e.name] = P.name), 
            P.price && (F[e.price] = P.price), P.solesOffer && (F[e.solesOffer] = P.solesOffer), 
            P.solesList && (F[e.solesList] = P.solesList), P.banner && (F[e.banner] = P.banner), 
            P.outDate && (F[e.outdate] = P.outDate), M.push({
              idx: r,
              cardId: e.card,
              imageId: e.image,
              nameId: e.name,
              priceId: e.price,
              solesOfferId: e.solesOffer,
              solesListId: e.solesList,
              bannerId: e.banner,
              outDateId: e.outdate
            });
          }
          if (!V(a)) return null;
          if (n && i && performance.now() - S > d) {
            if (s(), void 0 !== globalThis.scheduler && "function" == typeof globalThis.scheduler.yield ? await globalThis.scheduler.yield() : await new Promise(e => setTimeout(e, 0)), 
            !V(a)) return null;
            S = performance.now();
          }
        }
        const $ = t?.collage?.branding;
        if ($) {
          const t = o.height - o.padding;
          if (!L || t < m && o.height > c) {
            const t = H(e, o, $);
            T && t && (F["collage:branding"] = t);
          }
        }
        if (T && (F.collageGroups = M), O) {
          const selectedKey = resolveSelectionHitKey(C, F, h);
          selectedKey && ye(e, F[selectedKey]);
        }
        G && "number" == typeof a && V(a) && G("");
      } finally {
        e.restore();
      }
      return T ? {
        hitBoxes: F,
        collageGroups: M
      } : null;
    },
    drawItemAtIndex: async (e, t, {layout: o, token: a, forExport: l, index: r} = {}) => {
      const n = Array.isArray(t?.collage?.items) ? t.collage.items : [], s = A(t), i = Rr(t?.template?.conversionRate), c = t?.template?.collageDiscount ?? t?.template?.discount, m = Math.max(0, Math.floor(Number(r) || 0));
      if (m < 0 || m >= n.length) return null;
      const g = Array.isArray(t?.shop?.items) ? t.shop.items : [];
      he(g, t?.collage?.sectionStyles);
      const p = Z(n, o)[m];
      if (!p) return null;
      const {x: b, y: h, w: x, h: S, st: w, normalized: N} = p, d = resolveCardSubject(N, de), I = y(N.layoutId || N.layoutName || d.layoutId || d.layoutName || ""), v = await fe(e, {
        x: b,
        y: h,
        w: x,
        h: S,
        item: d,
        style: w,
        discount: N.discount,
        globalDiscount: c,
        conversionRate: i,
        token: a,
        forExport: l,
        baseCardW: o.cardW,
        globalDesign: s,
        layoutId: I,
        sectionContext: be
      }), C = {}, O = m, T = ne(n)[O] || buildCardHitIds(n, O);
      return v?.card && (C[T.card] = v.card), v?.image && (C[T.image] = v.image), v?.name && (C[T.name] = v.name), 
      v?.price && (C[T.price] = v.price), v?.solesOffer && (C[T.solesOffer] = v.solesOffer), 
      v?.solesList && (C[T.solesList] = v.solesList), v?.banner && (C[T.banner] = v.banner), 
      v?.outDate && (C[T.outdate] = v.outDate), {
        hitBoxes: C,
        group: {
          idx: O,
          cardId: T.card,
          imageId: T.image,
          nameId: T.name,
          priceId: T.price,
          solesOfferId: T.solesOffer,
          solesListId: T.solesList,
          bannerId: T.banner,
          outDateId: T.outdate
        }
      };
    },
    drawSelectionOutline: ye,
    normalizeCollageItemStyle: e => b(e),
    resolveCollageGlobalDesign: A,
    resolveCollageCardGradientColors: ee,
    resolveCollageCardAccentSpec: oe,
    computeGradientStops: m,
    drawImageCover: q,
    formatSoles: N,
    tokenOk: V,
    ensureMemoized: he,
    getByOfferId: () => de
  });
}