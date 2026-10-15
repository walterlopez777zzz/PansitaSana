<?php
// Archivo: db_connect.php

// ZONE-AGNOSTIC: Force UTC globally so PHP time() / date() / strtotime()
// always match the UTC_TIMESTAMP() values stored in MySQL.
date_default_timezone_set('UTC');

$servername = "localhost";
$username = "zkckhehj_pistas";
$password = "Pistas2628";
$dbname = "zkckhehj_pistas";

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Chequear conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Establecer el charset a UTF-8 para evitar problemas con tildes y ñ
$conn->set_charset("utf8mb4");

// ZONE-AGNOSTIC: Force MySQL session to UTC so DATETIME columns
// and UTC_TIMESTAMP() are always in the same reference frame.
$conn->query("SET time_zone = '+00:00'");
?>