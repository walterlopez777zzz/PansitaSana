<?php
/**
 * ============================================================================
 * BANANO STORE — VIDEO COMMUNITY LIBRARY (video_community_lib.php)
 * ============================================================================
 * Shared domain logic for the community Jam Track video system:
 *
 *   - Schema bootstrap (submissions, submission edit history, reports, report
 *     reasons, bans + pistas_musicales columns incl. direct media).
 *   - Configurable settings (site_settings: video_upload_reward / video_report_reward /
 *     video_top_visible / video_top_limit / video_upload_daily_limit /
 *     video_upload_limit_enabled / video_upload_enabled / video_edit_reported).
 *   - Direct media (admin-uploaded audio/video files) stored under
 *     <public root>/assets/pistas and served as /assets/pistas/<file>.
 *   - Admin-managed report reasons (video_report_reasons): the public modal
 *     offers the active ones, video_reports.reason stores the stable code.
 *   - YouTube URL parsing + oEmbed validation.
 *   - Coin credit/debit with mandatory coin_transactions ledger entries.
 *   - Derived per-track state: missing | published | reported | locked.
 *
 * Included by the public API (video_community.php), the legacy player endpoint
 * (get_video.php) and the wabo admin API (videos_comunidad).
 * Requires an active mysqli $conn (core db_connect).
 * ============================================================================
 */

require_once dirname(__DIR__) . '/core/AvatarCatalog.php';
require_once dirname(__DIR__) . '/core/UtcTime.php';

const VC_SETTING_UPLOAD_REWARD = 'video_upload_reward';
const VC_SETTING_REPORT_REWARD = 'video_report_reward';
const VC_SETTING_TOP_VISIBLE   = 'video_top_visible';
const VC_SETTING_TOP_LIMIT     = 'video_top_limit';
// Who may upload: 'verified' (only verified accounts) | 'logged_in' (any
// logged-in account, verified or not). Banned users are always refused.
const VC_SETTING_UPLOAD_POLICY = 'video_upload_policy';
// Whether unverified accounts (and guests) see/use "Reportar este video".
const VC_SETTING_REPORT_UNVERIFIED = 'video_report_unverified';
// Max submissions per user per rolling 24h, and whether that cap applies at all.
const VC_SETTING_UPLOAD_DAILY_LIMIT   = 'video_upload_daily_limit';
const VC_SETTING_UPLOAD_LIMIT_ENABLED = 'video_upload_limit_enabled';
// Master switch: when off, nobody can submit (the public CTA disappears and
// the API refuses new submissions). Existing videos keep playing.
const VC_SETTING_UPLOAD_ENABLED = 'video_upload_enabled';
// Whether the author may still fix a submission that has an open report.
const VC_SETTING_EDIT_REPORTED  = 'video_edit_reported';
const VC_DEFAULT_UPLOAD_ENABLED = true;
const VC_DEFAULT_EDIT_REPORTED  = false;
const VC_DEFAULT_UPLOAD_REWARD = 20;
const VC_DEFAULT_REPORT_REWARD = 500;
const VC_DEFAULT_TOP_VISIBLE   = true;
const VC_DEFAULT_TOP_LIMIT     = 10;
const VC_DEFAULT_UPLOAD_POLICY = 'verified';
const VC_DEFAULT_REPORT_UNVERIFIED = true;
const VC_DEFAULT_UPLOAD_DAILY_LIMIT   = 5;
const VC_DEFAULT_UPLOAD_LIMIT_ENABLED = true;
const VC_UPLOAD_DAILY_LIMIT_MAX       = 1000;
const VC_TOP_LIMIT_MAX         = 50;
const VC_UPLOAD_POLICIES       = ['verified', 'logged_in'];
// Videos placed on a track by the admin panel/system carry no submission row.
const VC_SOURCE_COMMUNITY      = 'community';
const VC_SOURCE_SYSTEM         = 'system';
const VC_SYSTEM_CONTRIBUTOR    = 'Banano';
// How a track's media is played: YouTube embed, direct audio file (artwork
// stays, player below) or direct video file (replaces the artwork).
const VC_MEDIA_YOUTUBE = 'youtube';
const VC_MEDIA_AUDIO   = 'audio';
const VC_MEDIA_VIDEO   = 'video';
const VC_MEDIA_TYPES   = [VC_MEDIA_YOUTUBE, VC_MEDIA_AUDIO, VC_MEDIA_VIDEO];
const VC_MEDIA_URL_BASE = '/assets/pistas/';
const VC_MEDIA_AUDIO_MIME = [
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/aac' => 'aac', 'audio/x-aac' => 'aac',
    'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav',
    'audio/x-wav' => 'wav', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
];
const VC_MEDIA_VIDEO_MIME = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
const VC_MEDIA_MAX_AUDIO_BYTES = 40 * 1024 * 1024;
const VC_MEDIA_MAX_VIDEO_BYTES = 300 * 1024 * 1024;
// Where a submission edit came from (video_submission_edits.edited_by).
const VC_EDIT_BY_USER  = 'user';
const VC_EDIT_BY_ADMIN = 'admin';
// video_submission_edits.track_state for the audit row written when the
// author withdraws their own submission (new_youtube_id is '' on that row).
const VC_EDIT_STATE_DELETED = 'deleted';
// video_submissions.status values: published | rejected (panel took it
// down) | replaced (panel swapped it for its own media) | deleted (the
// author withdrew it and the reward was reversed).
const VC_SUBMISSION_STATUSES = ['published', 'rejected', 'replaced', 'deleted'];

// Report reasons live in video_report_reasons (admin-managed). This seed is
// written once, when the table is first created; the panel owns it afterwards.
const VC_REPORT_REASON_SEED = [
    'no_funciona' => 'El video no funciona',
    'cancion_mal' => 'No es la canción correcta',
    'inicio_mal'  => 'El segundo de inicio está mal',
];
const VC_REPORT_REASON_CODE_MAX  = 50;  // video_reports.reason VARCHAR(50)
const VC_REPORT_REASON_LABEL_MIN = 3;
const VC_REPORT_REASON_LABEL_MAX = 120;

/** Creates/upgrades the community video tables. Idempotent and cheap. */
function vc_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS video_submissions (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id        INT NOT NULL,
        cosmetic_id    VARCHAR(120) NOT NULL DEFAULT '',
        track_name     VARCHAR(255) NOT NULL,
        youtube_id     VARCHAR(20) NOT NULL,
        start_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
        status         ENUM('published','rejected','replaced','deleted') NOT NULL DEFAULT 'published',
        reward_amount  INT UNSIGNED NOT NULL DEFAULT 20,
        video_title    VARCHAR(255) NULL,
        reject_reason  VARCHAR(255) NULL,
        reviewed_by    VARCHAR(100) NULL,
        reviewed_at    DATETIME NULL,
        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_track (track_name),
        KEY idx_cosmetic (cosmetic_id),
        KEY idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // active_key: generated column → at most ONE open report per track,
    // enforced by the DB itself even under concurrency.
    $conn->query("CREATE TABLE IF NOT EXISTS video_reports (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        pista_id       INT NOT NULL,
        submission_id  BIGINT UNSIGNED NULL,
        reporter_id    INT NOT NULL,
        reason         VARCHAR(50) NOT NULL,
        status         ENUM('open','upheld','dismissed') NOT NULL DEFAULT 'open',
        reward_amount  INT UNSIGNED NOT NULL DEFAULT 0,
        reward_paid    TINYINT(1) NOT NULL DEFAULT 0,
        reviewed_by    VARCHAR(100) NULL,
        reviewed_at    DATETIME NULL,
        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        active_key     INT AS (IF(status = 'open', pista_id, NULL)) STORED,
        UNIQUE KEY uq_active_report (active_key),
        KEY idx_reporter (reporter_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $reasonsTable = $conn->query("SHOW TABLES LIKE 'video_report_reasons'");
    $seedReasons = !($reasonsTable && $reasonsTable->num_rows > 0);
    $conn->query("CREATE TABLE IF NOT EXISTS video_report_reasons (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code       VARCHAR(50) NOT NULL,
        label      VARCHAR(120) NOT NULL,
        is_active  TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($seedReasons) {
        $stmt = $conn->prepare("INSERT IGNORE INTO video_report_reasons (code, label, sort_order) VALUES (?, ?, ?)");
        if ($stmt) {
            $order = 0;
            foreach (VC_REPORT_REASON_SEED as $code => $label) {
                $order++;
                $stmt->bind_param('ssi', $code, $label, $order);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS video_bans (
        user_id    INT PRIMARY KEY,
        reason     VARCHAR(255) NULL,
        banned_by  VARCHAR(100) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Every change of a submission's link/start second, by its author or by
    // the panel. Never pruned: it is the audit trail behind moderation.
    $conn->query("CREATE TABLE IF NOT EXISTS video_submission_edits (
        id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        submission_id     BIGINT UNSIGNED NOT NULL,
        user_id           INT NOT NULL,
        edited_by         ENUM('user','admin') NOT NULL DEFAULT 'user',
        editor_name       VARCHAR(100) NULL,
        old_youtube_id    VARCHAR(20) NOT NULL,
        old_start_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        new_youtube_id    VARCHAR(20) NOT NULL,
        new_start_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        track_state       VARCHAR(20) NOT NULL DEFAULT 'published',
        created_at        DATETIME NOT NULL,
        KEY idx_submission (submission_id, created_at),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // pistas_musicales: canonical id + verified lock + community credit link
    // + direct media (admin audio/video files) + publish/verify timestamps (UTC).
    vc_ensure_columns($conn, 'pistas_musicales', [
        'cosmetic_id'   => "ADD COLUMN cosmetic_id VARCHAR(120) NULL, ADD KEY idx_cosmetic (cosmetic_id)",
        'is_locked'     => "ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0",
        'submission_id' => "ADD COLUMN submission_id BIGINT UNSIGNED NULL",
        'media_type'    => "ADD COLUMN media_type ENUM('youtube','audio','video') NOT NULL DEFAULT 'youtube'",
        'media_file'    => "ADD COLUMN media_file VARCHAR(255) NULL",
        'published_at'  => "ADD COLUMN published_at DATETIME NULL",
        'verified_at'   => "ADD COLUMN verified_at DATETIME NULL",
        'verified_by'   => "ADD COLUMN verified_by VARCHAR(100) NULL",
    ]);

    // video_submissions: per-submission override that lets the author fix a
    // reported video, and when it was last edited.
    vc_ensure_columns($conn, 'video_submissions', [
        'edit_allowed' => "ADD COLUMN edit_allowed TINYINT(1) NOT NULL DEFAULT 0",
        'edited_at'    => "ADD COLUMN edited_at DATETIME NULL",
    ]);
    // reward_reversed: Bananos of the upload reward already clawed back from
    // the author's global balance for this submission. The row itself is the
    // source of truth for reversals/restores (the ledger stays the audit trail).
    $res = $conn->query("SHOW COLUMNS FROM video_submissions LIKE 'reward_reversed'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE video_submissions ADD COLUMN reward_reversed INT UNSIGNED NOT NULL DEFAULT 0");
        $conn->query(
            "UPDATE video_submissions s
             SET s.reward_reversed = LEAST(s.reward_amount, GREATEST(0, (
                 SELECT COALESCE(-SUM(t.amount), 0) FROM coin_transactions t
                 WHERE t.user_id = s.user_id AND t.reference_type = 'video_submission' AND t.reference_id = s.id
                   AND t.reason IN ('video_reward_reversal', 'video_reward_restore')
             )))"
        );
    }
    // 'replaced': the panel swapped the community video for admin media; the
    // author keeps the reward (unlike 'rejected'), the track just plays
    // something else now. 'deleted': the author withdrew it (reward reversed).
    $res = $conn->query("SHOW COLUMNS FROM video_submissions LIKE 'status'");
    $statusCol = $res ? $res->fetch_assoc() : null;
    $statusType = (string) ($statusCol['Type'] ?? '');
    if ($statusCol && (strpos($statusType, 'replaced') === false || strpos($statusType, 'deleted') === false)) {
        $conn->query("ALTER TABLE video_submissions MODIFY status ENUM('published','rejected','replaced','deleted') NOT NULL DEFAULT 'published'");
    }

    // Legacy pistas_musicales.youtube_id is NOT NULL: direct audio/video rows
    // have no YouTube id, so the column must accept NULL (and every clear /
    // direct-media write below also uses '' as a belt-and-braces fallback).
    $res = $conn->query("SHOW COLUMNS FROM pistas_musicales LIKE 'youtube_id'");
    $ytCol = $res ? $res->fetch_assoc() : null;
    if ($ytCol && strtoupper((string) ($ytCol['Null'] ?? '')) === 'NO') {
        $type = preg_replace('/[^A-Za-z0-9(),_ ]/', '', (string) ($ytCol['Type'] ?? '')) ?: 'VARCHAR(20)';
        $conn->query("ALTER TABLE pistas_musicales MODIFY youtube_id {$type} NULL DEFAULT NULL");
    }

    // Seed settings so the admin panel always finds them.
    $stmt = $conn->prepare("INSERT IGNORE INTO site_settings (key_name, value) VALUES (?, ?)");
    if ($stmt) {
        foreach ([
            VC_SETTING_UPLOAD_REWARD => (string) VC_DEFAULT_UPLOAD_REWARD,
            VC_SETTING_REPORT_REWARD => (string) VC_DEFAULT_REPORT_REWARD,
            VC_SETTING_TOP_VISIBLE   => VC_DEFAULT_TOP_VISIBLE ? '1' : '0',
            VC_SETTING_TOP_LIMIT     => (string) VC_DEFAULT_TOP_LIMIT,
            VC_SETTING_UPLOAD_POLICY => VC_DEFAULT_UPLOAD_POLICY,
            VC_SETTING_REPORT_UNVERIFIED => VC_DEFAULT_REPORT_UNVERIFIED ? '1' : '0',
            VC_SETTING_UPLOAD_DAILY_LIMIT   => (string) VC_DEFAULT_UPLOAD_DAILY_LIMIT,
            VC_SETTING_UPLOAD_LIMIT_ENABLED => VC_DEFAULT_UPLOAD_LIMIT_ENABLED ? '1' : '0',
            VC_SETTING_UPLOAD_ENABLED       => VC_DEFAULT_UPLOAD_ENABLED ? '1' : '0',
            VC_SETTING_EDIT_REPORTED        => VC_DEFAULT_EDIT_REPORTED ? '1' : '0',
        ] as $key => $value) {
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
        }
        $stmt->close();
    }
}

/** Adds the listed columns to $table when missing ($ddl = ALTER clause without the table). */
function vc_ensure_columns(mysqli $conn, string $table, array $columns): void
{
    foreach ($columns as $column => $ddl) {
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `{$table}` {$ddl}");
        }
    }
}

// ------------------------------------------------------------------ media

/** Physical folder for admin-uploaded audio/video files (public, web-served). */
function vc_media_dir(): string
{
    return AvatarCatalog::publicRoot() . '/assets/pistas';
}

/** Public URL of a stored media file, or null when there is none. */
function vc_media_url(?string $file): ?string
{
    $file = trim((string) $file);
    if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) return null;
    return VC_MEDIA_URL_BASE . rawurlencode($file);
}

/** Normalizes a stored media type, treating unknown/empty values as YouTube. */
function vc_normalize_media_type(?string $type): string
{
    return in_array((string) $type, VC_MEDIA_TYPES, true) ? (string) $type : VC_MEDIA_YOUTUBE;
}

/**
 * SQL predicate "this track row has playable media" for the given alias:
 * a YouTube id or a direct file. Kept in one place so the shop badge, the
 * player and the panel can never disagree.
 */
function vc_sql_has_media(string $alias = 'p'): string
{
    return "(({$alias}.youtube_id IS NOT NULL AND {$alias}.youtube_id <> '')"
        . " OR ({$alias}.media_file IS NOT NULL AND {$alias}.media_file <> ''))";
}

/** PHP twin of vc_sql_has_media() for a fetched pistas_musicales row. */
function vc_row_has_media(?array $row): bool
{
    if (!$row) return false;
    $yt = (string) ($row['youtube_id'] ?? '');
    $file = (string) ($row['media_file'] ?? '');
    return $yt !== '' || $file !== '';
}

/**
 * Validates an uploaded audio/video file (real MIME via finfo, size cap) and
 * moves it into vc_media_dir(). Returns ['file' => stored name, 'type' => audio|video].
 */
function vc_store_media_upload(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception('El archivo supera el tamaño permitido por el servidor (upload_max_filesize / post_max_size).');
        }
        throw new Exception('No se recibió el archivo.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new Exception('Archivo inválido.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string) ($finfo->file($tmp) ?: ''));
    if (isset(VC_MEDIA_AUDIO_MIME[$mime])) {
        $type = VC_MEDIA_AUDIO;
        $ext = VC_MEDIA_AUDIO_MIME[$mime];
        $max = VC_MEDIA_MAX_AUDIO_BYTES;
    } elseif (isset(VC_MEDIA_VIDEO_MIME[$mime])) {
        $type = VC_MEDIA_VIDEO;
        $ext = VC_MEDIA_VIDEO_MIME[$mime];
        $max = VC_MEDIA_MAX_VIDEO_BYTES;
    } else {
        throw new Exception('Formato no admitido. Audio: MP3, AAC, M4A, OGG, WAV, FLAC. Vídeo: MP4, WEBM, MOV.');
    }
    if ((int) ($file['size'] ?? 0) > $max) {
        throw new Exception('El archivo pesa demasiado (máx. ' . (int) round($max / 1048576) . ' MB para ' . ($type === VC_MEDIA_AUDIO ? 'audio' : 'vídeo') . ').');
    }

    $dir = vc_media_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new Exception('No se pudo crear la carpeta de pistas (' . $dir . ').');
    if (!is_writable($dir)) throw new Exception('La carpeta de pistas no tiene permisos de escritura (' . $dir . ').');
    $name = uniqid('trk_', true);
    $name = str_replace('.', '', $name) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) throw new Exception('Error al guardar el archivo en el servidor.');
    return ['file' => $name, 'type' => $type];
}

/** Deletes a stored media file (best effort, never throws). */
function vc_delete_media_file(?string $file): void
{
    $file = trim((string) $file);
    if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) return;
    $path = vc_media_dir() . '/' . $file;
    if (is_file($path)) @unlink($path);
}

/**
 * Empties a track row (keeps the row so history/reports still resolve) and
 * returns the direct media file that must be deleted from disk AFTER the
 * caller commits. Inside the caller's transaction.
 */
function vc_clear_track_media(mysqli $conn, int $pistaId): ?string
{
    $stmt = $conn->prepare("SELECT media_file FROM pistas_musicales WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $pistaId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $stmt = $conn->prepare(
        "UPDATE pistas_musicales
         SET youtube_id = '', start_time = 0, submission_id = NULL, is_locked = 0,
             media_type = 'youtube', media_file = NULL, published_at = NULL,
             verified_at = NULL, verified_by = NULL
         WHERE id = ?"
    );
    $stmt->bind_param('i', $pistaId);
    if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo retirar el contenido de la pista.'); }
    $stmt->close();
    $file = trim((string) ($row['media_file'] ?? ''));
    return $file !== '' ? $file : null;
}

/** Clamps a requested Top size to the supported range. */
function vc_clamp_top_limit(int $limit): int
{
    return max(1, min(VC_TOP_LIMIT_MAX, $limit));
}

/** Clamps a requested daily upload cap to the supported range. */
function vc_clamp_upload_daily_limit(int $limit): int
{
    return max(1, min(VC_UPLOAD_DAILY_LIMIT_MAX, $limit));
}

/** Normalizes a stored upload policy, falling back to the default. */
function vc_normalize_upload_policy(string $policy): string
{
    return in_array($policy, VC_UPLOAD_POLICIES, true) ? $policy : VC_DEFAULT_UPLOAD_POLICY;
}

/**
 * @return array{
 *   upload_reward:int, report_reward:int, top_visible:bool, top_limit:int,
 *   upload_policy:string, report_unverified:bool,
 *   upload_daily_limit:int, upload_limit_enabled:bool,
 *   upload_enabled:bool, edit_reported:bool
 * }
 */
function vc_get_settings(mysqli $conn): array
{
    $settings = [
        'upload_reward'        => VC_DEFAULT_UPLOAD_REWARD,
        'report_reward'        => VC_DEFAULT_REPORT_REWARD,
        'top_visible'          => VC_DEFAULT_TOP_VISIBLE,
        'top_limit'            => VC_DEFAULT_TOP_LIMIT,
        'upload_policy'        => VC_DEFAULT_UPLOAD_POLICY,
        'report_unverified'    => VC_DEFAULT_REPORT_UNVERIFIED,
        'upload_daily_limit'   => VC_DEFAULT_UPLOAD_DAILY_LIMIT,
        'upload_limit_enabled' => VC_DEFAULT_UPLOAD_LIMIT_ENABLED,
        'upload_enabled'       => VC_DEFAULT_UPLOAD_ENABLED,
        'edit_reported'        => VC_DEFAULT_EDIT_REPORTED,
    ];
    $res = $conn->query("SELECT key_name, value FROM site_settings WHERE key_name IN ('"
        . VC_SETTING_UPLOAD_REWARD . "', '" . VC_SETTING_REPORT_REWARD . "', '"
        . VC_SETTING_TOP_VISIBLE . "', '" . VC_SETTING_TOP_LIMIT . "', '"
        . VC_SETTING_UPLOAD_POLICY . "', '" . VC_SETTING_REPORT_UNVERIFIED . "', '"
        . VC_SETTING_UPLOAD_DAILY_LIMIT . "', '" . VC_SETTING_UPLOAD_LIMIT_ENABLED . "', '"
        . VC_SETTING_UPLOAD_ENABLED . "', '" . VC_SETTING_EDIT_REPORTED . "')");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $raw = (string) $row['value'];
            if ($row['key_name'] === VC_SETTING_UPLOAD_POLICY) {
                $settings['upload_policy'] = vc_normalize_upload_policy(trim($raw));
                continue;
            }
            $value = (int) $raw;
            if ($value < 0) continue;
            switch ($row['key_name']) {
                case VC_SETTING_UPLOAD_REWARD:     $settings['upload_reward'] = $value; break;
                case VC_SETTING_REPORT_REWARD:     $settings['report_reward'] = $value; break;
                case VC_SETTING_TOP_VISIBLE:       $settings['top_visible'] = $value === 1; break;
                case VC_SETTING_TOP_LIMIT:         $settings['top_limit'] = vc_clamp_top_limit($value); break;
                case VC_SETTING_REPORT_UNVERIFIED: $settings['report_unverified'] = $value === 1; break;
                case VC_SETTING_UPLOAD_DAILY_LIMIT:   $settings['upload_daily_limit'] = vc_clamp_upload_daily_limit($value); break;
                case VC_SETTING_UPLOAD_LIMIT_ENABLED: $settings['upload_limit_enabled'] = $value === 1; break;
                case VC_SETTING_UPLOAD_ENABLED:       $settings['upload_enabled'] = $value === 1; break;
                case VC_SETTING_EDIT_REPORTED:        $settings['edit_reported'] = $value === 1; break;
            }
        }
    }
    return $settings;
}

/** Whether this viewer may upload a video under the configured policy. */
function vc_can_upload(string $viewer, string $uploadPolicy, bool $uploadEnabled = true): bool
{
    if (!$uploadEnabled) return false;
    if ($viewer === 'verified') return true;
    return $viewer === 'unverified' && $uploadPolicy === 'logged_in';
}

/**
 * Whether the author may still edit (link / start second) their published
 * community video: never once verified (locked); while reported only if the
 * global toggle or the per-submission override allows it.
 */
function vc_can_edit(array $state, int $userId, array $settings): bool
{
    if ($userId <= 0 || $state['owner_id'] !== $userId) return false;
    if ($state['source'] !== VC_SOURCE_COMMUNITY || $state['is_locked']) return false;
    if ($state['state'] === 'published') return true;
    if ($state['state'] === 'reported') {
        return !empty($settings['edit_reported']) || !empty($state['edit_allowed']);
    }
    return false;
}

/**
 * Whether this viewer may open a report on a track in the given state.
 * Only community videos are reportable; unverified accounts and guests
 * depend on the admin toggle.
 */
function vc_can_report(array $state, string $viewer, bool $reportUnverified): bool
{
    if ($state['state'] !== 'published' || $state['source'] !== VC_SOURCE_COMMUNITY) return false;
    if ($viewer === 'banned') return false;
    if ($viewer === 'verified') return true;
    return $reportUnverified;
}

/**
 * Report reasons in the admin's order.
 * @return list<array{id:int, code:string, label:string, is_active:bool, sort_order:int}>
 */
function vc_report_reasons(mysqli $conn, bool $activeOnly = false): array
{
    $res = $conn->query(
        "SELECT id, code, label, is_active, sort_order FROM video_report_reasons"
        . ($activeOnly ? ' WHERE is_active = 1' : '')
        . ' ORDER BY sort_order ASC, id ASC'
    );
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'id'         => (int) $row['id'],
                'code'       => (string) $row['code'],
                'label'      => (string) $row['label'],
                'is_active'  => (int) $row['is_active'] === 1,
                'sort_order' => (int) $row['sort_order'],
            ];
        }
    }
    return $rows;
}

/**
 * What the public report modal offers: active reasons only, as {code, label}.
 * @return list<array{code:string, label:string}>
 */
function vc_public_report_reasons(mysqli $conn): array
{
    return array_map(
        static fn (array $reason): array => ['code' => $reason['code'], 'label' => $reason['label']],
        vc_report_reasons($conn, true)
    );
}

/** Whether a reason code is currently offered to reporters. */
function vc_report_reason_is_active(mysqli $conn, string $code): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM video_report_reasons WHERE code = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $active = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $active;
}

/**
 * Human label for a stored reason code. Disabled reasons still resolve (their
 * history must stay readable); codes that no longer exist fall back to the code.
 */
function vc_report_reason_label(mysqli $conn, string $code): string
{
    $stmt = $conn->prepare("SELECT label FROM video_report_reasons WHERE code = ? LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string) $row['label'] : $code;
}

/**
 * Stable machine code for a new reason, derived from its label:
 * 'El audio está desincronizado' → 'el_audio_esta_desincronizado'.
 * Deterministic (no locale/iconv dependency) and short enough to leave room
 * for a uniqueness suffix within VC_REPORT_REASON_CODE_MAX.
 */
function vc_report_reason_code_from_label(string $label): string
{
    $ascii = strtr(mb_strtolower($label, 'UTF-8'), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
    ]);
    $code = trim(preg_replace('/[^a-z0-9]+/', '_', $ascii) ?? '', '_');
    if ($code === '') $code = 'motivo';
    return substr($code, 0, VC_REPORT_REASON_CODE_MAX - 4);
}

/** Upserts one site_settings key (value stored as string). */
function vc_set_setting(mysqli $conn, string $key, string $value): void
{
    $stmt = $conn->prepare(
        "INSERT INTO site_settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
    );
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new Exception('No se pudo guardar la configuración.');
}

/** Extracts an 11-char YouTube video id from a watch/short/embed URL. */
function vc_extract_youtube_id(string $url): ?string
{
    $url = trim($url);
    if ($url === '') return null;
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) return $url;
    if (preg_match(
        '#(?:https?://)?(?:www\.|m\.)?(?:youtu\.be/|youtube\.com/(?:watch\?(?:[^&\s]*&)*v=|embed/|shorts/|live/|v/))([a-zA-Z0-9_-]{11})#',
        $url,
        $m
    )) {
        return $m[1];
    }
    return null;
}

/**
 * Server-side existence check via YouTube oEmbed.
 * Returns ['ok'=>bool, 'title'=>?string]. Network failures are treated as
 * inconclusive (ok=true, title=null) so users are never blocked by egress.
 */
function vc_validate_youtube(string $youtubeId): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    $raw = @file_get_contents(
        'https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $youtubeId),
        false,
        $ctx
    );
    if ($raw === false) {
        return ['ok' => true, 'title' => null];
    }
    $statusLine = $http_response_header[0] ?? '';
    if (preg_match('/\s(4\d\d)\s/', $statusLine)) {
        return ['ok' => false, 'title' => null];
    }
    $data = json_decode($raw, true);
    $title = is_array($data) && isset($data['title']) ? mb_substr((string) $data['title'], 0, 255) : null;
    return ['ok' => true, 'title' => $title];
}

/**
 * Who is looking at the panel, as a single server-derived enum the client
 * can render from without re-deriving auth rules:
 *   guest | banned | unverified | verified   (banned wins over verification).
 */
function vc_viewer_status(mysqli $conn, int $userId): string
{
    if ($userId <= 0) return 'guest';
    $stmt = $conn->prepare(
        "SELECT u.is_verified, (b.user_id IS NOT NULL) AS banned
         FROM users u
         LEFT JOIN video_bans b ON b.user_id = u.id
         WHERE u.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return 'guest';
    if ((int) $row['banned'] === 1) return 'banned';
    return (int) $row['is_verified'] === 1 ? 'verified' : 'unverified';
}

function vc_is_banned(mysqli $conn, int $userId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM video_bans WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $banned = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $banned;
}

/**
 * Credits coins and writes the mandatory ledger row, inside the caller's
 * transaction. $reason: video_reward | video_report_reward | reversal reasons.
 */
function vc_credit_coins(mysqli $conn, int $userId, int $amount, string $reason, string $refType, int $refId): void
{
    if ($amount === 0) return;
    $stmt = $conn->prepare(
        "INSERT INTO coins (user_id, balance) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)"
    );
    $stmt->bind_param('ii', $userId, $amount);
    if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo acreditar el saldo.'); }
    $stmt->close();
    vc_ledger($conn, $userId, $amount, $reason, $refType, $refId);
}

/** Current global Bananos balance of a user (the `coins` table every module spends from). */
function vc_coin_balance(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("SELECT COALESCE(balance, 0) AS balance FROM coins WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) floor((float) ($row['balance'] ?? 0));
}

/**
 * Debits the user's global balance (floored at 0 to never leave a negative
 * balance) and records the ACTUAL debited amount in the ledger so the books
 * always balance. Returns the amount actually debited.
 */
function vc_debit_coins(mysqli $conn, int $userId, int $amount, string $reason, string $refType, int $refId): int
{
    if ($amount === 0) return 0;
    $stmt = $conn->prepare("INSERT IGNORE INTO coins (user_id, balance) VALUES (?, 0)");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("SELECT COALESCE(balance, 0) AS balance FROM coins WHERE user_id = ? FOR UPDATE");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $balance = (int) floor((float) ($row['balance'] ?? 0));
    $debit = min($amount, max(0, $balance));
    if ($debit > 0) {
        $stmt = $conn->prepare("UPDATE coins SET balance = GREATEST(0, balance - ?) WHERE user_id = ?");
        $stmt->bind_param('ii', $debit, $userId);
        if (!$stmt->execute() || $stmt->affected_rows < 1) { $stmt->close(); throw new Exception('No se pudo descontar el saldo.'); }
        $stmt->close();
    }
    vc_ledger($conn, $userId, -$debit, $reason, $refType, $refId);
    return $debit;
}

function vc_ledger(mysqli $conn, int $userId, int $amount, string $reason, string $refType, int $refId): void
{
    $stmt = $conn->prepare(
        "INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iissi', $userId, $amount, $reason, $refType, $refId);
    if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo registrar la transacción.'); }
    $stmt->close();
}

/**
 * Net upload reward still in the author's pocket for a submission:
 * reward_amount minus what has already been clawed back (row truth).
 */
function vc_reward_net(mysqli $conn, int $userId, int $submissionId): int
{
    $stmt = $conn->prepare(
        "SELECT GREATEST(0, CAST(reward_amount AS SIGNED) - CAST(reward_reversed AS SIGNED)) AS net
         FROM video_submissions WHERE id = ? AND user_id = ?"
    );
    $stmt->bind_param('ii', $submissionId, $userId);
    $stmt->execute();
    $net = (int) ($stmt->get_result()->fetch_assoc()['net'] ?? 0);
    $stmt->close();
    return $net;
}

/**
 * Claws back whatever of the upload reward the author still holds from the
 * global balance (never below zero) and records it on the submission.
 * Idempotent: a second call debits nothing. Returns the Bananos actually
 * debited. Inside the caller's transaction.
 */
function vc_reverse_reward(mysqli $conn, array $sub): int
{
    $userId = (int) $sub['user_id'];
    $subId = (int) $sub['id'];
    $net = vc_reward_net($conn, $userId, $subId);
    if ($net <= 0) return 0;
    $debited = vc_debit_coins($conn, $userId, $net, 'video_reward_reversal', 'video_submission', $subId);
    if ($debited > 0) {
        $stmt = $conn->prepare("UPDATE video_submissions SET reward_reversed = reward_reversed + ? WHERE id = ?");
        $stmt->bind_param('ii', $debited, $subId);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo registrar la reversión.'); }
        $stmt->close();
    }
    return $debited;
}

/**
 * Re-credits to the global balance exactly what was clawed back for a
 * submission and clears the row's reversed counter. Idempotent. Returns the
 * Bananos credited. Inside the caller's transaction.
 */
function vc_restore_reward(mysqli $conn, array $sub): int
{
    $userId = (int) $sub['user_id'];
    $subId = (int) $sub['id'];
    $stmt = $conn->prepare("SELECT reward_reversed FROM video_submissions WHERE id = ? AND user_id = ? FOR UPDATE");
    $stmt->bind_param('ii', $subId, $userId);
    $stmt->execute();
    $reversed = (int) ($stmt->get_result()->fetch_assoc()['reward_reversed'] ?? 0);
    $stmt->close();
    if ($reversed <= 0) return 0;
    vc_credit_coins($conn, $userId, $reversed, 'video_reward_restore', 'video_submission', $subId);
    $stmt = $conn->prepare("UPDATE video_submissions SET reward_reversed = 0 WHERE id = ?");
    $stmt->bind_param('i', $subId);
    if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo registrar la restitución.'); }
    $stmt->close();
    return $reversed;
}

/** Public display name of a user (never the Epic username). */
function vc_public_name(mysqli $conn, int $userId): ?string
{
    $stmt = $conn->prepare("SELECT public_display_name FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = trim((string) ($row['public_display_name'] ?? ''));
    return $name !== '' ? $name : null;
}

/**
 * Derived state of a track's video for the public UI.
 * `source` is 'community' when the published video comes from a user
 * submission, 'system' when it was placed by the admin panel (no submission
 * row, or the submission no longer matches the track's video). System videos
 * are credited to VC_SYSTEM_CONTRIBUTOR.
 * `owner_id` / `contributor_avatar` describe the uploader of community videos
 * (user id and public avatar URL); both are null for system videos.
 * `media_type` / `media_url`: youtube (embed) | audio | video (direct file).
 * `published_at`: ISO-8601 UTC instant the current media went live.
 * @return array{
 *   state:string, youtube_id:?string, start_time:int, is_locked:bool,
 *   contributor:?string, contributor_avatar:?string, source:string,
 *   pista_id:?int, report_reason:?string, owner_id:?int,
 *   media_type:string, media_url:?string, published_at:?string,
 *   submission_id:?int, edit_allowed:bool
 * }
 */
function vc_track_state(mysqli $conn, string $trackName): array
{
    $out = [
        'state' => 'missing', 'youtube_id' => null, 'start_time' => 0,
        'is_locked' => false, 'contributor' => null, 'contributor_avatar' => null,
        'source' => VC_SOURCE_SYSTEM, 'pista_id' => null, 'report_reason' => null,
        'owner_id' => null, 'media_type' => VC_MEDIA_YOUTUBE, 'media_url' => null,
        'published_at' => null, 'submission_id' => null, 'edit_allowed' => false,
    ];
    $stmt = $conn->prepare(
        "SELECT p.id, p.youtube_id, p.start_time, p.is_locked, p.submission_id,
                p.media_type, p.media_file, p.published_at,
                s.id AS community_submission_id, s.youtube_id AS submission_youtube_id,
                s.user_id AS owner_id, s.edit_allowed, s.created_at AS submission_created_at,
                u.public_display_name AS contributor,
                a.filename AS contributor_avatar
         FROM pistas_musicales p
         LEFT JOIN video_submissions s ON s.id = p.submission_id AND s.status = 'published'
         LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN avatars a ON a.id = u.avatar_id AND a.is_active = 1
         WHERE p.nombre_pista = ? LIMIT 1"
    );
    $stmt->bind_param('s', $trackName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!vc_row_has_media($row)) {
        if ($row) $out['pista_id'] = (int) $row['id'];
        return $out;
    }

    $out['pista_id'] = (int) $row['id'];
    $out['start_time'] = (int) ($row['start_time'] ?? 0);
    $out['is_locked'] = ((int) ($row['is_locked'] ?? 0)) === 1;

    $mediaType = vc_normalize_media_type($row['media_type'] ?? null);
    $mediaUrl = $mediaType === VC_MEDIA_YOUTUBE ? null : vc_media_url($row['media_file'] ?? null);
    $youtubeId = (string) ($row['youtube_id'] ?? '');
    if ($mediaUrl !== null) {
        $out['media_type'] = $mediaType;
        $out['media_url'] = $mediaUrl;
    } elseif ($youtubeId !== '') {
        $out['media_type'] = VC_MEDIA_YOUTUBE;
        $out['youtube_id'] = $youtubeId;
    } else {
        // Direct media flagged but file reference unusable → treat as missing.
        return $out;
    }

    // Compared in PHP (not in the JOIN) so differing column collations between
    // pistas_musicales and video_submissions can never break the query.
    $isCommunity = $out['media_type'] === VC_MEDIA_YOUTUBE
        && $row['community_submission_id'] !== null
        && (string) $row['submission_youtube_id'] === $youtubeId;
    if ($isCommunity) {
        $out['source'] = VC_SOURCE_COMMUNITY;
        $contributor = trim((string) ($row['contributor'] ?? ''));
        $out['contributor'] = $contributor !== '' ? $contributor : null;
        $out['owner_id'] = $row['owner_id'] !== null ? (int) $row['owner_id'] : null;
        $out['contributor_avatar'] = AvatarCatalog::urlFor($conn, $row['contributor_avatar']);
        $out['submission_id'] = (int) $row['community_submission_id'];
        $out['edit_allowed'] = ((int) ($row['edit_allowed'] ?? 0)) === 1;
    } else {
        $out['contributor'] = VC_SYSTEM_CONTRIBUTOR;
    }
    // published_at is written in UTC by every publishing path; rows that
    // predate the column fall back to the submission's creation instant.
    $publishedSql = (string) ($row['published_at'] ?? '');
    if ($publishedSql === '' && $isCommunity) $publishedSql = (string) ($row['submission_created_at'] ?? '');
    $out['published_at'] = $publishedSql !== '' ? UtcTime::sqlToIso($publishedSql) : null;

    if ($out['is_locked']) {
        $out['state'] = 'locked';
        return $out;
    }

    $stmt = $conn->prepare("SELECT reason FROM video_reports WHERE pista_id = ? AND status = 'open' LIMIT 1");
    $stmt->bind_param('i', $out['pista_id']);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($report) {
        $out['state'] = 'reported';
        $out['report_reason'] = (string) $report['reason'];
    } else {
        $out['state'] = 'published';
    }
    return $out;
}

/** Top contributors by Bananos earned via published video submissions. */
function vc_top_contributors(mysqli $conn, int $limit = VC_DEFAULT_TOP_LIMIT): array
{
    $limit = vc_clamp_top_limit($limit);
    $sql = "SELECT s.user_id, u.public_display_name AS name, a.filename AS avatar,
                   SUM(s.reward_amount) AS coins_earned, COUNT(*) AS videos
            FROM video_submissions s
            INNER JOIN users u ON u.id = s.user_id
            LEFT JOIN avatars a ON a.id = u.avatar_id AND a.is_active = 1
            WHERE s.status IN ('published','replaced')
              AND u.public_display_name IS NOT NULL AND u.public_display_name != ''
            GROUP BY s.user_id, u.public_display_name, a.filename
            ORDER BY coins_earned DESC, videos DESC
            LIMIT {$limit}";
    $res = $conn->query($sql);
    $top = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $top[] = [
                'user_id' => (int) $row['user_id'],
                'name'   => (string) $row['name'],
                'avatar' => AvatarCatalog::urlFor($conn, $row['avatar']),
                'coins'  => (int) $row['coins_earned'],
                'videos' => (int) $row['videos'],
            ];
        }
    }
    return $top;
}

/**
 * Global top: Bananos from published videos PLUS paid (upheld) reports, per
 * user. Both sources are what the ledger actually credited and kept.
 * @return list<array{user_id:int,name:string,avatar:?string,coins:int,video_coins:int,report_coins:int,videos:int,reports:int}>
 */
function vc_top_global(mysqli $conn, int $limit = VC_DEFAULT_TOP_LIMIT): array
{
    $limit = vc_clamp_top_limit($limit);
    $sql = "SELECT t.user_id, u.public_display_name AS name, a.filename AS avatar,
                   SUM(t.video_coins) AS video_coins, SUM(t.report_coins) AS report_coins,
                   SUM(t.videos) AS videos, SUM(t.reports) AS reports,
                   SUM(t.video_coins) + SUM(t.report_coins) AS coins
            FROM (
                SELECT s.user_id, SUM(s.reward_amount) AS video_coins, 0 AS report_coins, COUNT(*) AS videos, 0 AS reports
                FROM video_submissions s WHERE s.status IN ('published','replaced') GROUP BY s.user_id
                UNION ALL
                SELECT r.reporter_id, 0, SUM(r.reward_amount), 0, COUNT(*)
                FROM video_reports r WHERE r.status = 'upheld' AND r.reward_paid = 1 GROUP BY r.reporter_id
            ) t
            INNER JOIN users u ON u.id = t.user_id
            LEFT JOIN avatars a ON a.id = u.avatar_id AND a.is_active = 1
            WHERE u.public_display_name IS NOT NULL AND u.public_display_name != ''
            GROUP BY t.user_id, u.public_display_name, a.filename
            ORDER BY coins DESC, videos DESC, reports DESC
            LIMIT {$limit}";
    $res = $conn->query($sql);
    $top = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $top[] = [
                'user_id'      => (int) $row['user_id'],
                'name'         => (string) $row['name'],
                'avatar'       => AvatarCatalog::urlFor($conn, $row['avatar']),
                'coins'        => (int) $row['coins'],
                'video_coins'  => (int) $row['video_coins'],
                'report_coins' => (int) $row['report_coins'],
                'videos'       => (int) $row['videos'],
                'reports'      => (int) $row['reports'],
            ];
        }
    }
    return $top;
}

/**
 * Records one link/start change of a submission (inside the caller's
 * transaction). $trackState is the public state at edit time so moderators
 * can see whether the fix happened while a report was open.
 */
function vc_log_submission_edit(
    mysqli $conn, int $submissionId, int $userId, string $editedBy, ?string $editorName,
    string $oldYoutubeId, int $oldStart, string $newYoutubeId, int $newStart, string $trackState
): void {
    $editedBy = $editedBy === VC_EDIT_BY_ADMIN ? VC_EDIT_BY_ADMIN : VC_EDIT_BY_USER;
    $stmt = $conn->prepare(
        "INSERT INTO video_submission_edits
            (submission_id, user_id, edited_by, editor_name, old_youtube_id, old_start_seconds,
             new_youtube_id, new_start_seconds, track_state, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    );
    $stmt->bind_param('iisssisis', $submissionId, $userId, $editedBy, $editorName, $oldYoutubeId, $oldStart, $newYoutubeId, $newStart, $trackState);
    if (!$stmt->execute()) { $stmt->close(); throw new Exception('No se pudo registrar la edición.'); }
    $stmt->close();
}
