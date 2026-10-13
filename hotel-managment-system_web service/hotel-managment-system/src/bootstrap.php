<?php
// src/bootstrap.php

// TEMPORARY: Show errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}
// CORS Configuration
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = [
    'http://localhost:3000', 
    'http://localhost:8000',
    'http://group3-service.com',
    'https://hotel-management-system.wuaze.com' // Your Domain
];

// Allow any origin for now to prevent blocking (Safe for public API)
// Or restrict to list if preferred. For this debug session, let's reflect origin.
if (!empty($origin)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
function config() {
  static $cfg;
  if (!$cfg) { $cfg = require __DIR__ . '/config.php'; }
  return $cfg;
}

function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;
  $cfg = config()['db'];
  $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
  try {
      $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
  } catch (PDOException $e) {
      // Return JSON error if DB fails
      header('Content-Type: application/json');
      http_response_code(500);
      echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
      exit;
  }
  return $pdo;
}

function json($data, int $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
}

function start_session() {
  $cfg = config()['security'];

  // 🔐 Force cookie-based sessions
  ini_set('session.use_only_cookies', 1);
  ini_set('session.use_strict_mode', 1);

  // 🧠 CRITICAL: cookie path must match your project
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',              // Changed for production deployment
    'domain'   => '',
    'secure'   => false,   // Set to true when using HTTPS/SSL
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_name($cfg['session_name']);

  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

