<?php

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tarjetas_error('Método no permitido.', 405);
}

$body = tarjetas_body();
$action = tarjetas_clean($_GET['action'] ?? '', 20);
$p = tarjetas_prefix();

switch ($action) {
    case 'create':
        $name = tarjetas_clean($body['name'] ?? '', 80);
        if ($name === '') {
            tarjetas_error('Escribe el nombre del emisor.');
        }
        $order = (int) $pdo->query("SELECT COALESCE(MAX(`sort_order`), -1) + 1 FROM `{$p}emitters`")->fetchColumn();
        try {
            $stmt = $pdo->prepare("INSERT INTO `{$p}emitters` (`name`, `color`, `sort_order`) VALUES (?, ?, ?)");
            $stmt->execute([$name, tarjetas_color($body['color'] ?? null), $order]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                tarjetas_error('Ya existe un emisor con ese nombre.', 409);
            }
            throw $error;
        }
        tarjetas_ok(['id' => (int) $pdo->lastInsertId()], 201);

    case 'update':
        $id = tarjetas_id($body['id'] ?? 0);
        $name = tarjetas_clean($body['name'] ?? '', 80);
        if ($id === 0 || $name === '') {
            tarjetas_error('Emisor inválido.');
        }
        try {
            $stmt = $pdo->prepare("UPDATE `{$p}emitters` SET `name` = ?, `color` = ? WHERE `id` = ?");
            $stmt->execute([$name, tarjetas_color($body['color'] ?? null), $id]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                tarjetas_error('Ya existe un emisor con ese nombre.', 409);
            }
            throw $error;
        }
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare("SELECT `id` FROM `{$p}emitters` WHERE `id` = ?");
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                tarjetas_error('Emisor no encontrado.', 404);
            }
        }
        tarjetas_ok();

    case 'delete':
        $id = tarjetas_id($body['id'] ?? 0);
        $count = $pdo->prepare("SELECT COUNT(*) FROM `{$p}profiles` WHERE `emitter_id` = ?");
        $count->execute([$id]);
        if ((int) $count->fetchColumn() > 0) {
            tarjetas_error('No puedes eliminar un emisor que todavía tiene perfiles.', 409);
        }
        $stmt = $pdo->prepare("DELETE FROM `{$p}emitters` WHERE `id` = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            tarjetas_error('Emisor no encontrado.', 404);
        }
        tarjetas_ok();

    default:
        tarjetas_error('Acción no encontrada.', 404);
}
