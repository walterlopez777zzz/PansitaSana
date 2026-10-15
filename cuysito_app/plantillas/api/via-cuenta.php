<?php
/**
 * Catálogo "Vía Cuenta" para el editor interno /plantillas.
 *
 * Lector de solo lectura sobre `productos_estaticos` (productos estáticos).
 * A diferencia del endpoint público `api/get_productos_estaticos.php`:
 *   - devuelve JSON plano (sin cifrado) porque /plantillas es una herramienta
 *     interna bloqueada en producción y protegida por Access en staging;
 *   - no ejecuta mutaciones de auto-limpieza (un lector no debe escribir);
 *   - devuelve TODOS los productos con su estado (`active`, `stock`,
 *     expiraciones) para que el editor decida qué listar y qué solo
 *     ofrecer mediante búsqueda manual;
 *   - no habilita CORS: solo se consume same-origin con la cookie de sesión.
 *
 * Método: GET. Respuesta: { generatedAt, products: ViaCuentaProduct[] }.
 * Soporta ETag / If-None-Match para que el cliente revalide barato.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../api/session_security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../../api/db_connect.php';

/** @var mysqli $conn */

const VIA_CUENTA_SELECT = 'SELECT
        id, nombre, descripcion, categoria, imagen_url,
        activo, stock_cantidad,
        precio_base, precio_oferta, tipo_oferta,
        fecha_expiracion, oferta_fecha_expiracion,
        etiqueta_texto, etiqueta_color_fondo, etiqueta_color_texto,
        orden_categoria, orden
    FROM productos_estaticos
    ORDER BY orden_categoria ASC, orden ASC, id ASC';

/**
 * Convierte un DATETIME UTC de MySQL ("Y-m-d H:i:s") a ISO 8601 con sufijo Z.
 * Devuelve null para valores vacíos o no parseables.
 */
function via_cuenta_iso_utc(?string $mysqlDateTime): ?string
{
    if ($mysqlDateTime === null || $mysqlDateTime === '' || $mysqlDateTime === '0000-00-00 00:00:00') {
        return null;
    }
    $timestamp = strtotime($mysqlDateTime . ' UTC');
    return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
}

/**
 * Precio en soles como número con dos decimales exactos (nunca redondeo a décimas).
 * Devuelve null cuando la columna es NULL o no numérica.
 */
function via_cuenta_money(string|int|float|null $value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return round((float)$value, 2);
}

/**
 * Normaliza una fila de `productos_estaticos` al contrato del editor.
 *
 * Contrato ViaCuentaProduct:
 *   id: string            — identificador estable ("vc:<id>" se compone en cliente)
 *   name, description, category, imageUrl: string
 *   active: bool          — `activo = 1`
 *   stock: int            — -1 = ilimitado, 0 = agotado, >0 = unidades
 *   basePriceSoles: float — precio base en soles (fuente de verdad del editor)
 *   offerPriceSoles: float|null — precio de oferta final (solo si el modo ofertas está activo)
 *   offerKind: "precio_fijo" | "porcentaje"
 *   expiresAt, offerExpiresAt: ISO 8601 UTC | null
 *   label: { text, bg, fg } | null — etiqueta configurada en el panel /wabo
 */
function via_cuenta_normalize_row(array $row): array
{
    $labelText = trim((string)($row['etiqueta_texto'] ?? ''));

    return [
        'id'              => (string)$row['id'],
        'name'            => (string)($row['nombre'] ?? ''),
        'description'     => (string)($row['descripcion'] ?? ''),
        'category'        => (string)($row['categoria'] ?? ''),
        'imageUrl'        => (string)($row['imagen_url'] ?? ''),
        'active'          => (int)($row['activo'] ?? 0) === 1,
        'stock'           => (int)($row['stock_cantidad'] ?? 0),
        'basePriceSoles'  => via_cuenta_money($row['precio_base'] ?? null) ?? 0.0,
        'offerPriceSoles' => via_cuenta_money($row['precio_oferta'] ?? null),
        'offerKind'       => (string)($row['tipo_oferta'] ?? 'precio_fijo'),
        'expiresAt'       => via_cuenta_iso_utc($row['fecha_expiracion'] ?? null),
        'offerExpiresAt'  => via_cuenta_iso_utc($row['oferta_fecha_expiracion'] ?? null),
        'label'           => $labelText === '' ? null : [
            'text' => $labelText,
            'bg'   => (string)($row['etiqueta_color_fondo'] ?? ''),
            'fg'   => (string)($row['etiqueta_color_texto'] ?? ''),
        ],
        'sortKey'         => [(int)($row['orden_categoria'] ?? 0), (int)($row['orden'] ?? 0)],
    ];
}

$result = $conn->query(VIA_CUENTA_SELECT);
if ($result === false) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['error' => 'Catalog query failed']);
    exit;
}

$products = [];
while (is_array($row = $result->fetch_assoc())) {
    $products[] = via_cuenta_normalize_row($row);
}
$result->free();
$conn->close();

$payload = json_encode(
    ['generatedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'products' => $products],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($payload === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Catalog encoding failed']);
    exit;
}

// El ETag se calcula solo sobre los productos: `generatedAt` cambia en cada
// petición y rompería la revalidación condicional.
$etag = '"' . sha1((string)json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
header('ETag: ' . $etag);

$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

echo $payload;
