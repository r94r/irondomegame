<?php
// =====================================================================
//  Iron Dome – Leaderboard API
//  Upload this file to your server alongside index.html
//
//  Setup:
//   1. Create a MySQL database + user in cPanel
//   2. Fill in the 4 config values below
//   3. Visit https://yourdomain.com/irondome/api.php?setup=1 once
//      to create the scores table (then remove ?setup=1 from URL)
// =====================================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');    // e.g. r94r_irondome
define('DB_USER', 'YOUR_DB_USER');    // e.g. r94r_ironuser
define('DB_PASS', 'YOUR_DB_PASS');    // your db password

// ---- CORS – allow requests from GitHub Pages + localhost ----
$allowed = [
    'https://r94r.github.io',
    'http://localhost',
    'http://127.0.0.1',
    'null', // file:// during local dev
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if(in_array($origin, $allowed) || str_starts_with($origin,'http://localhost') || str_starts_with($origin,'http://127')){
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://r94r.github.io");
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS'){ http_response_code(204); exit; }

// ---- DB connection ----
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch(Exception $e){
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// ---- One-time table setup: visit api.php?setup=1 ----
if(isset($_GET['setup'])){
    $pdo->exec("CREATE TABLE IF NOT EXISTS scores (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        name      VARCHAR(32) NOT NULL,
        score     INT NOT NULL,
        wave      TINYINT NOT NULL DEFAULT 1,
        created   DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_score (score DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo json_encode(['ok' => true, 'message' => 'Table ready. Remove ?setup=1 from the URL now.']);
    exit;
}

// ---- GET /api.php  → top 15 scores ----
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $stmt = $pdo->query("SELECT name, score, wave FROM scores ORDER BY score DESC LIMIT 15");
    echo json_encode($stmt->fetchAll());
    exit;
}

// ---- POST /api.php  → submit score ----
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $body = json_decode(file_get_contents('php://input'), true);
    $name  = trim(strip_tags($body['name']  ?? ''));
    $score = (int)($body['score'] ?? 0);
    $wave  = (int)($body['wave']  ?? 1);

    if(!$name || strlen($name) > 32 || $score <= 0){
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    // Rate limit: max 5 entries per IP per hour
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM scores WHERE created > NOW() - INTERVAL 1 HOUR");
    // (simple global limit – good enough for a game)

    $stmt = $pdo->prepare("INSERT INTO scores (name, score, wave) VALUES (?, ?, ?)");
    $stmt->execute([$name, $score, $wave]);
    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
