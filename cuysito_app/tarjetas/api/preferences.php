<?php

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    tarjetas_error('Método no permitido.', 405);
}

$body = tarjetas_body();
$selected = tarjetas_clean($body['selected_preset'] ?? 'split', 80);
$theme = tarjetas_clean($body['theme'] ?? 'system', 10);
$theme = in_array($theme, ['system', 'dark', 'light'], true) ? $theme : 'system';
$presets = is_array($body['custom_presets'] ?? null) ? $body['custom_presets'] : [];
$cleanPresets = [];

foreach (array_slice($presets, 0, 20) as $preset) {
    if (!is_array($preset)) {
        continue;
    }
    $id = preg_replace('/[^a-z0-9_-]/', '', strtolower(tarjetas_clean($preset['id'] ?? '', 50))) ?? '';
    $name = tarjetas_clean($preset['name'] ?? '', 50);
    if ($id === '' || $name === '') {
        continue;
    }
    $cleanPresets[] = [
        'id' => $id,
        'name' => $name,
        'columns' => max(1, min(6, (int) ($preset['columns'] ?? 3))),
        'ratio' => in_array($preset['ratio'] ?? '', ['portrait', 'classic', 'square', 'auto'], true)
            ? $preset['ratio']
            : 'portrait',
        'sidebar' => ($preset['sidebar'] ?? 'right') === 'bottom' ? 'bottom' : 'right',
        'sort' => in_array($preset['sort'] ?? '', ['manual', 'profile', 'uses', 'cooldown', 'recent'], true)
            ? $preset['sort']
            : 'manual',
        'density' => ($preset['density'] ?? 'comfortable') === 'compact' ? 'compact' : 'comfortable',
    ];
}
$allowedSelected = array_merge(['split', 'compact', 'focus', 'ledger'], array_column($cleanPresets, 'id'));
if (!in_array($selected, $allowedSelected, true)) {
    $selected = 'split';
}

$p = tarjetas_prefix();
$stmt = $pdo->prepare("
    UPDATE `{$p}preferences`
    SET `selected_preset` = ?, `custom_presets` = ?, `theme` = ?
    WHERE `id` = 1
");
$stmt->execute([
    $selected,
    json_encode($cleanPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    $theme,
]);

tarjetas_ok([
    'preferences' => [
        'selected_preset' => $selected,
        'custom_presets' => $cleanPresets,
        'theme' => $theme,
    ],
]);
