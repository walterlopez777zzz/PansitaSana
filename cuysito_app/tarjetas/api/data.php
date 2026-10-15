<?php

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    tarjetas_error('Método no permitido.', 405);
}

$p = tarjetas_prefix();

$emitters = $pdo->query("
    SELECT `id`, `name`, `color`, `sort_order`
    FROM `{$p}emitters`
    ORDER BY `sort_order`, `name`
")->fetchAll();

$profiles = $pdo->query("
    SELECT p.`id`, p.`emitter_id`, p.`bank`, p.`device`, p.`holder`, p.`cvu`,
           p.`email`, p.`account_password`, p.`location`, p.`account_number`,
           p.`phone`, p.`color`, p.`sort_order`, p.`created_at`, p.`updated_at`,
           e.`name` AS `emitter_name`,
           (SELECT COUNT(*) FROM `{$p}cards` c WHERE c.`profile_id` = p.`id`) AS `card_count`
    FROM `{$p}profiles` p
    INNER JOIN `{$p}emitters` e ON e.`id` = p.`emitter_id`
    ORDER BY p.`sort_order`, p.`holder`
")->fetchAll();

foreach ($profiles as &$profile) {
    $profile['id'] = (int) $profile['id'];
    $profile['emitter_id'] = (int) $profile['emitter_id'];
    $profile['sort_order'] = (int) $profile['sort_order'];
    $profile['card_count'] = (int) $profile['card_count'];
    $profile['account_password'] = tarjetas_decrypt($profile['account_password']);
}
unset($profile);

$cards = $pdo->query("
    SELECT c.`id`, c.`profile_id`, c.`number_encrypted`, c.`expiry`,
           c.`cvv_encrypted`, c.`uses_remaining`, c.`cooldown_until`,
           c.`sort_order`, c.`created_at`, c.`updated_at`,
           p.`holder`, p.`cvu`, p.`color`, p.`bank`, p.`device`,
           e.`name` AS `emitter_name`
    FROM `{$p}cards` c
    INNER JOIN `{$p}profiles` p ON p.`id` = c.`profile_id`
    INNER JOIN `{$p}emitters` e ON e.`id` = p.`emitter_id`
    ORDER BY c.`sort_order`, c.`created_at` DESC
")->fetchAll();

foreach ($cards as &$card) {
    $card['id'] = (int) $card['id'];
    $card['profile_id'] = (int) $card['profile_id'];
    $card['uses_remaining'] = (int) $card['uses_remaining'];
    $card['sort_order'] = (int) $card['sort_order'];
    $card['number'] = tarjetas_decrypt($card['number_encrypted']);
    $card['cvv'] = tarjetas_decrypt($card['cvv_encrypted']);
    unset($card['number_encrypted'], $card['cvv_encrypted']);
}
unset($card);

$preferences = $pdo->query("
    SELECT `selected_preset`, `custom_presets`, `theme`
    FROM `{$p}preferences`
    WHERE `id` = 1
")->fetch();

$customPresets = json_decode((string) ($preferences['custom_presets'] ?? '[]'), true);
if (!is_array($customPresets)) {
    $customPresets = [];
}

tarjetas_ok([
    'emitters' => $emitters,
    'profiles' => $profiles,
    'cards' => $cards,
    'preferences' => [
        'selected_preset' => (string) ($preferences['selected_preset'] ?? 'split'),
        'custom_presets' => $customPresets,
        'theme' => (string) ($preferences['theme'] ?? 'system'),
    ],
    'server_time' => gmdate('c'),
]);
