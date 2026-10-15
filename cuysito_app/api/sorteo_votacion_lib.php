<?php

if (!defined('SV_DEFAULT_MAX_PROPUESTAS')) define('SV_DEFAULT_MAX_PROPUESTAS', 4);
if (!defined('SV_DEFAULT_RECOMPENSA')) define('SV_DEFAULT_RECOMPENSA', 20);
if (!defined('SV_DEFAULT_MAX_PAVOS')) define('SV_DEFAULT_MAX_PAVOS', 1500);
if (!defined('SV_MAX_ITEMS')) define('SV_MAX_ITEMS', 12);
if (!defined('SV_REASON')) define('SV_REASON', 'sorteo_votacion');
if (!defined('SV_STATS_TTL')) define('SV_STATS_TTL', 300);

require_once __DIR__ . '/../core/DailySpinFilters.php';
require_once __DIR__ . '/../core/AvatarCatalog.php';
require_once __DIR__ . '/raffle_private.php';

if (!function_exists('sv_ensure_schema')) {
    function sv_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS sorteo_votacion_ciclos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estado ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
            opened_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            opened_by INT NOT NULL DEFAULT 0,
            closed_by INT NULL,
            KEY idx_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $conn->query("CREATE TABLE IF NOT EXISTS sorteo_votacion_propuestas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ciclo_id INT NOT NULL,
            usuario_id BIGINT UNSIGNED NOT NULL,
            items_json LONGTEXT NOT NULL,
            combo_hash CHAR(40) NOT NULL,
            items_count INT NOT NULL,
            total_pavos INT NOT NULL,
            total_soles DECIMAL(10,2) NOT NULL DEFAULT 0,
            estado ENUM('activa','ganadora','rechazada') NOT NULL DEFAULT 'activa',
            votos_count INT NOT NULL DEFAULT 0,
            recompensa_bananos DECIMAL(10,2) NOT NULL DEFAULT 0,
            shop_date VARCHAR(32) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_ciclo_usuario (ciclo_id, usuario_id),
            UNIQUE KEY uq_ciclo_combo (ciclo_id, combo_hash),
            KEY idx_ciclo_estado (ciclo_id, estado),
            KEY idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $conn->query("CREATE TABLE IF NOT EXISTS sorteo_votacion_votos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ciclo_id INT NOT NULL,
            propuesta_id INT NOT NULL,
            usuario_id BIGINT UNSIGNED NOT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_ciclo_usuario (ciclo_id, usuario_id),
            KEY idx_propuesta (propuesta_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $note = $conn->query("SHOW COLUMNS FROM coin_transactions LIKE 'note'");
            if ($note && $note->num_rows === 0) {
                $conn->query("ALTER TABLE coin_transactions ADD COLUMN note VARCHAR(255) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log('[sorteo_votacion] ' . $e->getMessage());
        }
        try {
            $res = $conn->query("SHOW COLUMNS FROM coin_transactions LIKE 'reason'");
            $column = $res ? $res->fetch_assoc() : null;
            if ($column && preg_match('/^enum\\(/i', (string)$column['Type']) && preg_match_all("/'((?:\\\\\\\\.|[^'])*)'/", (string)$column['Type'], $matches)) {
                $values = [];
                foreach ($matches[1] as $value) {
                    $values[] = stripcslashes($value);
                }
                if (!in_array(SV_REASON, $values, true)) {
                    $values[] = SV_REASON;
                    $enumValues = array_map(static function ($value) use ($conn) {
                        return "'" . $conn->real_escape_string($value) . "'";
                    }, $values);
                    $nullSql = (($column['Null'] ?? '') === 'NO') ? 'NOT NULL' : 'NULL';
                    $defaultSql = '';
                    if (array_key_exists('Default', $column) && $column['Default'] !== null) {
                        $defaultSql = " DEFAULT '" . $conn->real_escape_string((string)$column['Default']) . "'";
                    }
                    $conn->query("ALTER TABLE coin_transactions MODIFY reason ENUM(" . implode(',', $enumValues) . ") {$nullSql}{$defaultSql}");
                }
            }
        } catch (Throwable $e) {
            error_log('[sorteo_votacion] ' . $e->getMessage());
        }
    }
}

if (!function_exists('sv_settings')) {
    function sv_settings(mysqli $conn): array
    {
        $values = [
            'sv_votacion_abierta' => '0',
            'sv_max_propuestas' => (string)SV_DEFAULT_MAX_PROPUESTAS,
            'sv_recompensa_bananos' => (string)SV_DEFAULT_RECOMPENSA,
            'sv_max_pavos' => (string)SV_DEFAULT_MAX_PAVOS,
            'sv_meme_url' => '',
        ];
        $stmt = $conn->prepare('SELECT key_name, value FROM site_settings WHERE key_name IN (?, ?, ?, ?, ?)');
        if ($stmt) {
            $keys = array_keys($values);
            $stmt->bind_param('sssss', $keys[0], $keys[1], $keys[2], $keys[3], $keys[4]);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (array_key_exists($row['key_name'], $values)) $values[$row['key_name']] = (string)$row['value'];
            }
            $stmt->close();
        }
        return [
            'votacion_abierta' => $values['sv_votacion_abierta'] === '1',
            'max_propuestas' => max(1, min(50, (int)$values['sv_max_propuestas'])),
            'recompensa_bananos' => max(0.0, min(1000.0, (float)$values['sv_recompensa_bananos'])),
            'max_pavos' => max(100, min(10000, (int)$values['sv_max_pavos'])),
            'meme_url' => trim($values['sv_meme_url']) !== '' ? trim($values['sv_meme_url']) : null,
        ];
    }
}

if (!function_exists('sv_save_settings')) {
    function sv_save_settings(mysqli $conn, array $in, int $adminId): array
    {
        $open = filter_var($in['votacion_abierta'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $max = max(1, min(50, (int)($in['max_propuestas'] ?? SV_DEFAULT_MAX_PROPUESTAS)));
        $reward = max(0.0, min(1000.0, (float)str_replace(',', '.', (string)($in['recompensa_bananos'] ?? SV_DEFAULT_RECOMPENSA))));
        $pavos = max(100, min(10000, (int)($in['max_pavos'] ?? SV_DEFAULT_MAX_PAVOS)));
        $meme = trim((string)($in['meme_url'] ?? ''));
        if ($meme !== '' && ((!str_starts_with($meme, '/') || str_starts_with($meme, '//')) && !preg_match('#^https?://#i', $meme))) {
            throw new Exception('La URL del meme debe ser una ruta relativa o una URL http(s).');
        }
        if ($meme !== '' && preg_match('#^https?://#i', $meme) && filter_var($meme, FILTER_VALIDATE_URL) === false) {
            throw new Exception('La URL del meme no es válida.');
        }

        $pairs = [
            'sv_votacion_abierta' => $open ? '1' : '0',
            'sv_max_propuestas' => (string)$max,
            'sv_recompensa_bananos' => (string)$reward,
            'sv_max_pavos' => (string)$pavos,
            'sv_meme_url' => $meme,
        ];
        $stmt = $conn->prepare('INSERT INTO site_settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        if (!$stmt) throw new Exception('No se pudo guardar la configuración.');
        foreach ($pairs as $key => $value) {
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
        }
        $stmt->close();

        $cycle = sv_open_cycle($conn, true);
        if ($open && !$cycle) {
            $stmt = $conn->prepare("INSERT INTO sorteo_votacion_ciclos (estado, opened_at, opened_by) VALUES ('abierto', UTC_TIMESTAMP(), ?)");
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $stmt->close();
        } elseif (!$open && $cycle) {
            $cycleId = (int)$cycle['id'];
            $stmt = $conn->prepare("UPDATE sorteo_votacion_ciclos SET estado = 'cerrado', closed_at = UTC_TIMESTAMP(), closed_by = ? WHERE id = ? AND estado = 'abierto'");
            $stmt->bind_param('ii', $adminId, $cycleId);
            $stmt->execute();
            $stmt->close();
        }
        return sv_settings($conn);
    }
}

if (!function_exists('sv_open_cycle')) {
    function sv_open_cycle(mysqli $conn, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id, estado, opened_at, closed_at, opened_by, closed_by FROM sorteo_votacion_ciclos WHERE estado = 'abierto' ORDER BY id DESC LIMIT 1";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $res = $conn->query($sql);
        return $res ? ($res->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('sv_last_cycle')) {
    function sv_last_cycle(mysqli $conn): ?array
    {
        $res = $conn->query('SELECT id, estado, opened_at, closed_at, opened_by, closed_by FROM sorteo_votacion_ciclos ORDER BY id DESC LIMIT 1');
        return $res ? ($res->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('sv_shop_index')) {
    function sv_shop_index(): array
    {
        static $index = null;
        if ($index !== null) return $index;
        $index = [];
        foreach (DailySpinFilters::loadShopEntries() as $entry) {
            if (!is_array($entry) || empty($entry['offerId'])) continue;
            $image = $entry['images']['icon'] ?? $entry['images']['featured'] ?? '';
            if (!$image && isset($entry['displayAssets'][0]['url'])) $image = $entry['displayAssets'][0]['url'];
            $index[(string)$entry['offerId']] = [
                'offer_id' => (string)$entry['offerId'],
                'name' => (string)($entry['displayName'] ?? 'Artículo sin nombre'),
                'type' => (string)($entry['displayType'] ?? ''),
                'rarity' => (string)($entry['rarity']['name'] ?? ''),
                'image' => (string)$image,
                'pavos' => (int)($entry['finalPrice'] ?? 0),
                'soles' => (float)($entry['precio_oferta_soles'] ?? $entry['precio_base_soles'] ?? 0),
                'giftable' => ($entry['giftable'] ?? true) !== false,
                'out_date' => (string)($entry['outDate'] ?? ''),
            ];
        }
        return $index;
    }
}

if (!function_exists('sv_validate_combo')) {
    function sv_validate_combo(array $rawIds, array $settings): array
    {
        $ids = [];
        foreach ($rawIds as $rawId) {
            if (!is_scalar($rawId)) throw new Exception('La lista de artículos no es válida.');
            $id = trim((string)$rawId);
            if ($id !== '') $ids[$id] = $id;
        }
        $ids = array_values($ids);
        if (count($ids) < 1 || count($ids) > SV_MAX_ITEMS) {
            throw new Exception('El combo debe incluir entre 1 y ' . SV_MAX_ITEMS . ' artículos.');
        }
        $shop = sv_shop_index();
        $items = [];
        $totalPavos = 0;
        $totalSoles = 0.0;
        foreach ($ids as $id) {
            if (!isset($shop[$id])) throw new Exception('Uno de los artículos ya no está en la tienda de hoy.');
            $item = $shop[$id];
            if (!$item['giftable']) throw new Exception('El artículo «' . $item['name'] . '» no se puede regalar.');
            $items[] = [
                'offer_id' => $item['offer_id'],
                'name' => $item['name'],
                'type' => $item['type'],
                'rarity' => $item['rarity'],
                'image' => $item['image'],
                'pavos' => $item['pavos'],
                'soles' => $item['soles'],
            ];
            $totalPavos += $item['pavos'];
            $totalSoles += $item['soles'];
        }
        $limit = (int)($settings['max_pavos'] ?? SV_DEFAULT_MAX_PAVOS);
        if ($totalPavos > $limit) throw new Exception("El combo suma {$totalPavos} paVos y supera el límite de {$limit} paVos.");
        $sorted = array_column($items, 'offer_id');
        sort($sorted, SORT_STRING);
        return [
            'items' => $items,
            'total_pavos' => $totalPavos,
            'total_soles' => round($totalSoles, 2),
            'combo_hash' => sha1(implode('|', $sorted)),
            'shop_date' => gmdate('Y-m-d'),
        ];
    }
}

if (!function_exists('sv_user_is_banned')) {
    function sv_user_is_banned(mysqli $conn, int $userId, string $ip): bool
    {
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND account_status = 'banned' LIMIT 1");
        if (!$stmt) throw new Exception('No se pudo verificar el estado de la cuenta.');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;
        require_once __DIR__ . '/raffle_bans.php';
        if (function_exists('rb_find_active_ban')) {
            return rb_find_active_ban($conn, $userId, $ip, '') !== null;
        }
        return false;
    }
}

if (!function_exists('sv_submit_proposal')) {
    function sv_submit_proposal(mysqli $conn, int $userId, array $rawIds, string $ip): array
    {
        require_once __DIR__ . '/raffle_bans.php';
        if (function_exists('rb_ensure_schema')) rb_ensure_schema($conn);
        $conn->begin_transaction();
        try {
            $cycle = sv_open_cycle($conn, true);
            $settings = sv_settings($conn);
            if (!$settings['votacion_abierta'] || !$cycle) throw new Exception('La votación no está abierta.');
            if (sv_user_is_banned($conn, $userId, $ip)) throw new Exception('Tu cuenta no puede participar en Sorteo Flash.');
            $cycleId = (int)$cycle['id'];
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM sorteo_votacion_propuestas WHERE ciclo_id = ? AND estado = 'activa'");
            $stmt->bind_param('i', $cycleId);
            $stmt->execute();
            $active = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            if ($active >= $settings['max_propuestas']) throw new Exception('Ya se alcanzó el máximo de propuestas para este ciclo.');
            $stmt = $conn->prepare('SELECT 1 FROM sorteo_votacion_propuestas WHERE ciclo_id = ? AND usuario_id = ? LIMIT 1');
            $stmt->bind_param('ii', $cycleId, $userId);
            $stmt->execute();
            $already = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($already) throw new Exception('Ya enviaste una propuesta en esta fase.');

            $combo = sv_validate_combo($rawIds, $settings);
            $reward = (float)$settings['recompensa_bananos'];
            $itemsJson = json_encode($combo['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $conn->prepare("INSERT INTO sorteo_votacion_propuestas (ciclo_id, usuario_id, items_json, combo_hash, items_count, total_pavos, total_soles, estado, recompensa_bananos, shop_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'activa', ?, ?, UTC_TIMESTAMP())");
            $count = count($combo['items']);
            $stmt->bind_param('iissiidds', $cycleId, $userId, $itemsJson, $combo['combo_hash'], $count, $combo['total_pavos'], $combo['total_soles'], $reward, $combo['shop_date']);
            $stmt->execute();
            $proposalId = (int)$conn->insert_id;
            $stmt->close();
            if ($reward > 0) {
                $credit = $conn->prepare('INSERT INTO coins (user_id, balance) VALUES (?, ?) ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)');
                $credit->bind_param('id', $userId, $reward);
                $credit->execute();
                $credit->close();
                $reason = SV_REASON;
                $referenceType = 'sorteo_votacion';
                $note = "sorteo_votacion: propuesta #{$proposalId} (ciclo #{$cycleId})";
                $txn = $conn->prepare('INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
                $txn->bind_param('idssis', $userId, $reward, $reason, $referenceType, $proposalId, $note);
                $txn->execute();
                $txn->close();
            }
            $conn->commit();
            return [
                'propuesta' => ['id' => $proposalId, 'ciclo_id' => $cycleId, 'items' => $combo['items'], 'total_pavos' => $combo['total_pavos'], 'total_soles' => $combo['total_soles'], 'estado' => 'activa', 'votos_count' => 0],
                'cupos_restantes' => max(0, $settings['max_propuestas'] - $active - 1),
                'recompensa_acreditada' => $reward,
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) throw new Exception('Ya existe una propuesta igual o ya enviaste una propuesta en esta fase.');
            throw $e;
        }
    }
}

if (!function_exists('sv_cast_vote')) {
    function sv_cast_vote(mysqli $conn, int $userId, int $propuestaId, string $ip): array
    {
        require_once __DIR__ . '/raffle_bans.php';
        if (function_exists('rb_ensure_schema')) rb_ensure_schema($conn);
        $conn->begin_transaction();
        try {
            $cycle = sv_open_cycle($conn, true);
            $settings = sv_settings($conn);
            if (!$settings['votacion_abierta'] || !$cycle) throw new Exception('La votación no está abierta.');
            if (sv_user_is_banned($conn, $userId, $ip)) throw new Exception('Tu cuenta no puede participar en Sorteo Flash.');
            $cycleId = (int)$cycle['id'];
            $stmt = $conn->prepare("SELECT id FROM sorteo_votacion_propuestas WHERE id = ? AND ciclo_id = ? AND estado = 'activa' LIMIT 1");
            $stmt->bind_param('ii', $propuestaId, $cycleId);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$exists) throw new Exception('La propuesta ya no está disponible para votar.');
            $stmt = $conn->prepare('SELECT 1 FROM sorteo_votacion_votos WHERE ciclo_id = ? AND usuario_id = ? LIMIT 1');
            $stmt->bind_param('ii', $cycleId, $userId);
            $stmt->execute();
            $already = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($already) throw new Exception('Ya votaste en esta fase.');
            $stmt = $conn->prepare('INSERT INTO sorteo_votacion_votos (ciclo_id, propuesta_id, usuario_id, ip_address, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
            $stmt->bind_param('iiis', $cycleId, $propuestaId, $userId, $ip);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('UPDATE sorteo_votacion_propuestas SET votos_count = votos_count + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->bind_param('i', $propuestaId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('SELECT votos_count FROM sorteo_votacion_propuestas WHERE id = ?');
            $stmt->bind_param('i', $propuestaId);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['votos_count'] ?? 0);
            $stmt->close();
            $conn->commit();
            return ['propuesta_id' => $propuestaId, 'votos_count' => $count];
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) throw new Exception('Ya votaste en esta fase.');
            throw $e;
        }
    }
}

if (!function_exists('sv_reject_proposal')) {
    function sv_reject_proposal(mysqli $conn, int $propuestaId, int $adminId): array
    {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT usuario_id, ciclo_id, recompensa_bananos, estado FROM sorteo_votacion_propuestas WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $propuestaId);
            $stmt->execute();
            $proposal = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$proposal) throw new Exception('La propuesta no existe.');
            if ($proposal['estado'] === 'rechazada') {
                $conn->commit();
                return ['id' => $propuestaId, 'estado' => 'rechazada', 'reverso' => 0.0];
            }
            $stmt = $conn->prepare("UPDATE sorteo_votacion_propuestas SET estado = 'rechazada', votos_count = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?");
            $stmt->bind_param('i', $propuestaId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM sorteo_votacion_votos WHERE propuesta_id = ?');
            $stmt->bind_param('i', $propuestaId);
            $stmt->execute();
            $stmt->close();
            $reward = (float)$proposal['recompensa_bananos'];
            $userId = (int)$proposal['usuario_id'];
            if ($reward > 0) {
                $debit = $conn->prepare('UPDATE coins SET balance = GREATEST(0, balance - ?) WHERE user_id = ?');
                $debit->bind_param('di', $reward, $userId);
                $debit->execute();
                $debit->close();
                $reason = SV_REASON;
                $referenceType = 'sorteo_votacion';
                $note = "sorteo_votacion: reverso propuesta #{$propuestaId}";
                $txn = $conn->prepare('INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
                $negative = -$reward;
                $txn->bind_param('idssis', $userId, $negative, $reason, $referenceType, $propuestaId, $note);
                $txn->execute();
                $txn->close();
            }
            $conn->commit();
            return ['id' => $propuestaId, 'estado' => 'rechazada', 'reverso' => $reward];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('sv_set_winner')) {
    function sv_set_winner(mysqli $conn, int $propuestaId): array
    {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT ciclo_id, estado FROM sorteo_votacion_propuestas WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $propuestaId);
            $stmt->execute();
            $proposal = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$proposal || $proposal['estado'] === 'rechazada') throw new Exception('No se puede marcar esta propuesta como ganadora.');
            $cycleId = (int)$proposal['ciclo_id'];
            $stmt = $conn->prepare("UPDATE sorteo_votacion_propuestas SET estado = 'activa', updated_at = UTC_TIMESTAMP() WHERE ciclo_id = ? AND estado = 'ganadora'");
            $stmt->bind_param('i', $cycleId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("UPDATE sorteo_votacion_propuestas SET estado = 'ganadora', updated_at = UTC_TIMESTAMP() WHERE id = ?");
            $stmt->bind_param('i', $propuestaId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            return ['id' => $propuestaId, 'estado' => 'ganadora'];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('sv_list_proposals')) {
    function sv_list_proposals(mysqli $conn, int $cicloId, bool $includeRejected, int $viewerId = 0): array
    {
        $condition = $includeRejected ? '' : " AND p.estado <> 'rechazada'";
        $stmt = $conn->prepare(
            "SELECT p.id, p.ciclo_id, p.usuario_id, p.items_json, p.items_count, p.total_pavos, p.total_soles, p.estado, p.votos_count, p.recompensa_bananos, p.created_at,
                    COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name, u.is_verified, a.filename AS avatar,
                    (SELECT COUNT(*) FROM sorteo_votacion_votos vv WHERE vv.ciclo_id = p.ciclo_id) AS cycle_votes
             FROM sorteo_votacion_propuestas p JOIN users u ON u.id = p.usuario_id
             LEFT JOIN avatars a ON a.id = u.avatar_id
             WHERE p.ciclo_id = ?{$condition}
             ORDER BY p.votos_count DESC, p.created_at ASC"
        );
        $stmt->bind_param('i', $cicloId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $items = json_decode((string)$row['items_json'], true);
            $votes = (int)$row['votos_count'];
            $row['id'] = (int)$row['id'];
            $row['ciclo_id'] = (int)$row['ciclo_id'];
            $row['usuario_id'] = (int)$row['usuario_id'];
            $row['items'] = is_array($items) ? $items : [];
            $row['items_count'] = (int)$row['items_count'];
            $row['total_pavos'] = (int)$row['total_pavos'];
            $row['total_soles'] = (float)$row['total_soles'];
            $row['votos_count'] = $votes;
            $row['pct'] = (int)$row['cycle_votes'] > 0 ? round(100 * $votes / (int)$row['cycle_votes'], 1) : 0.0;
            $row['is_mine'] = $viewerId > 0 && (int)$row['usuario_id'] === $viewerId;
            $row['is_verified'] = (bool)$row['is_verified'];
            $row['avatar'] = AvatarCatalog::filename($conn, $row['avatar'] ?? null);
            unset($row['items_json'], $row['cycle_votes'], $row['recompensa_bananos']);
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('sv_stats_user')) {
    function sv_stats_user(mysqli $conn, array $row): array
    {
        return [
            'user_id' => (int)$row['user_id'],
            'name' => (string)$row['name'],
            'avatar' => AvatarCatalog::filename($conn, $row['avatar'] ?? null),
            'is_verified' => (bool)$row['is_verified'],
        ];
    }
}

if (!function_exists('sv_stats')) {
    function sv_stats(mysqli $conn, bool $fresh = false): array
    {
        $cacheFile = __DIR__ . '/../cache/sorteo_votacion_stats.json';
        if (!$fresh && is_file($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['generated_at']) && (time() - (int)($cached['_cached_at'] ?? 0)) < SV_STATS_TTL) {
                unset($cached['_cached_at']);
                return $cached;
            }
        }
        $priv = rp_private_column_sql($conn, 'r');
        $privateFilter = $priv === '0 AS is_private' ? '1=1' : "{$priv} = 0";
        $winnerFilter = "w.choice IN ('original','cashback','double_won','double_lost')";
        $topWinners = [];
        $res = $conn->query("SELECT w.user_id, COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name, a.filename AS avatar, u.is_verified,
                    COUNT(DISTINCT w.raffle_id) AS wins, MAX(COALESCE(w.gamble_resolved_at, w.prize_selected_at, r.end_time)) AS last_win_at
             FROM raffle_winners w JOIN raffles r ON r.raffle_id = w.raffle_id AND r.status = 'completed'
             JOIN users u ON u.id = w.user_id LEFT JOIN avatars a ON a.id = u.avatar_id
             WHERE {$winnerFilter} GROUP BY w.user_id, name, a.filename, u.is_verified
             ORDER BY wins DESC, last_win_at DESC LIMIT 10");
        while ($row = $res->fetch_assoc()) {
            $user = sv_stats_user($conn, $row);
            $user['wins'] = (int)$row['wins'];
            $user['last_win_at'] = sv_utc_iso($row['last_win_at'] ?? null);
            $topWinners[] = $user;
        }

        $participantRows = [];
        $res = $conn->query("SELECT rp.user_id, COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name, a.filename AS avatar, u.is_verified,
                    COUNT(DISTINCT rp.raffle_id) AS participations,
                    COALESCE(MAX(user_wins.wins), 0) AS wins
             FROM raffle_participants rp JOIN raffles r ON r.raffle_id = rp.raffle_id AND r.status = 'completed'
             JOIN users u ON u.id = rp.user_id LEFT JOIN avatars a ON a.id = u.avatar_id
             LEFT JOIN (
                 SELECT w.user_id, COUNT(DISTINCT w.raffle_id) AS wins
                 FROM raffle_winners w JOIN raffles wr ON wr.raffle_id = w.raffle_id AND wr.status = 'completed'
                 WHERE {$winnerFilter}
                 GROUP BY w.user_id
             ) user_wins ON user_wins.user_id = rp.user_id
             WHERE rp.review_status <> 'expelled' GROUP BY rp.user_id, name, a.filename, u.is_verified
             ORDER BY participations DESC, wins DESC, name ASC");
        while ($row = $res->fetch_assoc()) $participantRows[] = $row;
        $topParticipants = [];
        foreach ($participantRows as $row) {
            $user = sv_stats_user($conn, $row);
            $user['participations'] = (int)$row['participations'];
            $user['wins'] = (int)$row['wins'];
            if (count($topParticipants) < 10) $topParticipants[] = $user;
        }
        $worstLuck = [];
        $res = $conn->query("SELECT rp.user_id, COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name, a.filename AS avatar, u.is_verified,
                    COUNT(DISTINCT rp.raffle_id) AS participations
             FROM raffle_participants rp JOIN raffles r ON r.raffle_id = rp.raffle_id AND r.status = 'completed'
             JOIN users u ON u.id = rp.user_id LEFT JOIN avatars a ON a.id = u.avatar_id
             WHERE rp.review_status <> 'expelled'
               AND NOT EXISTS (
                   SELECT 1 FROM raffle_winners w JOIN raffles wr ON wr.raffle_id = w.raffle_id AND wr.status = 'completed'
                   WHERE w.user_id = rp.user_id AND w.choice IN ('original','cashback','double_won','double_lost')
               )
             GROUP BY rp.user_id, name, a.filename, u.is_verified
             ORDER BY participations DESC, name ASC LIMIT 5");
        while ($row = $res->fetch_assoc()) {
            $user = sv_stats_user($conn, $row);
            $user['participations'] = (int)$row['participations'];
            $user['wins'] = 0;
            $worstLuck[] = $user;
        }

        $raffles = [];
        $res = $conn->query("SELECT r.raffle_id FROM raffles r WHERE r.status = 'completed' AND {$privateFilter} ORDER BY COALESCE(r.end_time,r.start_time) ASC, r.raffle_id ASC");
        while ($row = $res->fetch_assoc()) $raffles[] = (int)$row['raffle_id'];
        $streakMap = [];
        if ($raffles) {
            $ids = implode(',', array_map('intval', $raffles));
            $res = $conn->query("SELECT rp.raffle_id, rp.user_id, COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name, a.filename AS avatar, u.is_verified
                 FROM raffle_participants rp JOIN raffles r ON r.raffle_id = rp.raffle_id AND r.status = 'completed' AND {$privateFilter}
                 JOIN users u ON u.id = rp.user_id LEFT JOIN avatars a ON a.id = u.avatar_id
                 WHERE rp.review_status <> 'expelled' AND rp.raffle_id IN ({$ids})
                 ORDER BY rp.raffle_id ASC");
            $byRaffle = [];
            while ($row = $res->fetch_assoc()) $byRaffle[(int)$row['raffle_id']][] = $row;
            $runs = [];
            foreach ($raffles as $index => $raffleId) {
                foreach ($runs as $userId => &$run) {
                    if (!isset($run['present'][$index])) $run['current'] = 0;
                }
                unset($run);
                foreach ($byRaffle[$raffleId] ?? [] as $row) {
                    $userId = (int)$row['user_id'];
                    if (!isset($runs[$userId])) $runs[$userId] = ['best' => 0, 'current' => 0, 'user' => $row];
                    $runs[$userId]['current']++;
                    $runs[$userId]['best'] = max($runs[$userId]['best'], $runs[$userId]['current']);
                    $runs[$userId]['present'][$index] = true;
                    $runs[$userId]['user'] = $row;
                }
            }
            foreach ($runs as $run) {
                $user = sv_stats_user($conn, $run['user']);
                $user['best_streak'] = (int)$run['best'];
                $user['current_streak'] = (int)$run['current'];
                $streakMap[] = $user;
            }
        }
        usort($streakMap, static fn($a, $b) => ($b['best_streak'] <=> $a['best_streak']) ?: ($b['current_streak'] <=> $a['current_streak']));
        $topStreaks = array_slice($streakMap, 0, 10);

        $choices = ['original' => 0, 'cashback' => 0, 'doble' => 0, 'doble_ganados' => 0, 'doble_perdidos' => 0];
        $res = $conn->query("SELECT SUM(w.choice = 'original') AS original_count, SUM(w.choice = 'cashback') AS cashback_count,
                    SUM(w.choice IN ('double_won','double_lost')) AS double_count,
                    SUM(w.choice = 'double_won') AS double_wins, SUM(w.choice = 'double_lost') AS double_losses
             FROM raffle_winners w JOIN raffles r ON r.raffle_id = w.raffle_id AND r.status = 'completed'
             WHERE {$winnerFilter}");
        $row = $res ? $res->fetch_assoc() : [];
        $choices['original'] = (int)($row['original_count'] ?? 0);
        $choices['cashback'] = (int)($row['cashback_count'] ?? 0);
        $choices['doble'] = (int)($row['double_count'] ?? 0);
        $choices['doble_ganados'] = (int)($row['double_wins'] ?? 0);
        $choices['doble_perdidos'] = (int)($row['double_losses'] ?? 0);
        $totalChoices = $choices['original'] + $choices['cashback'] + $choices['doble'];
        $choices['total'] = $totalChoices;
        $choices['pct'] = [
            'original' => $totalChoices ? round(100 * $choices['original'] / $totalChoices, 1) : 0,
            'cashback' => $totalChoices ? round(100 * $choices['cashback'] / $totalChoices, 1) : 0,
            'doble' => $totalChoices ? round(100 * $choices['doble'] / $totalChoices, 1) : 0,
        ];
        $choices['doble_win_rate'] = ($choices['doble'] > 0) ? round(100 * $choices['doble_ganados'] / $choices['doble'], 1) : 0.0;

        $latestChoices = [];
        foreach (['original', 'cashback', 'doble'] as $choice) {
            $choiceSql = $choice === 'doble' ? "w.choice IN ('double_won','double_lost')" : 'w.choice = ?';
            $stmt = $conn->prepare("SELECT w.id, w.choice, w.user_id, COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name,
                        a.filename AS avatar, u.is_verified, r.raffle_id, r.title,
                        COALESCE(w.gamble_resolved_at, w.prize_selected_at, r.end_time) AS fecha
                 FROM raffle_winners w JOIN raffles r ON r.raffle_id = w.raffle_id AND r.status = 'completed'
                 JOIN users u ON u.id = w.user_id LEFT JOIN avatars a ON a.id = u.avatar_id
                 WHERE {$choiceSql} ORDER BY fecha DESC, w.id DESC LIMIT 1");
            if ($choice !== 'doble') $stmt->bind_param('s', $choice);
            $stmt->execute();
            $latest = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($latest) {
                $user = sv_stats_user($conn, $latest);
                $latestChoices[$choice] = $user + [
                    'raffle_id' => (int)$latest['raffle_id'], 'title' => (string)$latest['title'],
                    'fecha' => sv_utc_iso($latest['fecha'] ?? null),
                    'resultado' => $choice === 'doble' ? ($latest['choice'] === 'double_won' ? 'gano' : 'perdio') : null,
                ];
            } else $latestChoices[$choice] = null;
        }
        $payload = [
            'top_ganadores' => $topWinners,
            'top_participantes' => $topParticipants,
            'peor_suerte' => $worstLuck,
            'top_rachas' => $topStreaks,
            'total_sorteos' => count($raffles),
            'elecciones' => $choices,
            'ultimas_elecciones' => $latestChoices,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            '_cached_at' => time(),
        ];
        try {
            if (is_dir(dirname($cacheFile)) && is_writable(dirname($cacheFile))) {
                $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) @rename($tmp, $cacheFile);
            }
        } catch (Throwable $e) {
        }
        unset($payload['_cached_at']);
        return $payload;
    }
}

if (!function_exists('sv_utc_iso')) {
    function sv_utc_iso(?string $value): ?string
    {
        if (!$value || $value === '0000-00-00 00:00:00') return null;
        try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'); }
        catch (Throwable $e) { return null; }
    }
}

if (!function_exists('sv_public_state')) {
    function sv_public_state(mysqli $conn, int $viewerId): array
    {
        $settings = sv_settings($conn);
        $cycle = sv_open_cycle($conn) ?: sv_last_cycle($conn);
        $cycleId = $cycle ? (int)$cycle['id'] : 0;
        $proposals = $cycleId > 0 ? sv_list_proposals($conn, $cycleId, false, $viewerId) : [];
        $totalVotes = 0;
        $hasVoted = false;
        $votedProposalId = null;
        $hasProposed = false;
        if ($cycleId > 0) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM sorteo_votacion_votos WHERE ciclo_id = ?');
            $stmt->bind_param('i', $cycleId);
            $stmt->execute();
            $totalVotes = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            if ($viewerId > 0) {
                $stmt = $conn->prepare('SELECT propuesta_id FROM sorteo_votacion_votos WHERE ciclo_id = ? AND usuario_id = ? LIMIT 1');
                $stmt->bind_param('ii', $cycleId, $viewerId);
                $stmt->execute();
                $vote = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $hasVoted = (bool)$vote;
                $votedProposalId = $vote ? (int)$vote['propuesta_id'] : null;
                $stmt = $conn->prepare('SELECT 1 FROM sorteo_votacion_propuestas WHERE ciclo_id = ? AND usuario_id = ? LIMIT 1');
                $stmt->bind_param('ii', $cycleId, $viewerId);
                $stmt->execute();
                $hasProposed = $stmt->get_result()->num_rows > 0;
                $stmt->close();
            }
        }
        $openCycle = sv_open_cycle($conn);
        $activeCount = 0;
        if ($openCycle) {
            $openId = (int)$openCycle['id'];
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM sorteo_votacion_propuestas WHERE ciclo_id = ? AND estado = 'activa'");
            $stmt->bind_param('i', $openId);
            $stmt->execute();
            $activeCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }
        return [
            'votacion_abierta' => $settings['votacion_abierta'] && $openCycle !== null,
            'max_propuestas' => $settings['max_propuestas'],
            'cupos_restantes' => max(0, $settings['max_propuestas'] - $activeCount),
            'max_pavos' => $settings['max_pavos'],
            'recompensa_bananos' => $settings['recompensa_bananos'],
            'meme_url' => $settings['meme_url'],
            'ciclo' => $cycle ? ['id' => (int)$cycle['id'], 'estado' => (string)$cycle['estado'], 'opened_at' => sv_utc_iso($cycle['opened_at'] ?? null), 'closed_at' => sv_utc_iso($cycle['closed_at'] ?? null)] : null,
            'propuestas' => $proposals,
            'total_votos' => $totalVotes,
            'my' => ['logged_in' => $viewerId > 0, 'has_proposed' => $hasProposed, 'has_voted' => $hasVoted, 'voted_propuesta_id' => $votedProposalId],
            'stats' => sv_stats($conn),
        ];
    }
}
