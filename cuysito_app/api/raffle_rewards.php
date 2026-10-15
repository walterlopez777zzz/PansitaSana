<?php
/**
 * ============================================================================
 * raffle_rewards.php — Shared reward / valuation / Provably-Fair helpers
 * ============================================================================
 * Single source of truth for the money side of the Sorteo Flash:
 *   - resolving a prize's base value in soles (skins via shop cache, static
 *     products via DB),
 *   - the coins (Bananos) <-> soles conversion,
 *   - cashback amounts,
 *   - the DOBLE O NADA payout (original prize + Bananos equivalent),
 *   - OFERTA prizes (discount_percent < 100): valued at the coupon's worth and
 *     delivered as a personal coupon in daily_discounts (never as a free order),
 *   - the canonical Provably-Fair outcome.
 *
 * It is included by raffle_core.php, raffle_gamble.php and (transitively) the
 * cron, so every code path computes the SAME numbers and the SAME gamble result.
 *
 * NOTE: This file purposely defines ONLY rr_* helpers (all guarded with
 * function_exists). It calls deliverPrize()/findProductInCache(), which the
 * including file already provides (raffle_core.php and raffle_gamble.php each
 * declare their own). That keeps it includable from both without redeclaration.
 */

require_once __DIR__ . '/logic_discounts.php';
require_once dirname(__DIR__) . '/core/ShopRepository.php';

// =================================================================================
// HELPER: Look up a Static Product (productos_estaticos) by its numeric id.
// Returns the row (assoc) or null. Used to resolve image + base price for
// "product" prizes, which live in the DB (not the Fortnite shop cache).
// =================================================================================
if (!function_exists('rr_get_static_product')) {
    function rr_get_static_product($conn, $id) {
        $id = (int)$id;
        if ($id <= 0) return null;
        $chk = $conn->query("SHOW TABLES LIKE 'productos_estaticos'");
        if (!$chk || $chk->num_rows === 0) return null;
        $stmt = $conn->prepare("SELECT id, nombre, imagen_url, precio_base, precio_oferta, tipo_oferta, oferta_fecha_expiracion FROM productos_estaticos WHERE id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

// =================================================================================
// HELPER: Resolve the prize base value in soles (S/.) for a non-coins prize.
// Order of resolution (first hit wins):
//   1. prize_config snapshot fields (base_price_soles / precio_base_soles / price_soles)
//   2. Fortnite shop cache by offerId (skins)
//   3. Static product row by id (productos_estaticos: precio_oferta vigente o precio_base)
// Returns float soles (0.0 if it cannot be determined).
// =================================================================================
if (!function_exists('rr_prize_base_soles')) {
    function rr_prize_base_soles($conn, $prizeConfig, $cacheFile) {
        if (!is_array($prizeConfig)) return 0.0;

        $base = 0.0;
        if (!empty($prizeConfig['base_price_soles']))   $base = (float)$prizeConfig['base_price_soles'];
        elseif (!empty($prizeConfig['precio_base_soles'])) $base = (float)$prizeConfig['precio_base_soles'];
        elseif (!empty($prizeConfig['price_soles']))    $base = (float)$prizeConfig['price_soles'];
        if ($base > 0) return $base;

        $id = $prizeConfig['id'] ?? '';
        if ($id === '' || $id === null) return 0.0;

        // Skins / shop items keyed by offerId
        $cached = findProductInCache($id, $cacheFile);
        if ($cached) {
            if (isset($cached['precio_base_soles']))      return (float)$cached['precio_base_soles'];
            if (isset($cached['price']['finalPrice']))    return (float)$cached['price']['finalPrice'];
            if (isset($cached['price']) && is_numeric($cached['price'])) return (float)$cached['price'];
        }

        // Static products live in the DB. Use the active offer price if present.
        if (ctype_digit((string)$id)) {
            $sp = rr_get_static_product($conn, (int)$id);
            if ($sp) {
                $offer = isset($sp['precio_oferta']) ? (float)$sp['precio_oferta'] : 0.0;
                if ($offer > 0) return $offer;
                return isset($sp['precio_base']) ? (float)$sp['precio_base'] : 0.0;
            }
        }

        return 0.0;
    }
}

// =================================================================================
// HELPER: Read the coins<->money conversion rate from site_settings.
// Returns ['unit_coins' => float, 'unit_money' => float] with safe defaults
// (100 coins ≈ S/.1.30), identical to checkout/process.php.
// =================================================================================
if (!function_exists('rr_coins_unit_rates')) {
    function rr_coins_unit_rates($conn) {
        $unitCoins = 100.0;
        $unitMoney = 1.30;
        $res = $conn->query("SELECT key_name, value FROM site_settings WHERE key_name IN ('coins_unit_coins', 'coins_unit_money')");
        if ($res) {
            $settings = [];
            while ($row = $res->fetch_assoc()) $settings[$row['key_name']] = $row['value'];
            if (isset($settings['coins_unit_coins']) && (float)$settings['coins_unit_coins'] > 0) $unitCoins = (float)$settings['coins_unit_coins'];
            if (isset($settings['coins_unit_money']) && (float)$settings['coins_unit_money'] > 0) $unitMoney = (float)$settings['coins_unit_money'];
        }
        return ['unit_coins' => $unitCoins, 'unit_money' => $unitMoney];
    }
}

// =================================================================================
// HELPER: Convert a soles amount into coins (Bananos) using the site rate.
//   coins = (soles / unit_money) * unit_coins
// =================================================================================
if (!function_exists('rr_soles_to_coins')) {
    function rr_soles_to_coins($conn, $soles) {
        $soles = (float)$soles;
        if ($soles <= 0) return 0.0;
        $rates = rr_coins_unit_rates($conn);
        return round(($soles / $rates['unit_money']) * $rates['unit_coins'], 2);
    }
}

// =================================================================================
// HELPER: Native V-Bucks (pavos) value of a prize.
// Skins / shop items carry a native price in pavos where 1 pavo = 1 Banano, so it
// must NOT go through the soles<->coins fiat conversion. Order of resolution:
//   1. prize_config snapshot ('vbucks')
//   2. Fortnite shop cache by offerId ('vbucks')
// Returns int pavos (0 if it is not a pavos-priced prize, e.g. a static product).
// =================================================================================
if (!function_exists('rr_prize_vbucks')) {
    function rr_prize_vbucks($conn, $prizeConfig, $cacheFile) {
        if (!is_array($prizeConfig)) return 0;
        if (!empty($prizeConfig['vbucks'])) return (int)$prizeConfig['vbucks'];

        $id = $prizeConfig['id'] ?? '';
        if ($id === '' || $id === null) return 0;

        $cached = findProductInCache($id, $cacheFile);
        if ($cached && !empty($cached['vbucks'])) return (int)$cached['vbucks'];

        return 0;
    }
}

// =================================================================================
// HELPER: OFERTA prizes. A skin / product prize configured with discount_percent
// between 1 and 99 is NOT the item itself but a personal coupon for that item.
// Returns the percent (int) or 0 when the prize is a full (free) item / not an
// item at all (coins, pools, combos, custom, category).
// =================================================================================
if (!function_exists('rr_prize_discount_percent')) {
    function rr_prize_discount_percent($prizeConfig) {
        if (!is_array($prizeConfig)) return 0;
        $kind = (string)($prizeConfig['type'] ?? $prizeConfig['kind'] ?? '');
        if (!in_array($kind, ['skin', 'product', 'catalog'], true)) return 0;
        if (!isset($prizeConfig['discount_percent']) || !is_numeric($prizeConfig['discount_percent'])) return 0;
        $pct = (int)round((float)$prizeConfig['discount_percent']);
        return ($pct >= 1 && $pct <= 99) ? $pct : 0;
    }
}

// =================================================================================
// HELPER: Canonical value of a prize expressed in coins (Bananos).
// Single source of truth for the Doble o Nada bonus and the Cashback base, so the
// dynamic texts and the real payout always agree. For OFERTA prizes the value is
// the coupon's worth (full item value × discount_percent), e.g. a 50% coupon on
// a 1500-pavos skin is worth 750 Bananos → cashback 50% = 375, Doble o Nada
// bonus = 750. Returns float coins (0.0 if it cannot be valued).
// =================================================================================
if (!function_exists('rr_prize_value_coins')) {
    function rr_prize_value_coins($conn, $prizeConfig, $cacheFile) {
        $full = rr_prize_full_value_coins($conn, $prizeConfig, $cacheFile);
        $pct = rr_prize_discount_percent($prizeConfig);
        if ($pct <= 0 || $full <= 0) return $full;
        return round($full * $pct / 100, 2);
    }
}

// =================================================================================
// HELPER: FULL value of a prize in coins (Bananos), ignoring discount_percent.
// Branching:
//   1. coins prize          → the coin amount itself.
//   2. skin / shop item      → its native pavos value (1 pavo = 1 Banano), NO fiat
//                              conversion (fixes the inflated bonus, e.g. a 1500
//                              pavos skin must give 1500 Bananos, not 1615).
//   3. static product (soles)→ inverse fiat conversion soles → coins
//                              (e.g. S/.13 → 1000 Bananos at 100 ≈ S/.1.30).
// Combo items / pool options are valued through rr_prize_value_coins(), so an
// OFERTA component inside a pack counts at its coupon worth.
// =================================================================================
if (!function_exists('rr_prize_full_value_coins')) {
    function rr_prize_full_value_coins($conn, $prizeConfig, $cacheFile) {
        if (!is_array($prizeConfig)) return 0.0;

        if (($prizeConfig['type'] ?? '') === 'coins') {
            return round((float)($prizeConfig['amount'] ?? 0), 2);
        }

        // Combo → sum of its items (each fixed item at its own value, each
        // pool at its max), so cashback / Doble o Nada cover the whole pack.
        if (($prizeConfig['type'] ?? '') === 'combo') {
            $total = 0.0;
            foreach ((is_array($prizeConfig['items'] ?? null) ? $prizeConfig['items'] : []) as $item) {
                $total += rr_prize_value_coins($conn, $item, $cacheFile);
            }
            return round($total, 2);
        }

        // Unselected pool → value it by the MAX of its options (transparent
        // upper bound for cashback / Doble o Nada texts and payouts).
        if (($prizeConfig['type'] ?? '') === 'pool') {
            $max = 0.0;
            foreach (($prizeConfig['options'] ?? []) as $opt) {
                $v = rr_option_value_coins($conn, $opt, $cacheFile);
                if ($v > $max) $max = $v;
            }
            return $max;
        }

        // Custom / category prizes (and any option with an explicit admin
        // reference value) are valued by their mandatory value_ref.
        if (isset($prizeConfig['value_ref']) && is_array($prizeConfig['value_ref'])) {
            $v = rr_value_ref_coins($conn, $prizeConfig['value_ref']);
            if ($v > 0) return $v;
        }

        $vbucks = rr_prize_vbucks($conn, $prizeConfig, $cacheFile);
        if ($vbucks > 0) return (float)$vbucks;

        $baseSoles = rr_prize_base_soles($conn, $prizeConfig, $cacheFile);
        if ($baseSoles > 0) return rr_soles_to_coins($conn, $baseSoles);

        return 0.0;
    }
}

// =================================================================================
// HELPER: Category presets for pool prizes ("Skin", "Gesto", "Pista musical"...).
// Each preset carries a default reference value in pavos (1 pavo = 1 Banano) so
// cashback / Doble o Nada always have a transparent standard valuation even for
// prizes resolved later by the admin. The admin can override the value (and the
// image) per raffle when building the pool.
// =================================================================================
if (!function_exists('rr_category_presets')) {
    function rr_category_presets() {
        return [
            ['key' => 'skin',      'label' => 'Skin',              'categories' => ['Atuendo'],           'vbucks' => 1500],
            ['key' => 'gesto',     'label' => 'Gesto',             'categories' => ['Gesto'],             'vbucks' => 500],
            ['key' => 'pista',     'label' => 'Pista musical',     'categories' => ['Pista de improvisación'], 'vbucks' => 500],
            ['key' => 'lote',      'label' => 'Lote',              'categories' => ['Lote'],              'vbucks' => 2500],
            ['key' => 'pico',      'label' => 'Pico',              'categories' => ['Pico'],              'vbucks' => 800],
            ['key' => 'mochila',   'label' => 'Mochila',           'categories' => ['Mochila'],           'vbucks' => 800],
            ['key' => 'ala',       'label' => 'Ala delta',         'categories' => ['Ala delta'],         'vbucks' => 800],
            ['key' => 'envoltorio','label' => 'Envoltorio',        'categories' => ['Envoltorio'],        'vbucks' => 500],
            ['key' => 'zapatos',   'label' => 'Zapatos',           'categories' => ['Zapatos'],           'vbucks' => 1000],
            ['key' => 'compinche', 'label' => 'Compinche',         'categories' => ['Compinche'],         'vbucks' => 1200],
            ['key' => 'pantalla',  'label' => 'Pantalla de carga', 'categories' => ['Pantalla de carga'], 'vbucks' => 300],
            ['key' => 'estela',    'label' => 'Estela',            'categories' => ['Estela'],            'vbucks' => 500],
        ];
    }
}

// =================================================================================
// HELPER: Normalize any stored prize_config into Schema 2:
//   { "schema": 2, "positions": [ { "position": N, "prize": {...} }, ... ] }
// A prize is either:
//   { "mode": "fixed", "option": {<option>} }
//   { "mode": "pool",  "label": "...", "options": [<option>, ...] }
// Options carry "kind": coins | catalog | product | custom | category.
// Legacy (v1) configs — a single {type: coins|skin|product, ...} — are wrapped
// as a fixed prize replicated for every position. Never mutates stored rows.
// =================================================================================
if (!function_exists('rr_normalize_prizes')) {
    function rr_normalize_prizes($prizeConfig, $winnersCount = 1) {
        $winnersCount = max(1, (int)$winnersCount);
        if (is_string($prizeConfig)) $prizeConfig = json_decode($prizeConfig, true);
        if (!is_array($prizeConfig)) $prizeConfig = [];

        if ((int)($prizeConfig['schema'] ?? 0) === 2 && is_array($prizeConfig['positions'] ?? null)) {
            $positions = [];
            foreach ($prizeConfig['positions'] as $entry) {
                if (!is_array($entry)) continue;
                $pos = (int)($entry['position'] ?? 0);
                if ($pos <= 0 || !is_array($entry['prize'] ?? null)) continue;
                $positions[$pos] = ['position' => $pos, 'prize' => $entry['prize']];
            }
            ksort($positions);
            return ['schema' => 2, 'positions' => array_values($positions)];
        }

        // Legacy v1: single prize config shared by all positions.
        $legacyOption = rr_legacy_config_to_option($prizeConfig);
        $positions = [];
        for ($i = 1; $i <= $winnersCount; $i++) {
            $positions[] = ['position' => $i, 'prize' => ['mode' => 'fixed', 'option' => $legacyOption]];
        }
        return ['schema' => 2, 'positions' => $positions, 'legacy' => true];
    }
}

// =================================================================================
// HELPER: Convert a legacy v1 prize_config into a Schema-2 option.
// =================================================================================
if (!function_exists('rr_legacy_config_to_option')) {
    function rr_legacy_config_to_option($prizeConfig) {
        if (!is_array($prizeConfig)) $prizeConfig = [];
        $type = (string)($prizeConfig['type'] ?? '');
        if ($type === 'coins') {
            return [
                'kind'   => 'coins',
                'amount' => (float)($prizeConfig['amount'] ?? 0),
                'name'   => (string)($prizeConfig['name'] ?? (number_format((float)($prizeConfig['amount'] ?? 0)) . ' Monedas')),
            ];
        }
        $option = [
            'kind'  => ($type === 'product') ? 'product' : 'catalog',
            'id'    => (string)($prizeConfig['id'] ?? ''),
            'name'  => (string)($prizeConfig['name'] ?? 'Premio'),
            'image' => (string)($prizeConfig['image'] ?? ''),
        ];
        foreach (['image_icon', 'vbucks', 'rarity', 'colors', 'displayType', 'base_price_soles', 'discount_percent'] as $k) {
            if (isset($prizeConfig[$k])) $option[$k] = $prizeConfig[$k];
        }
        return $option;
    }
}

// =================================================================================
// HELPER: Convert a Schema-2 option into a legacy-shaped deliverable prize config
// (what createOrderHelper / deliverPrize / the valuation helpers expect).
// =================================================================================
if (!function_exists('rr_option_to_prize_config')) {
    function rr_option_to_prize_config($option) {
        if (!is_array($option)) return [];
        $kind = (string)($option['kind'] ?? '');
        if ($kind === 'coins') {
            $amount = (float)($option['amount'] ?? 0);
            return ['type' => 'coins', 'amount' => $amount, 'name' => (string)($option['name'] ?? (number_format($amount) . ' Monedas'))];
        }
        if ($kind === 'custom') {
            $cfg = [
                'type'  => 'custom',
                'id'    => 'custom-' . (string)($option['custom_id'] ?? uniqid()),
                'name'  => (string)($option['name'] ?? 'Premio personalizado'),
                'image' => (string)($option['image'] ?? ''),
            ];
            if (isset($option['description'])) $cfg['description'] = $option['description'];
            if (isset($option['value_ref'])) $cfg['value_ref'] = $option['value_ref'];
            return $cfg;
        }
        if ($kind === 'category') {
            $cfg = [
                'type'  => 'category',
                'id'    => 'category-' . (string)($option['preset_key'] ?? 'custom'),
                'name'  => (string)($option['label'] ?? ($option['name'] ?? 'Premio por categoría')),
                'image' => (string)($option['image'] ?? ''),
            ];
            if (isset($option['categories'])) $cfg['categories'] = $option['categories'];
            if (isset($option['value_ref'])) $cfg['value_ref'] = $option['value_ref'];
            $cfg['admin_resolves'] = true;
            return $cfg;
        }
        // catalog / product
        $cfg = [
            'type'  => ($kind === 'product') ? 'product' : 'skin',
            'id'    => (string)($option['id'] ?? ''),
            'name'  => (string)($option['name'] ?? 'Premio'),
            'image' => (string)($option['image'] ?? ''),
        ];
        foreach (['image_icon', 'vbucks', 'rarity', 'colors', 'displayType', 'base_price_soles', 'discount_percent', 'value_ref'] as $k) {
            if (isset($option[$k])) $cfg[$k] = $option[$k];
        }
        return $cfg;
    }
}

// =================================================================================
// HELPER: Resolve the effective prize config for a winner position.
// Returns a deliverable/valuable prize config array:
//   - fixed prize  → legacy-shaped config (type coins/skin/product/custom/category)
//   - pool prize   → ['type' => 'pool', 'label' => ..., 'options' => [...]] where
//     each option keeps its Schema-2 shape (use rr_option_to_prize_config on the
//     chosen one at selection time).
// Works with both legacy v1 configs and Schema-2 configs.
// =================================================================================
if (!function_exists('rr_prize_for_position')) {
    function rr_prize_for_position($prizeConfig, $position) {
        if (is_string($prizeConfig)) $prizeConfig = json_decode($prizeConfig, true);
        if (!is_array($prizeConfig)) return [];
        $position = max(1, (int)$position);

        if ((int)($prizeConfig['schema'] ?? 0) !== 2) {
            // Legacy v1: same prize for every position.
            return $prizeConfig;
        }

        $match = null;
        $first = null;
        foreach (($prizeConfig['positions'] ?? []) as $entry) {
            if (!is_array($entry) || !is_array($entry['prize'] ?? null)) continue;
            if ($first === null) $first = $entry['prize'];
            if ((int)($entry['position'] ?? 0) === $position) { $match = $entry['prize']; break; }
        }
        $prize = $match ?? $first;
        if (!is_array($prize)) return [];

        return rr_resolve_prize_entry($prize);
    }
}

// =================================================================================
// HELPER: Resolve a Schema-2 prize entry ({mode: fixed|pool|combo}) into its
// deliverable/valuable config. Combos resolve each item recursively:
//   ['type' => 'combo', 'label' => ..., 'items' => [<resolved item>, ...]]
// =================================================================================
if (!function_exists('rr_resolve_prize_entry')) {
    function rr_resolve_prize_entry($prize) {
        if (!is_array($prize)) return [];
        $mode = (string)($prize['mode'] ?? '');
        if ($mode === 'pool') {
            return [
                'type'    => 'pool',
                'label'   => (string)($prize['label'] ?? 'Elige tu premio'),
                'options' => is_array($prize['options'] ?? null) ? $prize['options'] : [],
            ];
        }
        if ($mode === 'combo') {
            $items = [];
            foreach ((is_array($prize['items'] ?? null) ? $prize['items'] : []) as $item) {
                if (!is_array($item)) continue;
                $resolved = rr_resolve_prize_entry($item);
                if (!empty($resolved)) $items[] = $resolved;
            }
            return [
                'type'  => 'combo',
                'label' => (string)($prize['label'] ?? 'Combo de premios'),
                'items' => $items,
            ];
        }
        return rr_option_to_prize_config($prize['option'] ?? []);
    }
}

// =================================================================================
// HELPER: Is this resolved prize config an unselected pool?
// =================================================================================
if (!function_exists('rr_prize_is_pool')) {
    function rr_prize_is_pool($prizeConfig) {
        return is_array($prizeConfig) && (($prizeConfig['type'] ?? '') === 'pool');
    }
}

// =================================================================================
// HELPER: Is this resolved prize config a combo (multiple rewards, one position)?
// =================================================================================
if (!function_exists('rr_prize_is_combo')) {
    function rr_prize_is_combo($prizeConfig) {
        return is_array($prizeConfig) && (($prizeConfig['type'] ?? '') === 'combo');
    }
}

// =================================================================================
// HELPER: Resolved items of a combo (empty array for non-combos).
// =================================================================================
if (!function_exists('rr_combo_items')) {
    function rr_combo_items($prizeConfig) {
        if (!rr_prize_is_combo($prizeConfig)) return [];
        $items = [];
        foreach ((is_array($prizeConfig['items'] ?? null) ? $prizeConfig['items'] : []) as $item) {
            if (is_array($item)) $items[] = $item;
        }
        return $items;
    }
}

// =================================================================================
// HELPER: The pool a position's prize exposes for selection, if any: the prize
// itself when it is a pool, or the (single) pool item inside a combo. A combo
// can carry at most ONE pool so pre-selections / claims stay keyed by position.
// Returns the pool config or null.
// =================================================================================
if (!function_exists('rr_position_pool')) {
    function rr_position_pool($prizeConfig) {
        if (rr_prize_is_pool($prizeConfig)) return $prizeConfig;
        foreach (rr_combo_items($prizeConfig) as $item) {
            if (rr_prize_is_pool($item)) return $item;
        }
        return null;
    }
}

// =================================================================================
// HELPER: Deliver the FIXED (non-pool) items of a combo: coins are credited
// directly, everything else creates a zero-cost prize order. The pool item (if
// any) is NOT touched here — it goes through the vitrina / AFK selection flow.
// Caller owns the transaction and the raffle_winners state update.
// Returns ['order_id' => int|null (first order), 'order_ids' => [int, ...]].
// =================================================================================
if (!function_exists('rr_deliver_combo_fixed')) {
    function rr_deliver_combo_fixed($conn, $userId, $comboConfig, $cacheFile, $raffleId) {
        $userId = (int)$userId;
        $orderIds = [];
        foreach (rr_combo_items($comboConfig) as $item) {
            if (rr_prize_is_pool($item)) continue;
            if (($item['type'] ?? '') === 'coins') {
                rr_credit_coins($conn, $userId, (float)($item['amount'] ?? 0), 'raffle_win', $raffleId);
                continue;
            }
            $oid = rr_create_prize_order($conn, $userId, $item, $cacheFile, $raffleId, "GANADOR SORTEO #$raffleId (Combo de premios)");
            if ($oid !== null) $orderIds[] = (int)$oid;
        }
        return ['order_id' => $orderIds[0] ?? null, 'order_ids' => $orderIds];
    }
}

// =================================================================================
// HELPER: Public (spectator-safe) view of a resolved position prize. Strips
// internal fields; for pools returns the label plus a light option list so the
// raffle card can render the "vitrina" preview before entering.
// =================================================================================
if (!function_exists('rr_prize_public_view')) {
    function rr_prize_public_view($prizeConfig) {
        if (!is_array($prizeConfig) || empty($prizeConfig)) return null;
        if (($prizeConfig['type'] ?? '') === 'combo') {
            $items = [];
            foreach ((is_array($prizeConfig['items'] ?? null) ? $prizeConfig['items'] : []) as $item) {
                $v = rr_prize_public_view($item);
                if ($v !== null) $items[] = $v;
            }
            return ['type' => 'combo', 'label' => (string)($prizeConfig['label'] ?? 'Combo de premios'), 'items' => $items];
        }
        if (($prizeConfig['type'] ?? '') === 'pool') {
            $options = [];
            foreach (($prizeConfig['options'] ?? []) as $opt) {
                if (!is_array($opt)) continue;
                $options[] = [
                    'kind'      => (string)($opt['kind'] ?? 'catalog'),
                    'name'      => (string)($opt['name'] ?? ($opt['label'] ?? 'Premio')),
                    'amount'    => isset($opt['amount']) ? (float)$opt['amount'] : null,
                    'image'     => (string)($opt['image'] ?? ''),
                    'vbucks'    => isset($opt['vbucks']) ? (int)$opt['vbucks'] : null,
                    'image_icon'  => (string)($opt['image_icon'] ?? ''),
                    // El snapshot guarda rarity como {id,name}; conservamos esa
                    // estructura para que Sorteo Flash replique el badge de tienda.
                    'rarity'      => $opt['rarity'] ?? null,
                    'displayType' => isset($opt['displayType']) ? (string)$opt['displayType'] : null,
                    'colors'    => (isset($opt['colors']) && is_array($opt['colors'])) ? $opt['colors'] : null,
                    'value_ref' => (isset($opt['value_ref']) && is_array($opt['value_ref'])) ? $opt['value_ref'] : null,
                    'option_key'=> (string)($opt['option_key'] ?? ''),
                    'discount_percent' => rr_prize_discount_percent($opt) ?: null,
                ];
            }
            return ['type' => 'pool', 'label' => (string)($prizeConfig['label'] ?? 'Elige tu premio'), 'options' => $options];
        }
        $view = ['type' => (string)($prizeConfig['type'] ?? '')];
        foreach (['name', 'image', 'image_icon', 'amount', 'vbucks', 'rarity', 'colors', 'displayType', 'value_ref'] as $k) {
            if (isset($prizeConfig[$k])) $view[$k] = $prizeConfig[$k];
        }
        $pct = rr_prize_discount_percent($prizeConfig);
        if ($pct > 0) $view['discount_percent'] = $pct;
        return $view;
    }
}

// =================================================================================
// HELPERS: Winner fulfillment contract for public/admin delivery UIs.
// =================================================================================
if (!function_exists('rr_option_requires_manual_delivery')) {
    function rr_option_requires_manual_delivery($option): bool {
        if (!is_array($option) || empty($option)) return false;
        $kind = strtolower(trim((string)($option['kind'] ?? $option['type'] ?? '')));
        if ($kind === 'coins') return false;
        if ($kind === 'combo') {
            foreach ((is_array($option['items'] ?? null) ? $option['items'] : []) as $item) {
                if (rr_prize_requires_manual_delivery($item)) return true;
            }
            return false;
        }
        if ($kind === 'pool') {
            foreach ((is_array($option['options'] ?? null) ? $option['options'] : []) as $poolOption) {
                if (rr_option_requires_manual_delivery($poolOption)) return true;
            }
            return false;
        }
        // OFERTA: se entrega como cupón automático en la tienda, sin envío manual.
        if (rr_prize_discount_percent($option) > 0) return false;
        return in_array($kind, ['catalog', 'skin', 'product', 'custom', 'category'], true);
    }
}

if (!function_exists('rr_prize_requires_manual_delivery')) {
    function rr_prize_requires_manual_delivery($prizeConfig, $finalPrize = null): bool {
        if (is_string($prizeConfig)) $prizeConfig = json_decode($prizeConfig, true);
        if (is_string($finalPrize)) $finalPrize = json_decode($finalPrize, true);

        // A concrete Vitrina/category selection is more authoritative than its pool.
        if (is_array($finalPrize) && !empty($finalPrize)) {
            if (!empty($finalPrize['resolved_item']) && is_array($finalPrize['resolved_item'])) return true;
            return rr_option_requires_manual_delivery($finalPrize);
        }
        if (!is_array($prizeConfig) || empty($prizeConfig)) return false;

        $type = strtolower(trim((string)($prizeConfig['type'] ?? '')));
        if ($type === 'coins') return false;
        if ($type === 'combo') {
            foreach (rr_combo_items($prizeConfig) as $item) {
                if (rr_prize_requires_manual_delivery($item)) return true;
            }
            return false;
        }
        if ($type === 'pool') {
            foreach ((is_array($prizeConfig['options'] ?? null) ? $prizeConfig['options'] : []) as $option) {
                if (rr_option_requires_manual_delivery($option)) return true;
            }
            return false;
        }
        if (rr_prize_discount_percent($prizeConfig) > 0) return false;
        return in_array($type, ['catalog', 'skin', 'product', 'custom', 'category'], true);
    }
}

// Un premio concreto (posición o selección final) que es un cupón de OFERTA.
if (!function_exists('rr_prize_is_offer_coupon')) {
    function rr_prize_is_offer_coupon($prizeConfig, $finalPrize = null): bool {
        if (is_string($prizeConfig)) $prizeConfig = json_decode($prizeConfig, true);
        if (is_string($finalPrize)) $finalPrize = json_decode($finalPrize, true);
        if (is_array($finalPrize) && !empty($finalPrize)) return rr_prize_discount_percent($finalPrize) > 0;
        return rr_prize_discount_percent($prizeConfig) > 0;
    }
}

if (!function_exists('rr_recorded_raffle_credit')) {
    function rr_recorded_raffle_credit(mysqli $conn, int $userId, int $raffleId, string $reason): float {
        if ($userId <= 0 || $raffleId <= 0 || $reason === '') return 0.0;
        $referenceId = (string)$raffleId;
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM coin_transactions
             WHERE user_id = ? AND reason = ? AND reference_type = 'raffle_id' AND reference_id = ?"
        );
        if (!$stmt) return 0.0;
        $stmt->bind_param('iss', $userId, $reason, $referenceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return round((float)($row['total'] ?? 0), 2);
    }
}

if (!function_exists('rr_cashback_credit_view')) {
    function rr_cashback_credit_view(mysqli $conn, int $userId, int $raffleId, $storedAmount, $prizeConfig, $cacheFile, $percent): array {
        if ($storedAmount !== null && $storedAmount !== '') {
            return ['coins' => round((float)$storedAmount, 2), 'source' => 'snapshot'];
        }
        $ledgerAmount = rr_recorded_raffle_credit($conn, $userId, $raffleId, 'raffle_cashback');
        if ($ledgerAmount > 0) return ['coins' => $ledgerAmount, 'source' => 'ledger'];
        return [
            'coins' => round((float)rr_cashback_coins($conn, $prizeConfig, $cacheFile, $percent), 2),
            'source' => 'legacy_recomputed'
        ];
    }
}

if (!function_exists('rr_winner_fulfillment')) {
    function rr_winner_fulfillment($choice, $positionPrize, $finalPrize = null, $gambleResult = null, $cashbackCredit = null): array {
        $choice = strtolower(trim((string)$choice));
        if (is_string($finalPrize)) $finalPrize = json_decode($finalPrize, true);
        if (is_string($gambleResult)) $gambleResult = json_decode($gambleResult, true);
        if (!is_array($gambleResult)) $gambleResult = [];
        if (!is_array($cashbackCredit)) $cashbackCredit = ['coins' => 0.0, 'source' => null];

        $automaticCoins = 0.0;
        $automaticSource = null;
        $manualRequired = false;
        $manualScope = 'none';

        if ($choice === 'original') {
            // Compatibility rule: the original-prize admin workflow remains untouched,
            // except for OFERTA coupons, which the shop applies automatically.
            $manualRequired = !rr_prize_is_offer_coupon($positionPrize, $finalPrize);
            $manualScope = $manualRequired ? 'original_prize' : 'none';
        } elseif ($choice === 'cashback') {
            $automaticCoins = round((float)($cashbackCredit['coins'] ?? 0), 2);
            $automaticSource = $cashbackCredit['source'] ?? null;
        } elseif ($choice === 'double_won') {
            $automaticCoins = round((float)($gambleResult['double_bonus_coins'] ?? 0), 2);
            $automaticSource = 'gamble_result';
            $manualRequired = rr_prize_requires_manual_delivery($positionPrize, $finalPrize);
            $manualScope = $manualRequired ? 'product_component' : 'none';
        }

        $offerCoupon = in_array($choice, ['original', 'double_won'], true)
            && rr_prize_is_offer_coupon($positionPrize, $finalPrize);

        return [
            'kind' => $choice,
            'automatic_credit_coins' => $automaticCoins,
            'automatic_credit_source' => $automaticSource,
            'manual_delivery_required' => $manualRequired,
            'manual_delivery_scope' => $manualScope,
            // OFERTA: el premio es un cupón personal ya activo en la tienda.
            'offer_coupon' => $offerCoupon,
            'offer_coupon_label' => $offerCoupon ? DD_RAFFLE_PRIZE_LABEL : null,
            'offer_coupon_color' => $offerCoupon ? DD_RAFFLE_PRIZE_COLOR : null,
        ];
    }
}

// =================================================================================
// HELPER: Value of an explicit admin reference ("value_ref") in coins (Bananos).
//   - unit 'vbucks': 1 pavo = 1 Banano (no fiat conversion).
//   - unit 'soles' : inverse fiat conversion soles → coins.
// =================================================================================
if (!function_exists('rr_value_ref_coins')) {
    function rr_value_ref_coins($conn, $valueRef) {
        if (!is_array($valueRef)) return 0.0;
        $amount = (float)($valueRef['amount'] ?? 0);
        if ($amount <= 0) return 0.0;
        $unit = (string)($valueRef['unit'] ?? 'vbucks');
        if ($unit === 'soles') return rr_soles_to_coins($conn, $amount);
        return round($amount, 2);
    }
}

// =================================================================================
// HELPER: Value of a single Schema-2 pool option in coins (Bananos).
// =================================================================================
if (!function_exists('rr_option_value_coins')) {
    function rr_option_value_coins($conn, $option, $cacheFile) {
        if (!is_array($option)) return 0.0;
        if (($option['kind'] ?? '') === 'coins') {
            return round((float)($option['amount'] ?? 0), 2);
        }
        if (isset($option['value_ref']) && is_array($option['value_ref'])) {
            $v = rr_value_ref_coins($conn, $option['value_ref']);
            if ($v > 0) {
                $pct = rr_prize_discount_percent($option);
                return $pct > 0 ? round($v * $pct / 100, 2) : $v;
            }
        }
        return rr_prize_value_coins($conn, rr_option_to_prize_config($option), $cacheFile);
    }
}

// =================================================================================
// HELPER: Deliver a concrete pool option to a winner row (final choice snapshot).
// Same delivery semantics as raffle_claim_item.php: coins credit, category goes
// to admin_pending, anything else creates an order. Caller owns the transaction.
// =================================================================================
if (!function_exists('rr_deliver_pool_option')) {
    function rr_deliver_pool_option(mysqli $conn, $userId, $winnerRowId, $option, $cacheFile, $raffleId) {
        $userId = (int)$userId;
        $winnerRowId = (int)$winnerRowId;
        $kind = (string)($option['kind'] ?? '');
        $finalPrizeJson = json_encode($option, JSON_UNESCAPED_UNICODE);

        if ($kind === 'coins') {
            $amount = rr_credit_coins($conn, $userId, (float)($option['amount'] ?? 0), 'raffle_prize_coins', $raffleId);
            $upd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', prize_state = 'selected', final_prize = ?, prize_selected_at = UTC_TIMESTAMP(), prize_sent = 1 WHERE id = ?");
            $upd->bind_param("si", $finalPrizeJson, $winnerRowId);
            $upd->execute();
            $upd->close();
            return true;
        }
        if ($kind === 'category') {
            // El admin registra el ítem concreto al final (chat interno).
            $upd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', prize_state = 'admin_pending', final_prize = ?, prize_selected_at = UTC_TIMESTAMP(), prize_sent = 0 WHERE id = ?");
            $upd->bind_param("si", $finalPrizeJson, $winnerRowId);
            $upd->execute();
            $upd->close();
            return true;
        }
        // catalog / product / custom => pedido inmediato
        $prizeCfg = rr_option_to_prize_config($option);
        $orderId = rr_create_prize_order($conn, $userId, $prizeCfg, $cacheFile, $raffleId);
        $upd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', prize_state = 'selected', final_prize = ?, prize_selected_at = UTC_TIMESTAMP(), prize_sent = 1, generated_order_id = ? WHERE id = ?");
        $upd->bind_param("sii", $finalPrizeJson, $orderId, $winnerRowId);
        $upd->execute();
        $upd->close();
        return true;
    }
}

// =================================================================================
// HELPER: Savepoint-guarded variant of rr_deliver_pool_option() for unattended
// (AFK / polling) delivery. If the delivery throws (e.g. user row or cache item
// missing) only its partial writes are rolled back — the caller's transaction
// stays valid — and the winner is degraded to 'original' / 'awaiting_selection'
// so the raffle can still close and the winner picks manually from the vitrina.
// Returns true when the option was delivered, false when degraded.
// =================================================================================
if (!function_exists('rr_deliver_pool_option_safe')) {
    function rr_deliver_pool_option_safe(mysqli $conn, $userId, $winnerRowId, $option, $cacheFile, $raffleId) {
        $winnerRowId = (int)$winnerRowId;
        $sp = 'sp_pool_' . $winnerRowId;
        $conn->query("SAVEPOINT $sp");
        try {
            rr_deliver_pool_option($conn, $userId, $winnerRowId, $option, $cacheFile, $raffleId);
            $conn->query("RELEASE SAVEPOINT $sp");
            return true;
        } catch (Throwable $e) {
            $conn->query("ROLLBACK TO SAVEPOINT $sp");
            $conn->query("RELEASE SAVEPOINT $sp");
            error_log("[raffle_rewards] AFK pool delivery failed for winner row $winnerRowId (raffle $raffleId): " . $e->getMessage());
            $upd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', prize_sent = 0, prize_state = 'awaiting_selection' WHERE id = ?");
            $upd->bind_param("i", $winnerRowId);
            $upd->execute();
            $upd->close();
            return false;
        }
    }
}

// =================================================================================
// HELPER: Resolve the pool option a winner should receive when AFK: their
// pre-selected item for that position (raffle_participants.pre_pool_choices)
// or, if they skipped the pre-selection, the FIRST option of the pool
// ("asignación automática").
// =================================================================================
if (!function_exists('rr_pool_preselected_option')) {
    // Strict variant: the option the participant pre-selected for this
    // position, or null when there is none / it no longer exists in the pool.
    function rr_pool_preselected_option($poolPrize, $prePoolChoicesJson, $position) {
        $options = (is_array($poolPrize) && is_array($poolPrize['options'] ?? null)) ? $poolPrize['options'] : [];
        if (empty($options)) return null;
        $prefs = json_decode((string)$prePoolChoicesJson, true);
        if (!is_array($prefs)) return null;
        $key = (string)($prefs[(string)(int)$position] ?? '');
        if ($key === '') return null;
        foreach ($options as $opt) {
            if (is_array($opt) && (string)($opt['option_key'] ?? '') === $key) return $opt;
        }
        return null;
    }
}

if (!function_exists('rr_pool_option_for_afk')) {
    function rr_pool_option_for_afk($poolPrize, $prePoolChoicesJson, $position) {
        $options = (is_array($poolPrize) && is_array($poolPrize['options'] ?? null)) ? $poolPrize['options'] : [];
        if (empty($options)) return null;
        $pre = rr_pool_preselected_option($poolPrize, $prePoolChoicesJson, $position);
        if ($pre !== null) return $pre;
        $first = $options[0] ?? null;
        return is_array($first) ? $first : null;
    }
}

// =================================================================================
// HELPER: Espacio de decisión del ganador. Cashback y Doble o Nada son las
// únicas alternativas al premio original, y cada una solo existe si el sorteo
// la permite Y tiene sentido (porcentaje > 0 / modo distinto de fast_batch).
// Es la misma lectura que hace el cliente (SfPreChoice.describe): cuando no
// hay alternativas el ganador no decide nada y el premio original se reclama
// automáticamente.
// =================================================================================
if (!function_exists('rr_raffle_cashback_enabled')) {
    function rr_raffle_cashback_enabled(array $raffle): bool {
        return (int)($raffle['allow_cashback'] ?? 0) === 1
            && (float)($raffle['cashback_percent'] ?? 0) > 0;
    }
}

if (!function_exists('rr_raffle_gamble_enabled')) {
    function rr_raffle_gamble_enabled(array $raffle): bool {
        return (int)($raffle['allow_double_or_nothing'] ?? 0) === 1
            && (($raffle['execution_mode'] ?? 'standard_queue') !== 'fast_batch');
    }
}

if (!function_exists('rr_raffle_has_alternatives')) {
    function rr_raffle_has_alternatives(array $raffle): bool {
        return rr_raffle_cashback_enabled($raffle) || rr_raffle_gamble_enabled($raffle);
    }
}

// =================================================================================
// HELPER: Reclama el PREMIO ORIGINAL de una fila 'pending' de raffle_winners.
//   - premio fijo → entrega inmediata (pedido / Bananos / cupón), prize_sent = 1
//   - combo       → los fijos se entregan ya; el pool del pack sigue la regla
//                   de abajo
//   - pool        → el ítem pre-elegido al inscribirse (pre_pool_choices) si
//                   sigue vigente; si no, la fila queda en awaiting_selection
//                   para que el ganador elija en la Vitrina. Nunca se asigna un
//                   ítem por el ganador.
// Es la misma semántica que el reclamo manual (raffle_gamble.php) y la del
// reclamo automático cuando el sorteo no ofrece alternativas. El caller es
// dueño de la transacción y debe tener la fila bloqueada (FOR UPDATE).
// $winnerRow necesita id, user_id y position; $userData se pasa a deliverPrize.
// =================================================================================
if (!function_exists('rr_claim_original_prize')) {
    function rr_claim_original_prize(mysqli $conn, array $raffle, array $winnerRow, $prePoolChoicesJson, $cacheFile, array $userData = []): void {
        $raffleId    = (int)($raffle['raffle_id'] ?? 0);
        $winnerRowId = (int)($winnerRow['id'] ?? 0);
        $userId      = (int)($winnerRow['user_id'] ?? 0);
        $position    = (int)($winnerRow['position'] ?? 1);
        if ($raffleId <= 0 || $winnerRowId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('rr_claim_original_prize: fila de ganador incompleta.');
        }

        $prizeConfig = json_decode((string)($raffle['prize_config'] ?? ''), true);
        if (!is_array($prizeConfig)) {
            throw new RuntimeException('Configuración de premio inválida.');
        }
        $prizeConfig = rr_prize_for_position($prizeConfig, $position);
        if ((int)($raffle['allow_pre_choice'] ?? 1) !== 1) {
            $prePoolChoicesJson = null;
        }

        $setOriginal = function (string $extraSet, string $types, array $params) use ($conn, $winnerRowId): void {
            $sql = "UPDATE raffle_winners SET choice = 'original', coin_choice = NULL, selected_animation_mode = NULL"
                 . ($extraSet !== '' ? ', ' . $extraSet : '') . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $params[] = $winnerRowId;
            $stmt->bind_param($types . 'i', ...$params);
            $stmt->execute();
            $stmt->close();
        };

        $resolvePool = function ($pool) use ($conn, $userId, $winnerRowId, $prePoolChoicesJson, $position, $cacheFile, $raffleId, $setOriginal): void {
            $preOption = rr_pool_preselected_option($pool, $prePoolChoicesJson, $position);
            if ($preOption !== null) {
                rr_deliver_pool_option($conn, $userId, $winnerRowId, $preOption, $cacheFile, $raffleId);
                $setOriginal('', '', []);
                return;
            }
            $setOriginal("prize_sent = 0, prize_state = 'awaiting_selection'", '', []);
        };

        if (rr_prize_is_combo($prizeConfig)) {
            $comboOrderId = rr_deliver_combo_fixed($conn, $userId, $prizeConfig, $cacheFile, $raffleId)['order_id'];
            $comboPool = rr_position_pool($prizeConfig);
            if ($comboPool !== null) {
                $resolvePool($comboPool);
                if ($comboOrderId !== null) {
                    $stmt = $conn->prepare("UPDATE raffle_winners SET generated_order_id = COALESCE(generated_order_id, ?) WHERE id = ?");
                    $stmt->bind_param("ii", $comboOrderId, $winnerRowId);
                    $stmt->execute();
                    $stmt->close();
                }
                return;
            }
            if ($comboOrderId !== null) {
                $setOriginal('generated_order_id = ?, prize_sent = 1', 'i', [(int)$comboOrderId]);
            } else {
                $setOriginal('generated_order_id = NULL, prize_sent = 1', '', []);
            }
            return;
        }

        if (rr_prize_is_pool($prizeConfig)) {
            $resolvePool($prizeConfig);
            return;
        }

        $orderId = deliverPrize($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId);
        if (is_numeric($orderId)) {
            $setOriginal('generated_order_id = ?, prize_sent = 1', 'i', [(int)$orderId]);
        } else {
            $setOriginal('generated_order_id = NULL, prize_sent = 1', '', []);
        }
    }
}

// =================================================================================
// HELPER: Close a raffle stuck in 'waiting_for_winner' once every winner row has
// a terminal main decision (nothing 'pending' / 'gamble_pending' / empty left).
// Idempotent and race-safe: the UPDATE is conditional on the current status.
// Caller owns the transaction (if any). Returns true when the raffle was closed.
// =================================================================================
if (!function_exists('rr_raffle_close_if_all_decided')) {
    function rr_raffle_close_if_all_decided(mysqli $conn, $raffleId) {
        $raffleId = (int)$raffleId;
        if ($raffleId <= 0) return false;
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN choice IS NULL OR choice = '' OR choice = 'pending' OR choice = 'gamble_pending' THEN 1 ELSE 0 END) AS undecided
             FROM raffle_winners WHERE raffle_id = ?"
        );
        if ($stmt === false) return false;
        $stmt->bind_param("i", $raffleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $total = (int)($row['total'] ?? 0);
        $undecided = (int)($row['undecided'] ?? 0);
        if ($total === 0 || $undecided > 0) return false;

        $upd = $conn->prepare("UPDATE raffles SET status = 'completed' WHERE raffle_id = ? AND status = 'waiting_for_winner'");
        if ($upd === false) return false;
        $upd->bind_param("i", $raffleId);
        $upd->execute();
        $closed = $upd->affected_rows > 0;
        $upd->close();
        return $closed;
    }
}

// =================================================================================
// HELPER: Credit coins (Bananos) to a user's balance and log the transaction.
// =================================================================================
if (!function_exists('rr_credit_coins')) {
    function rr_credit_coins($conn, $userId, $amount, $reason, $raffleId) {
        $amount = round((float)$amount, 2);
        if ($amount <= 0) return 0.0;

        $upd = $conn->prepare("UPDATE coins SET balance = balance + ? WHERE user_id = ?");
        $upd->bind_param("di", $amount, $userId);
        $upd->execute();
        $upd->close();

        $tx = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at) VALUES (?, ?, ?, 'raffle_id', ?, UTC_TIMESTAMP())");
        $tx->bind_param("idsi", $userId, $amount, $reason, $raffleId);
        $tx->execute();
        $tx->close();

        return $amount;
    }
}

// =================================================================================
// HELPER: Compute the cashback coins for a prize at a given percentage.
//   - coins prize: percent of the coin amount directly.
//   - skin / shop item: percent of its native pavos value (1 pavo = 1 Banano).
//   - static product: percent of its soles value, converted to coins.
//   - OFERTA prize: percent of the COUPON worth (1500 pavos × 50% OFF = 750 →
//     cashback 50% = 375 Bananos).
// Returns float coins (0.0 if it cannot be valued).
// =================================================================================
if (!function_exists('rr_cashback_coins')) {
    function rr_cashback_coins($conn, $prizeConfig, $cacheFile, $percent) {
        $percent = (float)$percent;
        if ($percent <= 0 || !is_array($prizeConfig)) return 0.0;

        // Cashback is always a percentage of the prize's real value in Bananos
        // (coins amount / native pavos / soles-equivalent), never of an inflated
        // fiat-converted figure.
        $valueCoins = rr_prize_value_coins($conn, $prizeConfig, $cacheFile);
        if ($valueCoins <= 0) return 0.0;
        return round($valueCoins * ($percent / 100), 2);
    }
}

// =================================================================================
// HELPER: Deliver a DOBLE O NADA win.
// Business rule (Fase 3): we cannot ship two identical physical/skin items, so a
// doubled win is paid as the ORIGINAL prize + its exact monetary equivalent in
// coins (Bananos).
//   - coins prize: deliver the base amount once, then credit the same amount
//     again as the "double" bonus (net 2x coins).
//   - product/skin: create ONE order for the original item, then credit its full
//     value in coins (native pavos for skins, soles-equivalent for static
//     products) as the double bonus.
//   - OFERTA prize: the coupon is saved in daily_discounts (no order) and the
//     bonus is the coupon worth (50% OFF on 1500 pavos → 750 Bananos), so the
//     item ends up free when bought with coupon + Bananos.
// Returns ['order_id' => int|null, 'bonus_coins' => float].
// =================================================================================
if (!function_exists('rr_deliver_double_win')) {
    function rr_deliver_double_win($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId) {
        $orderId = null;
        $bonusCoins = 0.0;

        // Combo: pay each item with its own doubling rule (fixed items ship
        // once + equivalent coins; the pool item pays its equivalent only,
        // like the standalone pool case). Returns the first order id.
        if (rr_prize_is_combo($prizeConfig)) {
            $totalBonus = 0.0;
            foreach (rr_combo_items($prizeConfig) as $item) {
                $payout = rr_deliver_double_win($conn, $userId, $userData, $item, $cacheFile, $raffleId);
                if ($orderId === null && $payout['order_id'] !== null) $orderId = $payout['order_id'];
                $totalBonus += (float)$payout['bonus_coins'];
            }
            return ['order_id' => $orderId, 'bonus_coins' => round($totalBonus, 2)];
        }

        // Unselected pool: no concrete item to ship yet. Credit the equivalent
        // coins (max of the pool) as the double bonus; the original item is
        // resolved through the selection flow / admin registration.
        if (rr_prize_is_pool($prizeConfig)) {
            $equivCoins = rr_prize_value_coins($conn, $prizeConfig, $cacheFile);
            if ($equivCoins > 0) {
                $bonusCoins = rr_credit_coins($conn, $userId, $equivCoins, 'raffle_double_bonus', $raffleId);
            }
            return ['order_id' => null, 'bonus_coins' => $bonusCoins];
        }

        if (($prizeConfig['type'] ?? '') === 'coins') {
            // Base prize (1x) via the standard delivery, then the doubling bonus.
            deliverPrize($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId);
            $bonusCoins = rr_credit_coins($conn, $userId, (float)($prizeConfig['amount'] ?? 0), 'raffle_double_bonus', $raffleId);
            return ['order_id' => null, 'bonus_coins' => $bonusCoins];
        }

        // product / skin → original item (or its coupon) once + equivalent coins.
        $orderRaw = deliverPrize($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId);
        if (is_numeric($orderRaw)) $orderId = (int)$orderRaw;

        $equivCoins = rr_prize_value_coins($conn, $prizeConfig, $cacheFile);
        if ($equivCoins > 0) {
            $bonusCoins = rr_credit_coins($conn, $userId, $equivCoins, 'raffle_double_bonus', $raffleId);
        }

        return ['order_id' => $orderId, 'bonus_coins' => $bonusCoins];
    }
}

// =================================================================================
// HELPER: Canonical Provably-Fair outcome for DOBLE O NADA.
// Single source of truth shared by raffle_gamble.php (poll_status) and
// raffle_core.php (triggerGambleResolve), so every resolution path produces
// the SAME result for the same inputs.
//
// Formula: HMAC_SHA256(serverSeed + blockHash + winnerId, key=serverSeed).
// The first 8 hex chars → integer; EVEN (par) = 'heads' (gana/Cara), ODD (impar)
// = 'tails' (pierde/Sello). serverSeed is committed via gamble_server_hash =
// sha256(serverSeed) before the BSC target block is mined (commit-reveal).
// =================================================================================
if (!function_exists('rr_gamble_outcome')) {
    function rr_gamble_outcome($serverSeed, $blockHash, $winnerId) {
        $serverSeed = (string)$serverSeed;
        $dataString = $serverSeed . (string)$blockHash . (string)$winnerId;
        $hmac = hash_hmac('sha256', $dataString, $serverSeed);
        $dec = hexdec(substr($hmac, 0, 8));
        return [
            'hmac'    => $hmac,
            'dec'     => $dec,
            'outcome' => ($dec % 2 === 0) ? 'heads' : 'tails',
        ];
    }
}

// =================================================================================
// HELPER: Búsqueda de una oferta en el snapshot del catálogo (por offerId).
// =================================================================================
if (!function_exists('rr_find_in_cache')) {
    function rr_find_in_cache($offerId, $cacheFile) {
        if ($offerId === '' || $offerId === null) return null;
        if (!$cacheFile || !file_exists($cacheFile)) return null;
        $data = json_decode((string)file_get_contents($cacheFile), true);
        $items = $data['entries'] ?? $data['shop'] ?? null;
        if (!is_array($items)) return null;
        foreach ($items as $item) {
            if (strcasecmp((string)($item['offerId'] ?? ''), (string)$offerId) === 0) return $item;
        }
        return null;
    }
}

// =================================================================================
// HELPER: Minutos de margen de seguridad antes de que un ítem salga de la tienda
// (misma clave de configuracion_tienda que usa cart_manager.php).
// =================================================================================
if (!function_exists('rr_shop_safety_buffer_minutes')) {
    function rr_shop_safety_buffer_minutes($conn) {
        $minutes = 5;
        if (!($conn instanceof mysqli)) return $minutes;
        $res = $conn->query("SELECT valor FROM configuracion_tienda WHERE clave = 'tiempo_reserva_seguridad_min' LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            $v = (int)$row['valor'];
            if ($v >= 0) $minutes = $v;
        }
        if ($res) $res->free();
        return $minutes;
    }
}

// =================================================================================
// HELPER: Unix timestamp de expiración de un cupón de OFERTA para un premio.
//   - skin (catálogo API): offerDates.out del snapshot canónico (mismo dato que
//     usa el carrito/checkout) menos el margen de seguridad de la tienda.
//   - product (estático): productos_estaticos.oferta_fecha_expiracion si existe
//     y es futura.
//   - fallback (sin fecha válida / ya pasada): 7 días desde la entrega.
// Devuelve ['expires_at' => int, 'source' => 'shop_api'|'static_offer'|'fallback_7d'].
// =================================================================================
if (!function_exists('rr_offer_coupon_expiry')) {
    function rr_offer_coupon_expiry($conn, $prizeConfig, $cacheFile) {
        $now = time();
        $id = (string)($prizeConfig['id'] ?? '');
        $type = (string)($prizeConfig['type'] ?? '');

        if ($id !== '' && $type !== 'product') {
            $item = null;
            if (class_exists('ShopRepository')) $item = ShopRepository::findByOfferId($id);
            if ($item === null) $item = rr_find_in_cache($id, $cacheFile);
            $out = null;
            if (is_array($item)) $out = $item['offerDates']['out'] ?? ($item['outDate'] ?? null);
            if (is_string($out) && $out !== '') {
                $ts = strtotime($out);
                if ($ts !== false) {
                    $ts -= rr_shop_safety_buffer_minutes($conn) * 60;
                    if ($ts > $now) return ['expires_at' => $ts, 'source' => 'shop_api'];
                }
            }
        }

        if ($id !== '' && ctype_digit($id)) {
            $sp = rr_get_static_product($conn, (int)$id);
            $raw = is_array($sp) ? ($sp['oferta_fecha_expiracion'] ?? null) : null;
            if (is_string($raw) && $raw !== '' && strpos($raw, '0000-00-00') !== 0) {
                $ts = strtotime($raw);
                if ($ts !== false && $ts > $now) return ['expires_at' => $ts, 'source' => 'static_offer'];
            }
        }

        return ['expires_at' => $now + 7 * 86400, 'source' => 'fallback_7d'];
    }
}

// =================================================================================
// HELPER: Entrega de un premio en modo OFERTA como cupón personal.
// Inserta (una sola vez por usuario/ítem/sorteo) una fila en daily_discounts —
// la misma tabla que consumen get_my_discounts.php, cart_manager.php y
// checkout/process.php — de modo que el descuento se aplica automáticamente en
// la tienda SOLO al usuario logueado (checkout lo anula para 'friend'/'multi' y
// lo quema tras la compra). Caller owns the transaction.
// Devuelve ['discount_id' => int, 'percent' => int, 'value_coins' => float,
//           'expires_at' => int, 'expiry_source' => string] o null si el premio
// no es una oferta.
// =================================================================================
if (!function_exists('rr_deliver_offer_coupon')) {
    function rr_deliver_offer_coupon($conn, $userId, $prizeConfig, $cacheFile, $raffleId) {
        $pct = rr_prize_discount_percent($prizeConfig);
        if ($pct <= 0) return null;
        $userId = (int)$userId;
        $raffleId = (int)$raffleId;
        $itemId = (string)($prizeConfig['id'] ?? '');
        if ($userId <= 0 || $itemId === '') throw new Exception('Cupón de oferta sin usuario o ítem válido.');

        dd_ensure_schema($conn);

        $source = DD_SOURCE_RAFFLE_PRIZE;
        $valueCoins = round((float)rr_prize_value_coins($conn, $prizeConfig, $cacheFile), 2);
        $expiry = rr_offer_coupon_expiry($conn, $prizeConfig, $cacheFile);

        $chk = $conn->prepare("SELECT id, expires_at FROM daily_discounts WHERE user_id = ? AND item_id = ? AND raffle_id = ? AND spin_source = ? AND status = 'active' LIMIT 1");
        if (!$chk) throw new Exception('No se pudo verificar el cupón: ' . $conn->error);
        $chk->bind_param("isis", $userId, $itemId, $raffleId, $source);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($existing) {
            $existingExpires = UtcTime::fromSql((string)$existing['expires_at']);
            return [
                'discount_id'   => (int)$existing['id'],
                'percent'       => $pct,
                'value_coins'   => $valueCoins,
                'expires_at'    => $existingExpires === null ? 0 : $existingExpires->getTimestamp(),
                'expiry_source' => 'existing',
            ];
        }

        $stmtUser = $conn->prepare("SELECT game_username FROM users WHERE id = ? LIMIT 1");
        if (!$stmtUser) throw new Exception('No se pudo consultar el usuario: ' . $conn->error);
        $stmtUser->bind_param("i", $userId);
        $stmtUser->execute();
        $uRow = $stmtUser->get_result()->fetch_assoc();
        $stmtUser->close();
        if (!$uRow) throw new Exception('Usuario no encontrado.');
        $nick = (string)($uRow['game_username'] ?? '');

        $name = (string)($prizeConfig['name'] ?? 'Premio Sorteo');
        $image = (string)($prizeConfig['image'] ?? '');
        if ($image === '') {
            $cached = rr_find_in_cache($itemId, $cacheFile);
            if ($cached) $image = (string)($cached['displayAssets'][0]['url'] ?? ($cached['images']['featured'] ?? ($cached['images']['icon'] ?? '')));
            if ($image === '' && ctype_digit($itemId)) {
                $sp = rr_get_static_product($conn, (int)$itemId);
                if ($sp) $image = (string)($sp['imagen_url'] ?? '');
            }
        }

        $isFlash = 0;
        $coinsSpent = 0;
        $expiresTs = (int)$expiry['expires_at'];
        // expires_at/created_at en UTC explícito (no FROM_UNIXTIME/NOW(), que
        // dependen del time_zone de la sesión) para que el cupón se compare bien
        // en tienda/carrito/checkout desde cualquier conexión.
        $expiresSql = UtcTime::toSql(UtcTime::fromTimestamp($expiresTs));
        $ins = $conn->prepare(
            "INSERT INTO daily_discounts
                (user_id, epic_nick_snapshot, item_id, item_name, item_image_url, discount_percent,
                 is_flash_prize, spin_source, coins_spent, expires_at, created_at, status, raffle_id, coupon_value_coins)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), 'active', ?, ?)"
        );
        if (!$ins) throw new Exception('No se pudo crear el cupón: ' . $conn->error);
        $ins->bind_param("issssiisisid",
            $userId, $nick, $itemId, $name, $image, $pct,
            $isFlash, $source, $coinsSpent, $expiresSql, $raffleId, $valueCoins
        );
        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            throw new Exception('Error al guardar el cupón de oferta: ' . $err);
        }
        $discountId = (int)$conn->insert_id;
        $ins->close();

        return [
            'discount_id'   => $discountId,
            'percent'       => $pct,
            'value_coins'   => $valueCoins,
            'expires_at'    => $expiresTs,
            'expiry_source' => $expiry['source'],
        ];
    }
}

// =================================================================================
// HELPER: Pedido de premio de sorteo (costo 0 para el ganador).
// Fuente única compartida por raffle_claim_item.php (elección de pool) y
// api_sorteos.php (set_winner_final_item: categoría concretada por el admin).
// $prizeConfig: ['id' => offerId|null, 'name' => ..., 'image' => ...]
// Devuelve el id del pedido creado. Un premio en modo OFERTA no genera pedido:
// se entrega como cupón (rr_deliver_offer_coupon) y devuelve null.
// =================================================================================
if (!function_exists('rr_create_prize_order')) {
    function rr_create_prize_order($conn, $userId, $prizeConfig, $cacheFile, $raffleId, $notes = null) {
        if (rr_prize_discount_percent($prizeConfig) > 0) {
            rr_deliver_offer_coupon($conn, $userId, $prizeConfig, $cacheFile, $raffleId);
            return null;
        }
        $pIdStr = $prizeConfig['id'] ?? 'sorteo-' . $raffleId;
        $pName = $prizeConfig['name'] ?? 'Premio Sorteo';
        $pImage = $prizeConfig['image'] ?? '';

        $cachedItem = rr_find_in_cache($prizeConfig['id'] ?? '', $cacheFile);

        $precioBase = 0.00;
        $vBucks = 0;
        $tipoProducto = 'Premio Sorteo';
        $sectionName = 'Sorteo';
        $storeRank = 0;
        $c1 = null; $c2 = null; $c3 = null;

        if ($cachedItem) {
            $precioBase = (float)($cachedItem['precio_base_soles'] ?? 0);
            $vBucks = (int)($cachedItem['vbucks'] ?? 0);
            $tipoProducto = $cachedItem['displayType'] ?? 'Atuendo';
            $sectionName = $cachedItem['section_name'] ?? 'Tienda';
            $storeRank = (int)($cachedItem['store_rank'] ?? 0);
            if (isset($cachedItem['colors'])) {
                if (!empty($cachedItem['colors']['color1'])) $c1 = $cachedItem['colors']['color1'];
                if (!empty($cachedItem['colors']['color2'])) $c2 = $cachedItem['colors']['color2'];
                if (!empty($cachedItem['colors']['color3'])) $c3 = $cachedItem['colors']['color3'];
            }
            if ($pImage === '' && !empty($cachedItem['imagen_url'])) $pImage = (string)$cachedItem['imagen_url'];
        }

        $stmtUser = $conn->prepare("SELECT game_username, email, whatsapp_phone_e164 FROM users WHERE id = ?");
        if (!$stmtUser) throw new Exception("No se pudo consultar el usuario: " . $conn->error);
        $stmtUser->bind_param("i", $userId);
        $stmtUser->execute();
        $dbUserInfo = $stmtUser->get_result()->fetch_assoc();
        $stmtUser->close();
        if (!$dbUserInfo) throw new Exception("Usuario no encontrado.");

        $userWhatsapp = (string)($dbUserInfo['whatsapp_phone_e164'] ?? '');

        $emailCliente = $dbUserInfo['email'];
        $gameNick = $dbUserInfo['game_username'];

        $code = 'WIN-' . strtoupper(substr(md5(uniqid()), 0, 8));
        $notas = $notes !== null ? (string)$notes : "GANADOR SORTEO #$raffleId (Elección de pool)";
        $estado = 'pagado';
        $metodo = 'saldo';
        $estadoPago = 'notificado_pendiente_revision';
        $mode = 'self';
        $esRegalo = 0;
        $cero = 0.00;

        $sqlOrder = "INSERT INTO pedidos (
            user_id, codigo_pedido, email_cliente, whatsapp_discord_cliente, nickname_fortnite_cliente,
            subtotal, monto_descuento, total_pagar, estado, metodo_pago,
            notas_admin, fecha_creacion, fecha_pago, estado_pago_cliente, recipient_mode, es_regalo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?)";

        $stmtOrder = $conn->prepare($sqlOrder);
        $nickPlaceholder = 'Ganador Sorteo';
        $stmtOrder->bind_param("issssdddsssssi",
            $userId, $code, $emailCliente, $userWhatsapp, $nickPlaceholder,
            $precioBase, $precioBase, $cero, $estado, $metodo,
            $notas, $estadoPago, $mode, $esRegalo
        );
        if (!$stmtOrder->execute()) throw new Exception("Error al crear el pedido.");
        $oId = $conn->insert_id;
        $stmtOrder->close();

        $sqlDetail = "INSERT INTO detalles_pedido (
            pedido_id, producto_id_str, nombre_producto, imagen_producto_url,
            precio_base, precio_final, cantidad, tipo_producto,
            v_bucks, color_1, color_2, color_3, section_name, store_rank
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtDetail = $conn->prepare($sqlDetail);
        $cantidad = 1;
        $stmtDetail->bind_param("isssddisissssi",
            $oId, $pIdStr, $pName, $pImage,
            $precioBase, $cero, $cantidad, $tipoProducto,
            $vBucks, $c1, $c2, $c3, $sectionName, $storeRank
        );
        $stmtDetail->execute();
        $detailId = $conn->insert_id;
        $stmtDetail->close();

        $sqlDest = "INSERT INTO detalles_pedido_destinatarios (detalle_pedido_id, nickname_destinatario) VALUES (?, ?)";
        $stmtDest = $conn->prepare($sqlDest);
        $stmtDest->bind_param("is", $detailId, $gameNick);
        $stmtDest->execute();
        $stmtDest->close();

        return $oId;
    }
}
