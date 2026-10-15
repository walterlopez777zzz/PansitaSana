<?php

declare(strict_types=1);

// --- MODO DE DEPURACIÓN ACTIVADO ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// --- FIN MODO DE DEPURACIÓN ---

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  http_response_code(204);
  exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// --- DB CONFIG (replace with your production values) ---
const DB_HOST = 'localhost';
const DB_USER = 'zkckhehj_pistas';
const DB_PASS = 'Pistas2628';
const DB_NAME = 'zkckhehj_pistas';

const TABLE_NAME = 'collage_backgrounds';

function generate_uuid_v4() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function respond(int $status, $payload): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function getPdo(): PDO {
  $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];
  return new PDO($dsn, DB_USER, DB_PASS, $options);
}

function readJsonBody(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) return [];
  return $data;
}

function normalizeId($raw): string {
  return trim((string)($raw ?? ''));
}

function isLikelySafeId(string $id): bool {
  if ($id === '') return false;
  if (strlen($id) > 64) return false;
  return preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1;
}

function normalizeName($raw): string {
  $name = trim((string)($raw ?? ''));
  if (mb_strlen($name) > 140) $name = mb_substr($name, 0, 140);
  return $name;
}

function normalizeUrl($raw): string {
  return trim((string)($raw ?? ''));
}

function isValidHttpUrl(string $url): bool {
  if ($url === '') return false;
  if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
  $scheme = parse_url($url, PHP_URL_SCHEME);
  return $scheme === 'http' || $scheme === 'https';
}

/**
 * Validates a data: URI for image uploads from local files.
 * Accepts common image MIME types and enforces a 10 MB size limit.
 */
function isValidDataUrl(string $url): bool {
  if (!preg_match('#^data:image/(png|jpeg|jpg|gif|webp|svg\+xml|bmp|avif);base64,#i', $url)) {
    return false;
  }
  // Enforce a 10 MB limit on the raw data URI string length
  if (strlen($url) > 10 * 1024 * 1024) return false;
  return true;
}

/**
 * Returns true if the URL is either a valid HTTP(S) URL or a valid image data: URI.
 */
function isValidBackgroundUrl(string $url): bool {
  return isValidHttpUrl($url) || isValidDataUrl($url);
}

try {
  $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

  if ($method === 'GET') {
    $pdo = getPdo();
    $sql = 'SELECT id, name, url, createdAt FROM ' . TABLE_NAME . ' ORDER BY createdAt DESC, id DESC';
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
      $createdAt = isset($r['createdAt']) ? (string)$r['createdAt'] : '';
      $out[] = [
        'id' => (string)($r['id'] ?? ''),
        'name' => (string)($r['name'] ?? ''),
        'url' => (string)($r['url'] ?? ''),
        'createdAt' => $createdAt,
        'updatedAt' => $createdAt,
      ];
    }

    respond(200, [ 'ok' => true, 'data' => $out ]);
  }

  if ($method === 'POST') {
    $body = readJsonBody();

    $id = normalizeId($body['id'] ?? '');
    $name = normalizeName($body['name'] ?? '');
    $url = normalizeUrl($body['url'] ?? '');

    if ($name === '') respond(400, [ 'ok' => false, 'error' => 'Nombre requerido' ]);
    if (!isValidBackgroundUrl($url)) respond(400, [ 'ok' => false, 'error' => 'URL inválida' ]);

    $pdo = getPdo();

    if ($id !== '') {
      $stmt = $pdo->prepare('UPDATE ' . TABLE_NAME . ' SET name = :name, url = :url, updatedAt = NOW() WHERE id = :id');
      $stmt->execute([
        ':name' => $name,
        ':url' => $url,
        ':id' => $id,
      ]);

      $fetch = $pdo->prepare('SELECT id, name, url, createdAt, updatedAt FROM ' . TABLE_NAME . ' WHERE id = :id LIMIT 1');
      $fetch->execute([ ':id' => $id ]);
      $row = $fetch->fetch();
      if (!$row) respond(404, [ 'ok' => false, 'error' => 'No encontrado' ]);

      respond(200, [
        'ok' => true,
        'data' => [
          'id' => (string)($row['id'] ?? ''),
          'name' => (string)($row['name'] ?? ''),
          'url' => (string)($row['url'] ?? ''),
          'createdAt' => (string)($row['createdAt'] ?? ''),
          'updatedAt' => (string)($row['updatedAt'] ?? ''),
        ],
      ]);
    } else {
      $newId = generate_uuid_v4();
      $stmt = $pdo->prepare('INSERT INTO ' . TABLE_NAME . ' (id, name, url) VALUES (:id, :name, :url)');
      $stmt->execute([
        ':id' => $newId,
        ':name' => $name,
        ':url' => $url,
      ]);

      $fetch = $pdo->prepare('SELECT id, name, url, createdAt, updatedAt FROM ' . TABLE_NAME . ' WHERE id = :id LIMIT 1');
      $fetch->execute([ ':id' => $newId ]);
      $row = $fetch->fetch();

      if (!$row) {
        respond(201, [ 'ok' => true, 'data' => [ 'id' => (string)$newId, 'name' => $name, 'url' => $url ] ]);
      }

      respond(201, [
        'ok' => true,
        'data' => [
          'id' => (string)($row['id'] ?? ''),
          'name' => (string)($row['name'] ?? ''),
          'url' => (string)($row['url'] ?? ''),
          'createdAt' => (string)($row['createdAt'] ?? ''),
          'updatedAt' => (string)($row['updatedAt'] ?? ''),
        ],
      ]);
    }
  }

  if ($method === 'DELETE') {
    $id = normalizeId($_GET['id'] ?? '');
    if ($id === '') respond(400, [ 'ok' => false, 'error' => 'id requerido' ]);

    $pdo = getPdo();
    $stmt = $pdo->prepare('DELETE FROM ' . TABLE_NAME . ' WHERE id = :id');
    $stmt->execute([ ':id' => $id ]);

    respond(200, [ 'ok' => true ]);
  }

  respond(405, [ 'ok' => false, 'error' => 'Método no permitido' ]);
} catch (Throwable $e) {
  respond(500, [ 'ok' => false, 'error' => $e->getMessage() ]);
}