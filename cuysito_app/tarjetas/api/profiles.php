<?php

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tarjetas_error('Método no permitido.', 405);
}

$body = tarjetas_body();
$action = tarjetas_clean($_GET['action'] ?? '', 24);
$p = tarjetas_prefix();

function tarjetas_profile_payload(array $body): array
{
    $payload = [
        'emitter_id' => tarjetas_id($body['emitter_id'] ?? 0),
        'bank' => tarjetas_clean($body['bank'] ?? '', 100),
        'device' => tarjetas_clean($body['device'] ?? '', 100),
        'holder' => tarjetas_clean($body['holder'] ?? '', 120),
        'cvu' => preg_replace('/\s+/', '', tarjetas_clean($body['cvu'] ?? '', 34)) ?? '',
        'email' => tarjetas_clean($body['email'] ?? '', 190),
        'account_password' => tarjetas_clean($body['account_password'] ?? '', 255),
        'location' => tarjetas_clean($body['location'] ?? '', 190),
        'account_number' => tarjetas_clean($body['account_number'] ?? '', 100),
        'phone' => tarjetas_clean($body['phone'] ?? '', 60),
        'color' => tarjetas_color($body['color'] ?? null),
    ];
    foreach (['emitter_id', 'bank', 'device', 'holder', 'cvu'] as $required) {
        if ($payload[$required] === '' || $payload[$required] === 0) {
            tarjetas_error('Completa todos los campos obligatorios del perfil.');
        }
    }
    return $payload;
}

switch ($action) {
    case 'create':
        $data = tarjetas_profile_payload($body);
        $exists = $pdo->prepare("SELECT `id` FROM `{$p}emitters` WHERE `id` = ?");
        $exists->execute([$data['emitter_id']]);
        if (!$exists->fetchColumn()) {
            tarjetas_error('El emisor seleccionado ya no existe.', 409);
        }
        $order = (int) $pdo->query("SELECT COALESCE(MAX(`sort_order`), -1) + 1 FROM `{$p}profiles`")->fetchColumn();
        $stmt = $pdo->prepare("
            INSERT INTO `{$p}profiles`
                (`emitter_id`, `bank`, `device`, `holder`, `cvu`, `email`,
                 `account_password`, `location`, `account_number`, `phone`, `color`, `sort_order`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['emitter_id'], $data['bank'], $data['device'], $data['holder'], $data['cvu'],
            $data['email'] ?: null, tarjetas_encrypt($data['account_password']), $data['location'] ?: null,
            $data['account_number'] ?: null, $data['phone'] ?: null, $data['color'], $order,
        ]);
        tarjetas_ok(['id' => (int) $pdo->lastInsertId()], 201);

    case 'update':
        $id = tarjetas_id($body['id'] ?? 0);
        $data = tarjetas_profile_payload($body);
        if ($id === 0) {
            tarjetas_error('Perfil inválido.');
        }
        $exists = $pdo->prepare("SELECT `id` FROM `{$p}emitters` WHERE `id` = ?");
        $exists->execute([$data['emitter_id']]);
        if (!$exists->fetchColumn()) {
            tarjetas_error('El emisor seleccionado ya no existe.', 409);
        }
        $stmt = $pdo->prepare("
            UPDATE `{$p}profiles`
            SET `emitter_id` = ?, `bank` = ?, `device` = ?, `holder` = ?, `cvu` = ?,
                `email` = ?, `account_password` = ?, `location` = ?,
                `account_number` = ?, `phone` = ?, `color` = ?
            WHERE `id` = ?
        ");
        $stmt->execute([
            $data['emitter_id'], $data['bank'], $data['device'], $data['holder'], $data['cvu'],
            $data['email'] ?: null, tarjetas_encrypt($data['account_password']), $data['location'] ?: null,
            $data['account_number'] ?: null, $data['phone'] ?: null, $data['color'], $id,
        ]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare("SELECT `id` FROM `{$p}profiles` WHERE `id` = ?");
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                tarjetas_error('Perfil no encontrado.', 404);
            }
        }
        tarjetas_ok();

    case 'delete':
        $id = tarjetas_id($body['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM `{$p}profiles` WHERE `id` = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            tarjetas_error('Perfil no encontrado.', 404);
        }
        tarjetas_ok();

    case 'reorder':
        $ids = array_values(array_filter(array_map('tarjetas_id', is_array($body['ids'] ?? null) ? $body['ids'] : [])));
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE `{$p}profiles` SET `sort_order` = ? WHERE `id` = ?");
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
