<?php
// =====================================================================
//  Iron Dome – Private Stats Dashboard
//  Protected by HTTP Basic Auth — password set in config.php
// =====================================================================

require __DIR__ . '/config.php';

// ---- Auth ----
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW']   ?? '';
if ($user !== 'admin' || $pass !== STATS_PASS) {
    header('WWW-Authenticate: Basic realm="Kipod Stats"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Unauthorized';
    exit;
}

// ---- DB ----
$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ---- Period filter ----
$period = isset($_GET['period']) ? (int)$_GET['period'] : 0;
if ($period === 1) {
    $w            = "WHERE DATE(created) = CURDATE()";
    $ws           = "WHERE DATE(s.created) = CURDATE()";
    $wg           = "WHERE DATE(g.created) = CURDATE()";
    $period_label = 'Today';
} elseif ($period === 7) {
    $w            = "WHERE created >= NOW() - INTERVAL 7 DAY";
    $ws           = "WHERE s.created >= NOW() - INTERVAL 7 DAY";
    $wg           = "WHERE g.created >= NOW() - INTERVAL 7 DAY";
    $period_label = 'Last 7 Days';
} elseif ($period === 30) {
    $w            = "WHERE created >= NOW() - INTERVAL 30 DAY";
    $ws           = "WHERE s.created >= NOW() - INTERVAL 30 DAY";
    $wg           = "WHERE g.created >= NOW() - INTERVAL 30 DAY";
    $period_label = 'Last 30 Days';
} else {
    $period       = 0;
    $w            = "";
    $ws           = "";
    $wg           = "";
    $period_label = 'All Time';
}

// ---- Check if games table exists ----
$has_games = false;
try {
    $pdo->query("SELECT 1 FROM games LIMIT 1");
    $has_games = true;
} catch (Exception $e) {}

// ---- Queries: scores table ----
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
    $w
")->fetch();

$per_player = $pdo->query("
    SELECT s.name,
        COUNT(g.id)          AS total_rounds,
        MAX(s.score)         AS best_score,
        ROUND(AVG(s.score))  AS avg_score,
        MAX(s.wave)          AS best_wave,
        MIN(s.created)       AS first_seen,
        MAX(s.created)       AS last_seen
    FROM scores s
    LEFT JOIN games g ON g.player_id = s.player_id AND s.player_id IS NOT NULL
    $ws
    GROUP BY s.name
    ORDER BY best_score DESC
")->fetchAll();

$recent = $pdo->query("
    SELECT name, score, wave, created
    FROM scores
    $w
    ORDER BY created DESC
    LIMIT 50
")->fetchAll();

$by_wave = $pdo->query("
    SELECT wave, COUNT(*) AS rounds, ROUND(AVG(score)) AS avg_score, MAX(score) AS top_score
    FROM scores
    $w
    GROUP BY wave
    ORDER BY wave ASC
")->fetchAll();

$daily = $pdo->query("
    SELECT DATE(created) AS day, COUNT(*) AS rounds, COUNT(DISTINCT name) AS players
    FROM scores
    $w
    GROUP BY DATE(created)
    ORDER BY day DESC
    LIMIT 30
")->fetchAll();

// ---- Queries: games table ----
$game_totals       = null;
$daily_games       = [];
$recent_games      = [];
$by_wave_games     = [];
$rounds_per_player = [];
$utm_breakdown     = [];
$source_breakdown  = [];
$visit_breakdown   = [];

if ($has_games) {
    $game_totals = $pdo->query("
        SELECT
            COUNT(*)                    AS total_games,
            SUM(named)                  AS named_games,
            COUNT(*) - SUM(named)       AS anon_games,
            ROUND(AVG(score))           AS avg_score,
            MAX(score)                  AS top_score,
            MAX(wave)                   AS max_wave,
            ROUND(AVG(wave),1)          AS avg_wave,
            MIN(created)                AS first_game,
            MAX(created)                AS last_game
        FROM games
        $w
    ")->fetch();

    $daily_games = $pdo->query("
        SELECT DATE(created) AS day,
            COUNT(*)                AS total,
            SUM(named)              AS named,
            COUNT(*) - SUM(named)   AS anon
        FROM games
        $w
        GROUP BY DATE(created)
        ORDER BY day DESC
        LIMIT 30
    ")->fetchAll();

    $by_wave_games = $pdo->query("
        SELECT wave,
            COUNT(*) AS total,
            SUM(named) AS named,
            COUNT(*) - SUM(named) AS anon,
            ROUND(AVG(score)) AS avg_score,
            MAX(score) AS top_score
        FROM games
        $w
        GROUP BY wave
        ORDER BY wave ASC
    ")->fetchAll();

    $recent_games = $pdo->query("
        SELECT g.score, g.wave, g.named, g.created, g.utm, g.source,
               s.name
        FROM games g
        LEFT JOIN scores s ON s.player_id = g.player_id AND g.player_id IS NOT NULL
        $wg
        ORDER BY g.created DESC
        LIMIT 50
    ")->fetchAll();

    $rounds_per_player = $pdo->query("
        SELECT s.name,
               COUNT(g.id)                                    AS total_rounds,
               COALESCE(SUM(g.named), 0)                     AS named_rounds,
               COUNT(g.id) - COALESCE(SUM(g.named), 0)       AS anon_rounds,
               s.score                                        AS best_score,
               s.wave                                         AS best_wave,
               ROUND(AVG(g.score))                            AS avg_score,
               MAX(g.source)                                  AS source
        FROM scores s
        LEFT JOIN games g ON g.player_id = s.player_id AND s.player_id IS NOT NULL
        GROUP BY s.id, s.name, s.score, s.wave
        ORDER BY s.score DESC
    ")->fetchAll();

    // Visit pings (page loads with UTM, before game starts)
    try {
        $visit_breakdown = $pdo->query("
            SELECT utm AS source,
                COUNT(*) AS visits,
                COUNT(DISTINCT player_id) AS unique_visitors
            FROM visits
            $w
            GROUP BY utm
            ORDER BY visits DESC
        ")->fetchAll();
    } catch(Exception $e){}

    try {
        $utm_breakdown = $pdo->query("
            SELECT COALESCE(utm, 'direct') AS source,
                COUNT(*) AS plays,
                SUM(named) AS named
            FROM games
            $w
            GROUP BY source
            ORDER BY plays DESC
        ")->fetchAll();
    } catch (Exception $e) {}

    try {
        $source_breakdown = $pdo->query("
            SELECT COALESCE(source, 'direct') AS source,
                COUNT(*) AS plays,
                COUNT(DISTINCT player_id) AS players
            FROM games
            $w
            GROUP BY source
            ORDER BY plays DESC
        ")->fetchAll();
    } catch (Exception $e) {}
}

// ---- Helpers ----
function fmt($n) {
    return $n !== null ? number_format((int)$n) : '—';
}
function fmtf($n, $decimals = 1) {
    return $n !== null ? number_format((float)$n, $decimals) : '—';
}
function fmtDate($d) {
    if (!$d) return '—';
    return date('M j, Y', strtotime($d));
}
function fmtTime($d) {
    if (!$d) return '—';
    return date('M j, H:i', strtotime($d));
}
function utmColor($source) {
    if ($source === 'direct')                      return '#6b7280';
    if ($source === 'qr')                          return '#a855f7';
    if ($source === 'facebook')                    return '#3b82f6';
    if ($source === 'twitter' || $source === 'x')  return '#1da1f2';
    if ($source === 'instagram')                   return '#e1306c';
    if ($source === 'whatsapp')                    return '#25d366';
    return '#4aaeff';
}

// ---- Summary values (prefer games table) ----
$s_total   = $has_games ? (int)($game_totals['total_games'] ?? 0) : (int)($totals['total_rounds']  ?? 0);
$s_named   = $has_games ? (int)($game_totals['named_games'] ?? 0) : (int)($totals['unique_players'] ?? 0);
$s_anon    = $has_games ? (int)($game_totals['anon_games']  ?? 0) : 0;
$s_top     = $has_games ? (int)($game_totals['top_score']   ?? 0) : (int)($totals['top_score'] ?? 0);
$s_avg     = $has_games ? (int)($game_totals['avg_score']   ?? 0) : (int)($totals['avg_score'] ?? 0);
$s_topwave = $has_games ? (int)($game_totals['max_wave']    ?? 0) : (int)($totals['max_wave']  ?? 0);

// ---- Date range ----
$src = ($has_games && $game_totals) ? $game_totals : $totals;
$range_first = $src ? fmtDate($src['first_game']) : '—';
$range_last  = $src ? fmtDate($src['last_game'])  : '—';

// ---- Daily chart data ----
$chart_data    = $has_games ? $daily_games : $daily;
$chart_data_r  = array_reverse($chart_data);
$chart_max     = 1;
foreach ($chart_data as $row) {
    $v = $has_games ? (int)($row['total'] ?? 0) : (int)($row['rounds'] ?? 0);
    if ($v > $chart_max) $chart_max = $v;
}

// ---- UTM totals ----
$utm_total = 0;
foreach ($utm_breakdown as $row) $utm_total += (int)$row['plays'];
if ($utm_total === 0) $utm_total = 1;

// ---- Player/wave/recent row sources ----
$use_games_players = $has_games && count($rounds_per_player) > 0;
$player_rows       = $use_games_players ? $rounds_per_player : $per_player;
$use_games_waves   = $has_games && count($by_wave_games) > 0;
$wave_rows         = $use_games_waves ? $by_wave_games : $by_wave;
$use_games_recent  = $has_games && count($recent_games) > 0;
$recent_rows       = $use_games_recent ? $recent_games : $recent;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kipod Barzel — Stats</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #050510;
    --bg2:      #0a0e1a;
    --bg3:      #111827;
    --border:   #1a2535;
    --border2:  #253447;
    --accent:   #4aaeff;
    --gold:     #ffd700;
    --text:     #dde4f0;
    --muted:    #8899b0;
    --dim:      #3f5068;
    --success:  #22c55e;
    --warn:     #f59e0b;
    --anon:     #4b5a6e;
    --purple:   #a855f7;
    --red:      #ef4444;
}

html { font-size: 15px; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    line-height: 1.5;
    min-height: 100vh;
}

/* ---- Scrollbar ---- */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--bg2); }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--muted); }

/* ======= HEADER ======= */
.site-header {
    position: sticky;
    top: 0;
    z-index: 200;
    height: 56px;
    background: rgba(5,5,16,0.90);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 0 1.5rem;
}

.header-brand {
    font-size: 1.05rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: var(--accent);
    white-space: nowrap;
    flex-shrink: 0;
    text-decoration: none;
}
.header-brand span { color: var(--gold); }

.period-tabs {
    display: flex;
    gap: 3px;
}
.period-tab {
    padding: 5px 13px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--muted);
    text-decoration: none;
    border: 1px solid transparent;
    transition: color 0.15s, background 0.15s, border-color 0.15s;
    white-space: nowrap;
}
.period-tab:hover {
    color: var(--text);
    background: var(--bg3);
    border-color: var(--border2);
}
.player-limit-btn {
    background: none;
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--muted);
    font-size: 0.78rem;
    padding: 4px 12px;
    cursor: pointer;
    transition: color 0.15s, background 0.15s, border-color 0.15s;
}
.player-limit-btn:hover {
    color: var(--text);
    background: var(--bg3);
    border-color: var(--border2);
}
.period-tab.active, .player-limit-btn.active {
    color: var(--accent);
    background: rgba(74,174,255,0.1);
    border-color: rgba(74,174,255,0.4);
}

.header-range {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--dim);
    white-space: nowrap;
}

/* ======= LAYOUT ======= */
.wrap { max-width: 1340px; margin: 0 auto; padding: 1.5rem 1.25rem; }

/* ======= SUMMARY CARDS ======= */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 0.85rem;
    margin-bottom: 1.25rem;
}

.kpi-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-top: 2px solid var(--kpi-color, var(--accent));
    border-radius: 10px;
    padding: 1rem 1.1rem 0.85rem;
    transition: border-color 0.2s, transform 0.15s;
}
.kpi-card:hover {
    border-color: var(--kpi-color, var(--accent));
    transform: translateY(-1px);
}
.kpi-label {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.09em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 0.35rem;
}
.kpi-value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    color: var(--kpi-color, var(--text));
    font-variant-numeric: tabular-nums;
}
.kpi-sub {
    font-size: 0.7rem;
    color: var(--dim);
    margin-top: 0.3rem;
}

/* ======= SECTION / ACCORDION ======= */
.section {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 12px;
    margin-bottom: 0.85rem;
    overflow: hidden;
}

.section-toggle {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.95rem 1.25rem;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    transition: background 0.15s;
    color: inherit;
}
.section-toggle:hover { background: rgba(255,255,255,0.025); }

.sec-icon {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    background: rgba(74,174,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}
.sec-title {
    font-size: 0.92rem;
    font-weight: 600;
    color: var(--text);
    flex: 1;
}
.sec-badge {
    font-size: 0.69rem;
    font-weight: 600;
    padding: 2px 9px;
    border-radius: 20px;
    background: rgba(74,174,255,0.12);
    color: var(--accent);
    flex-shrink: 0;
}
.chevron {
    color: var(--dim);
    font-size: 0.65rem;
    flex-shrink: 0;
    transition: transform 0.25s ease;
}
.section.is-closed .chevron { transform: rotate(-90deg); }

.section-body {
    display: grid;
    grid-template-rows: 1fr;
    transition: grid-template-rows 0.3s ease;
    overflow: hidden;
}
.section.is-closed .section-body { grid-template-rows: 0fr; }
.section-body > div { overflow: hidden; }

.section-inner { padding: 0 1.25rem 1.25rem; }

/* ======= DIVIDER ======= */
.section-divider {
    border: none;
    border-top: 1px solid var(--border);
    margin: 0 1.25rem 1.1rem;
}

/* ======= TABLES ======= */
.tbl-wrap { overflow-x: auto; }

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.83rem;
}

thead th {
    padding: 0.5rem 0.85rem;
    text-align: left;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
    background: transparent;
}
thead th.r { text-align: right; }
thead th.c { text-align: center; }

tbody tr {
    border-bottom: 1px solid rgba(26,37,53,0.7);
    transition: background 0.1s;
}
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: rgba(74,174,255,0.035); }

tbody td {
    padding: 0.6rem 0.85rem;
    vertical-align: middle;
    white-space: nowrap;
}
tbody td.r    { text-align: right; }
tbody td.c    { text-align: center; }
tbody td.muted { color: var(--muted); }
tbody td.dim   { color: var(--dim); font-size: 0.78rem; }

.rank-num {
    color: var(--dim);
    font-size: 0.78rem;
    font-variant-numeric: tabular-nums;
}
.rank-num.g1 { color: var(--gold); font-weight: 700; }
.rank-num.g2 { color: #b0b8c8; font-weight: 600; }
.rank-num.g3 { color: #a07840; font-weight: 600; }

.player-name { font-weight: 600; }
.score-val {
    color: var(--accent);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.gold-val {
    color: var(--gold);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.wave-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 22px;
    padding: 0 7px;
    background: rgba(245,158,11,0.1);
    color: var(--warn);
    border-radius: 5px;
    font-size: 0.78rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.anon-label { color: var(--anon); font-style: italic; font-size: 0.8rem; }
.utm-tag { margin-left: 5px; font-size: 0.72rem; color: var(--muted); background: var(--bg3); border-radius: 4px; padding: 1px 5px; }

.dot {
    display: inline-block;
    width: 7px; height: 7px;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
    flex-shrink: 0;
}
.dot-named { background: var(--success); }
.dot-anon  { background: var(--anon); }

/* ======= BAR CHART ======= */
.chart-outer { padding-bottom: 0.5rem; }

.barchart-scroll {
    overflow-x: auto;
    padding-bottom: 4px;
}
.barchart-canvas {
    display: flex;
    align-items: flex-end;
    gap: 4px;
    height: 90px;
    padding: 0 2px 0;
    min-width: max-content;
}
.bar-grp {
    display: flex;
    align-items: flex-end;
    gap: 1px;
    flex-shrink: 0;
    position: relative;
}
.bar {
    width: 9px;
    border-radius: 2px 2px 0 0;
    min-height: 2px;
}
.bar-total { background: rgba(74,174,255,0.3); }
.bar-named { background: rgba(34,197,94,0.7); }
.bar-anon  { background: rgba(75,90,110,0.65); }
.bar-plain { background: rgba(74,174,255,0.65); }

.barchart-axis {
    display: flex;
    gap: 4px;
    padding: 3px 2px 2px;
    min-width: max-content;
}
.bar-axis-grp {
    display: flex;
    gap: 1px;
    flex-shrink: 0;
}
.bar-lbl {
    width: 9px;
    font-size: 0.55rem;
    color: var(--dim);
    text-align: center;
    flex-shrink: 0;
    overflow: visible;
}
/* extra space for groups */
.bar-grp + .bar-grp { margin-left: 0; }

.chart-legend {
    display: flex;
    gap: 1.1rem;
    margin-top: 0.75rem;
    flex-wrap: wrap;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.74rem;
    color: var(--muted);
}
.legend-swatch {
    width: 11px; height: 11px;
    border-radius: 3px;
}

/* ======= UTM BARS ======= */
.utm-bar-bg {
    height: 5px;
    background: var(--bg3);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 4px;
    min-width: 80px;
}
.utm-bar-fill {
    height: 100%;
    border-radius: 3px;
}
.src-label {
    display: flex;
    align-items: center;
    gap: 7px;
    font-weight: 600;
}
.src-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

/* ======= EMPTY ======= */
.empty-state {
    text-align: center;
    color: var(--dim);
    padding: 2.5rem 1rem;
    font-size: 0.83rem;
}

/* ======= FOOTER ======= */
.site-footer {
    text-align: center;
    padding: 2rem 0 1.5rem;
    font-size: 0.73rem;
    color: var(--dim);
}

/* ======= RESPONSIVE ======= */
@media (max-width: 1100px) {
    .summary-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 720px) {
    .site-header { flex-wrap: wrap; height: auto; padding: 0.6rem 1rem; gap: 0.5rem; }
    .header-range { display: none; }
    .wrap { padding: 1rem 0.85rem; }
    .summary-grid { grid-template-columns: repeat(2, 1fr); gap: 0.6rem; }
    .kpi-value { font-size: 1.5rem; }
    .bar { width: 6px; }
    .bar-lbl { width: 6px; overflow: visible; }
}
@media (max-width: 420px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .period-tab { padding: 4px 9px; font-size: 0.74rem; }
}
</style>
</head>
<body>

<!-- ======= HEADER ======= -->
<header class="site-header">
    <a class="header-brand" href="?period=<?= $period ?>">Kipod <span>Barzel</span> — Stats</a>
    <nav class="period-tabs">
        <a href="?period=1"  class="period-tab <?= $period === 1  ? 'active' : '' ?>">Today</a>
        <a href="?period=7"  class="period-tab <?= $period === 7  ? 'active' : '' ?>">7 days</a>
        <a href="?period=30" class="period-tab <?= $period === 30 ? 'active' : '' ?>">30 days</a>
        <a href="?period=0"  class="period-tab <?= $period === 0  ? 'active' : '' ?>">All time</a>
    </nav>
    <div class="header-range">
        <?= htmlspecialchars($period_label) ?>&nbsp;&nbsp;·&nbsp;&nbsp;<?= $range_first ?> – <?= $range_last ?>
    </div>
    <button id="toggle-all-btn" onclick="toggleAll()" style="background:none;border:1px solid var(--border);border-radius:6px;color:var(--muted);font-size:0.78rem;padding:4px 12px;cursor:pointer;white-space:nowrap">Open All</button>
</header>

<!-- ======= MAIN ======= -->
<main class="wrap">

    <!-- SUMMARY CARDS -->
    <div class="summary-grid">
        <div class="kpi-card" style="--kpi-color: var(--accent)">
            <div class="kpi-label">Total Plays</div>
            <div class="kpi-value"><?= fmt($s_total) ?></div>
            <div class="kpi-sub"><?= htmlspecialchars($period_label) ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color: var(--success)">
            <div class="kpi-label">Named</div>
            <div class="kpi-value"><?= fmt($s_named) ?></div>
            <div class="kpi-sub"><?= $s_total > 0 ? round(100 * $s_named / $s_total) : 0 ?>% of plays</div>
        </div>
        <div class="kpi-card" style="--kpi-color: var(--anon)">
            <div class="kpi-label">Anonymous</div>
            <div class="kpi-value" style="color: var(--muted)"><?= fmt($s_anon) ?></div>
            <div class="kpi-sub"><?= $s_total > 0 ? round(100 * $s_anon / $s_total) : 0 ?>% of plays</div>
        </div>
        <div class="kpi-card" style="--kpi-color: var(--gold)">
            <div class="kpi-label">Top Score</div>
            <div class="kpi-value" style="color: var(--gold)"><?= fmt($s_top) ?></div>
            <div class="kpi-sub">all players</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #60a5fa">
            <div class="kpi-label">Avg Score</div>
            <div class="kpi-value" style="color: #60a5fa"><?= fmt($s_avg) ?></div>
            <div class="kpi-sub">per game</div>
        </div>
        <div class="kpi-card" style="--kpi-color: var(--warn)">
            <div class="kpi-label">Top Wave</div>
            <div class="kpi-value" style="color: var(--warn)"><?= fmt($s_topwave) ?></div>
            <div class="kpi-sub">highest reached</div>
        </div>
    </div>

    <!-- ===== DAILY ACTIVITY ===== -->
    <div class="section is-closed" id="sec-daily">
        <button class="section-toggle" onclick="toggleSec('sec-daily')">
            <span class="sec-icon">📈</span>
            <span class="sec-title">Daily Activity</span>
            <span class="sec-badge"><?= count($chart_data) ?> days</span>
            <span class="chevron">&#9660;</span>
        </button>
        <div class="section-body"><div>
        <div class="section-inner">
            <?php if (count($chart_data) > 0): ?>
            <!-- Bar chart -->
            <div class="chart-outer">
            <div class="barchart-scroll">
                <div class="barchart-canvas" id="dailyChart">
                <?php foreach ($chart_data_r as $row):
                    if ($has_games) {
                        $total = (int)($row['total'] ?? 0);
                        $named = (int)($row['named'] ?? 0);
                        $anon  = (int)($row['anon']  ?? 0);
                    } else {
                        $total = (int)($row['rounds'] ?? 0);
                        $named = 0;
                        $anon  = 0;
                    }
                    $ph_total = $chart_max > 0 ? max(2, round(90 * $total / $chart_max)) : 2;
                    $ph_named = $chart_max > 0 ? max(2, round(90 * $named / $chart_max)) : 2;
                    $ph_anon  = $chart_max > 0 ? max(2, round(90 * $anon  / $chart_max)) : 2;
                    $day_label = isset($row['day']) ? substr($row['day'], 5) : '';
                ?>
                    <div class="bar-grp" title="<?= htmlspecialchars($row['day'] ?? '') ?>: <?= $total ?> plays">
                        <?php if ($has_games): ?>
                        <div class="bar bar-total" style="height:<?= $ph_total ?>px"></div>
                        <div class="bar bar-named" style="height:<?= $ph_named ?>px"></div>
                        <div class="bar bar-anon"  style="height:<?= $ph_anon  ?>px"></div>
                        <?php else: ?>
                        <div class="bar bar-plain" style="height:<?= $ph_total ?>px"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <div class="barchart-axis">
                <?php foreach ($chart_data_r as $row):
                    $day_label = isset($row['day']) ? substr($row['day'], 5) : '';
                    $num_bars = $has_games ? 3 : 1;
                ?>
                    <div class="bar-axis-grp">
                    <?php for ($bi = 0; $bi < $num_bars - 1; $bi++): ?>
                        <div class="bar-lbl"></div>
                    <?php endfor; ?>
                        <div class="bar-lbl"><?= htmlspecialchars($day_label) ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            </div>
            <?php if ($has_games): ?>
            <div class="chart-legend">
                <div class="legend-item"><div class="legend-swatch" style="background:rgba(74,174,255,0.3)"></div>Total</div>
                <div class="legend-item"><div class="legend-swatch" style="background:rgba(34,197,94,0.7)"></div>Named</div>
                <div class="legend-item"><div class="legend-swatch" style="background:rgba(75,90,110,0.65)"></div>Anonymous</div>
            </div>
            <?php endif; ?>

            <hr class="section-divider" style="margin: 1.1rem 0 0.9rem;">

            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="r">Plays</th>
                            <?php if ($has_games): ?>
                            <th class="r">Named</th>
                            <th class="r">Anon</th>
                            <?php else: ?>
                            <th class="r">Players</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($chart_data as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['day'] ?? '') ?></td>
                            <?php if ($has_games): ?>
                            <td class="r score-val"><?= fmt($row['total']) ?></td>
                            <td class="r" style="color:var(--success)"><?= fmt($row['named']) ?></td>
                            <td class="r muted"><?= fmt($row['anon']) ?></td>
                            <?php else: ?>
                            <td class="r score-val"><?= fmt($row['rounds']) ?></td>
                            <td class="r muted"><?= fmt($row['players']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">No daily data available yet.</div>
            <?php endif; ?>
        </div>
        </div></div>
    </div>

    <!-- ===== TRAFFIC SOURCES ===== -->
    <?php if ($has_games && count($utm_breakdown) > 0): ?>
    <div class="section is-closed" id="sec-utm">
        <button class="section-toggle" onclick="toggleSec('sec-utm')">
            <span class="sec-icon">🔗</span>
            <span class="sec-title">Traffic Sources</span>
            <span class="sec-badge"><?= count($utm_breakdown) ?> sources</span>
            <span class="chevron">&#9660;</span>
        </button>
        <div class="section-body"><div>
        <div class="section-inner">
            <div class="tbl-wrap">
                <table>
                    <?php
                    // Build visit lookup keyed by source
                    $visit_map = [];
                    foreach($visit_breakdown as $vr) $visit_map[$vr['source']] = $vr;
                    $has_visits = count($visit_breakdown) > 0;
                    ?>
                    <thead>
                        <tr>
                            <th>Source</th>
                            <?php if($has_visits): ?>
                            <th class="r">Scans</th>
                            <th class="r">Unique</th>
                            <?php endif; ?>
                            <th class="r">Plays</th>
                            <th class="r">Named</th>
                            <?php if($has_visits): ?>
                            <th class="r">Conversion</th>
                            <?php endif; ?>
                            <th class="r">Share</th>
                            <th style="min-width:120px; padding-left:1rem">Bar</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($utm_breakdown as $row):
                        $src      = $row['source'];
                        $src_name = htmlspecialchars($src);
                        $plays    = (int)$row['plays'];
                        $named    = (int)$row['named'];
                        $pct      = round(100 * $plays / $utm_total, 1);
                        $color    = utmColor($src);
                        $vr       = $visit_map[$src] ?? null;
                        $scans    = $vr ? (int)$vr['visits'] : null;
                        $uniq     = $vr ? (int)$vr['unique_visitors'] : null;
                        $conv     = ($scans && $scans > 0) ? round(100 * $plays / $scans) : null;
                    ?>
                        <tr>
                            <td>
                                <span class="src-label">
                                    <span class="src-dot" style="background:<?= $color ?>"></span>
                                    <?= $src_name ?>
                                </span>
                            </td>
                            <?php if($has_visits): ?>
                            <td class="r score-val"><?= $scans !== null ? fmt($scans) : '<span class="muted">—</span>' ?></td>
                            <td class="r muted"><?= $uniq  !== null ? fmt($uniq)  : '<span class="muted">—</span>' ?></td>
                            <?php endif; ?>
                            <td class="r score-val"><?= fmt($plays) ?></td>
                            <td class="r" style="color:var(--success)"><?= fmt($named) ?></td>
                            <?php if($has_visits): ?>
                            <td class="r" style="color:<?= $conv !== null && $conv >= 50 ? 'var(--gold)' : 'var(--muted)' ?>">
                                <?= $conv !== null ? $conv.'%' : '<span class="muted">—</span>' ?>
                            </td>
                            <?php endif; ?>
                            <td class="r muted"><?= $pct ?>%</td>
                            <td style="padding-left:1rem">
                                <div class="utm-bar-bg">
                                    <div class="utm-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($source_breakdown) > 0):
                $src_total = array_sum(array_column($source_breakdown, 'plays'));
                if (!$src_total) $src_total = 1;
            ?>
            <h3 style="margin:20px 0 8px;font-size:0.85rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Acquisition Source (first-ever visit)</h3>
            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th class="r">Players</th>
                            <th class="r">Plays</th>
                            <th class="r">Share</th>
                            <th style="min-width:120px;padding-left:1rem">Bar</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($source_breakdown as $row):
                        $src   = $row['source'];
                        $plays = (int)$row['plays'];
                        $pct   = round(100 * $plays / $src_total, 1);
                        $color = utmColor($src);
                    ?>
                        <tr>
                            <td><span class="src-label"><span class="src-dot" style="background:<?= $color ?>"></span><?= htmlspecialchars($src) ?></span></td>
                            <td class="r score-val"><?= fmt($row['players']) ?></td>
                            <td class="r muted"><?= fmt($plays) ?></td>
                            <td class="r muted"><?= $pct ?>%</td>
                            <td style="padding-left:1rem"><div class="utm-bar-bg"><div class="utm-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
        </div></div>
    </div>
    <?php endif; ?>

    <!-- ===== PLAYERS ===== -->
    <div class="section is-closed" id="sec-players">
        <button class="section-toggle" onclick="toggleSec('sec-players')">
            <span class="sec-icon">👤</span>
            <span class="sec-title">Players</span>
            <span class="sec-badge"><?= count($player_rows) ?> players</span>
            <span class="chevron">&#9660;</span>
        </button>
        <div class="section-body"><div>
        <div class="section-inner">
            <?php if (count($player_rows) > 0): ?>
            <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">
                <?php foreach([10,20,40,0] as $n): ?>
                <button class="player-limit-btn" data-n="<?= $n ?>" onclick="setPlayerLimit(<?= $n ?>)">
                    <?= $n === 0 ? 'All' : 'Top '.$n ?>
                </button>
                <?php endforeach; ?>
                <span style="color:var(--muted);font-size:0.78rem;line-height:2;margin-left:4px"><?= count($player_rows) ?> total</span>
            </div>
            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th>Player</th>
                            <th class="r">Rounds</th>
                            <?php if ($use_games_players): ?>
                            <th class="r">Named</th>
                            <th class="r">Anon</th>
                            <?php endif; ?>
                            <th class="r">Best Score</th>
                            <th class="r">Avg Score</th>
                            <th class="c">Best Wave</th>
                            <?php if ($use_games_players): ?>
                            <th>Source</th>
                            <?php else: ?>
                            <th>First Seen</th>
                            <th>Last Seen</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="players-tbody">
                    <?php foreach ($player_rows as $i => $row):
                        $rank = $i + 1;
                        $rk   = $rank === 1 ? 'g1' : ($rank === 2 ? 'g2' : ($rank === 3 ? 'g3' : ''));
                    ?>
                        <tr>
                            <td><span class="rank-num <?= $rk ?>"><?= $rank ?></span></td>
                            <td class="player-name"><?= htmlspecialchars($row['name'] ?? '—') ?></td>
                            <td class="r score-val"><?= fmt($row['total_rounds'] ?? 0) ?></td>
                            <?php if ($use_games_players): ?>
                            <td class="r" style="color:var(--success)"><?= fmt($row['named_rounds'] ?? 0) ?></td>
                            <td class="r muted"><?= fmt($row['anon_rounds'] ?? 0) ?></td>
                            <?php endif; ?>
                            <td class="r gold-val"><?= fmt($row['best_score'] ?? 0) ?></td>
                            <td class="r muted"><?= fmt($row['avg_score'] ?? 0) ?></td>
                            <td class="c"><span class="wave-pill"><?= (int)($row['best_wave'] ?? 0) ?></span></td>
                            <?php if ($use_games_players): ?>
                            <td><?php if (!empty($row['source'])): ?><span class="utm-tag" style="color:<?= utmColor($row['source']) ?>"><?= htmlspecialchars($row['source']) ?></span><?php else: ?><span class="dim">—</span><?php endif; ?></td>
                            <?php else: ?>
                            <td class="dim"><?= fmtDate($row['first_seen'] ?? '') ?></td>
                            <td class="dim"><?= fmtDate($row['last_seen'] ?? '') ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">No player data available yet.</div>
            <?php endif; ?>
        </div>
        </div></div>
    </div>

    <!-- ===== RECENT PLAYS ===== -->
    <div class="section is-closed" id="sec-recent">
        <button class="section-toggle" onclick="toggleSec('sec-recent')">
            <span class="sec-icon">🕐</span>
            <span class="sec-title">Recent Plays</span>
            <span class="sec-badge"><?= count($recent_rows) ?> entries</span>
            <span class="chevron">&#9660;</span>
        </button>
        <div class="section-body"><div>
        <div class="section-inner">
            <?php if (count($recent_rows) > 0): ?>
            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th class="r">Score</th>
                            <th class="c">Wave</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_rows as $row):
                        $is_named = $use_games_recent ? (bool)($row['named'] ?? false) : true;
                        $name     = $row['name'] ?? null;
                    ?>
                        <tr>
                            <td>
                                <span class="dot <?= $is_named ? 'dot-named' : 'dot-anon' ?>"></span>
                                <?php if ($name): ?>
                                    <span class="player-name"><?= htmlspecialchars($name) ?></span>
                                <?php else: ?>
                                    <span class="anon-label">anonymous</span>
                                <?php endif; ?>
                                <?php if (!empty($row['utm'])): ?>
                                    <span class="utm-tag"><?= htmlspecialchars($row['utm']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($row['source']) && $row['source'] !== ($row['utm'] ?? '')): ?>
                                    <span class="utm-tag" style="color:<?= utmColor($row['source']) ?>;opacity:0.7" title="acquired via <?= htmlspecialchars($row['source']) ?>">↩<?= htmlspecialchars($row['source']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="r score-val"><?= fmt($row['score'] ?? 0) ?></td>
                            <td class="c"><span class="wave-pill"><?= (int)($row['wave'] ?? 0) ?></span></td>
                            <td class="dim"><?= fmtTime($row['created'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">No recent plays yet.</div>
            <?php endif; ?>
        </div>
        </div></div>
    </div>

    <!-- ===== WAVE ANALYSIS ===== -->
    <div class="section is-closed" id="sec-waves">
        <button class="section-toggle" onclick="toggleSec('sec-waves')">
            <span class="sec-icon">🌊</span>
            <span class="sec-title">Wave Analysis</span>
            <span class="sec-badge"><?= count($wave_rows) ?> waves</span>
            <span class="chevron">&#9660;</span>
        </button>
        <div class="section-body"><div>
        <div class="section-inner">
            <?php if (count($wave_rows) > 0): ?>
            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Wave</th>
                            <th class="r">Games</th>
                            <?php if ($use_games_waves): ?>
                            <th class="r">Named</th>
                            <th class="r">Anon</th>
                            <?php endif; ?>
                            <th class="r">Avg Score</th>
                            <th class="r">Top Score</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($wave_rows as $row):
                        $gc = $use_games_waves ? (int)($row['total'] ?? 0) : (int)($row['rounds'] ?? 0);
                    ?>
                        <tr>
                            <td><span class="wave-pill"><?= (int)($row['wave'] ?? 0) ?></span></td>
                            <td class="r score-val"><?= fmt($gc) ?></td>
                            <?php if ($use_games_waves): ?>
                            <td class="r" style="color:var(--success)"><?= fmt($row['named'] ?? 0) ?></td>
                            <td class="r muted"><?= fmt($row['anon'] ?? 0) ?></td>
                            <?php endif; ?>
                            <td class="r muted"><?= fmt($row['avg_score'] ?? 0) ?></td>
                            <td class="r gold-val"><?= fmt($row['top_score'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">No wave data available yet.</div>
            <?php endif; ?>
        </div>
        </div></div>
    </div>

    <div class="site-footer">
        Kipod Barzel Stats &nbsp;·&nbsp; Generated <?= date('Y-m-d H:i:s') ?>
    </div>

</main>

<script>
function toggleSec(id) {
    var el = document.getElementById(id);
    if (el) el.classList.toggle('is-closed');
}

// Open / Close all
var _allOpen = false;
function toggleAll() {
    _allOpen = !_allOpen;
    document.querySelectorAll('.section').forEach(function(s) {
        s.classList.toggle('is-closed', !_allOpen);
    });
    document.getElementById('toggle-all-btn').textContent = _allOpen ? 'Close All' : 'Open All';
}

// Players pagination
var _playerLimit = 10;
function setPlayerLimit(n) {
    _playerLimit = n;
    document.querySelectorAll('.player-limit-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.n == n);
    });
    var rows = document.querySelectorAll('#players-tbody tr');
    rows.forEach(function(r, i) {
        r.style.display = (n === 0 || i < n) ? '' : 'none';
    });
}
document.addEventListener('DOMContentLoaded', function() { setPlayerLimit(10); });
</script>

</body>
</html>
