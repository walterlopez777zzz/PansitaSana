<?php
/**
 * ============================================================================
 * BANANO STORE — COMMUNITY JAM TRACK VIDEOS (video_community.php)
 * ============================================================================
 * Public API behind the gateway action 'video_community' (obfuscated 'v5c').
 * Subs (promoted to $_REQUEST['action'] by web.php):
 *
 *   state  → derived video state of a track (+ source: community | system),
 *            media (youtube | audio | video + URL), publish instant,
 *            contributor name + avatar, configured rewards/policies, the
 *            active report reasons, viewer status
 *            (guest | banned | unverified | verified), whether this viewer
 *            may upload / report / edit and whether the video is their own. Public.
 *   top10  → top contributors (public, only public name + avatar).
 *   submit → user submits a YouTube video; instant reward, instant publish.
 *            Who may submit depends on the admin upload policy and the
 *            master upload switch.
 *   edit   → the author fixes link / start second of their own published
 *            video (never once verified; while reported only if allowed).
 *            Every change lands in video_submission_edits.
 *   delete → the author withdraws their own published video: the track is
 *            emptied, the submission becomes 'deleted', the upload reward is
 *            reversed (as far as the balance allows) and an audit row lands
 *            in video_submission_edits (track_state 'deleted').
 *   report → user opens the single active report for a community video.
 *            Unverified accounts depend on the admin report toggle.
 *
 * Bans, locks, policies and the single-active-report rule are all enforced
 * server-side.
 * ============================================================================
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/session_security.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/video_community_lib.php';

function vc_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function vc_fail(string $message, int $code = 400): void
{
    vc_out(['status' => 'error', 'message' => $message], $code);
}

function vc_session_user_id(): int
{
    if (!isset($_SESSION['user_id'], $_SESSION['logged_in_client']) || $_SESSION['logged_in_client'] !== true) {
        return 0;
    }
    return (int) $_SESSION['user_id'];
}

function vc_require_user(): int
{
    $userId = vc_session_user_id();
    if ($userId <= 0) vc_fail('Inicia sesión para participar.', 401);
    return $userId;
}

$action    = $_REQUEST['action'] ?? '';
$trackName = trim((string) ($_REQUEST['track_name'] ?? ''));
$cosmeticId = strtolower(trim((string) ($_REQUEST['cosmetic_id'] ?? '')));

if ($cosmeticId !== '' && (strlen($cosmeticId) > 120 || !preg_match('/^[a-z0-9_.\-]+$/', $cosmeticId))) {
    vc_fail('Identificador inválido.');
}

try {
    vc_ensure_schema($conn);

    switch ($action) {
        case 'state': {
            if ($trackName === '' || mb_strlen($trackName) > 255) vc_fail('Pista inválida.');
            $state = vc_track_state($conn, $trackName);
            $rewards = vc_get_settings($conn);
            $userId = vc_session_user_id();
            $viewer = vc_viewer_status($conn, $userId);
            $canReport = vc_can_report($state, $viewer, $rewards['report_unverified']);
            $isOwner = $userId > 0 && $state['owner_id'] === $userId;
            $canEdit = vc_can_edit($state, $userId, $rewards);
            vc_out([
                'status'        => 'success',
                'state'         => $state['state'],
                'is_locked'     => $state['is_locked'],
                'contributor'   => $state['contributor'],
                'contributor_avatar' => $state['contributor_avatar'],
                'source'        => $state['source'],
                'media_type'    => $state['media_type'],
                'media_url'     => $state['media_url'],
                'published_at'  => $state['published_at'],
                'upload_reward' => $rewards['upload_reward'],
                'report_reward' => $rewards['report_reward'],
                'top_visible'   => $rewards['top_visible'],
                'upload_policy' => $rewards['upload_policy'],
                'upload_enabled' => $rewards['upload_enabled'],
                'viewer'        => $viewer,
                'can_upload'    => vc_can_upload($viewer, $rewards['upload_policy'], $rewards['upload_enabled']),
                'can_report'    => $canReport,
                'is_owner'      => $isOwner,
                'can_edit'      => $canEdit,
                'can_delete'    => $canEdit,
                // What the author would lose by withdrawing, and the global
                // balance it comes out of.
                'reward_net'    => $canEdit && $state['submission_id'] ? vc_reward_net($conn, $userId, (int) $state['submission_id']) : null,
                'balance'       => $canEdit ? vc_coin_balance($conn, $userId) : null,
                // Only the author needs the current values to prefill the editor.
                'youtube_id'    => $canEdit ? $state['youtube_id'] : null,
                'start_time'    => $canEdit ? $state['start_time'] : null,
                'report_reasons' => $canReport ? vc_public_report_reasons($conn) : [],
            ]);
            break;
        }

        case 'top10': {
            $settings = vc_get_settings($conn);
            if (!$settings['top_visible']) vc_fail('El ranking no está disponible.', 404);
            vc_out(['status' => 'success', 'top' => vc_top_contributors($conn, $settings['top_limit'])]);
            break;
        }

        case 'submit': {
            $userId = vc_require_user();
            if ($trackName === '' || mb_strlen($trackName) > 255) vc_fail('Pista inválida.');

            $youtubeId = vc_extract_youtube_id((string) ($_REQUEST['youtube_url'] ?? ''));
            if ($youtubeId === null) {
                vc_fail('El enlace no es un video válido de YouTube (youtube.com o youtu.be).');
            }
            $startSeconds = max(0, min(36000, (int) ($_REQUEST['start_seconds'] ?? 0)));

            $viewer = vc_viewer_status($conn, $userId);
            if ($viewer === 'banned') {
                vc_fail('Tu acceso al sistema de videos está suspendido.', 403);
            }
            $rewards = vc_get_settings($conn);
            if (!$rewards['upload_enabled']) {
                vc_fail('La subida de videos está pausada temporalmente. Vuelve más tarde.', 403);
            }
            if (!vc_can_upload($viewer, $rewards['upload_policy'], true)) {
                vc_fail('Solo las cuentas verificadas pueden subir videos.', 403);
            }

            // Rate limit (admin-configurable, can be switched off): at most
            // N submissions per user per rolling 24h.
            if ($rewards['upload_limit_enabled']) {
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) AS n FROM video_submissions
                     WHERE user_id = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
                );
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $recent = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
                $stmt->close();
                if ($recent >= $rewards['upload_daily_limit']) {
                    vc_fail("Has alcanzado el límite de {$rewards['upload_daily_limit']} envíos por día. Vuelve mañana.", 429);
                }
            }

            // Same YouTube video already published for another track → reject.
            $stmt = $conn->prepare(
                "SELECT nombre_pista FROM pistas_musicales
                 WHERE youtube_id = ? AND nombre_pista != ? LIMIT 1"
            );
            $stmt->bind_param('ss', $youtubeId, $trackName);
            $stmt->execute();
            $dupe = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dupe) {
                vc_fail('Ese video ya está asociado a otra pista.');
            }

            $check = vc_validate_youtube($youtubeId);
            if (!$check['ok']) {
                vc_fail('El video no existe en YouTube o no está disponible.');
            }

            $reward = $rewards['upload_reward'];

            $conn->begin_transaction();
            try {
                // Lock the track row (if any) so two users can't publish at once.
                $stmt = $conn->prepare("SELECT id, youtube_id, media_file, is_locked FROM pistas_musicales WHERE nombre_pista = ? FOR UPDATE");
                $stmt->bind_param('s', $trackName);
                $stmt->execute();
                $pista = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($pista && (int) $pista['is_locked'] === 1) {
                    throw new Exception('Esta pista ya tiene un video verificado.');
                }
                if (vc_row_has_media($pista)) {
                    throw new Exception('Esta pista ya tiene un video publicado.');
                }

                $status = 'published';
                $title = $check['title'];
                $stmt = $conn->prepare(
                    "INSERT INTO video_submissions
                        (user_id, cosmetic_id, track_name, youtube_id, start_seconds, status, reward_amount, video_title)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('isssisis', $userId, $cosmeticId, $trackName, $youtubeId, $startSeconds, $status, $reward, $title);
                if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo guardar el envío.'); }
                $submissionId = (int) $conn->insert_id;
                $stmt->close();

                if ($pista) {
                    $stmt = $conn->prepare(
                        "UPDATE pistas_musicales
                         SET youtube_id = ?, start_time = ?, cosmetic_id = ?, submission_id = ?,
                             media_type = 'youtube', media_file = NULL, published_at = UTC_TIMESTAMP()
                         WHERE id = ?"
                    );
                    $pistaId = (int) $pista['id'];
                    $stmt->bind_param('sisii', $youtubeId, $startSeconds, $cosmeticId, $submissionId, $pistaId);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO pistas_musicales (nombre_pista, youtube_id, start_time, cosmetic_id, submission_id, media_type, published_at)
                         VALUES (?, ?, ?, ?, ?, 'youtube', UTC_TIMESTAMP())"
                    );
                    $stmt->bind_param('ssisi', $trackName, $youtubeId, $startSeconds, $cosmeticId, $submissionId);
                }
                if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo publicar el video.'); }
                $stmt->close();

                vc_credit_coins($conn, $userId, $reward, 'video_reward', 'video_submission', $submissionId);
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                vc_fail($e->getMessage());
            }

            $published = vc_track_state($conn, $trackName);
            vc_out([
                'status'  => 'success',
                'message' => "¡Gracias! Tu video ya está publicado y recibiste {$reward} Bananos.",
                'reward'  => $reward,
                'video'   => [
                    'media_type'   => 'youtube',
                    'youtube_id'   => $youtubeId,
                    'start_time'   => $startSeconds,
                    'published_at' => $published['published_at'],
                ],
            ]);
            break;
        }

        case 'edit': {
            $userId = vc_require_user();
            if ($trackName === '' || mb_strlen($trackName) > 255) vc_fail('Pista inválida.');

            $youtubeId = vc_extract_youtube_id((string) ($_REQUEST['youtube_url'] ?? ''));
            if ($youtubeId === null) {
                vc_fail('El enlace no es un video válido de YouTube (youtube.com o youtu.be).');
            }
            $startSeconds = max(0, min(36000, (int) ($_REQUEST['start_seconds'] ?? 0)));

            $viewer = vc_viewer_status($conn, $userId);
            if ($viewer === 'banned') {
                vc_fail('Tu acceso al sistema de videos está suspendido.', 403);
            }
            $rewards = vc_get_settings($conn);
            $state = vc_track_state($conn, $trackName);
            if ($state['state'] === 'missing' || $state['pista_id'] === null) vc_fail('Esta pista no tiene un video publicado.');
            if ($state['owner_id'] !== $userId) vc_fail('Solo el autor puede editar este video.', 403);
            if ($state['is_locked']) vc_fail('Este video ya fue verificado por el equipo y no se puede editar.');
            if (!vc_can_edit($state, $userId, $rewards)) {
                vc_fail('Este video tiene un reporte en revisión; el equipo decidirá si puedes corregirlo.', 403);
            }

            $oldYoutubeId = (string) $state['youtube_id'];
            $oldStart = (int) $state['start_time'];
            if ($oldYoutubeId === $youtubeId && $oldStart === $startSeconds) {
                vc_fail('No hay cambios que guardar.');
            }

            if ($oldYoutubeId !== $youtubeId) {
                $stmt = $conn->prepare(
                    "SELECT nombre_pista FROM pistas_musicales
                     WHERE youtube_id = ? AND nombre_pista != ? LIMIT 1"
                );
                $stmt->bind_param('ss', $youtubeId, $trackName);
                $stmt->execute();
                $dupe = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($dupe) vc_fail('Ese video ya está asociado a otra pista.');

                $check = vc_validate_youtube($youtubeId);
                if (!$check['ok']) vc_fail('El video no existe en YouTube o no está disponible.');
            }

            $submissionId = (int) $state['submission_id'];
            $pistaId = (int) $state['pista_id'];
            $conn->begin_transaction();
            try {
                // Re-read under lock: the panel may have verified or removed it meanwhile.
                $stmt = $conn->prepare("SELECT youtube_id, submission_id, is_locked FROM pistas_musicales WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $pistaId);
                $stmt->execute();
                $pista = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$pista || (int) $pista['is_locked'] === 1 || (int) $pista['submission_id'] !== $submissionId
                    || (string) $pista['youtube_id'] !== $oldYoutubeId) {
                    throw new Exception('El video cambió mientras editabas. Recarga la página.');
                }

                $stmt = $conn->prepare("UPDATE pistas_musicales SET youtube_id = ?, start_time = ? WHERE id = ?");
                $stmt->bind_param('sii', $youtubeId, $startSeconds, $pistaId);
                if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo actualizar la pista.'); }
                $stmt->close();

                $title = isset($check) && $check['ok'] ? $check['title'] : null;
                if ($title !== null) {
                    $stmt = $conn->prepare(
                        "UPDATE video_submissions SET youtube_id = ?, start_seconds = ?, video_title = ?, edited_at = UTC_TIMESTAMP()
                         WHERE id = ? AND user_id = ?"
                    );
                    $stmt->bind_param('sisii', $youtubeId, $startSeconds, $title, $submissionId, $userId);
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE video_submissions SET youtube_id = ?, start_seconds = ?, edited_at = UTC_TIMESTAMP()
                         WHERE id = ? AND user_id = ?"
                    );
                    $stmt->bind_param('siii', $youtubeId, $startSeconds, $submissionId, $userId);
                }
                if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo actualizar el envío.'); }
                $stmt->close();

                vc_log_submission_edit(
                    $conn, $submissionId, $userId, VC_EDIT_BY_USER, null,
                    $oldYoutubeId, $oldStart, $youtubeId, $startSeconds, (string) $state['state']
                );
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                vc_fail($e->getMessage());
            }

            vc_out([
                'status'  => 'success',
                'message' => 'Cambios guardados. Tu video ya se reproduce con la nueva configuración.',
                'video'   => [
                    'media_type'   => 'youtube',
                    'youtube_id'   => $youtubeId,
                    'start_time'   => $startSeconds,
                    'published_at' => $state['published_at'],
                ],
            ]);
            break;
        }

        case 'delete': {
            $userId = vc_require_user();
            if ($trackName === '' || mb_strlen($trackName) > 255) vc_fail('Pista inválida.');

            $viewer = vc_viewer_status($conn, $userId);
            if ($viewer === 'banned') {
                vc_fail('Tu acceso al sistema de videos está suspendido.', 403);
            }
            $rewards = vc_get_settings($conn);
            $state = vc_track_state($conn, $trackName);
            if ($state['state'] === 'missing' || $state['pista_id'] === null) vc_fail('Esta pista no tiene un video publicado.');
            if ($state['owner_id'] !== $userId) vc_fail('Solo el autor puede eliminar este video.', 403);
            if ($state['is_locked']) vc_fail('Este video ya fue verificado por el equipo y no se puede eliminar.');
            if (!vc_can_edit($state, $userId, $rewards)) {
                vc_fail('Este video tiene un reporte en revisión; el equipo decidirá qué pasa con él.', 403);
            }

            $submissionId = (int) $state['submission_id'];
            $pistaId = (int) $state['pista_id'];
            $oldYoutubeId = (string) $state['youtube_id'];
            $oldStart = (int) $state['start_time'];
            $debited = 0;
            $removedFile = null;
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT * FROM video_submissions WHERE id = ? AND user_id = ? FOR UPDATE");
                $stmt->bind_param('ii', $submissionId, $userId);
                $stmt->execute();
                $sub = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$sub || $sub['status'] !== 'published') {
                    throw new Exception('El video cambió mientras lo eliminabas. Recarga la página.');
                }

                $stmt = $conn->prepare("SELECT submission_id, is_locked FROM pistas_musicales WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $pistaId);
                $stmt->execute();
                $pista = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$pista || (int) $pista['is_locked'] === 1 || (int) $pista['submission_id'] !== $submissionId) {
                    throw new Exception('El video cambió mientras lo eliminabas. Recarga la página.');
                }

                $removedFile = vc_clear_track_media($conn, $pistaId);

                $reason = 'Eliminado por el autor';
                $stmt = $conn->prepare(
                    "UPDATE video_submissions
                     SET status = 'deleted', reject_reason = ?, reviewed_by = NULL, reviewed_at = UTC_TIMESTAMP(), edit_allowed = 0
                     WHERE id = ? AND user_id = ?"
                );
                $stmt->bind_param('sii', $reason, $submissionId, $userId);
                if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo actualizar el envío.'); }
                $stmt->close();

                $debited = vc_reverse_reward($conn, $sub);

                vc_log_submission_edit(
                    $conn, $submissionId, $userId, VC_EDIT_BY_USER, null,
                    $oldYoutubeId, $oldStart, '', 0, VC_EDIT_STATE_DELETED
                );
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                vc_fail($e->getMessage());
            }
            if ($removedFile !== null) vc_delete_media_file($removedFile);

            vc_out([
                'status'  => 'success',
                'message' => $debited > 0
                    ? "Video eliminado. Se descontaron {$debited} Bananos de tu saldo."
                    : 'Video eliminado.',
                'reward_reversed' => $debited,
                'balance' => vc_coin_balance($conn, $userId),
                'state'   => 'missing',
            ]);
            break;
        }

        case 'report': {
            $userId = vc_require_user();
            if ($trackName === '' || mb_strlen($trackName) > 255) vc_fail('Pista inválida.');

            $reason = trim((string) ($_REQUEST['reason'] ?? ''));
            if ($reason === '' || strlen($reason) > VC_REPORT_REASON_CODE_MAX || !vc_report_reason_is_active($conn, $reason)) {
                vc_fail('Selecciona un motivo válido.');
            }
            $viewer = vc_viewer_status($conn, $userId);
            if ($viewer === 'banned') {
                vc_fail('Tu acceso al sistema de videos está suspendido.', 403);
            }

            $state = vc_track_state($conn, $trackName);
            if ($state['state'] === 'locked') vc_fail('Este video fue verificado por el equipo y no admite reportes.');
            if ($state['state'] === 'reported') vc_fail('Este video ya tiene un reporte en revisión.');
            if ($state['state'] !== 'published' || $state['pista_id'] === null) vc_fail('Esta pista no tiene un video para reportar.');
            if ($state['source'] !== VC_SOURCE_COMMUNITY) vc_fail('Este video fue añadido por Banano Store y no admite reportes.');

            $rewards = vc_get_settings($conn);
            if (!vc_can_report($state, $viewer, $rewards['report_unverified'])) {
                vc_fail('Solo las cuentas verificadas pueden reportar videos.', 403);
            }

            // Reporting your own video is not allowed.
            if ($state['owner_id'] === $userId) {
                vc_fail('No puedes reportar tu propio video.');
            }

            $stmt = $conn->prepare(
                "INSERT INTO video_reports (pista_id, submission_id, reporter_id, reason, reward_amount)
                 SELECT p.id, p.submission_id, ?, ?, ?
                 FROM pistas_musicales p WHERE p.id = ?"
            );
            $reportReward = $rewards['report_reward'];
            $stmt->bind_param('isii', $userId, $reason, $reportReward, $state['pista_id']);
            $ok = $stmt->execute();
            $errno = $conn->errno;
            $stmt->close();
            if (!$ok) {
                if ($errno === 1062) {
                    vc_fail('Este video ya tiene un reporte en revisión.');
                }
                vc_fail('No se pudo enviar el reporte. Inténtalo de nuevo.');
            }

            vc_out([
                'status'  => 'success',
                'message' => 'Reporte enviado. El equipo lo revisará y, si es correcto, recibirás '
                    . $rewards['report_reward'] . ' Bananos.',
            ]);
            break;
        }

        default:
            vc_fail('Acción no reconocida.', 404);
    }
} catch (Throwable $e) {
    vc_fail('Error interno. Inténtalo de nuevo.', 500);
}
