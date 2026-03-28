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

// ---- CORS – allow requests from kipod.fun + localhost ----
$allowed = [
    'https://kipod.fun',
    'https://www.kipod.fun',
    'https://r94r.github.io', // keep for backwards compat
    'http://localhost',
    'http://127.0.0.1',
    'null', // file:// during local dev
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if(in_array($origin, $allowed) || str_starts_with($origin,'http://localhost') || str_starts_with($origin,'http://127')){
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://kipod.fun");
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

// ---- Auto-migrate: add player_id column if not yet present ----
$cols = $pdo->query("SHOW COLUMNS FROM scores LIKE 'player_id'")->fetchAll();
if(!$cols){
    $pdo->exec("ALTER TABLE scores
        ADD COLUMN player_id VARCHAR(64) DEFAULT NULL,
        ADD UNIQUE INDEX idx_player (player_id)");
}

// ---- Auto-migrate: add game_id column to scores if not yet present ----
$cols = $pdo->query("SHOW COLUMNS FROM scores LIKE 'game_id'")->fetchAll();
if(!$cols){
    $pdo->exec("ALTER TABLE scores ADD COLUMN game_id INT DEFAULT NULL");
}

// ---- Auto-create visits table ----
$pdo->exec("CREATE TABLE IF NOT EXISTS visits (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    utm       VARCHAR(32) NOT NULL,
    player_id VARCHAR(64) DEFAULT NULL,
    created   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_utm (utm),
    INDEX idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---- Auto-migrate: add utm column to games if not yet present ----
$cols = $pdo->query("SHOW COLUMNS FROM games LIKE 'utm'")->fetchAll();
if(!$cols){
    $pdo->exec("ALTER TABLE games ADD COLUMN utm VARCHAR(32) DEFAULT NULL");
}

// ---- Auto-migrate: add player_id column to games if not yet present ----
$cols = $pdo->query("SHOW COLUMNS FROM games LIKE 'player_id'")->fetchAll();
if(!$cols){
    $pdo->exec("ALTER TABLE games ADD COLUMN player_id VARCHAR(64) DEFAULT NULL");
}

// ---- One-time dedup: visit api.php?dedup=1 ----
// Merges duplicate names, keeping only the highest score per name.
// Run once before deploying player_id support, then remove.
if(isset($_GET['dedup'])){
    $deleted = $pdo->exec("
        DELETE s1 FROM scores s1
        INNER JOIN scores s2
            ON s1.name = s2.name
           AND (s1.score < s2.score OR (s1.score = s2.score AND s1.id > s2.id))
    ");
    echo json_encode(['ok' => true, 'rows_removed' => $deleted]);
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

// ---- GET /api.php  → top 20 scores ----
if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $stmt = $pdo->query("SELECT name, score, wave FROM scores ORDER BY score DESC LIMIT 20");
    echo json_encode($stmt->fetchAll());
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $body = json_decode(file_get_contents('php://input'), true);

    // ---- POST ?visit=1 → log a UTM page visit (scan/click before game starts) ----
    if(isset($_GET['visit'])){
        $utm = preg_replace('/[^a-z0-9]/', '', strtolower(trim($body['utm'] ?? '')));
        $utm = substr($utm, 0, 32);
        if(!$utm){ http_response_code(400); echo json_encode(['error'=>'missing utm']); exit; }
        $pid = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($body['player_id'] ?? ''));
        $pid = substr($pid, 0, 64) ?: null;
        $pdo->prepare("INSERT INTO visits (utm, player_id) VALUES (?, ?)")->execute([$utm, $pid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- POST ?game=1 → create or update a game row ----
    if(isset($_GET['game'])){
        $token   = trim($body['token']   ?? '');
        $game_id = (int)($body['game_id'] ?? 0);
        if(!$token || !validateToken($token)){
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or expired session']);
            exit;
        }
        $score = max(0, (int)($body['score'] ?? 0));
        $wave  = max(1, (int)($body['wave']  ?? 1));
        $pid = preg_replace('/[^a-zA-Z0-9\-]/', '', trim($body['player_id'] ?? ''));
        $pid = substr($pid, 0, 64) ?: null;
        if($game_id > 0){
            // Update existing row (wave progress or game end)
            // Also backfill player_id if not yet set
            if($pid){
                $stmt = $pdo->prepare("UPDATE games SET score=?, wave=?, player_id=COALESCE(player_id,?) WHERE id=?");
                $stmt->execute([$score, $wave, $pid, $game_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE games SET score=?, wave=? WHERE id=?");
                $stmt->execute([$score, $wave, $game_id]);
            }
            echo json_encode(['ok' => true, 'game_id' => $game_id]);
        } else {
            // Create new row (game start)
            $utm = preg_replace('/[^a-z0-9]/', '', strtolower(trim($body['utm'] ?? '')));
            $utm = substr($utm, 0, 32) ?: null;
            $stmt = $pdo->prepare("INSERT INTO games (score, wave, utm, player_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([0, 1, $utm, $pid]);
            echo json_encode(['ok' => true, 'game_id' => (int)$pdo->lastInsertId()]);
        }
        exit;
    }

    // ---- POST /api.php  → submit named score to leaderboard ----
    $name      = trim(strip_tags($body['name']      ?? ''));
    $score     = (int)($body['score']     ?? 0);
    $wave      = (int)($body['wave']      ?? 1);
    $token     = trim($body['token']      ?? '');
    $game_id   = (int)($body['game_id']   ?? 0);
    $player_id = trim($body['player_id']  ?? '');

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

    // 5. Sanitise player_id (alphanumeric + hyphens only, max 64 chars)
    $player_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $player_id);
    $player_id = substr($player_id, 0, 64) ?: null;

    // 6. Insert or update: one row per player_id, keep highest score
    $gid = $game_id > 0 ? $game_id : null;
    $stmt = $pdo->prepare("INSERT INTO scores (name, score, wave, player_id, game_id) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name    = IF(VALUES(score) >= score, VALUES(name),    name),
            wave    = IF(VALUES(score) >= score, VALUES(wave),    wave),
            score   = IF(VALUES(score) >= score, VALUES(score),   score),
            game_id = IF(VALUES(score) >= score, VALUES(game_id), game_id)");
    $stmt->execute([$name, $score, $wave, $player_id, $gid]);

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
