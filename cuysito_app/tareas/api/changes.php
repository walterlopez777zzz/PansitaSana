<?php
/**
 * ============================================================
 * Tareas — Bitácora de cambios (tiempo real / SSE)
 * ============================================================
 * Capa mínima que alimenta el canal de tiempo real. Cada mutación
 * de la API "toca" una colección (`tareas_emit`) escribiendo una
 * fila efímera en `{prefix}changes`; el endpoint SSE (`events.php`)
 * lee esa bitácora y notifica al resto de pestañas/colaboradores
 * que deben resincronizar esa vista.
 *
 * Diseño deliberado (señal + resincronía incremental):
 *   - No se viaja la entidad por el canal, sólo "qué colección
 *     cambió y quién lo provocó". El cliente vuelve a pedir esa
 *     colección y el reconciliador incremental repinta SÓLO las
 *     tarjetas que de verdad cambiaron (sin parpadeo). Esto evita
 *     toda la familia de bugs de payloads desincronizados
 *     (orden, contadores, fijado, conversiones, renombrado de
 *     etiquetas que afecta a muchas tarjetas, etc.).
 *   - `actor` permite suprimir el "eco": quien origina el cambio
 *     ya lo aplicó de forma optimista y no debe repintarse.
 *
 * Robustez: registrar un cambio JAMÁS puede tumbar una mutación
 * que ya tuvo éxito. Toda esta capa traga sus propios errores
 * (incluido el caso de que la tabla aún no exista porque no se
 * corrió `setup_database.php`) y deja que la operación continúe.
 *
 * Se incluye desde `guard.php`, de modo que está disponible en
 * todos los endpoints de datos sin que cada uno lo importe.
 * ============================================================
 */

declare(strict_types=1);

/** Colecciones válidas que el cliente sabe resincronizar. */
const TAREAS_CHANGE_SCOPES = ['ideas', 'misiones', 'colaboradores', 'tags', 'all'];

/**
 * Ventana de retención de la bitácora (segundos). Una pestaña que
 * estuvo desconectada más que esto se resincroniza por completo al
 * reconectar (ver `events.php`), así que conservar más sería inútil.
 */
const TAREAS_CHANGE_RETENTION_SECONDS = 3600; // 1 hora

/**
 * Identificador de cliente que originó la petición. Lo envía el
 * frontend en la cabecera `X-Client-Id` (ver `assets/js/api.js`).
 * Se sanea a un token corto y seguro; si falta, devuelve ''.
 */
function tareas_client_id(): string
{
    $raw = $_SERVER['HTTP_X_CLIENT_ID'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '';
    return substr($clean, 0, 64);
}

/**
 * Registra que una colección cambió, atribuyéndolo al cliente actual.
 * Es la única función que el resto de endpoints necesita llamar:
 *
 *     tareas_emit($pdo, 'misiones');
 *
 * Nunca lanza: cualquier fallo (tabla ausente, error de BD) se
 * traga y se registra en el log del servidor.
 */
function tareas_emit(PDO $pdo, string $scope): void
{
    if (!in_array($scope, TAREAS_CHANGE_SCOPES, true)) {
        return;
    }

    $prefix = tareas_prefix();
    $actor  = tareas_client_id();

    try {
        $pdo->prepare(
            "INSERT INTO `{$prefix}changes` (scope, actor) VALUES (?, ?)"
        )->execute([$scope, $actor]);

        tareas_prune_changes($pdo, $prefix);
    } catch (Throwable $e) {
        // El tiempo real es auxiliar: si falla, la mutación ya tuvo
        // éxito y el resto de pestañas se enterará en su próxima
        // recarga. No propagamos para no romper la respuesta de la API.
        error_log('[tareas/changes] emit fallido: ' . $e->getMessage());
    }
}

/**
 * Poda probabilística de la bitácora: con baja frecuencia elimina las
 * filas más antiguas que la ventana de retención. Hacerlo de vez en
 * cuando (y no en cada inserción) mantiene la tabla acotada sin pagar
 * un DELETE en el camino crítico de cada mutación.
 */
function tareas_prune_changes(PDO $pdo, string $prefix): void
{
    // ~1 de cada 50 escrituras dispara la limpieza.
    if (random_int(1, 50) !== 1) {
        return;
    }
    try {
        $pdo->prepare(
            "DELETE FROM `{$prefix}changes`
              WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)"
        )->execute([TAREAS_CHANGE_RETENTION_SECONDS]);
    } catch (Throwable $e) {
        error_log('[tareas/changes] poda fallida: ' . $e->getMessage());
    }
}
