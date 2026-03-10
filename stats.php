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

// ---- Queries ----
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
  .cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:8px}
  .card{background:#111827;border:1px solid #1e2d45;border-radius:8px;padding:14px 20px;min-width:130px}
  .card .val{font-size:26px;font-weight:900;color:#fff}
  .card .lbl{font-size:11px;color:#556;margin-top:2px}
  table{width:100%;border-collapse:collapse;margin-bottom:8px}
  th{text-align:left;color:#4aaeff;font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:6px 10px;border-bottom:1px solid #1e2d45}
  td{padding:6px 10px;border-bottom:1px solid #111827;color:#ccd}
  tr:hover td{background:#111827}
  .badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:bold}
  .w1{color:#4aaeff}.w2{color:#ff8844}.w3{color:#ff44ff}.wnb{color:#ffcc44}
  .num{text-align:right}
  a{color:#4aaeff;text-decoration:none}
</style>
</head>
<body>
<h1>Kipod Barzel — Stats Dashboard</h1>

<h2>Overview</h2>
<div class="cards">
  <div class="card"><div class="val"><?= $totals['total_rounds'] ?></div><div class="lbl">Total Rounds</div></div>
  <div class="card"><div class="val"><?= $totals['unique_players'] ?></div><div class="lbl">Unique Players</div></div>
  <div class="card"><div class="val"><?= number_format($totals['top_score']) ?></div><div class="lbl">Top Score</div></div>
  <div class="card"><div class="val"><?= number_format($totals['avg_score']) ?></div><div class="lbl">Avg Score</div></div>
  <div class="card"><div class="val"><?= $totals['max_wave'] ?></div><div class="lbl">Highest Wave</div></div>
  <div class="card"><div class="val"><?= $totals['avg_wave'] ?></div><div class="lbl">Avg Wave</div></div>
</div>
<p style="color:#445;font-size:12px">First game: <?= $totals['first_game'] ?>  ·  Last game: <?= $totals['last_game'] ?></p>

<h2>Per Player</h2>
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

<h2>Rounds by Wave Reached</h2>
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

<h2>Daily Activity (last 30 days)</h2>
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

<h2>Recent Rounds (last 50)</h2>
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

</body>
</html>
