<?php
/**
 * ============================================================================
 * WABO ADMIN — COMMUNITY JAM TRACK VIDEOS API (api_videos_comunidad.php)
 * ============================================================================
 * Backend of the "Pistas Comunidad" panel (5 tabs: configuración, subida
 * admin, moderación, usuarios/colaboradores, Visual Tracks). Every action is transactional, idempotent
 * and quickly reversible. Coins ONLY move through vc_credit_coins /
 * vc_debit_coins, and reversals are computed from the ledger so an action run
 * twice can never debit twice.
 *
 *   overview          → settings + lists (published/rejected/reports/bans/locked/system media/edits).
 *   stats             → Visual Tracks dashboard (tops, coverage, coins, counters, daily series).
 *   tracks            → Jam Tracks currently in the Fortnite shop (for the admin upload picker).
 *   update_rewards    → change upload/report reward (propagates to all UI).
 *   set_upload_enabled→ master switch: community uploads on/off (frontend hides the button).
 *   set_edit_reported → may an author fix a link while a report is open? (global default)
 *   set_edit_allowed  → per-submission override of the previous rule.
 *   set_top_visible   → show/hide the "Top colaboradores" floating ranking.
 *   set_upload_policy → who may upload: verified | logged_in.
 *   set_report_unverified → show/hide "Reportar este video" for unverified accounts.
 *   set_upload_limit  → daily submissions cap per user (value + on/off switch).
 *   save_report_reason / move_report_reason / delete_report_reason
 *   admin_upload      → publish YouTube / direct audio / direct video on a track (multipart).
 *   remove_track_media→ take down admin (system) media of a track, deleting the file.
 *   remove_submission → unpublish + claw back reward, WITHOUT banning.
 *   reject_submission → unpublish + claw back reward + ban uploader.
 *   restore_submission→ undo a rejection/replacement: republish + re-credit (+ unban).
 *   set_lock          → verify (lock) / unverify (unlock) a track's media. Locking
 *                       disables the author's edit option automatically.
 *   resolve_report    → uphold (pay reporter + take down) or dismiss. Returns
 *                       the Bananos credited to the reporter and the Bananos
 *                       actually debited from the author.
 *   reopen_report     → undo a resolution (reverses reporter payment if paid).
 *   ban_user / unban_user
 *   edits             → edit history of one submission.
 *   users             → every user who ever contributed (or reported), with
 *                       per-user counters, net Bananos and ban state.
 *   user_detail       → full dossier of one user: submissions (all statuses),
 *                       edits, reports received/made, ledger, ban.
 * ============================================================================
 */

require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../api/video_community_lib.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
    exit;
}

$adminName = (string) ($_SESSION['username'] ?? 'admin');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$response = [];

/** Republishes a submission's video onto its track row. */
function vcadmin_publish_submission(mysqli $conn, array $submission): void
{
    $stmt = $conn->prepare("SELECT id, youtube_id, media_file, is_locked, submission_id FROM pistas_musicales WHERE nombre_pista = ? FOR UPDATE");
    $stmt->bind_param('s', $submission['track_name']);
    $stmt->execute();
    $pista = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($pista && vc_row_has_media($pista) && (int) $pista['submission_id'] !== (int) $submission['id']) {
        throw new Exception('La pista ya tiene otro contenido publicado. Retíralo antes de restaurar este envío.');
    }

    if ($pista) {
        $stmt = $conn->prepare(
            "UPDATE pistas_musicales
             SET youtube_id = ?, start_time = ?, cosmetic_id = ?, submission_id = ?,
                 media_type = 'youtube', media_file = NULL, published_at = UTC_TIMESTAMP(),
                 is_locked = 0, verified_at = NULL, verified_by = NULL
             WHERE id = ?"
        );
        $pistaId = (int) $pista['id'];
        $stmt->bind_param('sisii', $submission['youtube_id'], $submission['start_seconds'], $submission['cosmetic_id'], $submission['id'], $pistaId);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO pistas_musicales (nombre_pista, youtube_id, start_time, cosmetic_id, submission_id, media_type, published_at)
             VALUES (?, ?, ?, ?, ?, 'youtube', UTC_TIMESTAMP())"
        );
        $stmt->bind_param('ssisi', $submission['track_name'], $submission['youtube_id'], $submission['start_seconds'], $submission['cosmetic_id'], $submission['id']);
    }
    if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo publicar el video.'); }
    $stmt->close();
}

/** Removes a submission's video from its track row (keeps the row). */
function vcadmin_unpublish_submission(mysqli $conn, int $submissionId): void
{
    $stmt = $conn->prepare("SELECT id FROM pistas_musicales WHERE submission_id = ? FOR UPDATE");
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    foreach ($ids as $pistaId) vc_clear_track_media($conn, $pistaId);
}

/** Net upload reward still in the author's pocket for a submission. */
function vcadmin_reward_net(mysqli $conn, int $userId, int $submissionId): int
{
    return vc_reward_net($conn, $userId, $submissionId);
}

/** Claws back from the global balance whatever of the upload reward the author still holds. Idempotent. */
function vcadmin_reverse_reward(mysqli $conn, array $sub): int
{
    return vc_reverse_reward($conn, $sub);
}

/** Re-credits to the global balance exactly what was clawed back for a submission. Idempotent. */
function vcadmin_restore_reward(mysqli $conn, array $sub): int
{
    return vc_restore_reward($conn, $sub);
}

/**
 * Takes a published submission down: track cleared, status + reason stored,
 * reward reversed unless told otherwise, author banned only if asked.
 * Returns the Bananos actually debited. Inside the caller's transaction.
 */
function vcadmin_take_down(mysqli $conn, array $sub, string $status, string $reason, string $adminName, bool $reverseReward, bool $ban): int
{
    $subId = (int) $sub['id'];
    vcadmin_unpublish_submission($conn, $subId);
    $stmt = $conn->prepare(
        "UPDATE video_submissions
         SET status = ?, reject_reason = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
         WHERE id = ?"
    );
    $stmt->bind_param('sssi', $status, $reason, $adminName, $subId);
    if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo actualizar el envío.'); }
    $stmt->close();
    $debited = $reverseReward ? vcadmin_reverse_reward($conn, $sub) : 0;
    if ($ban) vcadmin_ban($conn, (int) $sub['user_id'], $reason, $adminName);
    return $debited;
}

/**
 * Jam Tracks in today's Fortnite shop (title → artist/artwork), cached on
 * disk for 10 minutes so the panel never hammers the public API.
 * @return array<string, array{name:string, artist:string, artwork:?string}>
 */
function vcadmin_shop_tracks(): array
{
    $cache = sys_get_temp_dir() . '/wabo_vc_shop_tracks.json';
    if (is_file($cache) && filemtime($cache) > time() - 600) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached)) return $cached;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $raw = @file_get_contents('https://fortnite-api.com/v2/shop?language=es-419', false, $ctx);
    if ($raw === false) throw new Exception('No se pudo conectar a la API de Fortnite.');
    $data = json_decode($raw, true);
    $tracks = [];
    foreach ((array) ($data['data']['entries'] ?? []) as $entry) {
        $track = $entry['tracks'][0] ?? null;
        if (!is_array($track) || empty($track['title'])) continue;
        $title = (string) $track['title'];
        $tracks[$title] = [
            'name'    => $title,
            'artist'  => (string) ($track['artist'] ?? 'Artista desconocido'),
            'artwork' => isset($track['albumArt']) ? (string) $track['albumArt'] : null,
            'cosmetic_id' => (string) ($track['id'] ?? ''),
        ];
    }
    @file_put_contents($cache, json_encode($tracks, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $tracks;
}

/** Public playback descriptor of a track row for panel previews. */
function vcadmin_media_of(array $row): array
{
    $type = vc_normalize_media_type($row['media_type'] ?? null);
    $url = $type === VC_MEDIA_YOUTUBE ? null : vc_media_url($row['media_file'] ?? null);
    if ($url === null) $type = VC_MEDIA_YOUTUBE;
    return ['media_type' => $type, 'media_url' => $url];
}

function vcadmin_get_submission(mysqli $conn, int $id, bool $forUpdate = false): array
{
    $sql = "SELECT * FROM video_submissions WHERE id = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new Exception('Envío no encontrado.');
    return $row;
}

/**
 * Report reasons for the panel: admin order + how many reports use each code
 * (a used reason can be disabled but never deleted, so history stays readable).
 * @return list<array{id:int, code:string, label:string, is_active:bool, sort_order:int, uses:int}>
 */
function vcadmin_report_reasons(mysqli $conn): array
{
    $uses = [];
    $res = $conn->query("SELECT reason, COUNT(*) AS n FROM video_reports GROUP BY reason");
    if ($res) while ($row = $res->fetch_assoc()) $uses[(string) $row['reason']] = (int) $row['n'];
    return array_map(
        static fn (array $reason): array => $reason + ['uses' => $uses[$reason['code']] ?? 0],
        vc_report_reasons($conn)
    );
}

/** Validated, trimmed reason label or an Exception with the user-facing rule. */
function vcadmin_reason_label(string $raw): string
{
    $label = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    $length = mb_strlen($label);
    if ($length < VC_REPORT_REASON_LABEL_MIN || $length > VC_REPORT_REASON_LABEL_MAX) {
        throw new Exception('El motivo debe tener entre ' . VC_REPORT_REASON_LABEL_MIN . ' y ' . VC_REPORT_REASON_LABEL_MAX . ' caracteres.');
    }
    return $label;
}

/** First free code for a label: slug, then slug_2, slug_3, … */
function vcadmin_unique_reason_code(mysqli $conn, string $label): string
{
    $base = vc_report_reason_code_from_label($label);
    $stmt = $conn->prepare("SELECT 1 FROM video_report_reasons WHERE code = ? LIMIT 1");
    $code = $base;
    for ($suffix = 2; ; $suffix++) {
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        if (!$taken) break;
        $code = $base . '_' . $suffix;
    }
    $stmt->close();
    return $code;
}

/** Renumbers sort_order 1..n following $orderedIds (inside the caller's transaction). */
function vcadmin_renumber_reasons(mysqli $conn, array $orderedIds): void
{
    $stmt = $conn->prepare("UPDATE video_report_reasons SET sort_order = ? WHERE id = ?");
    foreach (array_values($orderedIds) as $index => $id) {
        $position = $index + 1;
        $stmt->bind_param('ii', $position, $id);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo reordenar los motivos.'); }
    }
    $stmt->close();
}

function vcadmin_ban(mysqli $conn, int $userId, string $reason, string $adminName): void
{
    $stmt = $conn->prepare(
        "INSERT INTO video_bans (user_id, reason, banned_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_by = VALUES(banned_by)"
    );
    $stmt->bind_param('iss', $userId, $reason, $adminName);
    $stmt->execute();
    $stmt->close();
}

try {
    vc_ensure_schema($conn);

    switch ($action) {
        case 'overview': {
            $rewards = vc_get_settings($conn);

            $published = [];
            $res = $conn->query(
                "SELECT s.id, s.track_name, s.cosmetic_id, s.youtube_id, s.start_seconds, s.reward_amount,
                        s.video_title, s.created_at, s.edit_allowed, s.edited_at,
                        u.public_display_name AS user_name, u.id AS user_id,
                        p.id AS pista_id, p.is_locked, p.published_at, p.verified_by,
                        (SELECT COUNT(*) FROM video_reports r WHERE r.pista_id = p.id AND r.status = 'open') AS open_reports,
                        (SELECT COUNT(*) FROM video_submission_edits e WHERE e.submission_id = s.id) AS edits,
                        (SELECT 1 FROM video_bans b WHERE b.user_id = s.user_id) AS user_banned
                 FROM video_submissions s
                 INNER JOIN users u ON u.id = s.user_id
                 LEFT JOIN pistas_musicales p ON p.submission_id = s.id
                 WHERE s.status = 'published'
                 ORDER BY s.created_at DESC LIMIT 300"
            );
            if ($res) while ($row = $res->fetch_assoc()) $published[] = $row;

            $rejected = [];
            $res = $conn->query(
                "SELECT s.id, s.track_name, s.youtube_id, s.start_seconds, s.status, s.reward_amount, s.reject_reason, s.reviewed_by,
                        s.reviewed_at, s.created_at, u.public_display_name AS user_name, u.id AS user_id,
                        (SELECT 1 FROM video_bans b WHERE b.user_id = s.user_id) AS user_banned
                 FROM video_submissions s
                 INNER JOIN users u ON u.id = s.user_id
                 WHERE s.status IN ('rejected','replaced','deleted')
                 ORDER BY s.reviewed_at DESC LIMIT 300"
            );
            if ($res) while ($row = $res->fetch_assoc()) $rejected[] = $row;

            $reports = [];
            $res = $conn->query(
                "SELECT r.id, r.pista_id, r.submission_id, r.reason, r.status, r.reward_amount, r.reward_paid,
                        r.reviewed_by, r.reviewed_at, r.created_at,
                        p.nombre_pista AS track_name, p.youtube_id, p.media_type, p.media_file, p.submission_id AS current_submission_id,
                        u.public_display_name AS reporter_name, u.id AS reporter_id,
                        s.user_id AS uploader_id, su.public_display_name AS uploader_name, s.status AS submission_status, s.edit_allowed,
                        (SELECT COUNT(*) FROM video_bans vb WHERE vb.user_id = s.user_id) AS uploader_banned,
                        (SELECT COUNT(*) FROM video_bans vb WHERE vb.user_id = r.reporter_id) AS reporter_banned,
                        GREATEST(0, CAST(s.reward_amount AS SIGNED) - CAST(s.reward_reversed AS SIGNED)) AS uploader_reward_net
                 FROM video_reports r
                 INNER JOIN pistas_musicales p ON p.id = r.pista_id
                 INNER JOIN users u ON u.id = r.reporter_id
                 LEFT JOIN video_submissions s ON s.id = r.submission_id
                 LEFT JOIN users su ON su.id = s.user_id
                 ORDER BY (r.status = 'open') DESC, r.created_at DESC LIMIT 300"
            );
            if ($res) while ($row = $res->fetch_assoc()) $reports[] = $row + vcadmin_media_of($row);

            $bans = [];
            $res = $conn->query(
                "SELECT b.user_id, b.reason, b.banned_by, b.created_at, u.public_display_name AS user_name,
                        (SELECT COUNT(*) FROM video_submissions s WHERE s.user_id = b.user_id AND s.status = 'rejected') AS rejected_count
                 FROM video_bans b
                 INNER JOIN users u ON u.id = b.user_id
                 ORDER BY b.created_at DESC LIMIT 300"
            );
            if ($res) while ($row = $res->fetch_assoc()) $bans[] = $row;

            $locked = [];
            $res = $conn->query(
                "SELECT p.id, p.nombre_pista, p.youtube_id, p.start_time, p.submission_id, p.media_type, p.media_file,
                        p.published_at, p.verified_at, p.verified_by, u.public_display_name AS user_name
                 FROM pistas_musicales p
                 LEFT JOIN video_submissions s ON s.id = p.submission_id
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE p.is_locked = 1 ORDER BY p.verified_at DESC, p.nombre_pista ASC LIMIT 500"
            );
            if ($res) while ($row = $res->fetch_assoc()) $locked[] = $row + vcadmin_media_of($row);

            // Media published by the panel itself (no community submission behind it).
            $system = [];
            $res = $conn->query(
                "SELECT p.id, p.nombre_pista, p.youtube_id, p.start_time, p.media_type, p.media_file, p.is_locked,
                        p.published_at, p.verified_by,
                        (SELECT COUNT(*) FROM video_reports r WHERE r.pista_id = p.id AND r.status = 'open') AS open_reports
                 FROM pistas_musicales p
                 WHERE p.submission_id IS NULL AND " . vc_sql_has_media('p') . "
                 ORDER BY p.published_at DESC, p.nombre_pista ASC LIMIT 500"
            );
            if ($res) while ($row = $res->fetch_assoc()) $system[] = $row + vcadmin_media_of($row);

            $edits = [];
            $res = $conn->query(
                "SELECT e.id, e.submission_id, e.user_id, e.edited_by, e.editor_name, e.old_youtube_id, e.old_start_seconds,
                        e.new_youtube_id, e.new_start_seconds, e.track_state, e.created_at,
                        s.track_name, u.public_display_name AS user_name
                 FROM video_submission_edits e
                 INNER JOIN video_submissions s ON s.id = e.submission_id
                 LEFT JOIN users u ON u.id = e.user_id
                 ORDER BY e.created_at DESC LIMIT 100"
            );
            if ($res) while ($row = $res->fetch_assoc()) $edits[] = $row;

            $response = [
                'status' => 'success',
                'rewards' => $rewards,
                'report_reasons' => vcadmin_report_reasons($conn),
                'published' => $published,
                'rejected' => $rejected,
                'reports' => $reports,
                'bans' => $bans,
                'locked' => $locked,
                'system' => $system,
                'edits' => $edits,
                'media_limits' => [
                    'audio_mb' => (int) round(VC_MEDIA_MAX_AUDIO_BYTES / 1048576),
                    'video_mb' => (int) round(VC_MEDIA_MAX_VIDEO_BYTES / 1048576),
                    'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                    'post_max_size' => (string) ini_get('post_max_size'),
                    'dir_writable' => is_dir(vc_media_dir()) ? is_writable(vc_media_dir()) : is_writable(dirname(vc_media_dir())),
                ],
            ];
            break;
        }

        case 'stats': {
            $one = static function (string $sql) use ($conn): array {
                $res = $conn->query($sql);
                return $res ? ($res->fetch_assoc() ?: []) : [];
            };
            $tracks = $one(
                "SELECT COUNT(*) AS total,
                        SUM(" . vc_sql_has_media('p') . ") AS with_media,
                        SUM(" . vc_sql_has_media('p') . " AND p.media_type = 'youtube') AS youtube,
                        SUM(p.media_type = 'audio' AND p.media_file IS NOT NULL AND p.media_file <> '') AS audio,
                        SUM(p.media_type = 'video' AND p.media_file IS NOT NULL AND p.media_file <> '') AS video,
                        SUM(" . vc_sql_has_media('p') . " AND p.submission_id IS NOT NULL) AS community,
                        SUM(" . vc_sql_has_media('p') . " AND p.submission_id IS NULL) AS system_media,
                        SUM(p.is_locked = 1) AS verified
                 FROM pistas_musicales p"
            );
            $subs = $one(
                "SELECT SUM(status = 'published') AS published, SUM(status = 'rejected') AS rejected,
                        SUM(status = 'replaced') AS replaced, SUM(status = 'deleted') AS deleted,
                        COUNT(DISTINCT user_id) AS contributors,
                        SUM(created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS last_7d
                 FROM video_submissions"
            );
            $reports = $one(
                "SELECT SUM(status = 'open') AS open, SUM(status = 'upheld') AS upheld, SUM(status = 'dismissed') AS dismissed,
                        COUNT(DISTINCT reporter_id) AS reporters
                 FROM video_reports"
            );
            $coins = $one(
                "SELECT COALESCE(SUM(CASE WHEN reason = 'video_reward' THEN amount END), 0) AS upload_paid,
                        COALESCE(SUM(CASE WHEN reason = 'video_reward_restore' THEN amount END), 0) AS upload_restored,
                        COALESCE(-SUM(CASE WHEN reason = 'video_reward_reversal' THEN amount END), 0) AS upload_reversed,
                        COALESCE(SUM(CASE WHEN reason = 'video_report_reward' THEN amount END), 0) AS report_paid,
                        COALESCE(-SUM(CASE WHEN reason = 'video_report_reward_reversal' THEN amount END), 0) AS report_reversed,
                        COALESCE(SUM(amount), 0) AS net
                 FROM coin_transactions
                 WHERE reference_type IN ('video_submission', 'video_report')"
            );
            $bans = $one("SELECT COUNT(*) AS total FROM video_bans");
            $edits = $one("SELECT COUNT(*) AS total, SUM(edited_by = 'user') AS by_user FROM video_submission_edits");

            $daily = [];
            $res = $conn->query(
                "SELECT d.day, COALESCE(s.n, 0) AS uploads, COALESCE(r.n, 0) AS reports
                 FROM (
                    SELECT DATE(UTC_TIMESTAMP() - INTERVAL seq DAY) AS day
                    FROM (SELECT 0 seq UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
                          UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13) x
                 ) d
                 LEFT JOIN (SELECT DATE(created_at) AS day, COUNT(*) AS n FROM video_submissions WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 14 DAY GROUP BY DATE(created_at)) s ON s.day = d.day
                 LEFT JOIN (SELECT DATE(created_at) AS day, COUNT(*) AS n FROM video_reports WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 14 DAY GROUP BY DATE(created_at)) r ON r.day = d.day
                 ORDER BY d.day ASC"
            );
            if ($res) while ($row = $res->fetch_assoc()) $daily[] = ['day' => $row['day'], 'uploads' => (int) $row['uploads'], 'reports' => (int) $row['reports']];

            $topReporters = [];
            $res = $conn->query(
                "SELECT r.reporter_id AS user_id, u.public_display_name AS name, a.filename AS avatar,
                        SUM(r.reward_amount) AS coins, COUNT(*) AS reports
                 FROM video_reports r
                 INNER JOIN users u ON u.id = r.reporter_id
                 LEFT JOIN avatars a ON a.id = u.avatar_id AND a.is_active = 1
                 WHERE r.status = 'upheld' AND r.reward_paid = 1
                   AND u.public_display_name IS NOT NULL AND u.public_display_name != ''
                 GROUP BY r.reporter_id, u.public_display_name, a.filename
                 ORDER BY coins DESC, reports DESC LIMIT 10"
            );
            if ($res) while ($row = $res->fetch_assoc()) {
                $topReporters[] = [
                    'user_id' => (int) $row['user_id'], 'name' => (string) $row['name'],
                    'avatar' => AvatarCatalog::urlFor($conn, $row['avatar']),
                    'coins' => (int) $row['coins'], 'reports' => (int) $row['reports'],
                ];
            }

            // Coverage of TODAY's shop (what visitors actually see), best effort.
            $shop = null;
            try {
                $shopTracks = vcadmin_shop_tracks();
                $covered = 0;
                if ($shopTracks) {
                    $names = array_keys($shopTracks);
                    $placeholders = implode(',', array_fill(0, count($names), '?'));
                    $stmt = $conn->prepare("SELECT nombre_pista FROM pistas_musicales p WHERE " . vc_sql_has_media('p') . " AND nombre_pista IN ({$placeholders})");
                    $stmt->bind_param(str_repeat('s', count($names)), ...$names);
                    $stmt->execute();
                    $covered = $stmt->get_result()->num_rows;
                    $stmt->close();
                }
                $shop = ['total' => count($shopTracks), 'covered' => $covered];
            } catch (Exception $e) {
                $shop = null;
            }

            $response = [
                'status' => 'success',
                'generated_at' => UtcTime::toIso(UtcTime::now()),
                'tracks' => array_map('intval', $tracks),
                'submissions' => array_map('intval', $subs),
                'reports' => array_map('intval', $reports),
                'coins' => array_map('intval', $coins),
                'bans' => (int) ($bans['total'] ?? 0),
                'edits' => array_map('intval', $edits),
                'shop' => $shop,
                'daily' => $daily,
                'top_upload' => vc_top_contributors($conn, 10),
                'top_global' => vc_top_global($conn, 10),
                'top_reporters' => $topReporters,
            ];
            break;
        }

        case 'tracks': {
            $shopTracks = vcadmin_shop_tracks();
            $byName = [];
            $res = $conn->query(
                "SELECT p.id, p.nombre_pista, p.youtube_id, p.start_time, p.media_type, p.media_file, p.is_locked,
                        p.submission_id, p.published_at, u.public_display_name AS user_name
                 FROM pistas_musicales p
                 LEFT JOIN video_submissions s ON s.id = p.submission_id
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE " . vc_sql_has_media('p')
            );
            if ($res) while ($row = $res->fetch_assoc()) $byName[(string) $row['nombre_pista']] = $row + vcadmin_media_of($row);

            $list = [];
            foreach ($shopTracks as $name => $track) {
                $db = $byName[$name] ?? null;
                $list[] = $track + [
                    'has_media'   => $db !== null,
                    'pista_id'    => $db ? (int) $db['id'] : null,
                    'media_type'  => $db ? $db['media_type'] : null,
                    'media_url'   => $db ? $db['media_url'] : null,
                    'youtube_id'  => $db ? $db['youtube_id'] : null,
                    'start_time'  => $db ? (int) $db['start_time'] : 0,
                    'is_locked'   => $db ? (int) $db['is_locked'] === 1 : false,
                    'source'      => $db ? ($db['submission_id'] ? 'community' : 'system') : null,
                    'contributor' => $db ? ($db['user_name'] ?? null) : null,
                ];
            }
            usort($list, static fn (array $a, array $b): int => [$a['has_media'], $a['name']] <=> [$b['has_media'], $b['name']]);
            $response = ['status' => 'success', 'tracks' => $list];
            break;
        }

        case 'admin_upload': {
            $trackName = trim((string) ($_POST['track_name'] ?? ''));
            $cosmeticId = mb_substr(trim((string) ($_POST['cosmetic_id'] ?? '')), 0, 120);
            $kind = (string) ($_POST['media_kind'] ?? 'youtube');
            $startSeconds = max(0, min(36000, (int) ($_POST['start_seconds'] ?? 0)));
            $lock = (string) ($_POST['lock'] ?? '1') === '1';
            $replace = (string) ($_POST['replace'] ?? '0') === '1';
            if ($trackName === '' || mb_strlen($trackName) > 255) throw new Exception('Escribe el nombre exacto de la pista.');

            // pistas_musicales.youtube_id is NOT NULL on legacy databases:
            // direct media rows store '' there, never NULL.
            $youtubeId = '';
            $stored = null;
            if ($kind === 'youtube') {
                $youtubeId = vc_extract_youtube_id((string) ($_POST['youtube_url'] ?? ''));
                if ($youtubeId === null) throw new Exception('El enlace no es un video válido de YouTube (youtube.com o youtu.be).');
                if (!vc_validate_youtube($youtubeId)['ok']) throw new Exception('Ese video de YouTube no existe o es privado.');
            } elseif ($kind === 'file') {
                if (empty($_FILES['media']) || !is_array($_FILES['media'])) throw new Exception('Selecciona un archivo de audio o vídeo.');
                $stored = vc_store_media_upload($_FILES['media']);
                $youtubeId = '';
                $startSeconds = 0;
            } else {
                throw new Exception('Tipo de contenido inválido.');
            }

            $oldFile = null;
            $replacedSubmission = null;
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id, youtube_id, media_file, submission_id, cosmetic_id FROM pistas_musicales WHERE nombre_pista = ? FOR UPDATE");
                $stmt->bind_param('s', $trackName);
                $stmt->execute();
                $pista = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($pista && vc_row_has_media($pista)) {
                    if (!$replace) throw new Exception('La pista ya tiene contenido publicado. Marca «Reemplazar» para sustituirlo.');
                    if (!empty($pista['submission_id'])) {
                        $sub = vcadmin_get_submission($conn, (int) $pista['submission_id'], true);
                        if ($sub['status'] === 'published') {
                            $stmt = $conn->prepare(
                                "UPDATE video_submissions
                                 SET status = 'replaced', reject_reason = 'Sustituido por contenido del panel', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                                 WHERE id = ?"
                            );
                            $subId = (int) $sub['id'];
                            $stmt->bind_param('si', $adminName, $subId);
                            if (!$stmt->execute()) throw new Exception('No se pudo archivar el envío anterior.');
                            $stmt->close();
                            $replacedSubmission = $sub;
                        }
                    }
                    $oldFile = vc_clear_track_media($conn, (int) $pista['id']);
                }

                $mediaType = $stored ? $stored['type'] : VC_MEDIA_YOUTUBE;
                $mediaFile = $stored ? $stored['file'] : null;
                $locked = $lock ? 1 : 0;
                $verifiedBy = $lock ? $adminName : null;
                if ($pista) {
                    if ($cosmeticId === '') $cosmeticId = (string) ($pista['cosmetic_id'] ?? '');
                    $stmt = $conn->prepare(
                        "UPDATE pistas_musicales
                         SET youtube_id = ?, start_time = ?, cosmetic_id = NULLIF(?, ''), submission_id = NULL,
                             media_type = ?, media_file = ?, published_at = UTC_TIMESTAMP(),
                             is_locked = ?, verified_at = IF(? = 1, UTC_TIMESTAMP(), NULL), verified_by = ?
                         WHERE id = ?"
                    );
                    $pistaId = (int) $pista['id'];
                    $stmt->bind_param('sisssiisi', $youtubeId, $startSeconds, $cosmeticId, $mediaType, $mediaFile, $locked, $locked, $verifiedBy, $pistaId);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO pistas_musicales
                            (nombre_pista, youtube_id, start_time, cosmetic_id, submission_id, media_type, media_file,
                             published_at, is_locked, verified_at, verified_by)
                         VALUES (?, ?, ?, NULLIF(?, ''), NULL, ?, ?, UTC_TIMESTAMP(), ?, IF(? = 1, UTC_TIMESTAMP(), NULL), ?)"
                    );
                    $stmt->bind_param('ssisssiis', $trackName, $youtubeId, $startSeconds, $cosmeticId, $mediaType, $mediaFile, $locked, $locked, $verifiedBy);
                }
                if (!$stmt->execute()) throw new Exception('No se pudo guardar la pista.');
                $stmt->close();
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                if ($stored) vc_delete_media_file($stored['file']);
                throw $e;
            }
            if ($oldFile !== null) vc_delete_media_file($oldFile);

            $labels = [VC_MEDIA_YOUTUBE => 'Vídeo de YouTube', VC_MEDIA_AUDIO => 'Audio directo', VC_MEDIA_VIDEO => 'Vídeo directo'];
            $message = $labels[$mediaType] . " publicado en «{$trackName}»" . ($lock ? ' y verificado.' : '.');
            if ($replacedSubmission) {
                $message .= ' El envío anterior de la comunidad pasó a «Sustituido» y su autor conserva la recompensa.';
            }
            $response = [
                'status' => 'success',
                'message' => $message,
                'track' => vc_track_state($conn, $trackName),
            ];
            break;
        }

        case 'remove_track_media': {
            $pistaId = (int) ($_POST['pista_id'] ?? 0);
            if ($pistaId <= 0) throw new Exception('ID de pista inválido.');
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id, submission_id FROM pistas_musicales WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $pistaId);
                $stmt->execute();
                $pista = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$pista) throw new Exception('Pista no encontrada.');
                if (!empty($pista['submission_id'])) {
                    throw new Exception('Este contenido es de la comunidad: usa «Retirar» o «Retirar y suspender» sobre el envío.');
                }
                $file = vc_clear_track_media($conn, $pistaId);
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            vc_delete_media_file($file);
            $response = ['status' => 'success', 'message' => 'Contenido retirado de la pista.'];
            break;
        }

        case 'set_upload_enabled': {
            $enabled = (string) ($_POST['enabled'] ?? '') === '1';
            vc_set_setting($conn, VC_SETTING_UPLOAD_ENABLED, $enabled ? '1' : '0');
            $response = [
                'status' => 'success',
                'message' => $enabled
                    ? 'Subida de la comunidad ACTIVADA: el botón vuelve a mostrarse en la tienda.'
                    : 'Subida de la comunidad PAUSADA: el botón desaparece y la API rechaza envíos.',
                'rewards' => vc_get_settings($conn),
            ];
            break;
        }

        case 'set_edit_reported': {
            $enabled = (string) ($_POST['enabled'] ?? '') === '1';
            vc_set_setting($conn, VC_SETTING_EDIT_REPORTED, $enabled ? '1' : '0');
            $response = [
                'status' => 'success',
                'message' => $enabled
                    ? 'Los autores pueden corregir su vídeo aunque tenga un reporte abierto.'
                    : 'Con un reporte abierto, el autor solo podrá editar si lo autorizas envío a envío.',
                'rewards' => vc_get_settings($conn),
            ];
            break;
        }

        case 'set_edit_allowed': {
            $id = (int) ($_POST['id'] ?? 0);
            $allowed = (string) ($_POST['allowed'] ?? '') === '1' ? 1 : 0;
            if ($id <= 0) throw new Exception('ID inválido.');
            $sub = vcadmin_get_submission($conn, $id);
            if ($sub['status'] !== 'published') throw new Exception('Solo aplica a envíos publicados.');
            $stmt = $conn->prepare("UPDATE video_submissions SET edit_allowed = ? WHERE id = ?");
            $stmt->bind_param('ii', $allowed, $id);
            if (!$stmt->execute()) throw new Exception('No se pudo actualizar el permiso.');
            $stmt->close();
            $response = [
                'status' => 'success',
                'message' => $allowed
                    ? 'El autor ya puede corregir este vídeo aunque esté reportado.'
                    : 'Permiso de edición retirado para este envío.',
            ];
            break;
        }

        case 'edits': {
            $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');
            $stmt = $conn->prepare(
                "SELECT e.*, u.public_display_name AS user_name
                 FROM video_submission_edits e LEFT JOIN users u ON u.id = e.user_id
                 WHERE e.submission_id = ? ORDER BY e.created_at DESC LIMIT 200"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $response = ['status' => 'success', 'edits' => $rows];
            break;
        }

        case 'users': {
            // Everyone who ever touched the module: contributors, reporters and
            // banned accounts. One row per user with the counters the list shows.
            $res = $conn->query(
                "SELECT u.id AS user_id, u.public_display_name AS user_name, u.game_username, u.public_code,
                        u.is_verified, u.account_status, u.created_at AS registered_at, u.last_login_at,
                        a.filename AS avatar,
                        COALESCE(c.balance, 0) AS balance,
                        b.reason AS ban_reason, b.banned_by, b.created_at AS banned_at,
                        (SELECT COUNT(*) FROM video_submissions s WHERE s.user_id = u.id) AS total_submissions,
                        (SELECT COUNT(*) FROM video_submissions s WHERE s.user_id = u.id AND s.status = 'published') AS published_count,
                        (SELECT COUNT(*) FROM video_submissions s WHERE s.user_id = u.id AND s.status = 'rejected') AS rejected_count,
                        (SELECT COUNT(*) FROM video_submissions s WHERE s.user_id = u.id AND s.status = 'replaced') AS replaced_count,
                        (SELECT COUNT(*) FROM video_submissions s WHERE s.user_id = u.id AND s.status = 'deleted') AS deleted_count,
                        (SELECT COUNT(*) FROM video_submissions s INNER JOIN pistas_musicales p ON p.submission_id = s.id
                          WHERE s.user_id = u.id AND s.status = 'published' AND p.is_locked = 1) AS verified_count,
                        (SELECT COUNT(*) FROM video_submission_edits e WHERE e.user_id = u.id AND e.edited_by = 'user' AND e.track_state <> 'deleted') AS edits_count,
                        (SELECT COUNT(*) FROM video_reports r INNER JOIN video_submissions s ON s.id = r.submission_id
                          WHERE s.user_id = u.id) AS reports_received,
                        (SELECT COUNT(*) FROM video_reports r INNER JOIN video_submissions s ON s.id = r.submission_id
                          WHERE s.user_id = u.id AND r.status = 'upheld') AS reports_upheld,
                        (SELECT COUNT(*) FROM video_reports r INNER JOIN video_submissions s ON s.id = r.submission_id
                          WHERE s.user_id = u.id AND r.status = 'open') AS reports_open,
                        (SELECT COUNT(*) FROM video_reports r WHERE r.reporter_id = u.id) AS reports_made,
                        (SELECT COUNT(*) FROM video_reports r WHERE r.reporter_id = u.id AND r.status = 'upheld') AS reports_made_upheld,
                        (SELECT COUNT(*) FROM video_reports r WHERE r.reporter_id = u.id AND r.status = 'dismissed') AS reports_made_dismissed,
                        (SELECT COALESCE(SUM(t.amount), 0) FROM coin_transactions t
                          WHERE t.user_id = u.id AND t.reason IN ('video_reward','video_reward_restore')) AS coins_earned,
                        (SELECT COALESCE(SUM(-t.amount), 0) FROM coin_transactions t
                          WHERE t.user_id = u.id AND t.reason = 'video_reward_reversal') AS coins_reversed,
                        (SELECT COALESCE(SUM(t.amount), 0) FROM coin_transactions t
                          WHERE t.user_id = u.id AND t.reason = 'video_report_reward') AS coins_reports,
                        (SELECT COALESCE(SUM(t.amount), 0) FROM coin_transactions t
                          WHERE t.user_id = u.id AND t.reason IN ('video_reward','video_reward_restore','video_reward_reversal','video_report_reward','video_report_reward_reversal')) AS coins_net,
                        (SELECT MAX(s.created_at) FROM video_submissions s WHERE s.user_id = u.id) AS last_submission_at,
                        (SELECT MIN(s.created_at) FROM video_submissions s WHERE s.user_id = u.id) AS first_submission_at
                 FROM users u
                 LEFT JOIN avatars a ON a.id = u.avatar_id AND a.is_active = 1
                 LEFT JOIN coins c ON c.user_id = u.id
                 LEFT JOIN video_bans b ON b.user_id = u.id
                 WHERE EXISTS (SELECT 1 FROM video_submissions s WHERE s.user_id = u.id)
                    OR EXISTS (SELECT 1 FROM video_reports r WHERE r.reporter_id = u.id)
                    OR b.user_id IS NOT NULL
                 ORDER BY (b.user_id IS NOT NULL) DESC, last_submission_at DESC, u.id DESC
                 LIMIT 1000"
            );
            $users = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $row['avatar'] = AvatarCatalog::urlFor($conn, $row['avatar']);
                    $row['is_banned'] = $row['banned_at'] !== null ? 1 : 0;
                    $users[] = $row;
                }
            }
            $response = ['status' => 'success', 'users' => $users];
            break;
        }

        case 'user_detail': {
            $userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new Exception('Usuario inválido.');

            $stmt = $conn->prepare(
                "SELECT u.id AS user_id, u.public_display_name AS user_name, u.game_username, u.public_code,
                        u.is_verified, u.account_status, u.created_at AS registered_at, u.last_login_at,
                        a.filename AS avatar, COALESCE(c.balance, 0) AS balance,
                        b.reason AS ban_reason, b.banned_by, b.created_at AS banned_at
                 FROM users u
                 LEFT JOIN avatars a ON a.id = u.avatar_id AND a.is_active = 1
                 LEFT JOIN coins c ON c.user_id = u.id
                 LEFT JOIN video_bans b ON b.user_id = u.id
                 WHERE u.id = ? LIMIT 1"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$user) throw new Exception('Usuario no encontrado.');
            $user['avatar'] = AvatarCatalog::urlFor($conn, $user['avatar']);
            $user['is_banned'] = $user['banned_at'] !== null ? 1 : 0;

            // Every submission, whatever happened to it, with the track's
            // current state and the reward still in the author's pocket.
            $stmt = $conn->prepare(
                "SELECT s.id, s.track_name, s.cosmetic_id, s.youtube_id, s.start_seconds, s.status, s.reward_amount,
                        s.video_title, s.reject_reason, s.reviewed_by, s.reviewed_at, s.created_at, s.edited_at, s.edit_allowed,
                        p.id AS pista_id, p.is_locked, p.published_at, p.verified_by,
                        (SELECT COUNT(*) FROM video_submission_edits e WHERE e.submission_id = s.id AND e.track_state <> 'deleted') AS edits,
                        (SELECT COUNT(*) FROM video_reports r WHERE r.submission_id = s.id) AS reports,
                        (SELECT COUNT(*) FROM video_reports r WHERE r.submission_id = s.id AND r.status = 'open') AS open_reports,
                        GREATEST(0, CAST(s.reward_amount AS SIGNED) - CAST(s.reward_reversed AS SIGNED)) AS reward_net
                 FROM video_submissions s
                 LEFT JOIN pistas_musicales p ON p.submission_id = s.id
                 WHERE s.user_id = ?
                 ORDER BY s.created_at DESC LIMIT 500"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $stmt = $conn->prepare(
                "SELECT e.id, e.submission_id, e.edited_by, e.editor_name, e.old_youtube_id, e.old_start_seconds,
                        e.new_youtube_id, e.new_start_seconds, e.track_state, e.created_at, s.track_name
                 FROM video_submission_edits e
                 INNER JOIN video_submissions s ON s.id = e.submission_id
                 WHERE e.user_id = ?
                 ORDER BY e.created_at DESC LIMIT 500"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $edits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Reports against this user's videos.
            $stmt = $conn->prepare(
                "SELECT r.id, r.pista_id, r.submission_id, r.reason, r.status, r.reward_amount, r.reward_paid,
                        r.reviewed_by, r.reviewed_at, r.created_at, s.track_name,
                        ru.public_display_name AS reporter_name, r.reporter_id
                 FROM video_reports r
                 INNER JOIN video_submissions s ON s.id = r.submission_id
                 LEFT JOIN users ru ON ru.id = r.reporter_id
                 WHERE s.user_id = ?
                 ORDER BY (r.status = 'open') DESC, r.created_at DESC LIMIT 300"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $reportsReceived = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Reports this user filed against others (spots reward farming).
            $stmt = $conn->prepare(
                "SELECT r.id, r.pista_id, r.submission_id, r.reason, r.status, r.reward_amount, r.reward_paid,
                        r.reviewed_by, r.reviewed_at, r.created_at, p.nombre_pista AS track_name,
                        s.user_id AS uploader_id, su.public_display_name AS uploader_name
                 FROM video_reports r
                 INNER JOIN pistas_musicales p ON p.id = r.pista_id
                 LEFT JOIN video_submissions s ON s.id = r.submission_id
                 LEFT JOIN users su ON su.id = s.user_id
                 WHERE r.reporter_id = ?
                 ORDER BY r.created_at DESC LIMIT 300"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $reportsMade = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Ledger of everything the module ever moved for this user.
            $stmt = $conn->prepare(
                "SELECT t.id, t.amount, t.reason, t.reference_type, t.reference_id, t.created_at,
                        CASE t.reference_type
                            WHEN 'video_submission' THEN (SELECT s.track_name FROM video_submissions s WHERE s.id = t.reference_id)
                            WHEN 'video_report' THEN (SELECT p.nombre_pista FROM video_reports r INNER JOIN pistas_musicales p ON p.id = r.pista_id WHERE r.id = t.reference_id)
                            ELSE NULL
                        END AS track_name
                 FROM coin_transactions t
                 WHERE t.user_id = ?
                   AND t.reason IN ('video_reward','video_reward_reversal','video_reward_restore','video_report_reward','video_report_reward_reversal')
                 ORDER BY t.created_at DESC, t.id DESC LIMIT 500"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $ledger = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $totals = [
                'submissions' => count($submissions),
                'published' => 0, 'rejected' => 0, 'replaced' => 0, 'deleted' => 0, 'verified' => 0,
                'edits' => 0,
                'reports_received' => count($reportsReceived), 'reports_upheld' => 0, 'reports_open' => 0, 'reports_dismissed' => 0,
                'reports_made' => count($reportsMade), 'reports_made_upheld' => 0, 'reports_made_dismissed' => 0,
                'coins_earned' => 0, 'coins_reversed' => 0, 'coins_reports' => 0, 'coins_net' => 0,
            ];
            foreach ($submissions as $s) {
                if (isset($totals[$s['status']])) $totals[$s['status']]++;
                if ($s['status'] === 'published' && (int) $s['is_locked'] === 1) $totals['verified']++;
            }
            foreach ($edits as $e) {
                if ($e['edited_by'] === VC_EDIT_BY_USER && $e['track_state'] !== VC_EDIT_STATE_DELETED) $totals['edits']++;
            }
            foreach ($reportsReceived as $r) {
                if ($r['status'] === 'upheld') $totals['reports_upheld']++;
                elseif ($r['status'] === 'open') $totals['reports_open']++;
                elseif ($r['status'] === 'dismissed') $totals['reports_dismissed']++;
            }
            foreach ($reportsMade as $r) {
                if ($r['status'] === 'upheld') $totals['reports_made_upheld']++;
                elseif ($r['status'] === 'dismissed') $totals['reports_made_dismissed']++;
            }
            foreach ($ledger as $t) {
                $amount = (int) $t['amount'];
                $totals['coins_net'] += $amount;
                if ($t['reason'] === 'video_reward' || $t['reason'] === 'video_reward_restore') $totals['coins_earned'] += $amount;
                elseif ($t['reason'] === 'video_reward_reversal') $totals['coins_reversed'] += -$amount;
                elseif ($t['reason'] === 'video_report_reward') $totals['coins_reports'] += $amount;
            }

            $response = [
                'status' => 'success',
                'user' => $user,
                'totals' => $totals,
                'submissions' => $submissions,
                'edits' => $edits,
                'reports_received' => $reportsReceived,
                'reports_made' => $reportsMade,
                'ledger' => $ledger,
            ];
            break;
        }

        case 'update_rewards': {
            $upload = (int) ($_POST['upload_reward'] ?? -1);
            $report = (int) ($_POST['report_reward'] ?? -1);
            if ($upload < 0 || $upload > 100000 || $report < 0 || $report > 100000) {
                throw new Exception('Cantidades inválidas (0 a 100000).');
            }
            vc_set_setting($conn, VC_SETTING_UPLOAD_REWARD, (string) $upload);
            vc_set_setting($conn, VC_SETTING_REPORT_REWARD, (string) $report);
            $response = ['status' => 'success', 'message' => 'Recompensas actualizadas.', 'rewards' => vc_get_settings($conn)];
            break;
        }

        case 'set_top_visible': {
            $visible = (string) ($_POST['visible'] ?? '') === '1';
            vc_set_setting($conn, VC_SETTING_TOP_VISIBLE, $visible ? '1' : '0');
            $response = [
                'status' => 'success',
                'message' => $visible ? 'Top de colaboradores visible.' : 'Top de colaboradores oculto.',
                'rewards' => vc_get_settings($conn),
            ];
            break;
        }

        case 'set_upload_policy': {
            $policy = trim((string) ($_POST['policy'] ?? ''));
            if (!in_array($policy, VC_UPLOAD_POLICIES, true)) {
                throw new Exception('Política de subida inválida.');
            }
            vc_set_setting($conn, VC_SETTING_UPLOAD_POLICY, $policy);
            $response = [
                'status' => 'success',
                'message' => $policy === 'logged_in'
                    ? 'Ahora cualquier usuario logueado puede subir videos.'
                    : 'Ahora solo los usuarios verificados pueden subir videos.',
                'rewards' => vc_get_settings($conn),
            ];
            break;
        }

        case 'set_report_unverified': {
            $visible = (string) ($_POST['visible'] ?? '') === '1';
            vc_set_setting($conn, VC_SETTING_REPORT_UNVERIFIED, $visible ? '1' : '0');
            $response = [
                'status' => 'success',
                'message' => $visible
                    ? 'Los usuarios sin verificar ya pueden reportar videos.'
                    : 'El botón de reporte queda oculto para usuarios sin verificar.',
                'rewards' => vc_get_settings($conn),
            ];
            break;
        }

        case 'set_upload_limit': {
            $current = vc_get_settings($conn);
            $enabled = isset($_POST['enabled'])
                ? (string) $_POST['enabled'] === '1'
                : $current['upload_limit_enabled'];
            $limit = $current['upload_daily_limit'];
            if (isset($_POST['limit'])) {
                $limit = (int) $_POST['limit'];
                if ($limit < 1 || $limit > VC_UPLOAD_DAILY_LIMIT_MAX) {
                    throw new Exception('Límite inválido (1 a ' . VC_UPLOAD_DAILY_LIMIT_MAX . ').');
                }
            }
            vc_set_setting($conn, VC_SETTING_UPLOAD_DAILY_LIMIT, (string) $limit);
            vc_set_setting($conn, VC_SETTING_UPLOAD_LIMIT_ENABLED, $enabled ? '1' : '0');
            $response = [
                'status' => 'success',
                'message' => $enabled
                    ? "Límite diario activo: {$limit} video" . ($limit === 1 ? '' : 's') . ' por usuario.'
                    : 'Límite diario desactivado: los usuarios pueden subir sin tope.',
                'rewards' => vc_get_settings($conn),
            ];
            break;
        }

        case 'save_report_reason': {
            $id = (int) ($_POST['id'] ?? 0);
            $hasLabel = isset($_POST['label']);
            $hasEnabled = isset($_POST['enabled']);
            $enabled = (string) ($_POST['enabled'] ?? '1') === '1';

            if ($id <= 0) {
                if (!$hasLabel) throw new Exception('Escribe el texto del motivo.');
                $label = vcadmin_reason_label((string) $_POST['label']);
                $code = vcadmin_unique_reason_code($conn, $label);
                $res = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next FROM video_report_reasons");
                $position = (int) ($res ? ($res->fetch_assoc()['next'] ?? 1) : 1);
                $active = $enabled ? 1 : 0;
                $stmt = $conn->prepare("INSERT INTO video_report_reasons (code, label, is_active, sort_order) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssii', $code, $label, $active, $position);
                if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo añadir el motivo.'); }
                $stmt->close();
                $message = "Motivo añadido: «{$label}».";
            } else {
                if (!$hasLabel && !$hasEnabled) throw new Exception('Nada que guardar.');
                $stmt = $conn->prepare("SELECT label, is_active FROM video_report_reasons WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $current = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$current) throw new Exception('Motivo no encontrado.');

                $label = $hasLabel ? vcadmin_reason_label((string) $_POST['label']) : (string) $current['label'];
                $active = $hasEnabled ? ($enabled ? 1 : 0) : (int) $current['is_active'];
                $stmt = $conn->prepare("UPDATE video_report_reasons SET label = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param('sii', $label, $active, $id);
                if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo guardar el motivo.'); }
                $stmt->close();
                $message = $hasEnabled && !$hasLabel
                    ? ($active === 1 ? "Motivo activado: «{$label}»." : "Motivo desactivado: «{$label}». Ya no se ofrece al reportar.")
                    : "Motivo guardado: «{$label}».";
            }
            $response = ['status' => 'success', 'message' => $message, 'report_reasons' => vcadmin_report_reasons($conn)];
            break;
        }

        case 'move_report_reason': {
            $id = (int) ($_POST['id'] ?? 0);
            $direction = (string) ($_POST['direction'] ?? '');
            if ($id <= 0 || !in_array($direction, ['up', 'down'], true)) throw new Exception('Parámetros inválidos.');

            $conn->begin_transaction();
            try {
                $ids = array_column(vc_report_reasons($conn), 'id');
                $from = array_search($id, $ids, true);
                if ($from === false) throw new Exception('Motivo no encontrado.');
                $to = $direction === 'up' ? $from - 1 : $from + 1;
                if ($to >= 0 && $to < count($ids)) {
                    [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
                }
                vcadmin_renumber_reasons($conn, $ids);
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            $response = ['status' => 'success', 'message' => 'Orden actualizado.', 'report_reasons' => vcadmin_report_reasons($conn)];
            break;
        }

        case 'delete_report_reason': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT code, label FROM video_report_reasons WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $reason = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$reason) throw new Exception('Motivo no encontrado.');

                $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM video_reports WHERE reason = ?");
                $stmt->bind_param('s', $reason['code']);
                $stmt->execute();
                $uses = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
                $stmt->close();
                if ($uses > 0) {
                    throw new Exception("Este motivo ya se usó en {$uses} reporte" . ($uses === 1 ? '' : 's') . '. Desactívalo en lugar de borrarlo.');
                }

                $stmt = $conn->prepare("DELETE FROM video_report_reasons WHERE id = ?");
                $stmt->bind_param('i', $id);
                if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo eliminar el motivo.'); }
                $stmt->close();
                vcadmin_renumber_reasons($conn, array_column(vc_report_reasons($conn), 'id'));
                $conn->commit();
                $message = "Motivo eliminado: «{$reason['label']}».";
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            $response = ['status' => 'success', 'message' => $message, 'report_reasons' => vcadmin_report_reasons($conn)];
            break;
        }

        case 'remove_submission':
        case 'reject_submission': {
            $id = (int) ($_POST['id'] ?? 0);
            $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
            $ban = $action === 'reject_submission';
            if ($id <= 0) throw new Exception('ID inválido.');
            if ($reason === '') $reason = 'Video incorrecto o inapropiado';

            $conn->begin_transaction();
            try {
                $sub = vcadmin_get_submission($conn, $id, true);
                if ($sub['status'] !== 'published') throw new Exception('El envío ya no está publicado.');
                $debited = vcadmin_take_down($conn, $sub, 'rejected', $reason, $adminName, true, $ban);
                $conn->commit();
                $response = [
                    'status' => 'success',
                    'message' => $ban
                        ? "Vídeo retirado. Se revirtieron {$debited} Bananos y el usuario fue suspendido del sistema de vídeos."
                        : "Vídeo retirado y {$debited} Bananos revertidos. El usuario sigue activo.",
                ];
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
        }

        case 'restore_submission': {
            $id = (int) ($_POST['id'] ?? 0);
            $unban = ((string) ($_POST['unban'] ?? '1')) === '1';
            if ($id <= 0) throw new Exception('ID inválido.');

            $conn->begin_transaction();
            try {
                $sub = vcadmin_get_submission($conn, $id, true);
                if (!in_array($sub['status'], ['rejected', 'replaced', 'deleted'], true)) throw new Exception('El envío no está retirado.');

                vcadmin_publish_submission($conn, $sub);

                $stmt = $conn->prepare(
                    "UPDATE video_submissions
                     SET status = 'published', reject_reason = NULL, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                     WHERE id = ?"
                );
                $stmt->bind_param('si', $adminName, $id);
                if (!$stmt->execute()) throw new Exception('No se pudo restaurar el envío.');
                $stmt->close();

                $userId = (int) $sub['user_id'];
                vcadmin_restore_reward($conn, $sub);

                if ($unban) {
                    $stmt = $conn->prepare("DELETE FROM video_bans WHERE user_id = ?");
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();
                $response = ['status' => 'success', 'message' => 'Video restaurado, recompensa devuelta' . ($unban ? ' y usuario reactivado.' : '.')];
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
        }

        case 'set_lock': {
            $pistaId = (int) ($_POST['pista_id'] ?? 0);
            $locked = ((string) ($_POST['locked'] ?? '')) === '1' ? 1 : 0;
            if ($pistaId <= 0) throw new Exception('ID de pista inválido.');

            $stmt = $conn->prepare(
                "UPDATE pistas_musicales p
                 SET p.is_locked = ?,
                     p.verified_at = IF(? = 1, UTC_TIMESTAMP(), NULL),
                     p.verified_by = IF(? = 1, ?, NULL)
                 WHERE p.id = ? AND " . vc_sql_has_media('p')
            );
            $stmt->bind_param('iiisi', $locked, $locked, $locked, $adminName, $pistaId);
            if (!$stmt->execute()) throw new Exception('No se pudo actualizar el bloqueo.');
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed === 0 && $locked === 1) throw new Exception('La pista no tiene contenido publicado.');
            $response = [
                'status' => 'success',
                'message' => $locked
                    ? 'Pista verificada y bloqueada: el autor ya no puede editarla.'
                    : 'Verificación revertida: el autor recupera la opción de editar.',
            ];
            break;
        }

        case 'resolve_report': {
            $id = (int) ($_POST['id'] ?? 0);
            $verdict = (string) ($_POST['verdict'] ?? '');
            $banUploader = ((string) ($_POST['ban_uploader'] ?? '0')) === '1';
            $banReporter = ((string) ($_POST['ban_reporter'] ?? '0')) === '1';
            if ($id <= 0 || !in_array($verdict, ['uphold', 'dismiss'], true)) throw new Exception('Parámetros inválidos.');

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT * FROM video_reports WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $report = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$report) throw new Exception('Reporte no encontrado.');
                if ($report['status'] !== 'open') throw new Exception('El reporte ya fue resuelto.');

                $newStatus = $verdict === 'uphold' ? 'upheld' : 'dismissed';
                $rewardPaid = 0;
                $removedFile = null;
                $paidAmount = 0;
                $reversedAmount = 0;
                $uploaderId = null;
                $uploaderBanned = false;
                $contentRemoved = false;

                if ($verdict === 'uphold') {
                    // Pay the reporter the reward captured at report time.
                    $reporterId = (int) $report['reporter_id'];
                    $amount = (int) $report['reward_amount'];
                    if ($amount > 0) {
                        vc_credit_coins($conn, $reporterId, $amount, 'video_report_reward', 'video_report', $id);
                        $rewardPaid = 1;
                        $paidAmount = $amount;
                    }
                    // Take the reported content down (claw back; ban only if asked).
                    $reasonLabel = 'Reporte confirmado: ' . vc_report_reason_label($conn, (string) $report['reason']);
                    if (!empty($report['submission_id'])) {
                        $sub = vcadmin_get_submission($conn, (int) $report['submission_id'], true);
                        $uploaderId = (int) $sub['user_id'];
                        if ($sub['status'] === 'published') {
                            $reversedAmount = vcadmin_take_down($conn, $sub, 'rejected', $reasonLabel, $adminName, true, $banUploader);
                            $contentRemoved = true;
                            $uploaderBanned = $banUploader;
                        } elseif ($banUploader) {
                            // Already withdrawn/taken down meanwhile: the reward
                            // is reversed by that path; still honour the ban.
                            $reversedAmount = vcadmin_reverse_reward($conn, $sub);
                            vcadmin_ban($conn, $uploaderId, $reasonLabel, $adminName);
                            $uploaderBanned = true;
                        }
                    } else {
                        // Admin-added media without submission: just take it down.
                        $removedFile = vc_clear_track_media($conn, (int) $report['pista_id']);
                        $contentRemoved = true;
                    }
                } elseif ($banReporter) {
                    vcadmin_ban($conn, (int) $report['reporter_id'], 'Reporte malintencionado', $adminName);
                }

                $stmt = $conn->prepare(
                    "UPDATE video_reports SET status = ?, reward_paid = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?"
                );
                $stmt->bind_param('sisi', $newStatus, $rewardPaid, $adminName, $id);
                if (!$stmt->execute()) throw new Exception('No se pudo resolver el reporte.');
                $stmt->close();

                $conn->commit();
                if (!empty($removedFile)) vc_delete_media_file($removedFile);
                if ($verdict === 'uphold') {
                    $message = "Reporte confirmado: +{$paidAmount} Bananos al reportero";
                    if ($uploaderId !== null) {
                        $message .= $contentRemoved
                            ? ", contenido retirado y −{$reversedAmount} Bananos al autor"
                            : " (el contenido ya no estaba publicado; −{$reversedAmount} Bananos al autor)";
                        $message .= $uploaderBanned ? '. Autor suspendido.' : '. Autor sin suspender.';
                    } else {
                        $message .= ' y contenido del panel retirado.';
                    }
                } else {
                    $message = 'Reporte desestimado.' . ($banReporter ? ' Reportero suspendido.' : '');
                }
                $response = [
                    'status' => 'success',
                    'message' => $message,
                    'verdict' => $verdict,
                    'reward_paid_amount' => $paidAmount,
                    'reward_reversed_amount' => $reversedAmount,
                    'content_removed' => $contentRemoved,
                    'uploader_id' => $uploaderId,
                    'uploader_banned' => $uploaderBanned,
                    'reporter_banned' => $verdict === 'dismiss' && $banReporter,
                ];
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
        }

        case 'reopen_report': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID inválido.');

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT * FROM video_reports WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $report = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$report) throw new Exception('Reporte no encontrado.');
                if ($report['status'] === 'open') throw new Exception('El reporte ya está abierto.');

                // Reverse the reporter payment if it was paid.
                if ((int) $report['reward_paid'] === 1 && (int) $report['reward_amount'] > 0) {
                    vc_debit_coins($conn, (int) $report['reporter_id'], (int) $report['reward_amount'],
                        'video_report_reward_reversal', 'video_report', $id);
                }

                $stmt = $conn->prepare(
                    "UPDATE video_reports SET status = 'open', reward_paid = 0, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?"
                );
                $stmt->bind_param('si', $adminName, $id);
                if (!$stmt->execute()) {
                    // 1062 → another report is already open for this track.
                    throw new Exception($conn->errno === 1062
                        ? 'Ya existe otro reporte abierto para esta pista.'
                        : 'No se pudo reabrir el reporte.');
                }
                $stmt->close();

                $conn->commit();
                $response = ['status' => 'success', 'message' => 'Reporte reabierto (pago revertido si existía).'];
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
        }

        case 'ban_user': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
            $takeDown = (string) ($_POST['take_down'] ?? '1') === '1';
            if ($userId <= 0) throw new Exception('Usuario inválido.');
            if ($reason === '') $reason = 'Suspensión manual';

            $conn->begin_transaction();
            try {
                vcadmin_ban($conn, $userId, $reason, $adminName);
                $removed = 0;
                $debited = 0;
                if ($takeDown) {
                    $stmt = $conn->prepare("SELECT id FROM video_submissions WHERE user_id = ? AND status = 'published' FOR UPDATE");
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $ids = array_map(static fn (array $r): int => (int) $r['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
                    $stmt->close();
                    foreach ($ids as $subId) {
                        $sub = vcadmin_get_submission($conn, $subId, true);
                        $debited += vcadmin_take_down($conn, $sub, 'rejected', 'Usuario suspendido: ' . $reason, $adminName, true, false);
                        $removed++;
                    }
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            $response = [
                'status' => 'success',
                'message' => 'Usuario suspendido del sistema de vídeos.'
                    . ($takeDown ? " Se retiraron {$removed} vídeo" . ($removed === 1 ? '' : 's') . " y {$debited} Bananos." : ''),
            ];
            break;
        }

        case 'unban_user': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new Exception('Usuario inválido.');
            $stmt = $conn->prepare("DELETE FROM video_bans WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            $response = ['status' => 'success', 'message' => 'Usuario reactivado.'];
            break;
        }

        default:
            throw new Exception('Acción no reconocida.');
    }
} catch (Exception $e) {
    http_response_code(400);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    http_response_code(500);
    $response = ['status' => 'error', 'message' => 'Error interno.'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
$conn->close();
