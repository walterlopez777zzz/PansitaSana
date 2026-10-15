<?php

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tarjetas_error('Método no permitido.', 405);
}

$body = tarjetas_body();
$action = tarjetas_clean($_GET['action'] ?? '', 24);
$p = tarjetas_prefix();

function tarjetas_card_fields(array $body): array
{
    $number = preg_replace('/\D/', '', (string) ($body['number'] ?? '')) ?? '';
    $expiry = tarjetas_clean($body['expiry'] ?? '', 7);
    $cvv = preg_replace('/\D/', '', (string) ($body['cvv'] ?? '')) ?? '';
    if (strlen($number) < 12 || strlen($number) > 19) {
        tarjetas_error('El número de tarjeta debe tener entre 12 y 19 dígitos.');
    }
    if (!preg_match('/^(0[1-9]|1[0-2])\/\d{2,4}$/', $expiry)) {
        tarjetas_error('Usa una fecha de vencimiento con formato MM/AA.');
    }
    if (strlen($cvv) < 3 || strlen($cvv) > 4) {
        tarjetas_error('El CVV debe tener 3 o 4 dígitos.');
    }
    return ['number' => $number, 'expiry' => $expiry, 'cvv' => $cvv];
}

function tarjetas_cooldown_from_hours(mixed $raw): ?string
{
    if ($raw === '' || $raw === null) {
        return null;
    }
    if (!is_numeric($raw)) {
        tarjetas_error('El cooldown debe expresarse en horas.');
    }
    $hours = max(0, min(720, (float) $raw));
    return $hours > 0 ? date('Y-m-d H:i:s', time() + (int) round($hours * 3600)) : null;
}

switch ($action) {
    case 'create':
        $profileId = tarjetas_id($body['profile_id'] ?? 0);
        $data = tarjetas_card_fields($body);
        $uses = is_numeric($body['uses_remaining'] ?? null) ? (int) $body['uses_remaining'] : 5;
        $uses = max(-999, min(999, $uses));
        $cooldown = tarjetas_cooldown_from_hours($body['cooldown_hours'] ?? null);
        if (!empty($body['use_now'])) {
            $uses--;
            $cooldown = date('Y-m-d H:i:s', time() + 86400);
        }
        $profile = $pdo->prepare("SELECT `id` FROM `{$p}profiles` WHERE `id` = ?");
        $profile->execute([$profileId]);
        if (!$profile->fetchColumn()) {
            tarjetas_error('Selecciona un perfil válido.');
        }
        $order = (int) $pdo->query("SELECT COALESCE(MAX(`sort_order`), -1) + 1 FROM `{$p}cards`")->fetchColumn();
        $stmt = $pdo->prepare("
            INSERT INTO `{$p}cards`
                (`profile_id`, `number_encrypted`, `expiry`, `cvv_encrypted`,
                 `uses_remaining`, `cooldown_until`, `sort_order`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $profileId, tarjetas_encrypt($data['number']), $data['expiry'],
            tarjetas_encrypt($data['cvv']), $uses, $cooldown, $order,
        ]);
        tarjetas_ok(['id' => (int) $pdo->lastInsertId()], 201);

    case 'update':
        $id = tarjetas_id($body['id'] ?? 0);
        $profileId = tarjetas_id($body['profile_id'] ?? 0);
        $data = tarjetas_card_fields($body);
        if ($id === 0 || $profileId === 0) {
            tarjetas_error('Tarjeta o perfil inválido.');
        }
        $profile = $pdo->prepare("SELECT `id` FROM `{$p}profiles` WHERE `id` = ?");
        $profile->execute([$profileId]);
        if (!$profile->fetchColumn()) {
            tarjetas_error('El perfil seleccionado ya no existe.', 409);
        }
        $uses = is_numeric($body['uses_remaining'] ?? null) ? (int) $body['uses_remaining'] : 5;
        $cooldown = tarjetas_datetime_or_null($body['cooldown_until'] ?? null);
        $stmt = $pdo->prepare("
            UPDATE `{$p}cards`
            SET `profile_id` = ?, `number_encrypted` = ?, `expiry` = ?,
                `cvv_encrypted` = ?, `uses_remaining` = ?, `cooldown_until` = ?
            WHERE `id` = ?
        ");
        $stmt->execute([
            $profileId, tarjetas_encrypt($data['number']), $data['expiry'],
            tarjetas_encrypt($data['cvv']), max(-999, min(999, $uses)), $cooldown, $id,
        ]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare("SELECT `id` FROM `{$p}cards` WHERE `id` = ?");
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                tarjetas_error('Tarjeta no encontrada.', 404);
            }
        }
        tarjetas_ok();

    case 'use':
        $id = tarjetas_id($body['id'] ?? 0);
        $hours = is_numeric($body['hours'] ?? null) ? max(0, min(720, (float) $body['hours'])) : 24;
        $cooldown = $hours > 0 ? date('Y-m-d H:i:s', time() + (int) round($hours * 3600)) : null;
        $stmt = $pdo->prepare("
            UPDATE `{$p}cards`
            SET `uses_remaining` = `uses_remaining` - 1, `cooldown_until` = ?
            WHERE `id` = ?
        ");
        $stmt->execute([$cooldown, $id]);
        if ($stmt->rowCount() === 0) {
            tarjetas_error('Tarjeta no encontrada.', 404);
        }
        tarjetas_ok();

    case 'set_uses':
        $id = tarjetas_id($body['id'] ?? 0);
        $uses = is_numeric($body['uses_remaining'] ?? null) ? (int) $body['uses_remaining'] : 0;
        $stmt = $pdo->prepare("UPDATE `{$p}cards` SET `uses_remaining` = ? WHERE `id` = ?");
        $stmt->execute([max(-999, min(999, $uses)), $id]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare("SELECT `id` FROM `{$p}cards` WHERE `id` = ?");
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                tarjetas_error('Tarjeta no encontrada.', 404);
            }
        }
        tarjetas_ok();

    case 'set_cooldown':
        $id = tarjetas_id($body['id'] ?? 0);
        $cooldown = tarjetas_cooldown_from_hours($body['hours'] ?? null);
        $stmt = $pdo->prepare("UPDATE `{$p}cards` SET `cooldown_until` = ? WHERE `id` = ?");
        $stmt->execute([$cooldown, $id]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare("SELECT `id` FROM `{$p}cards` WHERE `id` = ?");
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                tarjetas_error('Tarjeta no encontrada.', 404);
            }
        }
        tarjetas_ok();

    case 'delete':
        $id = tarjetas_id($body['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM `{$p}cards` WHERE `id` = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            tarjetas_error('Tarjeta no encontrada.', 404);
        }
        tarjetas_ok();

    case 'reorder':
        $ids = array_values(array_filter(array_map('tarjetas_id', is_array($body['ids'] ?? null) ? $body['ids'] : [])));
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE `{$p}cards` SET `sort_order` = ? WHERE `id` = ?");
            foreach ($ids as $order => $id) {
                $stmt->execute([$order, $id]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        tarjetas_ok();

    default:
        tarjetas_error('Acción no encontrada.', 404);
}
