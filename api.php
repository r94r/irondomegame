<?php
// =====================================================================
//  Iron Dome – Leaderboard API
//  Upload this file to your server alongside index.html
//
//  Setup:
//   1. Create a MySQL database + user in cPanel
//   2. Fill in the 5 config values below
//   3. Visit https://yourdomain.com/irondome/api.php?setup=1 once
//      to create the scores + games tables (then remove ?setup=1)
// =====================================================================

require __DIR__ . '/config.php'; // DB_HOST, DB_NAME, DB_USER, DB_PASS, TOKEN_SECRET

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

// ---- Session token helpers ----
function makeToken(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ts = time();
    $hmac = hash_hmac('sha256', $ts . '|' . $ip, TOKEN_SECRET);
    return $ts . '.' . $hmac;
}

function validateToken(string $token): bool {
    $parts = explode('.', $token, 2);
    if(count($parts) !== 2) return false;
    [$ts, $hmac] = $parts;
    if(!ctype_digit($ts)) return false;
    // Expire after 4 hours (generous to cover long sessions)
    if(time() - (int)$ts > 14400) return false;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $expected = hash_hmac('sha256', $ts . '|' . $ip, TOKEN_SECRET);
    return hash_equals($expected, $hmac);
}

// ---- GET ?token=1 → issue a session token (no DB needed) ----
if(isset($_GET['token'])){
    echo json_encode(['token' => makeToken()]);
    exit;
}

// ---- DB connection ----
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS games (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        score     INT NOT NULL DEFAULT 0,
        wave      TINYINT NOT NULL DEFAULT 1,
        named     TINYINT(1) NOT NULL DEFAULT 0,
        created   DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo json_encode(['ok' => true, 'message' => 'Tables ready. Remove ?setup=1 from the URL now.']);
    exit;
}

// ---- GET /api.php  → top 15 scores ----
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $stmt = $pdo->query("SELECT name, score, wave FROM scores ORDER BY score DESC LIMIT 15");
    echo json_encode($stmt->fetchAll());
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $body = json_decode(file_get_contents('php://input'), true);

    // ---- POST ?game=1 → track any game completion (named or anonymous) ----
    if(isset($_GET['game'])){
        $token = trim($body['token'] ?? '');
        if(!$token || !validateToken($token)){
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or expired session']);
            exit;
        }
        $score = max(0, (int)($body['score'] ?? 0));
        $wave  = max(1, (int)($body['wave']  ?? 1));
        $stmt  = $pdo->prepare("INSERT INTO games (score, wave) VALUES (?, ?)");
        $stmt->execute([$score, $wave]);
        echo json_encode(['ok' => true, 'game_id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    // ---- POST /api.php  → submit named score to leaderboard ----
    $name    = trim(strip_tags($body['name']    ?? ''));
    $score   = (int)($body['score']   ?? 0);
    $wave    = (int)($body['wave']    ?? 1);
    $token   = trim($body['token']    ?? '');
    $game_id = (int)($body['game_id'] ?? 0);

    // 1. Validate session token
    if(!$token || !validateToken($token)){
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or expired session']);
        exit;
    }

    // 2. Basic input validation
    if(!$name || strlen($name) > 32 || $score <= 0 || $wave < 1){
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    // 3. Plausibility cap: max ~5000 pts per wave reached (very generous)
    if($score > $wave * 5000){
        http_response_code(400);
        echo json_encode(['error' => 'Score not plausible']);
        exit;
    }

    // 4. Rate limit: max 60 entries per hour globally
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM scores WHERE created > NOW() - INTERVAL 1 HOUR");
    $stmt->execute();
    if((int)$stmt->fetchColumn() >= 60){
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded, try again later']);
        exit;
    }

    // 5. Insert score
    $stmt = $pdo->prepare("INSERT INTO scores (name, score, wave) VALUES (?, ?, ?)");
    $stmt->execute([$name, $score, $wave]);

    // 6. Mark the game row as named (if client sent a game_id)
    if($game_id > 0){
        $upd = $pdo->prepare("UPDATE games SET named=1 WHERE id=?");
        $upd->execute([$game_id]);
    }

    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
