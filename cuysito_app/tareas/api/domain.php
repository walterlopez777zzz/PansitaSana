<?php
/**
 * ============================================================
 * Tareas — Dominio compartido
 * ============================================================
 * Constantes y helpers de dominio reutilizados por varios
 * endpoints (acentos, estados, prioridades, recurrencias) y la
 * lógica transversal de etiquetas y fechas. Se incluye después
 * del guard en cada endpoint que lo necesite.
 * ============================================================
 */

declare(strict_types=1);

/** Paleta de acentos válida (compartida por ideas, misiones, tags, plantillas). */
const TAREAS_ACCENTS = ['violet', 'blue', 'green', 'amber', 'rose', 'cyan'];

/** Estados de misión (incluye el soft-delete 'archivada'). */
const TAREAS_MIS_STATUSES = ['pendiente', 'en_progreso', 'completada', 'archivada'];

/** Estados a los que puede volver una misión restaurada (nunca 'archivada'). */
const TAREAS_MIS_RESTORE_STATUSES = ['pendiente', 'en_progreso', 'completada'];

/** Prioridades de misión. */
const TAREAS_MIS_PRIORITIES = ['baja', 'media', 'alta'];

/** Recurrencias soportadas al completar una misión. */
const TAREAS_RECURRENCES = ['none', 'daily', 'weekly', 'monthly'];

/** Longitud máxima del texto de una idea. */
const TAREAS_IDEA_MAXLEN = 1000;

/**
 * Normaliza una fecha de vencimiento de entrada a 'YYYY-MM-DD' o null.
 * Acepta cadenas vacías/null (→ sin vencimiento) y rechaza formatos
 * inválidos devolviendo null para no introducir basura en la columna DATE.
 */
function tareas_due_date(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $value = trim($raw);
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $date->format('Y-m-d');
}

/**
 * Avanza una fecha 'YYYY-MM-DD' según la recurrencia. Devuelve la nueva
 * fecha o null si la recurrencia es 'none' o la fecha base es inválida.
 */
function tareas_advance_due_date(?string $date, string $recurrence): ?string
{
    if ($date === null || $recurrence === 'none') {
        return null;
    }
    $base = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($base === false) {
        return null;
    }
    $interval = match ($recurrence) {
        'daily'   => 'P1D',
        'weekly'  => 'P1W',
        'monthly' => 'P1M',
        default   => null,
    };
    if ($interval === null) {
        return null;
    }
    return $base->add(new DateInterval($interval))->format('Y-m-d');
}

/** Nombre de la tabla pivote y su columna dueña según el tipo de entidad. */
function tareas_tag_pivot(string $prefix, string $type): array
{
    return $type === 'idea'
        ? ["{$prefix}idea_tags", 'idea_id']
        : ["{$prefix}mision_tags", 'mision_id'];
}

/**
 * Etiquetas de un conjunto de entidades ('idea' | 'mision'), agrupadas por
 * id de la entidad dueña. Usa placeholders parametrizados (anti inyección).
 */
function tareas_tags_for(PDO $pdo, string $prefix, string $type, array $ownerIds): array
{
    $ownerIds = array_values(array_filter(array_map('intval', $ownerIds)));
    if (!$ownerIds) {
        return [];
    }
    [$pivot, $col] = tareas_tag_pivot($prefix, $type);
    $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT p.`{$col}` AS owner_id, t.id, t.name, t.color
           FROM `{$pivot}` p
           JOIN `{$prefix}tags` t ON t.id = p.tag_id
          WHERE p.`{$col}` IN ({$placeholders})
          ORDER BY t.name ASC"
    );
    $stmt->execute($ownerIds);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['owner_id']][] = [
            'id'    => (int) $row['id'],
            'name'  => $row['name'],
            'color' => $row['color'],
        ];
    }
    return $grouped;
}

/**
 * Reemplaza el conjunto de etiquetas de una entidad por `$tagIds` (sólo
 * los que existan realmente). Idempotente y atómico desde el llamador.
 */
function tareas_set_tags(PDO $pdo, string $prefix, string $type, int $ownerId, array $tagIds): void
{
    [$pivot, $col] = tareas_tag_pivot($prefix, $type);

    $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));

    $pdo->prepare("DELETE FROM `{$pivot}` WHERE `{$col}` = ?")->execute([$ownerId]);

    if (!$tagIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
    $valid = $pdo->prepare("SELECT id FROM `{$prefix}tags` WHERE id IN ({$placeholders})");
    $valid->execute($tagIds);
    $validIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));

    if (!$validIds) {
        return;
    }

    $insert = $pdo->prepare("INSERT INTO `{$pivot}` (`{$col}`, tag_id) VALUES (?, ?)");
    foreach ($validIds as $tagId) {
        $insert->execute([$ownerId, $tagId]);
    }
}

/**
 * Aplica un nuevo orden a un conjunto de filas (drag & drop). `$ids` es la
 * secuencia ordenada de identificadores; cada uno recibe su índice como
 * `sort_order`. Se ejecuta dentro de una transacción para no dejar órdenes
 * a medias. Sólo afecta filas existentes en la tabla indicada.
 */
function tareas_apply_order(PDO $pdo, string $table, array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return 0;
    }
    $update = $pdo->prepare("UPDATE `{$table}` SET sort_order = ? WHERE id = ?");
    $count = 0;
    foreach ($ids as $position => $id) {
        $update->execute([$position, $id]);
        $count += $update->rowCount();
    }
    return $count;
}

/**
 * Plegado de mayúsculas/minúsculas seguro para comparar nombres sin depender
 * de la extensión mbstring (que puede no estar habilitada en el hosting).
 */
function tareas_fold(string $text): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($text, 'UTF-8')
        : strtolower($text);
}
