<?php
// =====================================================================
//  Iron Dome – Private Stats Dashboard
//  Protected by HTTP Basic Auth — password set in config.php
// =====================================================================

require __DIR__ . '/config.php';

// ---- Auth ----
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW']   ?? '';
if($user !== 'admin' || $pass !== STATS_PASS){
    header('WWW-Authenticate: Basic realm="Kipod Stats"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Unauthorized';
    exit;
}

// ---- DB ----
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ---- Check if games table exists ----
$has_games = false;
try {
    $pdo->query("SELECT 1 FROM games LIMIT 1");
    $has_games = true;
} catch(Exception $e){}

// ---- Queries: scores table (named players) ----
$totals = $pdo->query("
    SELECT
        COUNT(*)                    AS total_rounds,
        COUNT(DISTINCT name)        AS unique_players,
        MAX(score)                  AS top_score,
        ROUND(AVG(score))           AS avg_score,
        MAX(wave)                   AS max_wave,
        ROUND(AVG(wave),1)          AS avg_wave,
        MIN(created)                AS first_game,
        MAX(created)                AS last_game
    FROM scores
")->fetch();

$per_player = $pdo->query("
    SELECT
        name,
        COUNT(*)            AS rounds,
        MAX(score)          AS best_score,
        ROUND(AVG(score))   AS avg_score,
        MAX(wave)           AS best_wave,
        MIN(created)        AS first_seen,
        MAX(created)        AS last_seen
    FROM scores
    GROUP BY name
    ORDER BY best_score DESC
")->fetchAll();

$recent = $pdo->query("
    SELECT name, score, wave, created
    FROM scores
    ORDER BY created DESC
    LIMIT 50
")->fetchAll();

$by_wave = $pdo->query("
    SELECT wave, COUNT(*) AS rounds, ROUND(AVG(score)) AS avg_score, MAX(score) AS top_score
    FROM scores
    GROUP BY wave
    ORDER BY wave ASC
")->fetchAll();

$daily = $pdo->query("
    SELECT DATE(created) AS day, COUNT(*) AS rounds, COUNT(DISTINCT name) AS players
    FROM scores
    GROUP BY DATE(created)
    ORDER BY day DESC
    LIMIT 30
")->fetchAll();

// ---- Queries: games table (all plays including anonymous) ----
$game_totals   = null;
$daily_games   = [];
$recent_games  = [];
$by_wave_games = [];
if($has_games){
    $game_totals = $pdo->query("
        SELECT
            COUNT(*)        AS total_games,
            SUM(named)      AS named_games,
            COUNT(*)-SUM(named) AS anon_games,
            ROUND(AVG(score))   AS avg_score,
            MAX(wave)           AS max_wave,
            ROUND(AVG(wave),1)  AS avg_wave,
            MIN(created)        AS first_game,
            MAX(created)        AS last_game
        FROM games
    ")->fetch();

    $daily_games = $pdo->query("
        SELECT DATE(created) AS day,
            COUNT(*)            AS total,
            SUM(named)          AS named,
            COUNT(*)-SUM(named) AS anon
        FROM games
        GROUP BY DATE(created)
        ORDER BY day DESC
        LIMIT 30
    ")->fetchAll();

    $by_wave_games = $pdo->query("
        SELECT wave,
            COUNT(*) AS total,
            SUM(named) AS named,
            COUNT(*)-SUM(named) AS anon,
            ROUND(AVG(score)) AS avg_score,
            MAX(score) AS top_score
        FROM games
        GROUP BY wave
        ORDER BY wave ASC
    ")->fetchAll();

    $recent_games = $pdo->query("
        SELECT score, wave, named, created
        FROM games
        ORDER BY created DESC
        LIMIT 50
    ")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kipod Barzel – Stats</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#0a0e1a;color:#ccd;font-family:system-ui,sans-serif;font-size:14px;padding:24px}
  h1{color:#4aaeff;font-size:22px;margin-bottom:20px}
  h2{color:#aac;font-size:14px;text-transform:uppercase;letter-spacing:1px;margin:28px 0 10px}
  h3{color:#667;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin:20px 0 8px}
  .cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:8px}
  .card{background:#111827;border:1px solid #1e2d45;border-radius:8px;padding:14px 20px;min-width:130px}
  .card .val{font-size:26px;font-weight:900;color:#fff}
  .card .lbl{font-size:11px;color:#556;margin-top:2px}
  .card.hi { border-color:#4aaeff44; }
  .card.hi .val { color:#4aaeff; }
  table{width:100%;border-collapse:collapse;margin-bottom:8px}
  th{text-align:left;color:#4aaeff;font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:6px 10px;border-bottom:1px solid #1e2d45}
  td{padding:6px 10px;border-bottom:1px solid #111827;color:#ccd}
  tr:hover td{background:#111827}
  .num{text-align:right}
  .wnb{color:#ffcc44}
  .anon{color:#888}
  .named{color:#4aaeff}
  .sep{border-top:1px solid #1e2d45;margin:32px 0}
  .note{color:#445;font-size:12px;margin-top:4px}
</style>
</head>
<body>
<h1>Kipod Barzel — Stats Dashboard</h1>

<?php if($has_games && $game_totals): ?>
<h2>All Plays (including anonymous)</h2>
<div class="cards">
  <div class="card"><div class="val"><?= $game_totals['total_games'] ?></div><div class="lbl">Total Plays</div></div>
  <div class="card hi"><div class="val"><?= $game_totals['named_games'] ?></div><div class="lbl">Named (leaderboard)</div></div>
  <div class="card"><div class="val"><?= $game_totals['anon_games'] ?></div><div class="lbl">Anonymous</div></div>
  <div class="card"><div class="val"><?= number_format($game_totals['avg_score']) ?></div><div class="lbl">Avg Score (all)</div></div>
  <div class="card"><div class="val"><?= $game_totals['max_wave'] ?></div><div class="lbl">Highest Wave</div></div>
  <div class="card"><div class="val"><?= $game_totals['avg_wave'] ?></div><div class="lbl">Avg Wave</div></div>
</div>
<p class="note">First play: <?= $game_totals['first_game'] ?>  ·  Last play: <?= $game_totals['last_game'] ?></p>
<?php endif; ?>

<div class="sep"></div>
<h2>Named Players (leaderboard submissions)</h2>
<div class="cards">
  <div class="card"><div class="val"><?= $totals['total_rounds'] ?></div><div class="lbl">Total Rounds</div></div>
  <div class="card"><div class="val"><?= $totals['unique_players'] ?></div><div class="lbl">Unique Players</div></div>
  <div class="card"><div class="val"><?= number_format($totals['top_score']) ?></div><div class="lbl">Top Score</div></div>
  <div class="card"><div class="val"><?= number_format($totals['avg_score']) ?></div><div class="lbl">Avg Score</div></div>
  <div class="card"><div class="val"><?= $totals['max_wave'] ?></div><div class="lbl">Highest Wave</div></div>
  <div class="card"><div class="val"><?= $totals['avg_wave'] ?></div><div class="lbl">Avg Wave</div></div>
</div>
<p class="note">First game: <?= $totals['first_game'] ?>  ·  Last game: <?= $totals['last_game'] ?></p>

<h3>Per Player</h3>
<table>
  <tr><th>#</th><th>Name</th><th class="num">Rounds</th><th class="num">Best Score</th><th class="num">Avg Score</th><th class="num">Best Wave</th><th>First Seen</th><th>Last Seen</th></tr>
  <?php foreach($per_player as $i => $p): ?>
  <tr>
    <td style="color:#445"><?= $i+1 ?></td>
    <td><?= htmlspecialchars($p['name']) ?></td>
    <td class="num"><?= $p['rounds'] ?></td>
    <td class="num" style="color:#fff;font-weight:bold"><?= number_format($p['best_score']) ?></td>
    <td class="num"><?= number_format($p['avg_score']) ?></td>
    <td class="num wnb"><?= $p['best_wave'] ?></td>
    <td style="color:#445"><?= $p['first_seen'] ?></td>
    <td style="color:#445"><?= $p['last_seen'] ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php if($has_games && $daily_games): ?>
<div class="sep"></div>
<h2>Daily Activity (last 30 days)</h2>
<table>
  <tr><th>Date</th><th class="num">Total Plays</th><th class="num named">Named</th><th class="num anon">Anonymous</th></tr>
  <?php foreach($daily_games as $d): ?>
  <tr>
    <td><?= $d['day'] ?></td>
    <td class="num"><?= $d['total'] ?></td>
    <td class="num named"><?= $d['named'] ?></td>
    <td class="num anon"><?= $d['anon'] ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Plays by Wave Reached</h2>
<table>
  <tr><th>Wave</th><th class="num">Total Plays</th><th class="num named">Named</th><th class="num anon">Anon</th><th class="num">Avg Score</th><th class="num">Top Score</th></tr>
  <?php foreach($by_wave_games as $r): ?>
  <tr>
    <td class="wnb">Wave <?= $r['wave'] ?></td>
    <td class="num"><?= $r['total'] ?></td>
    <td class="num named"><?= $r['named'] ?></td>
    <td class="num anon"><?= $r['anon'] ?></td>
    <td class="num"><?= number_format($r['avg_score']) ?></td>
    <td class="num" style="color:#fff"><?= number_format($r['top_score']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Recent Plays (last 50)</h2>
<table>
  <tr><th>Time</th><th>Type</th><th class="num">Score</th><th class="num">Wave</th></tr>
  <?php foreach($recent_games as $r): ?>
  <tr>
    <td style="color:#445"><?= $r['created'] ?></td>
    <td><?= $r['named'] ? '<span class="named">named</span>' : '<span class="anon">anonymous</span>' ?></td>
    <td class="num" style="color:#fff"><?= number_format($r['score']) ?></td>
    <td class="num wnb"><?= $r['wave'] ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php else: ?>
<div class="sep"></div>
<h2>Rounds by Wave Reached (named only)</h2>
<table>
  <tr><th>Wave</th><th class="num">Rounds ended here</th><th class="num">Avg Score</th><th class="num">Top Score</th></tr>
  <?php foreach($by_wave as $r): ?>
  <tr>
    <td class="wnb">Wave <?= $r['wave'] ?></td>
    <td class="num"><?= $r['rounds'] ?></td>
    <td class="num"><?= number_format($r['avg_score']) ?></td>
    <td class="num" style="color:#fff"><?= number_format($r['top_score']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Daily Activity — named only (last 30 days)</h2>
<table>
  <tr><th>Date</th><th class="num">Rounds</th><th class="num">Players</th></tr>
  <?php foreach($daily as $d): ?>
  <tr>
    <td><?= $d['day'] ?></td>
    <td class="num"><?= $d['rounds'] ?></td>
    <td class="num"><?= $d['players'] ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h2>Recent Named Rounds (last 50)</h2>
<table>
  <tr><th>Time</th><th>Name</th><th class="num">Score</th><th class="num">Wave</th></tr>
  <?php foreach($recent as $r): ?>
  <tr>
    <td style="color:#445"><?= $r['created'] ?></td>
    <td><?= htmlspecialchars($r['name']) ?></td>
    <td class="num" style="color:#fff"><?= number_format($r['score']) ?></td>
    <td class="num wnb"><?= $r['wave'] ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

</body>
</html>
