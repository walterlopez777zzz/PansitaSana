<?php
// Banano Store - Helpers financieros del panel de pedidos.
// Bananos gastados/devueltos, cashback y recálculo de totales al editar pedidos.
// Todas las funciones asumen que el llamador ya abrió la transacción cuando escriben.

require_once __DIR__ . '/../../../core/ShopRepository.php';

/** Estados en los que el pedido queda anulado y los Bananos gastados deben volver al cliente. */
const BS_VOID_STATUSES = ['cancelado', 'reembolsado'];
/** Estados en los que se permite editar productos. */
const BS_EDITABLE_STATUSES = ['pendiente', 'pagado', 'completado'];
const BS_MAX_ADD_QTY = 10;

function bs_setting_float(mysqli $conn, string $key, float $default): float {
    $stmt = $conn->prepare("SELECT value FROM site_settings WHERE key_name = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && is_numeric($row['value'])) ? (float)$row['value'] : $default;
}

function bs_config_float(mysqli $conn, string $clave, float $default): float {
    $stmt = $conn->prepare("SELECT valor FROM configuracion_tienda WHERE clave = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $clave);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && is_numeric($row['valor'])) ? (float)$row['valor'] : $default;
}

/**
 * Misma fórmula que checkout/process.php: cashback en dinero según la tasa del modo,
 * convertido a Bananos con el precio por 100 paVos.
 */
function bs_compute_cashback(mysqli $conn, string $cashback_mode, float $total_final): float {
    if ($total_final <= 0) return 0.0;
    if ($cashback_mode !== 'base' && $cashback_mode !== 'verified') return 0.0;

    $rate_key = $cashback_mode === 'verified' ? 'cashback_percentage_verified' : 'cashback_base';
    $rate = bs_setting_float($conn, $rate_key, 0.0);
    $precio_por_100 = bs_config_float($conn, 'precio_por_100_pavos', 0.0);
    if ($rate <= 0 || $precio_por_100 <= 0) return 0.0;

    $cashback_dinero = round($total_final * $rate, 2);
    return round(($cashback_dinero / $precio_por_100) * 100, 2);
}

/** Bananos netos que el cliente tiene retenidos en el pedido (gastados y aún no devueltos). */
function bs_order_coins_outstanding(mysqli $conn, int $order_id, int $user_id): float {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS net
           FROM coin_transactions
          WHERE user_id = ? AND reference_type = 'order_id' AND reference_id = ?
            AND reason IN ('spend', 'refund')"
    );
    if (!$stmt) return 0.0;
    $ref = (string)$order_id;
    $stmt->bind_param('is', $user_id, $ref);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $net = (float)($row['net'] ?? 0);
    return $net < 0 ? round(-$net, 2) : 0.0;
}

/** Cashback ya abonado al saldo por este pedido (0 si aún no se completó o fue revertido). */
function bs_order_cashback_delivered(mysqli $conn, int $order_id, int $user_id): float {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS total
           FROM coin_transactions
          WHERE user_id = ? AND reference_id = ? AND reason = 'cashback'"
    );
    if (!$stmt) return 0.0;
    $ref = (string)$order_id;
    $stmt->bind_param('is', $user_id, $ref);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return round((float)($row['total'] ?? 0), 2);
}

function bs_user_balance(mysqli $conn, int $user_id): float {
    $stmt = $conn->prepare("SELECT balance FROM coins WHERE user_id = ? LIMIT 1");
    if (!$stmt) return 0.0;
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['balance'] : 0.0;
}

function bs_adjust_balance(mysqli $conn, int $user_id, float $delta): void {
    if (abs($delta) < 0.005) return;
    $stmt = $conn->prepare("INSERT INTO coins (user_id, balance) VALUES (?, ?) ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)");
    if (!$stmt) throw new Exception('Error al preparar ajuste de saldo.');
    $stmt->bind_param('id', $user_id, $delta);
    if (!$stmt->execute()) throw new Exception('Error al ajustar saldo: ' . $stmt->error);
    $stmt->close();
}

function bs_insert_coin_tx(mysqli $conn, int $user_id, float $amount, string $reason, int $order_id): void {
    $stmt = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?, ?, ?, 'order_id', ?)");
    if (!$stmt) throw new Exception('Error al preparar transacción de Bananos.');
    $ref = (string)$order_id;
    $stmt->bind_param('idss', $user_id, $amount, $reason, $ref);
    if (!$stmt->execute()) throw new Exception('Error al registrar transacción de Bananos: ' . $stmt->error);
    $stmt->close();
}

function bs_notify(mysqli $conn, int $user_id, string $type, string $title, string $message): void {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param('isss', $user_id, $type, $title, $message);
    $stmt->execute();
    $stmt->close();
}

/**
 * Sincroniza los Bananos gastados con el estado del pedido:
 *  - Al pasar a un estado anulado devuelve lo que siga retenido (idempotente: si ya
 *    se devolvió no hace nada).
 *  - Al salir de un estado anulado vuelve a cobrar lo que el checkout descontó, para
 *    que un pedido reactivado no quede "gratis".
 * Devuelve ['refunded' => x, 'recharged' => y].
 */
function bs_sync_spent_coins(mysqli $conn, array $order, string $old_status, string $new_status): array {
    $result = ['refunded' => 0.0, 'recharged' => 0.0];
    $user_id = (int)($order['user_id'] ?? 0);
    $order_id = (int)($order['id'] ?? 0);
    $coins_redeemed = round((float)($order['coins_redeemed'] ?? 0), 2);
    if ($user_id <= 0 || $order_id <= 0 || $coins_redeemed <= 0) return $result;

    $to_void = in_array($new_status, BS_VOID_STATUSES, true) && !in_array($old_status, BS_VOID_STATUSES, true);
    $from_void = in_array($old_status, BS_VOID_STATUSES, true) && !in_array($new_status, BS_VOID_STATUSES, true);
    if (!$to_void && !$from_void) return $result;

    $outstanding = bs_order_coins_outstanding($conn, $order_id, $user_id);
    $codigo = (string)($order['codigo_pedido'] ?? $order_id);

    if ($to_void && $outstanding > 0) {
        bs_adjust_balance($conn, $user_id, $outstanding);
        bs_insert_coin_tx($conn, $user_id, $outstanding, 'refund', $order_id);
        $n = number_format($outstanding, 0);
        bs_notify($conn, $user_id, 'reward', 'Bananos devueltos', "Tu pedido #$codigo fue cancelado y te devolvimos los $n Bananos que habías usado.");
        $result['refunded'] = $outstanding;
    } elseif ($from_void && $outstanding < $coins_redeemed) {
        $missing = round($coins_redeemed - $outstanding, 2);
        $balance = bs_user_balance($conn, $user_id);
        if ($balance + 0.005 < $missing) {
            throw new Exception(sprintf(
                'No se puede reactivar: el cliente usó %s Bananos en este pedido pero ahora solo tiene %s en su saldo.',
                number_format($missing, 0), number_format($balance, 0)
            ));
        }
        bs_adjust_balance($conn, $user_id, -$missing);
        bs_insert_coin_tx($conn, $user_id, -$missing, 'spend', $order_id);
        $result['recharged'] = $missing;
    }

    return $result;
}

/** Filas de detalles_pedido del pedido (una por unidad), con su destinatario si existe. */
function bs_load_order_lines(mysqli $conn, int $order_id): array {
    $stmt = $conn->prepare(
        "SELECT dp.id, dp.producto_id_str, dp.nombre_producto, dp.precio_base, dp.precio_final,
                dp.cantidad, dp.fecha_expiracion_item, dpd.nickname_destinatario
           FROM detalles_pedido dp
           LEFT JOIN detalles_pedido_destinatarios dpd ON dp.id = dpd.detalle_pedido_id
          WHERE dp.pedido_id = ? ORDER BY dp.id ASC"
    );
    if (!$stmt) throw new Exception('Error al cargar los productos del pedido.');
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function bs_canonical_image(array $item): string {
    foreach (['displayAssets', 'images'] as $k) {
        if (!isset($item[$k])) continue;
        $v = $item[$k];
        if (is_array($v)) {
            if (isset($v[0]) && is_array($v[0])) {
                $url = $v[0]['url'] ?? $v[0]['background'] ?? $v[0]['full_background'] ?? null;
                if (is_string($url) && $url !== '') return $url;
            }
            foreach (['icon', 'featured', 'background', 'full_background', 'url'] as $ik) {
                if (isset($v[$ik]) && is_string($v[$ik]) && $v[$ik] !== '') return $v[$ik];
            }
        } elseif (is_string($v) && $v !== '') {
            return $v;
        }
    }
    return '';
}

function bs_canonical_expiry_ts(array $item): ?int {
    $out = $item['offerDates']['out'] ?? null;
    if (!is_string($out) || $out === '') return null;
    $ts = strtotime($out);
    return $ts !== false && $ts > 0 ? $ts : null;
}

/** Catálogo resumido para el buscador del modal: tienda del día (con overrides del DAL) + estáticos activos. */
function bs_edit_catalog(mysqli $conn): array {
    $shop = [];
    foreach (ShopRepository::items() as $item) {
        if (!is_array($item) || empty($item['offerId'])) continue;
        $base = round((float)($item['precio_base_soles'] ?? 0), 2);
        if ($base <= 0) continue;
        $has_offer = !empty($item['tiene_oferta_nuestra']) && isset($item['precio_oferta_soles']) && (float)$item['precio_oferta_soles'] > 0;
        $shop[] = [
            'source'     => 'shop',
            'id'         => (string)$item['offerId'],
            'name'       => (string)($item['displayName'] ?? $item['devName'] ?? 'Item'),
            'type'       => (string)($item['displayType'] ?? ''),
            'section'    => (string)($item['section_name'] ?? ''),
            'vbucks'     => (int)($item['vbucks'] ?? 0),
            'image'      => bs_canonical_image($item),
            'price_base' => $base,
            'price'      => $has_offer ? round((float)$item['precio_oferta_soles'], 2) : $base,
        ];
    }

    $static = [];
    $res = $conn->query("SELECT id, nombre, imagen_url, precio_base, precio_oferta, oferta_fecha_expiracion, categoria FROM productos_estaticos WHERE activo = 1 ORDER BY orden_categoria, categoria, orden, nombre ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $base = round((float)$r['precio_base'], 2);
            $offer = isset($r['precio_oferta']) && is_numeric($r['precio_oferta']) ? round((float)$r['precio_oferta'], 2) : 0.0;
            $offer_ok = $offer > 0 && $offer < $base
                && (empty($r['oferta_fecha_expiracion']) || strtotime((string)$r['oferta_fecha_expiracion']) > time());
            $static[] = [
                'source'     => 'static',
                'id'         => (string)$r['id'],
                'name'       => (string)$r['nombre'],
                'type'       => (string)($r['categoria'] ?? 'Producto'),
                'section'    => 'Estáticos',
                'vbucks'     => 0,
                'image'      => (string)($r['imagen_url'] ?? ''),
                'price_base' => $base,
                'price'      => $offer_ok ? $offer : $base,
            ];
        }
        $res->free();
    }

    return ['shop' => $shop, 'static' => $static];
}

/** Resuelve un producto a añadir con precios actuales del servidor (nunca del cliente). */
function bs_resolve_add_item(mysqli $conn, string $source, string $id): array {
    if ($source === 'static') {
        $stmt = $conn->prepare("SELECT id, nombre, imagen_url, precio_base, precio_oferta, oferta_fecha_expiracion, categoria FROM productos_estaticos WHERE id = ? AND activo = 1 LIMIT 1");
        if (!$stmt) throw new Exception('Error al buscar producto estático.');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r) throw new Exception("Producto estático '$id' no encontrado o inactivo.");
        $base = round((float)$r['precio_base'], 2);
        $offer = isset($r['precio_oferta']) && is_numeric($r['precio_oferta']) ? round((float)$r['precio_oferta'], 2) : 0.0;
        $offer_ok = $offer > 0 && $offer < $base
            && (empty($r['oferta_fecha_expiracion']) || strtotime((string)$r['oferta_fecha_expiracion']) > time());
        return [
            'producto_id_str' => (string)$r['id'],
            'nombre'          => (string)$r['nombre'],
            'imagen'          => (string)($r['imagen_url'] ?? ''),
            'precio_base'     => $base,
            'precio_final'    => $offer_ok ? $offer : $base,
            'tipo'            => (string)($r['categoria'] ?? 'Producto'),
            'section_name'    => 'General',
            'store_rank'      => 0,
            'vbucks'          => 0,
            'colors'          => [null, null, null],
            'expira_ts'       => null,
        ];
    }

    $item = ShopRepository::findByOfferId($id);
    if ($item === null) throw new Exception("El ítem '$id' ya no está en la tienda de hoy.");
    $base = round((float)($item['precio_base_soles'] ?? 0), 2);
    if ($base <= 0) throw new Exception("El ítem '$id' no tiene precio configurado.");
    $has_offer = !empty($item['tiene_oferta_nuestra']) && isset($item['precio_oferta_soles']) && (float)$item['precio_oferta_soles'] > 0;
    $colors = is_array($item['colors'] ?? null) ? $item['colors'] : [];
    $norm = static function ($v): ?string {
        if (!is_string($v)) return null;
        $v = ltrim(strtolower(trim($v)), '#');
        return preg_match('/^[0-9a-f]{8}$/', $v) === 1 ? $v : null;
    };
    return [
        'producto_id_str' => (string)$item['offerId'],
        'nombre'          => (string)($item['displayName'] ?? $item['devName'] ?? 'Item'),
        'imagen'          => bs_canonical_image($item),
        'precio_base'     => $base,
        'precio_final'    => $has_offer ? round((float)$item['precio_oferta_soles'], 2) : $base,
        'tipo'            => (string)($item['displayType'] ?? 'General'),
        'section_name'    => (string)($item['section_name'] ?? 'General'),
        'store_rank'      => (int)($item['store_rank'] ?? 0),
        'vbucks'          => (int)($item['vbucks'] ?? 0),
        'colors'          => [$norm($colors['color1'] ?? null), $norm($colors['color2'] ?? null), $norm($colors['color3'] ?? null)],
        'expira_ts'       => bs_canonical_expiry_ts($item),
    ];
}

/**
 * Calcula el pedido resultante de quitar filas y añadir ítems, sin escribir nada.
 * Reglas: precios de servidor; el descuento de cupón original se conserva (porcentual →
 * se reescala al nuevo subtotal, fijo → se mantiene con tope al total); los Bananos
 * gastados se conservan y si superan el nuevo total se devuelve el excedente en unidades
 * enteras; el cashback se recalcula con la fórmula del checkout.
 */
function bs_compute_order_edit(mysqli $conn, array $order, array $remove_ids, array $add_items): array {
    $order_id = (int)$order['id'];
    $estado = (string)$order['estado'];
    if (!in_array($estado, BS_EDITABLE_STATUSES, true)) {
        throw new Exception("Solo se pueden editar pedidos pendientes, pagados o completados (estado actual: $estado).");
    }

    $lines = bs_load_order_lines($conn, $order_id);
    $line_ids = array_map(static fn($l) => (int)$l['id'], $lines);
    $remove_ids = array_values(array_unique(array_map('intval', $remove_ids)));
    foreach ($remove_ids as $rid) {
        if (!in_array($rid, $line_ids, true)) throw new Exception("La fila #$rid no pertenece a este pedido.");
    }

    $kept = array_values(array_filter($lines, static fn($l) => !in_array((int)$l['id'], $remove_ids, true)));

    $adds = [];
    foreach ($add_items as $a) {
        if (!is_array($a)) continue;
        $source = (string)($a['source'] ?? 'shop');
        $id = trim((string)($a['id'] ?? ''));
        $qty = (int)($a['quantity'] ?? 1);
        if ($id === '') continue;
        if ($qty < 1 || $qty > BS_MAX_ADD_QTY) throw new Exception('Cantidad no válida (1-' . BS_MAX_ADD_QTY . ').');
        $resolved = bs_resolve_add_item($conn, $source === 'static' ? 'static' : 'shop', $id);
        $resolved['quantity'] = $qty;
        $resolved['recipient'] = trim((string)($a['recipient'] ?? ''));
        $adds[] = $resolved;
    }

    $units_total = count($kept) + array_sum(array_map(static fn($a) => (int)$a['quantity'], $adds));
    if ($units_total <= 0) throw new Exception('El pedido no puede quedar vacío. Usa "Cancelado" si el cliente ya no quiere nada.');

    $old_subtotal = round((float)($order['subtotal'] ?? 0), 2);
    $old_total = round((float)($order['total_pagar'] ?? 0), 2);
    $old_monto_desc = round((float)($order['monto_descuento'] ?? 0), 2);
    $old_offers_total = 0.0;
    foreach ($lines as $l) $old_offers_total += (float)$l['precio_final'] * max(1, (int)$l['cantidad']);
    $old_coupon_component = round($old_monto_desc - ($old_subtotal - $old_offers_total), 2);

    $new_subtotal = 0.0;
    $new_offers_total = 0.0;
    $expiries = [];
    foreach ($kept as $l) {
        $q = max(1, (int)$l['cantidad']);
        $new_subtotal += (float)$l['precio_base'] * $q;
        $new_offers_total += (float)$l['precio_final'] * $q;
        if (!empty($l['fecha_expiracion_item'])) $expiries[] = strtotime((string)$l['fecha_expiracion_item'] . ' UTC');
    }
    foreach ($adds as $a) {
        $new_subtotal += $a['precio_base'] * $a['quantity'];
        $new_offers_total += $a['precio_final'] * $a['quantity'];
        if ($a['expira_ts'] !== null) $expiries[] = $a['expira_ts'];
    }
    $new_subtotal = round($new_subtotal, 2);
    $new_offers_total = round($new_offers_total, 2);

    $coupon_code = trim((string)($order['cupon_usado_codigo'] ?? ''));
    $coupon_component = 0.0;
    if ($coupon_code !== '' && abs($old_coupon_component) > 0.005) {
        $tipo = 'fijo';
        $stmt_c = $conn->prepare("SELECT tipo_descuento FROM cupones WHERE codigo = ? LIMIT 1");
        if ($stmt_c) {
            $stmt_c->bind_param('s', $coupon_code);
            $stmt_c->execute();
            $rc = $stmt_c->get_result()->fetch_assoc();
            $stmt_c->close();
            if ($rc && isset($rc['tipo_descuento'])) $tipo = strtolower((string)$rc['tipo_descuento']);
        }
        $is_percent = strpos($tipo, 'porc') !== false || strpos($tipo, 'percent') !== false;
        $coupon_component = ($is_percent && $old_subtotal > 0)
            ? round($old_coupon_component * ($new_subtotal / $old_subtotal), 2)
            : $old_coupon_component;
        $coupon_component = min($coupon_component, $new_offers_total);
    }

    $total_before_coins = round(max(0.0, $new_offers_total - $coupon_component), 2);

    $coins_redeemed = round((float)($order['coins_redeemed'] ?? 0), 2);
    $coins_value = round((float)($order['coins_value_money'] ?? 0), 2);
    $coins_excess_refund = 0.0;
    if ($coins_redeemed > 0 && $coins_value > $total_before_coins + 0.005) {
        $unit_coins = max(1.0, bs_setting_float($conn, 'coins_unit_coins', 100.0));
        $unit_money = bs_setting_float($conn, 'coins_unit_money', 1.0);
        if ($unit_money > 0) {
            $excess_money = $coins_value - $total_before_coins;
            $coins_excess_refund = floor(($excess_money * $unit_coins) / $unit_money);
            $coins_excess_refund = min($coins_excess_refund, $coins_redeemed);
            if ($coins_excess_refund > 0) {
                $coins_redeemed = round($coins_redeemed - $coins_excess_refund, 2);
                $coins_value = round(($coins_redeemed * $unit_money) / $unit_coins, 2);
            }
        }
    }

    $new_total = round(max(0.0, $total_before_coins - $coins_value), 2);
    $new_monto_desc = round($new_subtotal - $total_before_coins, 2);

    $cashback_mode = (string)($order['cashback_mode'] ?? 'none');
    $new_cashback = bs_compute_cashback($conn, $cashback_mode, $new_total);

    $user_id = (int)($order['user_id'] ?? 0);
    $cashback_delivered = ($estado === 'completado' && $user_id > 0) ? bs_order_cashback_delivered($conn, $order_id, $user_id) : 0.0;
    $cashback_delta = $estado === 'completado' ? round($new_cashback - $cashback_delivered, 2) : 0.0;
    $balance = $user_id > 0 ? bs_user_balance($conn, $user_id) : 0.0;
    $cashback_shortfall = ($cashback_delta < 0 && $balance + $cashback_delta < 0) ? round(-($balance + $cashback_delta), 2) : 0.0;

    $expiry_min = null;
    $expiries = array_values(array_filter($expiries, static fn($t) => is_int($t) && $t > 0));
    if ($expiries) $expiry_min = gmdate('Y-m-d H:i:s', min($expiries));

    return [
        'estado'              => $estado,
        'kept'                => $kept,
        'adds'                => $adds,
        'remove_ids'          => $remove_ids,
        'units_total'         => $units_total,
        'old'                 => ['subtotal' => $old_subtotal, 'total' => $old_total, 'monto_descuento' => $old_monto_desc, 'cashback' => round((float)($order['cashback_earned'] ?? 0), 2), 'coins_redeemed' => round((float)($order['coins_redeemed'] ?? 0), 2)],
        'new'                 => ['subtotal' => $new_subtotal, 'offers_total' => $new_offers_total, 'coupon_discount' => round($coupon_component, 2), 'monto_descuento' => $new_monto_desc, 'coins_redeemed' => $coins_redeemed, 'coins_value_money' => $coins_value, 'total' => $new_total, 'cashback' => $new_cashback],
        'total_delta'         => round($new_total - $old_total, 2),
        'coins_excess_refund' => $coins_excess_refund,
        'cashback_delivered'  => $cashback_delivered,
        'cashback_delta'      => $cashback_delta,
        'cashback_shortfall'  => $cashback_shortfall,
        'user_balance'        => $balance,
        'fecha_expiracion_oferta' => $expiry_min,
    ];
}

/** Aplica el resultado de bs_compute_order_edit dentro de la transacción abierta. */
function bs_apply_order_edit(mysqli $conn, array $order, array $calc): void {
    $order_id = (int)$order['id'];
    $user_id = (int)($order['user_id'] ?? 0);

    if ($calc['remove_ids']) {
        $ph = implode(',', array_fill(0, count($calc['remove_ids']), '?'));
        $types = str_repeat('i', count($calc['remove_ids']) + 1);
        $stmt = $conn->prepare("DELETE dpd FROM detalles_pedido_destinatarios dpd JOIN detalles_pedido dp ON dp.id = dpd.detalle_pedido_id WHERE dp.pedido_id = ? AND dp.id IN ($ph)");
        if (!$stmt) throw new Exception('Error al preparar borrado de destinatarios.');
        $stmt->bind_param($types, $order_id, ...$calc['remove_ids']);
        if (!$stmt->execute()) throw new Exception('Error al borrar destinatarios: ' . $stmt->error);
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM detalles_pedido WHERE pedido_id = ? AND id IN ($ph)");
        if (!$stmt) throw new Exception('Error al preparar borrado de productos.');
        $stmt->bind_param($types, $order_id, ...$calc['remove_ids']);
        if (!$stmt->execute()) throw new Exception('Error al borrar productos: ' . $stmt->error);
        $stmt->close();
    }

    if ($calc['adds']) {
        $stmt_d = $conn->prepare(
            "INSERT INTO detalles_pedido (pedido_id, producto_id_str, nombre_producto, imagen_producto_url, precio_base, precio_final, cantidad, tipo_producto, section_name, store_rank, v_bucks, color_1, color_2, color_3, fecha_expiracion_item)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt_r = $conn->prepare("INSERT INTO detalles_pedido_destinatarios (detalle_pedido_id, nickname_destinatario) VALUES (?, ?)");
        if (!$stmt_d || !$stmt_r) throw new Exception('Error al preparar inserción de productos.');

        foreach ($calc['adds'] as $a) {
            $pid = htmlspecialchars($a['producto_id_str'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($a['nombre'], ENT_QUOTES, 'UTF-8');
            $img = htmlspecialchars($a['imagen'], ENT_QUOTES, 'UTF-8');
            $base_s = number_format($a['precio_base'], 2, '.', '');
            $final_s = number_format($a['precio_final'], 2, '.', '');
            $one = 1;
            $tipo = $a['tipo'];
            $section = htmlspecialchars($a['section_name'] !== '' ? $a['section_name'] : 'General', ENT_QUOTES, 'UTF-8');
            $rank = (int)$a['store_rank'];
            $vb = (int)$a['vbucks'];
            [$c1, $c2, $c3] = $a['colors'];
            $exp = $a['expira_ts'] !== null ? gmdate('Y-m-d H:i:s', $a['expira_ts']) : null;
            $recipient = $a['recipient'] !== '' ? htmlspecialchars($a['recipient'], ENT_QUOTES, 'UTF-8') : '';

            for ($i = 0; $i < (int)$a['quantity']; $i++) {
                $stmt_d->bind_param('isssssissiissss', $order_id, $pid, $name, $img, $base_s, $final_s, $one, $tipo, $section, $rank, $vb, $c1, $c2, $c3, $exp);
                if (!$stmt_d->execute()) throw new Exception('Error al insertar producto: ' . $stmt_d->error);
                if ($recipient !== '') {
                    $det_id = $stmt_d->insert_id;
                    $stmt_r->bind_param('is', $det_id, $recipient);
                    if (!$stmt_r->execute()) throw new Exception('Error al insertar destinatario: ' . $stmt_r->error);
                }
            }
        }
        $stmt_d->close();
        $stmt_r->close();
    }

    $n = $calc['new'];
    $stmt = $conn->prepare(
        "UPDATE pedidos
            SET subtotal = ?, monto_descuento = ?, total_pagar = ?, cashback_earned = ?,
                coins_redeemed = ?, coins_value_money = ?, fecha_expiracion_oferta = ?
          WHERE id = ?"
    );
    if (!$stmt) throw new Exception('Error al preparar actualización del pedido.');
    $stmt->bind_param('ddddddsi', $n['subtotal'], $n['monto_descuento'], $n['total'], $n['cashback'], $n['coins_redeemed'], $n['coins_value_money'], $calc['fecha_expiracion_oferta'], $order_id);
    if (!$stmt->execute()) throw new Exception('Error al actualizar el pedido: ' . $stmt->error);
    $stmt->close();

    if ($user_id <= 0) return;

    $codigo = (string)($order['codigo_pedido'] ?? $order_id);

    if ($calc['coins_excess_refund'] > 0) {
        $refund = (float)$calc['coins_excess_refund'];
        bs_adjust_balance($conn, $user_id, $refund);
        bs_insert_coin_tx($conn, $user_id, $refund, 'refund', $order_id);
        bs_notify($conn, $user_id, 'reward', 'Bananos devueltos', 'Tu pedido #' . $codigo . ' fue ajustado y te devolvimos ' . number_format($refund, 0) . ' Bananos que ya no hacían falta.');
    }

    if ($calc['estado'] === 'completado') {
        $delta_total = round($n['total'] - $calc['old']['total'], 2);
        if (abs($delta_total) >= 0.005) {
            $is_self = trim((string)($order['recipient_mode'] ?? '')) === 'self';
            $self_delta = $is_self ? $delta_total : 0.0;
            $stmt = $conn->prepare(
                "INSERT INTO user_stats (user_id, total_spent_total, orders_count_total, total_spent_completed_self, orders_count_completed_self) VALUES (?, 0, 0, 0, 0)
                 ON DUPLICATE KEY UPDATE
                    total_spent_total = GREATEST(0, total_spent_total + ?),
                    total_spent_completed_self = GREATEST(0, total_spent_completed_self + ?)"
            );
            if (!$stmt) throw new Exception('Error al preparar ajuste de estadísticas.');
            $stmt->bind_param('idd', $user_id, $delta_total, $self_delta);
            if (!$stmt->execute()) throw new Exception('Error al ajustar estadísticas: ' . $stmt->error);
            $stmt->close();
        }

        $delta = (float)$calc['cashback_delta'];
        if (abs($delta) >= 0.005) {
            $applied = $delta;
            if ($delta < 0 && $calc['cashback_shortfall'] > 0) {
                $applied = round($delta + $calc['cashback_shortfall'], 2);
            }
            bs_adjust_balance($conn, $user_id, $applied);

            $ref = (string)$order_id;
            $stmt = $conn->prepare("DELETE FROM coin_transactions WHERE user_id = ? AND reference_id = ? AND reason = 'cashback'");
            if ($stmt) {
                $stmt->bind_param('is', $user_id, $ref);
                $stmt->execute();
                $stmt->close();
            }
            $new_cb = (float)$n['cashback'];
            if ($new_cb > 0) bs_insert_coin_tx($conn, $user_id, $new_cb, 'cashback', $order_id);

            $abs = number_format(abs($applied), 0);
            $msg = $applied > 0
                ? "Tu pedido #$codigo fue ajustado: recibiste $abs Bananos adicionales de cashback."
                : "Tu pedido #$codigo fue ajustado: se descontaron $abs Bananos de cashback de tu saldo.";
            bs_notify($conn, $user_id, 'reward', 'Cashback ajustado', $msg);
        }
    }
}
