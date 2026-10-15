<?php
/**
 * ============================================================================
 * BANANO STORE — ITEM RENDER IMAGES REPOSITORY (caché permanente)
 * ============================================================================
 * La API de cosméticos ya solo sirve icon/smallIcon para muchos ítems nuevos;
 * el ÚNICO lugar donde existe el arte grande real (renderimage_N.png de
 * newdisplayassets) es el catálogo de tienda, y solo mientras el ítem está a
 * la venta. Este repositorio acumula ese arte en un caché JSON permanente
 * (cosmeticId → mejor renderImage vista), alimentado con cada snapshot de
 * tienda, para que las páginas de objetos y el carousel puedan usar la imagen
 * grande incluso cuando el ítem ya no está en la tienda.
 *
 * Calidad/estabilidad:
 *   - Solo renderImages no-LEGO (productTag !== 'Product.Juno').
 *   - Si el newDisplayAsset declara su dueño (cosmeticId), el arte se guarda
 *     SOLO para ese cosmético: el render de una oferta skin+mochila suele
 *     retratar únicamente a la skin, y asignarlo a la mochila mostraría el
 *     objeto equivocado. Sin cosmeticId, si la oferta incluye una skin o un
 *     instrumento el render retrata a ese protagonista (dueño implícito);
 *     solo en ofertas sin uno (pico+mochila, etc.) se asigna a todos.
 *   - Se prefiere la oferta donde el ítem es protagonista y con menos ítems
 *     (su arte lo retrata mejor); un arte mejor reemplaza al peor guardado.
 *   - Los lotes reales (muchos ítems o sin protagonista) se ignoran: su arte
 *     mezcla demasiados ítems. Pero la API marca como "Bundle" también los
 *     dúos/tríos skin+accesorios (la venta normal de la skin), cuyo render
 *     retrata solo a la skin: esos sí se aceptan, asignados al protagonista.
 *   - Se ignoran los iconos (esos ya los sirve la API de cosméticos, más
 *     fiable para miniaturas).
 *   - Las pistas de improvisación (tracks) usan albumArt, que ya viaja en el
 *     propio ítem, así que no necesitan entrada en este caché.
 *   - Escritura atómica (tmp + rename) y tolerante a fallos: cualquier error
 *     degrada a "sin caché", nunca rompe la página.
 * ============================================================================
 */

if (!class_exists('ItemRenderImagesRepository')) {

final class ItemRenderImagesRepository
{
    /** Bump invalida cachés con asignaciones hechas con reglas anteriores. */
    private const CACHE_VERSION = 5;

    /** @var array<string,mixed>|null In-request memoization of the cache doc. */
    private static $doc = null;

    private static function cachePath(): string
    {
        return __DIR__ . '/../cache/item_render_images.json';
    }

    /** @return array{shop_date:?string, images:array<string,array{url:string,score:int}>} */
    private static function load(): array
    {
        if (self::$doc !== null) {
            return self::$doc;
        }
        $doc = ['version' => self::CACHE_VERSION, 'shop_date' => null, 'images' => []];
        $raw = @file_get_contents(self::cachePath());
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['images']) && is_array($data['images'])
                && (int) ($data['version'] ?? 1) === self::CACHE_VERSION) {
                $doc['shop_date'] = isset($data['shop_date']) && is_string($data['shop_date']) ? $data['shop_date'] : null;
                foreach ($data['images'] as $id => $rec) {
                    if (is_string($id) && is_array($rec)
                        && isset($rec['url']) && is_string($rec['url']) && $rec['url'] !== '') {
                        $doc['images'][strtolower($id)] = [
                            'url'   => $rec['url'],
                            'score' => isset($rec['score']) && is_numeric($rec['score']) ? (int) $rec['score'] : 100,
                        ];
                    }
                }
            }
        }
        self::$doc = $doc;
        return $doc;
    }

    /** Primer renderImage no-LEGO de una entrada, o null. */
    private static function entryRenderImage(array $entry): ?string
    {
        $renders = $entry['newDisplayAsset']['renderImages'] ?? null;
        if (!is_array($renders)) {
            return null;
        }
        foreach ($renders as $img) {
            if (!is_array($img) || !isset($img['image']) || !is_string($img['image']) || $img['image'] === '') {
                continue;
            }
            if (($img['productTag'] ?? null) === 'Product.Juno') {
                continue; // arte LEGO: no representa el ítem normal.
            }
            return $img['image'];
        }
        return null;
    }

    /** Ids de cosméticos (minúsculas) de una entrada, protagonista primero
     * (tracks antes que el resto, espejo de ShopEntryLocator::mainItemId). */
    private static function entryItemIds(array $entry): array
    {
        $ids = [];
        foreach (['tracks', 'brItems', 'instruments', 'cars'] as $key) {
            foreach (($entry[$key] ?? []) as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id']) && $item['id'] !== '') {
                    $ids[] = strtolower($item['id']);
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** Id (minúsculas) del ítem protagonista implícito de una entrada (la
     * skin o, en su defecto, el instrumento), o null si no incluye ninguno. */
    private static function entryHeroId(array $entry): ?string
    {
        foreach (($entry['brItems'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_string($item['id']) || $item['id'] === '') {
                continue;
            }
            $type = $item['type']['value'] ?? null;
            $id = strtolower($item['id']);
            if ($type === 'outfit' || strpos($id, 'character_') === 0) {
                return $id;
            }
        }
        foreach (($entry['instruments'] ?? []) as $item) {
            if (is_array($item) && isset($item['id']) && is_string($item['id']) && $item['id'] !== '') {
                return strtolower($item['id']);
            }
        }
        return null;
    }

    /**
     * Acumula en el caché los renderImages del snapshot actual. Barato cuando
     * la tienda no cambió (se compara shop_date). Nunca lanza.
     *
     * @param array<int,array<string,mixed>> $entries Entradas canónicas del snapshot.
     * @param string|null $shopDate Fecha canónica de la tienda (data.date).
     */
    public static function updateFromEntries(array $entries, ?string $shopDate): void
    {
        try {
            $doc = self::load();
            if ($shopDate !== null && $shopDate !== '' && $doc['shop_date'] === $shopDate) {
                return; // ya acumulado para esta tienda.
            }

            $changed = false;
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $isBundle = isset($entry['bundle']) && $entry['bundle'] !== null;
                $url = self::entryRenderImage($entry);
                if ($url === null) {
                    continue;
                }
                $ids = self::entryItemIds($entry);
                if (empty($ids)) {
                    continue;
                }
                $count = count($ids);
                // Dueño del arte: el declarado (cosmeticId) o, en su defecto,
                // el protagonista implícito de la oferta — skin o instrumento
                // (el render los retrata a ellos, no a sus accesorios); solo
                // él recibe este render.
                $declaredOwner = $entry['newDisplayAsset']['cosmeticId'] ?? null;
                $declaredOwner = (is_string($declaredOwner) && $declaredOwner !== '') ? $declaredOwner : null;
                $owner = $declaredOwner;
                if ($owner === null) {
                    $owner = $count > 1 ? self::entryHeroId($entry) : null;
                }
                // Entradas-bundle: solo los dúos/tríos con dueño (skin y sus
                // accesorios) aportan arte; un lote real mezcla demasiados
                // ítems en su render.
                if ($isBundle && $declaredOwner === null && ($owner === null || $count > 3)) {
                    continue;
                }
                if (is_string($owner) && $owner !== '') {
                    $ids = [strtolower($owner)];
                }
                foreach ($ids as $idx => $id) {
                    // Protagonista (primer ítem) y menos ítems = mejor retrato.
                    $score = ($idx === 0 ? 0 : 100) + $count;
                    $prev = $doc['images'][$id] ?? null;
                    if ($prev === null || $score <= $prev['score']) {
                        if ($prev === null || $prev['url'] !== $url || $prev['score'] !== $score) {
                            $doc['images'][$id] = ['url' => $url, 'score' => $score];
                            $changed = true;
                        }
                    }
                }
            }

            $newDate = ($shopDate !== null && $shopDate !== '') ? $shopDate : $doc['shop_date'];
            if (!$changed && $newDate === $doc['shop_date']) {
                return;
            }
            $doc['shop_date'] = $newDate;
            self::$doc = $doc;

            $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }
            $path = self::cachePath();
            $tmp = $path . '.tmp.' . getmypid();
            if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
                return;
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return;
            }
            @chmod($path, 0644);
        } catch (Throwable $e) {
            // Degradar en silencio: el caché es un extra, nunca debe fatal.
        }
    }

    /**
     * URLs de renderImage cacheadas para un conjunto de ids.
     *
     * @param array<int,string> $ids
     * @return array<string,string> cosmeticId (minúsculas) => url
     */
    public static function getForIds(array $ids): array
    {
        $out = [];
        try {
            $doc = self::load();
            foreach ($ids as $id) {
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $key = strtolower($id);
                if (isset($doc['images'][$key])) {
                    $out[$key] = $doc['images'][$key]['url'];
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}

}
