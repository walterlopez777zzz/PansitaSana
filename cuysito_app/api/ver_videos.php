<?php
// 1) Conexión a la base de datos (compartida: expone $conn)
require_once __DIR__ . '/db_connect.php';

// 2) Leer todos los videos de la tabla y agrupar por ítem
$sql = "SELECT * FROM videos_tienda ORDER BY nombre, tipo_principal, tipo_video";
$res = $conn->query($sql);
if ($res === false) {
    die("Error de consulta: " . $conn->error);
}

$items = [];
$previewCount = 0;
$epicCount    = 0;

while ($row = $res->fetch_assoc()) {
    $id   = $row['id'];
    if (!isset($items[$id])) {
        $items[$id] = [
            'nombre'         => $row['nombre'],
            'tipo_principal' => $row['tipo_principal'],
            'preview'        => [],
            'epic'           => [],
        ];
    }

if ($row['tipo_video'] === 'preview') {
    $styles = json_decode($row['styles'], true);
    $items[$id]['preview'][] = [
        'url'    => $row['video_url'],
        'styles' => is_array($styles) ? $styles : []
    ];
    $previewCount++;
} elseif ($row['tipo_video'] === 'epic') {
    $products = json_decode($row['products'], true);
    $items[$id]['epic'][] = [
        'url'      => $row['video_url'],
        'products' => is_array($products) ? $products : []
    ];
    $epicCount++;
}
}

// 3) Mostrar en texto plano
foreach ($items as $item) {
    echo "Item: {$item['nombre']} (Tipo: {$item['tipo_principal']})\n";

    // Preview Videos
    echo "  Preview Videos:\n";
    if (empty($item['preview'])) {
        echo "    (ninguno)\n";
    } else {
        foreach ($item['preview'] as $v) {
            echo "    • URL: {$v['url']}\n";
            if (!empty($v['styles'])) {
                echo "      Styles:\n";
                foreach ($v['styles'] as $style) {
                    $channel = $style['channel'] ?? 'Canal desconocido';
                    $tag     = $style['tag'] ?? 'Tag desconocido';
                    echo "        - {$channel}: {$tag}\n";
                }
            }
        }
    }

    // Epic Videos
    echo "  Epic Videos:\n";
    if (empty($item['epic'])) {
        echo "    (ninguno)\n";
    } else {
        foreach ($item['epic'] as $v) {
            echo "    • URL: {$v['url']}\n";
            if (!empty($v['products'])) {
                echo "      Products: " . implode(', ', $v['products']) . "\n";
            }
        }
    }

    echo str_repeat('-', 40) . "\n";
}

// 4) Mostrar resumen final
echo "Resumen total:\n";
echo "  Preview Videos: $previewCount\n";
echo "  Epic Videos: $epicCount\n";

$res->free();
$conn->close();
?>