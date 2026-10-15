<?php
// Helper de esquema autoreparable para la tabla `pedidos` (mismo patrón que
// rr_ensure_schema en api/raffle_review.php). Evita requerir una migración
// manual cuando se añaden columnas nuevas.
//
// `cancelado_auto`: distingue una cancelación AUTOMÁTICA por reserva/oferta
// expirada (1) de una cancelación MANUAL del staff (0). Lo escriben todos los
// puntos que cancelan por expiración (api_pedidos.php, checkout/confirmation.php
// y api/check_order_status.php) para que el panel muestre el indicador discreto.
//
// IMPORTANTE: debe llamarse FUERA de una transacción abierta, porque ALTER TABLE
// provoca un commit implícito en MySQL.

if (!function_exists('bs_ensure_pedidos_schema')) {
    function bs_ensure_pedidos_schema(mysqli $conn): void
    {
        $col = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'cancelado_auto'");
        if ($col && $col->num_rows === 0) {
            $conn->query(
                "ALTER TABLE pedidos
                 ADD COLUMN cancelado_auto TINYINT(1) NOT NULL DEFAULT 0"
            );
        }
    }
}
