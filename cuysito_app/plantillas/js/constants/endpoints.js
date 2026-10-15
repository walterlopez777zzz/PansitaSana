// La tienda se consume desde fuentes internas. El frontend ya NO referencia
// ninguna API externa de Fortnite.
//  - SHOP_GATEWAY_ENDPOINT: gateway interno del storefront principal (banano_primordial.json vía /web.php).
//  - LOCAL_SHOP_CACHE_URL: snapshot interno que consume el editor de plantillas (generado por ./api/sync-shop.php).
export const SHOP_GATEWAY_ENDPOINT="/web.php";export const LOCAL_SHOP_CACHE_URL="./api/shop-cache.json";export const BACKGROUNDS_API_ENDPOINT="/plantillas/api/backgrounds.php";
export const VIA_CUENTA_API_ENDPOINT="./api/via-cuenta.php";
