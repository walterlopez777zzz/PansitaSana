<?php
/**
 * ============================================================
 * Tareas — Canal de tiempo real (Server-Sent Events)
 * ============================================================
 * Mantiene UNA conexión HTTP persistente por pestaña y empuja
 * (server → cliente) avisos de "qué colección cambió". El cliente
 * (`assets/js/realtime.js`) recibe cada aviso y resincroniza esa
 * vista a través del mismo camino incremental de upsert/remove que
 * ya usa el resto de la app, así sólo se repintan las tarjetas que
 * de verdad cambiaron (sin parpadeo).
 *
 * Por qué SSE (y no WebSockets ni Mercure):
 *   - El flujo es unidireccional (server → cliente): basta `EventSource`.
 *   - No requiere ningún proceso/daemon aparte; corre dentro del mismo
 *     PHP/Apache del hosting compartido. Sólo hay que subir archivos.
 *
 * Diseño pensado para hosting compartido (Apache + php-fpm):
 *   - Libera el lock de sesión de inmediato (`session_write_close`): una
 *     conexión SSE vive decenas de segundos y, si retuviera la sesión,
 *     bloquearía TODO el resto de peticiones del mismo navegador.
 *   - Vida acotada (`TAREAS_SSE_MAX_SECONDS`): el stream se cierra solo
 *     y el navegador reconecta. Así ningún worker queda tomado para
 *     siempre y se respetan los límites de ejecución del hosting.
 *   - `id:` por evento = AUTO_INCREMENT de la bitácora. El navegador lo
 *     reenvía como `Last-Event-ID` al reconectar, de modo que el cursor
 *     se reanuda exacto sin perder ni duplicar avisos.
 *   - Heartbeat periódico (comentario `: …`) para mantener viva la
 *     conexión frente a proxies/balanceadores con timeouts de inactividad.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

/** Duración máxima del stream antes de pedir reconexión al cliente. */
const TAREAS_SSE_MAX_SECONDS = 25;
/**
 * Pausa entre sondeos del cursor cuando no hay novedades, en milisegundos.
 * Acota la latencia máxima con que un cambio de otro colaborador se ve: a
 * menor valor, más "instantáneo", pero más consultas por segundo y por
 * conexión. A 10 ms son ~100 sondeos/seg por conexión: agresivo, pero el
 * cuello de botella real no es la frecuencia sino los Entry Processes del
 * hosting (cada stream SSE ocupa uno durante su vida, ~25 s). Cuando sí hay
 * novedades el bucle re-sondea de inmediato (sin esta pausa).
 */
const TAREAS_SSE_POLL_MS = 10;
/** Cada cuánto enviar un heartbeat si no hubo eventos. */
const TAREAS_SSE_HEARTBEAT_SECONDS = 15;
/** Tope de filas entregadas por iteración (evita ráfagas enormes). */
const TAREAS_SSE_BATCH = 200;

tareas_session_start();

// La autenticación se valida una sola vez, al abrir el stream. Si la
// sesión no está autorizada respondemos 401: `EventSource` lo trata como
// error y deja de reconectar, que es justo lo que queremos (la pantalla
// ya está bloqueada). No exponemos cuerpo: el cliente sólo necesita el
// código de estado.
if (!tareas_is_authenticated()) {
    http_response_code(401);
    exit;
}

// A partir de aquí no volvemos a tocar la sesión: soltamos su lock para
// no bloquear el resto de la API mientras el stream siga abierto.
session_write_close();

tareas_sse_prepare_output();

$pdo    = TareasDB::connect();
$prefix = tareas_prefix();

// Cursor inicial: si el navegador reconecta trae `Last-Event-ID` y
// reanudamos justo después de ese id. En la primera conexión no hay
// historial que reproducir (el arranque de la página ya trajo datos
// frescos), así que partimos del último id existente.
$lastId = tareas_sse_initial_cursor($pdo, $prefix);

// Sugerencia de reconexión para el navegador (ms).
echo "retry: 3000\n\n";
tareas_sse_flush();

try {
    $select = $pdo->prepare(
        "SELECT `id`, `scope`, `actor`, UNIX_TIMESTAMP(`created_at`) AS `ts`
           FROM `{$prefix}changes`
          WHERE `id` > ?
          ORDER BY `id` ASC
          LIMIT " . TAREAS_SSE_BATCH
    );
} catch (Throwable $e) {
    // La tabla podría no existir aún (no se corrió setup_database.php).
    // Cerramos con gracia; el cliente reconectará y, cuando exista, fluirá.
    error_log('[tareas/events] no se pudo preparar la consulta: ' . $e->getMessage());
    exit;
}

$startedAt    = time();
$lastActivity = time();

while (!connection_aborted() && (time() - $startedAt) < TAREAS_SSE_MAX_SECONDS) {
    try {
        $select->execute([$lastId]);
        $rows = $select->fetchAll();
    } catch (Throwable $e) {
        // Un fallo transitorio de BD no debe tumbar al cliente: cerramos y
        // dejamos que reconecte (reanudará desde el mismo Last-Event-ID).
        error_log('[tareas/events] consulta fallida: ' . $e->getMessage());
        break;
    }

    foreach ($rows as $row) {
        $lastId = (int) $row['id'];
        tareas_sse_send_change($lastId, [
            'scope' => (string) $row['scope'],
            'actor' => (string) $row['actor'],
            'ts'    => (int) $row['ts'],
        ]);
        $lastActivity = time();
    }

    if ($rows) {
        // Hubo novedades: volvemos a sondear de inmediato por si llegaron
        // más mientras entregábamos este lote.
        tareas_sse_flush();
        continue;
    }

    // Sin novedades: heartbeat si toca y una pausa breve antes de re-sondear.
    if ((time() - $lastActivity) >= TAREAS_SSE_HEARTBEAT_SECONDS) {
        echo ": keep-alive\n\n";
        tareas_sse_flush();
        $lastActivity = time();
    }

    usleep(TAREAS_SSE_POLL_MS * 1000);
}

/**
 * Configura PHP para emitir un stream SSE sin buffering: desactiva la
 * compresión y los buffers de salida, fija las cabeceras del protocolo y
 * permite que el script siga vivo para detectar la desconexión del cliente.
 */
function tareas_sse_prepare_output(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    // Mantenemos el script vivo tras una desconexión para limpiar con
    // orden; `connection_aborted()` corta el bucle en la siguiente vuelta.
    ignore_user_abort(true);
    set_time_limit(0);

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Connection: keep-alive');
    header('X-Content-Type-Options: nosniff');
    // Desactiva el buffering de nginx en reverse proxies (no afecta a Apache).
    header('X-Accel-Buffering: no');
}

/**
 * Cursor de arranque del stream. Reanuda desde el último id conocido por el
 * cliente —ya sea por la cabecera estándar `Last-Event-ID` (reconexión
 * automática del navegador) o por el parámetro `?last_id` (reconexión
 * manual del cliente con backoff)—; en la primera conexión arranca desde el
 * último id existente para no reproducir historial ya conocido.
 *
 * Sólo un id POSITIVO se interpreta como cursor reanudable. Un `0` (o un
 * valor ausente/no numérico) significa «aún no tengo cursor»: arrancamos
 * desde el último id existente en lugar de reproducir toda la bitácora
 * retenida. Reproducir el historial en cada apertura inunda al cliente de
 * resincronías que compiten con sus propias ediciones y provocan parpadeos.
 */
function tareas_sse_initial_cursor(PDO $pdo, string $prefix): int
{
    foreach ([$_SERVER['HTTP_LAST_EVENT_ID'] ?? '', $_GET['last_id'] ?? ''] as $candidate) {
        if (is_string($candidate) && ctype_digit($candidate) && (int) $candidate > 0) {
            return (int) $candidate;
        }
    }

    try {
        $max = $pdo->query("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}changes`")
                   ->fetchColumn();
        return (int) $max;
    } catch (Throwable $e) {
        error_log('[tareas/events] cursor inicial fallido: ' . $e->getMessage());
        return 0;
    }
}

/** Emite un evento `change` con su id (Last-Event-ID) y carga JSON. */
function tareas_sse_send_change(int $id, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    echo "id: {$id}\n";
    echo "event: change\n";
    echo "data: {$json}\n\n";
}

/** Vuelca lo pendiente al cliente de inmediato. */
function tareas_sse_flush(): void
{
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}
