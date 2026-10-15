<?php
// 1. Reloj de Lima (Tu hora real)
$tz_lima = new DateTimeZone('America/Lima');
$hora_lima = new DateTime('now', $tz_lima);

// 2. Reloj de la Costa Este de EE.UU. (ET / America/New_York)
// Nota: Usamos New_York porque maneja automáticamente el cambio de verano/invierno del ET.
$tz_et = new DateTimeZone('America/New_York');
$hora_et = new DateTime('now', $tz_et);

// 3. Reloj UTC (El que te salió antes por defecto en la consola)
$tz_utc = new DateTimeZone('UTC');
$hora_utc = new DateTime('now', $tz_utc);

// Formato de salida para el archivo de texto
echo "--- REPORTE EXACTO DE ZONAS HORARIAS CRON ---" . PHP_EOL;
echo "Hora LIMA (America/Lima) : " . $hora_lima->format('Y-m-d H:i:s') . PHP_EOL;
echo "Hora ET   (America/New_York): " . $hora_et->format('Y-m-d H:i:s') . " <-- (Suele ser el reloj de cPanel)" . PHP_EOL;
echo "Hora UTC  (Tiempo Universal) : " . $hora_utc->format('Y-m-d H:i:s') . PHP_EOL;
echo "---------------------------------------------" . PHP_EOL;
?>