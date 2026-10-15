<?php
// /api/track_product_click.php (VERSIÓN ULTRA-SIMPLE Y SILENCIOSA)

// Usamos @ para suprimir cualquier posible "warning" si los datos no llegan,
// aunque ya sabemos que sí lo hacen.
if (isset($_POST['offerId']) && isset($_POST['productName'])) {

    // Si los datos existen, procedemos.
    require_once 'db_connect.php';

    $offer_id = $_POST['offerId'];
    $product_name = $_POST['productName'];
    $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR']);

    $sql = "INSERT INTO product_clicks (offer_id, product_name, ip_address_hash) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("sss", $offer_id, $product_name, $ip_hash);
        $stmt->execute();
        $stmt->close();
    }

    $conn->close();
}

// No hacemos NADA MÁS. No hay exit(), no hay http_response_code().
// Simplemente dejamos que el script termine.
?>