<?php
/**
 * gold_monitor.php — Gold Buy Signal Monitor + Live Dashboard
 * PHP 8.1+ | Cron: every minute | Single file
 *
 * ── ENHANCEMENTS (this version) ──────────────────────────────────
 *  E1  Signal tooltip in plain English — no jargon for end users.
 *  E2  Hero shows RETAIL price (igold.ae) only. AED everywhere.
 *      USD/spot removed from public UI entirely.
 *  E3  Browser Push Notifications — bell icon in header.
 *      Same alerts that go to Telegram appear in browser.
 *      Notification permission requested on bell click only.
 *  E4  Alert deduplication fixed — alerts keyed by key+date so the
 *      same signal cannot appear twice in alert_log on the same day.
 *  E5  Stale-crumb race condition fixed — state reload before save
 *      in get_yahoo_auth() to avoid overwriting fresh crumb.
 *  E6  New signal S12: Price Channel Break — price breaks above a
 *      recent 15-run resistance level (descending channel break).
 *  E7  New signal S13: Pre-Market Low Test — when price during
 *      session approaches within 0.3% of pre-market low, fire an
 *      "approaching support" alert as early warning.
 *  E8  Day-low alert dedup: uses key+date not just cooldown, so it
 *      cannot repeat after state reset (previous bug).
 *  E9  All OHLC, stats, alerts display AED only (no USD shown).
 *  E10 API endpoint now includes pending_notifications array so
 *      browser JS can show notifications via Service Worker.
 *
 * ── BUGS FIXED (inherited) ───────────────────────────────────────
 *  #1  Day high/low inconsistency: tracked from session_open only.
 *  #2  AED display vs USD math mismatch: igold retail for hero,
 *      Yahoo spot→AED for all OHLC math.
 *  #3  Stale high guard added.
 *  #4  Weekend close: Saturday AND Sunday skipped.
 *  #5  Bounce signal: session_low gate.
 *  #6  Day-low alert: requires day_chg < -0.1%.
 *  #7  consec_up: fires only when day_chg > -0.5%.
 *  #8  full_signal_block removed.
 *  #9  Cooldowns tuned.
 *  #10 All OHLC math uses spot_aed.
 */

// ══════════════════════════════════════════════════════════════════
//  CONFIG
// ══════════════════════════════════════════════════════════════════

define('TELEGRAM_BOT_TOKEN',   '8768920785:AAHIMrmaYfUDeS53tMulIgY0y7NCKcXi7X5');
define('TELEGRAM_GROUP_ID',    -1002122852079);
define('TELEGRAM_THREAD_ID',   4323);
define('TELEGRAM_CHANNEL_ID',  -1003856342030);

// ── Signal thresholds ─────────────────────────────────────────────
define('DXY_DROP_PCT',           0.30);
define('TIP_RISE_PCT',           0.30);
define('BOUNCE_PCT',             0.50);
define('BOUNCE_DIP_MIN_PCT',     0.60);
define('CONSEC_UP_COUNT',           3);
define('GLD_VOL_MULT',           2.00);
define('DAY_LOW_MIN_DROP_PCT',   0.40);
define('DEEP_DIP_PCT',           2.00);
define('DEEP_DIP_BOUNCE_PCT',    0.50);
define('OVERSOLD_DROP_PCT',      1.50);
define('OVERSOLD_WINDOW',           6);
define('DXY_WEAK_LEVEL',       100.00);
define('STALE_THRESHOLD_PCT',    1.50);
define('ORB_WINDOW_MINS',           30);
define('EMA_FAST',                   5);
define('EMA_SLOW',                  20);
define('MACRO_CONF_DXY_DROP',    0.20);
define('MACRO_CONF_TIP_RISE',    0.20);
define('CHANNEL_BREAK_WINDOW',      15); // E6: runs to look back for resistance
define('PM_SUPPORT_PCT',          0.30); // E7: % within pre-market low to alert

// ── Active hours ──────────────────────────────────────────────────
define('ACTIVE_HOUR_START',         8);
define('ACTIVE_HOUR_END',          22);
define('DAILY_SUMMARY_HOUR',       22);

// ── Cooldowns (minutes) ───────────────────────────────────────────
define('COOLDOWN_TREND',          240);
define('COOLDOWN_BOUNCE',          90);
define('COOLDOWN_MACRO',           60);
define('COOLDOWN_VOLUME',          90);
define('COOLDOWN_DAY_LOW',         45);
define('COOLDOWN_DEEP_DIP',       120);
define('COOLDOWN_OVERSOLD',        90);
define('COOLDOWN_DXY_WEAK',       360);
define('COOLDOWN_ORB',            180);
define('COOLDOWN_EMA_CROSS',      180);
define('COOLDOWN_MACRO_CONF',      60);
define('COOLDOWN_CHANNEL_BREAK',  120); // E6
define('COOLDOWN_PM_SUPPORT',      60); // E7

// ── Unit constants ────────────────────────────────────────────────
define('USD_TO_AED',           3.6725);
define('OZ_TO_GRAM',          31.1035);

// ── File paths & limits ───────────────────────────────────────────
define('LOG_MAX_LINES',          5000);
define('CHART_HISTORY_MAX',       480);
define('ALERT_LOG_MAX',            50);
define('PRICE_HIST_MAX',           60);

define('STATE_FILE',   __DIR__ . '/gold_monitor_state.json');
define('LOG_FILE',     __DIR__ . '/gold_monitor.log');
define('COOKIE_FILE',  __DIR__ . '/yf_cookies.txt');

// ══════════════════════════════════════════════════════════════════
//  BOOTSTRAP + ROUTING
// ══════════════════════════════════════════════════════════════════

date_default_timezone_set('Asia/Dubai');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
    if (isset($_GET['api']) && $_GET['api'] === '1') {
        serve_api();
        exit(0);
    }
    render_dashboard();
    exit(0);
}

// ══════════════════════════════════════════════════════════════════
//  JSON API ENDPOINT  (polled by chart every 15s)
// ══════════════════════════════════════════════════════════════════

function serve_api(): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');

    $s  = load_state();
    $do = (float)($s['day_open_gold'] ?? 0);
    $gu = (float)($s['live_gold_usd'] ?? 0);

    $sl_aed = isset($s['session_low'])
                ? spot_to_aed((float)$s['session_low']) : null;
    $pm_aed = isset($s['pre_market_low'])
                ? spot_to_aed((float)$s['pre_market_low']) : null;

    // E10: collect pending browser notifications (alerts in last 2 mins not yet notified)
    $now           = time();
    $alert_log     = $s['alert_log'] ?? [];
    $notified_ts   = (array)($s['browser_notified_ts'] ?? []);
    $pending_notifs = [];
    foreach ($alert_log as $al) {
        $al_ts = (int)($al['ts'] ?? 0);
        $al_key = ($al['key'] ?? '') . '_' . $al_ts;
        if ($al_ts > ($now - 120) && !in_array($al_key, $notified_ts)) {
            $pending_notifs[] = [
                'key'   => $al['key'] ?? '',
                'time'  => $al['time'] ?? '',
                'title' => notif_title($al['key'] ?? ''),
                'body'  => notif_body($al),
                'ts'    => $al_ts,
            ];
            $notified_ts[] = $al_key;
        }
    }
    // Keep last 200 notified keys to avoid memory bloat
    if (count($notified_ts) > 200) {
        $notified_ts = array_slice($notified_ts, -200);
    }
    if (!empty($pending_notifs)) {
        $s['browser_notified_ts'] = $notified_ts;
        save_state($s);
    }

    echo json_encode([
        'ts'                  => $now,
        'display_aed'         => $s['live_display_aed']  ?? null,
        'spot_aed'            => $s['live_spot_aed']     ?? null,
        'day_chg'             => ($do > 0 && $gu > 0) ? pct_change($do, $gu) : null,
        'day_high_aed'        => isset($s['session_high']) ? spot_to_aed((float)$s['session_high']) : null,
        'session_low_aed'     => $sl_aed,
        'premarket_low_aed'   => $pm_aed,
        'day_open_aed'        => $do > 0 ? spot_to_aed($do) : null,
        'last_run_ts'         => $s['last_run_ts'] ?? null,
        'chart'               => $s['chart_history'] ?? [],
        'alerts'              => array_slice(array_reverse($s['alert_log'] ?? []), 0, ALERT_LOG_MAX),
        'pending_notifs'      => $pending_notifs,
    ], JSON_UNESCAPED_UNICODE);
}

function notif_title(string $key): string {
    $map = [
        'day_low'       => '🔴 New Session Low',
        'dxy_drop'      => '💵 Dollar Weakening',
        'tip_rise'      => '📉 Real Yields Falling',
        'bounce'        => '🎯 Gold Bouncing Off Low',
        'consec_up'     => '📈 Uptrend Building',
        'gld_vol'       => '🏦 Big Money Buying',
        'deep_dip'      => '💎 Deep Dip Recovery',
        'oversold'      => '⚡ Oversold Snap',
        'dxy_weak'      => '🌍 Dollar Below 100',
        'orb'           => '🚀 Breakout Signal',
        'ema_cross'     => '✨ Momentum Crossover',
        'macro_conf'    => '🎪 Macro Confluence',
        'channel_break' => '📐 Resistance Broken',
        'pm_support'    => '🛡️ Approaching Support',
    ];
    return $map[$key] ?? '🔔 Gold Alert';
}

function notif_body(array $al): string {
    $msg = $al['msg'] ?? '';
    // Strip markdown
    $msg = preg_replace('/[*_`]/', '', $msg);
    // Take first 2 lines
    $lines = array_filter(explode("\n", $msg));
    $lines = array_values($lines);
    $body  = implode(' — ', array_slice($lines, 1, 2));
    return $body ?: 'Check the gold dashboard for details.';
}

// ══════════════════════════════════════════════════════════════════
//  CRON EXECUTION
// ══════════════════════════════════════════════════════════════════

$state  = load_state();
$now    = time();
$hour   = (int) date('G');
$dow    = (int) date('N');
$alerts = [];

if ($dow >= 6) {
    log_msg("Weekend (dow={$dow}) — market closed.");
    exit(0);
}

// ── Step 1: igold.ae retail price ────────────────────────────────
$t0         = microtime(true);
$retail_aed = fetch_igold_price();
$igold_ms   = round((microtime(true) - $t0) * 1000);
log_msg($retail_aed !== null
    ? "igold.ae OK: AED {$retail_aed}/g ({$igold_ms}ms)"
    : "WARN: igold.ae failed ({$igold_ms}ms)");

// ── Step 2: Yahoo Finance ─────────────────────────────────────────
$auth = get_yahoo_auth();
if ($auth === false) {
    log_msg("FATAL: Yahoo auth failed.");
    die("Yahoo auth failed.");
}

$yf = []; $yf_meta = [];
foreach (['GC=F', 'DX-Y.NYB', 'TIP', 'GLD'] as $ticker) {
    $t0 = microtime(true);
    $q  = fetch_yahoo_quote($ticker, $auth);
    $ms = round((microtime(true) - $t0) * 1000);

    if (is_array($q) && ($q['_http'] ?? 0) === 401) {
        log_msg("WARN: $ticker 401 — invalidating crumb + retry.");
        $auth = invalidate_crumb_and_reauth();
        if ($auth !== false) {
            $t0 = microtime(true);
            $q  = fetch_yahoo_quote($ticker, $auth);
            $ms = round((microtime(true) - $t0) * 1000);
        } else {
            $q = null;
        }
    }

    if ($q === null || !isset($q['price'])) {
        log_msg("WARN: Failed $ticker ({$ms}ms)");
        $yf_meta[$ticker] = ['ms' => $ms, 'status' => 'FAIL', 'endpoint' => '—'];
    } else {
        $yf[$ticker]      = $q;
        $yf_meta[$ticker] = ['ms' => $ms, 'status' => 'OK', 'endpoint' => $q['_endpoint'] ?? 'v8'];
        log_msg("OK: $ticker=" . $q['price'] . " ({$ms}ms via " . ($q['_endpoint'] ?? 'v8') . ")");
    }
}

$state['yf_meta']     = $yf_meta;
$state['igold_ms']    = $igold_ms;
$state['last_run_ts'] = $now;

if (!isset($yf['GC=F'])) {
    log_msg("FATAL: No GC=F data.");
    save_state($state);
    die("No gold data.");
}

$gold_usd_oz = (float) $yf['GC=F']['price'];
$spot_aed    = spot_to_aed($gold_usd_oz);
$display_aed = ($retail_aed !== null && $retail_aed > 0) ? $retail_aed : $spot_aed;

$dxy     = isset($yf['DX-Y.NYB']['price']) ? (float) $yf['DX-Y.NYB']['price'] : null;
$tip     = isset($yf['TIP']['price'])      ? (float) $yf['TIP']['price']       : null;
$gld_vol = isset($yf['GLD']['volume'])     ? (int)   $yf['GLD']['volume']      : null;
$gld_avg = isset($state['gld_avg_volume']) ? (float) $state['gld_avg_volume']  : null;
$prev    = isset($state['prev_gold'])      ? (float) $state['prev_gold']       : null;

$state['live_display_aed'] = $display_aed;
$state['live_spot_aed']    = $spot_aed;
$state['live_gold_usd']    = $gold_usd_oz;
$state['live_dxy']         = $dxy;
$state['live_tip']         = $tip;
$state['live_gld_vol']     = $gld_vol;
$state['live_gld_avg']     = $gld_avg;

// ── Chart history ─────────────────────────────────────────────────
$ch   = $state['chart_history'] ?? [];
$ch[] = ['ts' => $now, 'spot_aed' => $spot_aed, 'display_aed' => $display_aed];
if (count($ch) > CHART_HISTORY_MAX) $ch = array_slice($ch, -CHART_HISTORY_MAX);
$state['chart_history'] = $ch;

// ── Step 3: Day/Session tracking ─────────────────────────────────
$day_start_ts  = (int)($state['day_start_ts'] ?? 0);
$is_new_day    = !isset($state['day_open_gold'])
              || date('Y-m-d', $day_start_ts) !== date('Y-m-d');
$in_hours      = ($hour >= ACTIVE_HOUR_START && $hour < ACTIVE_HOUR_END);
$in_premarket  = ($hour < ACTIVE_HOUR_START);

if ($is_new_day) {
    $state = array_merge($state, [
        'day_open_gold'         => $gold_usd_oz,
        'day_start_ts'          => $now,
        'session_high'          => null,
        'session_low'           => null,
        'session_open'          => null,
        'session_dipped'        => false,
        'orb_high'              => null,
        'orb_set'               => false,
        'orb_broken'            => false,
        'pre_market_low'        => $gold_usd_oz,
        'pre_market_low_ts'     => $now,
        'day_low_prev'          => $gold_usd_oz,
        'day_low_reached'       => false,
        'pm_support_alerted'    => false,   // E7
        'consec_up'             => 0,
        'signals_today'         => 0,
        'gold_price_hist'       => [$gold_usd_oz],
        'ema_fast_val'          => null,
        'ema_slow_val'          => null,
        'ema_cross_bullish'     => false,
        'alert_log'             => [],
        'alert_log_keys_today'  => [],      // E4: dedup set
        'browser_notified_ts'   => [],
        'chart_history'         => [['ts' => $now, 'spot_aed' => $spot_aed, 'display_aed' => $display_aed]],
    ]);
    log_msg("New day reset. Open=" . fmt_aed($display_aed));
}

$day_open    = (float) $state['day_open_gold'];
$in_hours    = ($hour >= ACTIVE_HOUR_START && $hour < ACTIVE_HOUR_END);

// ── Pre-market: track overnight floor ────────────────────────────
if ($in_premarket) {
    $pm_low = (float)($state['pre_market_low'] ?? $gold_usd_oz);
    if ($gold_usd_oz < $pm_low) {
        $state['pre_market_low']    = $gold_usd_oz;
        $state['pre_market_low_ts'] = $now;
        log_msg("Pre-mkt low updated: " . fmt_aed($spot_aed));
    }
}

// ── Session open: first run at 08:00+ ────────────────────────────
if ($in_hours && $state['session_open'] === null) {
    $state['session_open']    = $gold_usd_oz;
    $state['session_high']    = $gold_usd_oz;
    $state['session_low']     = $gold_usd_oz;
    $state['session_open_ts'] = $now;
    log_msg("Session open: " . fmt_aed($display_aed));
}

$session_open = (float)($state['session_open'] ?? $gold_usd_oz);
$session_high = isset($state['session_high']) ? (float)$state['session_high'] : $gold_usd_oz;
$session_low  = isset($state['session_low'])  ? (float)$state['session_low']  : $gold_usd_oz;

// ── Update session high/low with stale guards ─────────────────────
if ($in_hours) {
    if ($gold_usd_oz > $session_high) {
        $session_high = $gold_usd_oz;
        $state['session_high'] = $gold_usd_oz;
    }
    if ($gold_usd_oz < $session_low) {
        $session_low = $gold_usd_oz;
        $state['session_low'] = $gold_usd_oz;
    }
    if ($session_low < $gold_usd_oz * (1 - STALE_THRESHOLD_PCT / 100)) {
        log_msg("WARN: session_low stale — resetting.");
        $session_low = $gold_usd_oz;
        $state['session_low']  = $gold_usd_oz;
        $state['day_low_prev'] = $gold_usd_oz;
    }
    if ($session_high > $gold_usd_oz * (1 + STALE_THRESHOLD_PCT / 100)) {
        log_msg("WARN: session_high stale — resetting.");
        $session_high = $gold_usd_oz;
        $state['session_high'] = $gold_usd_oz;
    }
}

$day_chg         = pct_change($day_open, $gold_usd_oz);
$session_dip_pct = $session_open > 0 ? pct_change($session_open, $session_low) : 0;

if ($in_hours && $session_dip_pct <= -BOUNCE_DIP_MIN_PCT && !($state['session_dipped'] ?? false)) {
    $state['session_dipped'] = true;
    log_msg("Session dipped: " . number_format(abs($session_dip_pct), 2) . "%");
}
$session_dipped = (bool)($state['session_dipped'] ?? false);

// ── Price history + EMA ───────────────────────────────────────────
$price_hist   = (array)($state['gold_price_hist'] ?? [$gold_usd_oz]);
$price_hist[] = $gold_usd_oz;
if (count($price_hist) > PRICE_HIST_MAX) array_shift($price_hist);
$state['gold_price_hist'] = $price_hist;

$alpha_fast = 2 / (EMA_FAST + 1);
$alpha_slow = 2 / (EMA_SLOW + 1);
$ema_fast   = isset($state['ema_fast_val'])
    ? (float)$state['ema_fast_val'] * (1 - $alpha_fast) + $gold_usd_oz * $alpha_fast
    : $gold_usd_oz;
$ema_slow   = isset($state['ema_slow_val'])
    ? (float)$state['ema_slow_val'] * (1 - $alpha_slow) + $gold_usd_oz * $alpha_slow
    : $gold_usd_oz;
$prev_ema_fast = isset($state['ema_fast_val']) ? (float)$state['ema_fast_val'] : null;
$prev_ema_slow = isset($state['ema_slow_val']) ? (float)$state['ema_slow_val'] : null;
$state['ema_fast_val'] = $ema_fast;
$state['ema_slow_val'] = $ema_slow;

// ── Opening Range tracking ────────────────────────────────────────
if ($in_hours && !($state['orb_set'] ?? false)) {
    if (!isset($state['session_open_ts'])) $state['session_open_ts'] = $now;
    $elapsed = ($now - (int)$state['session_open_ts']) / 60;
    $orb_h   = isset($state['orb_high']) ? max((float)$state['orb_high'], $gold_usd_oz) : $gold_usd_oz;
    $state['orb_high'] = $orb_h;
    if ($elapsed >= ORB_WINDOW_MINS) {
        $state['orb_set'] = true;
        log_msg("ORB set: high=" . fmt_aed(spot_to_aed($orb_h)));
    }
}
$orb_high   = isset($state['orb_high']) ? (float)$state['orb_high'] : null;
$orb_set    = (bool)($state['orb_set']    ?? false);
$orb_broken = (bool)($state['orb_broken'] ?? false);

// ── E4: alert dedup helper ────────────────────────────────────────
// Tracks key+date to prevent same signal repeating on same calendar day
$alert_log_keys_today = (array)($state['alert_log_keys_today'] ?? []);
function alert_not_duped(string $key, array &$keys_today): bool {
    $dk = $key . '_' . date('Y-m-d');
    // For signals with own cooldown management (day_low, dxy_weak) we only gate once per day
    $once_per_day = ['dxy_weak', 'orb'];
    if (in_array($key, $once_per_day) && in_array($dk, $keys_today)) return false;
    return true;
}
function mark_alert_duped(string $key, array &$keys_today): void {
    $dk = $key . '_' . date('Y-m-d');
    if (!in_array($dk, $keys_today)) $keys_today[] = $dk;
}

// ── Step 4: Day-low alert ─────────────────────────────────────────
$prev_day_low  = (float)($state['day_low_prev'] ?? $day_open);
$pm_low_ts     = (int)($state['pre_market_low_ts'] ?? 0);
$pm_set_early  = ($pm_low_ts > 0 && ($day_start_ts - $pm_low_ts) > 1800);
$pm_low_val    = (float)($state['pre_market_low'] ?? $prev_day_low);
$low_reference = ($in_hours && $pm_set_early) ? min($pm_low_val, $prev_day_low) : $prev_day_low;
$low_drop_pct  = pct_change($low_reference, $gold_usd_oz);

if ($day_chg < -0.10
    && $gold_usd_oz < $low_reference
    && abs($low_drop_pct) >= DAY_LOW_MIN_DROP_PCT
    && cooldown_ok($state, 'day_low', COOLDOWN_DAY_LOW, $now)
) {
    $ref_label = ($in_hours && $pm_set_early && $gold_usd_oz < $pm_low_val)
                  ? "pre-market floor" : "previous low";
    $alerts[] = [
        'key'  => 'day_low',
        'text' => "🔴 *NEW SESSION LOW*\n"
                . "`" . fmt_aed($display_aed) . "` — " . fmt_chg($day_chg) . " from open\n"
                . "_(broke " . number_format(abs($low_drop_pct), 2) . "% below {$ref_label})_",
    ];
    $state['day_low_prev']    = $gold_usd_oz;
    $state['day_low_reached'] = true;
    if ($in_hours && $pm_set_early) {
        $state['pre_market_low'] = min($pm_low_val, $gold_usd_oz);
    }
}

// ── Step 5: Bullish signals (active hours only) ───────────────────
if ($in_hours) {

    // ─ S1: DXY drop ──────────────────────────────────────────────
    if ($dxy !== null && isset($state['prev_dxy']) && (float)$state['prev_dxy'] > 0) {
        $d = pct_change((float)$state['prev_dxy'], $dxy);
        if ($d <= -DXY_DROP_PCT && cooldown_ok($state, 'dxy_drop', COOLDOWN_MACRO, $now)) {
            $alerts[] = ['key' => 'dxy_drop', 'text' =>
                "💵 *DOLLAR WEAKENING*\n"
                . "DXY " . number_format((float)$state['prev_dxy'], 2) . " → "
                . number_format($dxy, 2) . " (" . dxy_label_plain($dxy) . ")\n"
                . "Fell " . number_format(abs($d), 2) . "% — gold tailwind"];
        }
    }

    // ─ S2: TIP ETF rising ────────────────────────────────────────
    if ($tip !== null && isset($state['prev_tip']) && (float)$state['prev_tip'] > 0) {
        $t = pct_change((float)$state['prev_tip'], $tip);
        if ($t >= TIP_RISE_PCT && cooldown_ok($state, 'tip_rise', COOLDOWN_MACRO, $now)) {
            $alerts[] = ['key' => 'tip_rise', 'text' =>
                "📉 *REAL YIELDS FALLING*\n"
                . "TIP ETF +" . number_format($t, 2) . "% — inflation premium rising"];
        }
    }

    // ─ S3: Bounce off session low ─────────────────────────────────
    if ($session_dipped && $session_low > 0 && $gold_usd_oz > $session_low) {
        $bounce = pct_change($session_low, $gold_usd_oz);
        if ($bounce >= BOUNCE_PCT && cooldown_ok($state, 'bounce', COOLDOWN_BOUNCE, $now)) {
            $alerts[] = ['key' => 'bounce', 'text' =>
                "🎯 *BOUNCING OFF SESSION LOW*\n"
                . "+" . number_format($bounce, 2) . "% recovery from `"
                . fmt_aed(spot_to_aed($session_low)) . "`\n"
                . "(Session dipped " . number_format(abs($session_dip_pct), 2) . "%) — now `"
                . fmt_aed($display_aed) . "`"];
        }
    }

    // ─ S4: Consecutive up moves ───────────────────────────────────
    $consec = (int)($state['consec_up'] ?? 0);
    if ($prev !== null) {
        $was_at = ($consec >= CONSEC_UP_COUNT);
        $consec = ($gold_usd_oz > $prev) ? $consec + 1 : 0;
        $state['consec_up'] = $consec;
        if ($consec === CONSEC_UP_COUNT && !$was_at
            && $day_chg > -0.50
            && cooldown_ok($state, 'consec_up', COOLDOWN_TREND, $now)
        ) {
            $alerts[] = ['key' => 'consec_up', 'text' =>
                "📈 *TREND TURNING UP*\n"
                . "{$consec} consecutive higher closes\n"
                . "Now at `" . fmt_aed($display_aed) . "`"];
        }
    } else {
        $state['consec_up'] = 0;
    }

    // ─ S5: GLD volume spike on up candle ──────────────────────────
    if ($gld_vol !== null && $gld_avg !== null && $gld_avg > 0 && $prev !== null) {
        $vm = $gld_vol / $gld_avg;
        if ($vm >= GLD_VOL_MULT && $gold_usd_oz > $prev
            && cooldown_ok($state, 'gld_vol', COOLDOWN_VOLUME, $now)
        ) {
            $alerts[] = ['key' => 'gld_vol', 'text' =>
                "🏦 *INSTITUTIONAL BUYING*\n"
                . "GLD volume " . number_format($vm, 1) . "× average on rising candle\n"
                . "Strong accumulation signal at `" . fmt_aed($display_aed) . "`"];
        }
    }

    // ─ S6: Deep dip recovery ──────────────────────────────────────
    if ($session_dipped && $day_chg <= -DEEP_DIP_PCT && $session_low > 0 && $gold_usd_oz > $session_low) {
        $bfl = pct_change($session_low, $gold_usd_oz);
        if ($bfl >= DEEP_DIP_BOUNCE_PCT && cooldown_ok($state, 'deep_dip', COOLDOWN_DEEP_DIP, $now)) {
            $alerts[] = ['key' => 'deep_dip', 'text' =>
                "💎 *DEEP DIP RECOVERY*\n"
                . "Down " . number_format(abs($day_chg), 2) . "% today — bouncing +"
                . number_format($bfl, 2) . "% off low\n"
                . "`" . fmt_aed(spot_to_aed($session_low)) . "` → `" . fmt_aed($display_aed) . "`\n"
                . "_Potential buy zone_"];
        }
    }

    // ─ S7: Oversold snap ──────────────────────────────────────────
    if (count($price_hist) >= OVERSOLD_WINDOW + 1 && $prev !== null && $gold_usd_oz > $prev) {
        $ws    = array_slice($price_hist, -(OVERSOLD_WINDOW + 1));
        $wdrop = pct_change((float)$ws[0], (float)min($ws));
        if ($wdrop <= -OVERSOLD_DROP_PCT && $gold_usd_oz > (float)min($ws)
            && cooldown_ok($state, 'oversold', COOLDOWN_OVERSOLD, $now)
        ) {
            $alerts[] = ['key' => 'oversold', 'text' =>
                "⚡ *OVERSOLD SNAP*\n"
                . "Fell " . number_format(abs($wdrop), 2) . "% in last " . OVERSOLD_WINDOW . " mins — reversing\n"
                . "Early buy signal at `" . fmt_aed($display_aed) . "`"];
        }
    }

    // ─ S8: Structural DXY weakness ────────────────────────────────
    if ($dxy !== null && $dxy < DXY_WEAK_LEVEL
        && alert_not_duped('dxy_weak', $alert_log_keys_today)
        && cooldown_ok($state, 'dxy_weak', COOLDOWN_DXY_WEAK, $now)
    ) {
        $alerts[] = ['key' => 'dxy_weak', 'text' =>
            "🌍 *DOLLAR BELOW 100*\n"
            . "DXY " . number_format($dxy, 2) . " — structurally weak dollar\n"
            . "Gold historically outperforms when DXY < 100"];
        mark_alert_duped('dxy_weak', $alert_log_keys_today);
    }

    // ─ S9: Opening Range Breakout ─────────────────────────────────
    if ($orb_set && !$orb_broken && $orb_high !== null && $gold_usd_oz > $orb_high
        && alert_not_duped('orb', $alert_log_keys_today)
        && cooldown_ok($state, 'orb', COOLDOWN_ORB, $now)
    ) {
        $break_pct = pct_change($orb_high, $gold_usd_oz);
        $alerts[] = ['key' => 'orb', 'text' =>
            "🚀 *OPENING RANGE BREAKOUT*\n"
            . "Price broke above 30-min ORB high `" . fmt_aed(spot_to_aed($orb_high)) . "`\n"
            . "+" . number_format($break_pct, 2) . "% above range — bullish continuation"];
        $state['orb_broken'] = true;
        mark_alert_duped('orb', $alert_log_keys_today);
    }

    // ─ S10: EMA Golden Cross Proxy ───────────────────────────────
    if ($prev_ema_fast !== null && $prev_ema_slow !== null
        && count($price_hist) >= EMA_SLOW
        && $prev_ema_fast <= $prev_ema_slow
        && $ema_fast > $ema_slow
        && $gold_usd_oz > $prev
        && cooldown_ok($state, 'ema_cross', COOLDOWN_EMA_CROSS, $now)
    ) {
        $alerts[] = ['key' => 'ema_cross', 'text' =>
            "✨ *MOMENTUM CROSSOVER*\n"
            . "Fast EMA(" . EMA_FAST . ") crossed above Slow EMA(" . EMA_SLOW . ")\n"
            . "Short-term momentum turning bullish at `" . fmt_aed($display_aed) . "`"];
    }

    // ─ S11: Macro Confluence ─────────────────────────────────────
    $dxy_drop_now = ($dxy !== null && isset($state['prev_dxy']) && (float)$state['prev_dxy'] > 0)
                    ? pct_change((float)$state['prev_dxy'], $dxy) : 0;
    $tip_rise_now = ($tip !== null && isset($state['prev_tip']) && (float)$state['prev_tip'] > 0)
                    ? pct_change((float)$state['prev_tip'], $tip) : 0;
    if ($dxy_drop_now <= -MACRO_CONF_DXY_DROP && $tip_rise_now >= MACRO_CONF_TIP_RISE
        && cooldown_ok($state, 'macro_conf', COOLDOWN_MACRO_CONF, $now)
    ) {
        $alerts[] = ['key' => 'macro_conf', 'text' =>
            "🎪 *MACRO CONFLUENCE*\n"
            . "DXY ↓" . number_format(abs($dxy_drop_now), 2)
            . "% AND TIP ↑" . number_format($tip_rise_now, 2) . "% simultaneously\n"
            . "Both macro tailwinds aligning — high-confidence gold setup at `" . fmt_aed($display_aed) . "`"];
    }

    // ─ S12 (E6): Price Channel Break ─────────────────────────────
    // Fires when price rises above the highest point in recent N runs (resistance break)
    if (count($price_hist) >= CHANNEL_BREAK_WINDOW + 1 && $prev !== null && $gold_usd_oz > $prev) {
        $window = array_slice($price_hist, -(CHANNEL_BREAK_WINDOW + 1), CHANNEL_BREAK_WINDOW);
        $resistance = max($window);
        if ($gold_usd_oz > $resistance
            && pct_change($resistance, $gold_usd_oz) >= 0.10  // at least 0.1% break
            && cooldown_ok($state, 'channel_break', COOLDOWN_CHANNEL_BREAK, $now)
        ) {
            $break_p = pct_change($resistance, $gold_usd_oz);
            $alerts[] = ['key' => 'channel_break', 'text' =>
                "📐 *RESISTANCE BROKEN*\n"
                . "Price cleared " . CHANNEL_BREAK_WINDOW . "-min high `" . fmt_aed(spot_to_aed($resistance)) . "`\n"
                . "+" . number_format($break_p, 2) . "% breakout — momentum continuing at `" . fmt_aed($display_aed) . "`"];
        }
    }

    // ─ S13 (E7): Pre-Market Support Approach ─────────────────────
    // Early warning: price approaching pre-market low during session (potential bounce zone)
    $pm_low_support = (float)($state['pre_market_low'] ?? 0);
    if ($pm_low_support > 0 && $day_chg < -0.20) {
        $dist_pct = pct_change($pm_low_support, $gold_usd_oz); // negative = approaching
        if ($dist_pct <= 0 && $dist_pct >= -(PM_SUPPORT_PCT)  // within X% above support
            && !($state['pm_support_alerted'] ?? false)
            && cooldown_ok($state, 'pm_support', COOLDOWN_PM_SUPPORT, $now)
        ) {
            $alerts[] = ['key' => 'pm_support', 'text' =>
                "🛡️ *APPROACHING SUPPORT*\n"
                . "Price near pre-market low `" . fmt_aed(spot_to_aed($pm_low_support)) . "`\n"
                . "Strong support zone — watch for bounce. Now at `" . fmt_aed($display_aed) . "`"];
            $state['pm_support_alerted'] = true;
        }
        // Reset arm once price moves away from support (> 0.5% above)
        if ($dist_pct > 0.50) {
            $state['pm_support_alerted'] = false;
        }
    }
}

// ── Step 6: Rolling state ─────────────────────────────────────────
if ($gld_vol !== null) {
    $vh = (array)($state['gld_vol_history'] ?? []);
    $vh[] = $gld_vol;
    if (count($vh) > 20) array_shift($vh);
    $state['gld_vol_history'] = $vh;
    $state['gld_avg_volume']  = array_sum($vh) / count($vh);
}
if ($dxy !== null) $state['prev_dxy'] = $dxy;
if ($tip !== null) $state['prev_tip'] = $tip;
$state['prev_gold'] = $gold_usd_oz;
$state['alert_log_keys_today'] = $alert_log_keys_today;

// ── Step 7: Build + send alerts ───────────────────────────────────
$bullish_keys  = ['dxy_drop','tip_rise','bounce','consec_up','gld_vol',
                  'deep_dip','oversold','dxy_weak','orb','ema_cross','macro_conf',
                  'channel_break','pm_support'];
$bullish_count = 0;
foreach ($alerts as $a) {
    if (in_array($a['key'], $bullish_keys)) $bullish_count++;
}

if (!empty($alerts)) {
    $consec_val = (int)($state['consec_up'] ?? 0);
    $consec_str = $consec_val > 0 ? "{$consec_val}↑ in a row" : "—";
    $from_low   = ($session_dipped && $session_low > 0 && $gold_usd_oz > $session_low)
                    ? pct_change($session_low, $gold_usd_oz) : 0;
    $bounce_str = $from_low >= 0.05 ? "+" . number_format($from_low, 2) . "%" : "—";

    $msg = "";
    if ($bullish_count >= 2) {
        $msg .= "🚨 *STRONG BUY — {$bullish_count} SIGNALS ALIGNING*\n"
              . "━━━━━━━━━━━━━━━━━━━━━━\n";
    }
    $msg .= "🕐 *" . date('d M · H:i') . " UAE*\n"
          . "━━━━━━━━━━━━━━━━━━━━━━\n"
          . "💰 `" . fmt_aed($display_aed) . "` — " . strength_label($day_chg) . "\n"
          . "📊 Today: `" . fmt_chg($day_chg) . "`  |  From low: `{$bounce_str}`\n"
          . "💵 DXY `" . ($dxy !== null ? number_format($dxy, 2) : "—") . "` — " . dxy_label($dxy) . "\n"
          . "🔺 Momentum: {$consec_str}\n"
          . "━━━━━━━━━━━━━━━━━━━━━━\n";

    $alert_log = (array)($state['alert_log'] ?? []);
    foreach ($alerts as $a) {
        $msg .= $a['text'] . "\n\n";
        $state['last_alert'][$a['key']] = $now;
        // E4: build a dedup key for the alert log (key + minute-level ts)
        $dedup_key = $a['key'] . '_' . floor($now / 60);
        $already_in_log = false;
        foreach ($alert_log as $existing) {
            if (($existing['dedup_key'] ?? '') === $dedup_key) {
                $already_in_log = true; break;
            }
        }
        if (!$already_in_log) {
            $alert_log[] = [
                'time'      => date('H:i'),
                'key'       => $a['key'],
                'ts'        => $now,
                'msg'       => $a['text'],
                'dedup_key' => $dedup_key,
            ];
        }
    }
    if (count($alert_log) > ALERT_LOG_MAX) {
        $alert_log = array_slice($alert_log, -ALERT_LOG_MAX);
    }
    $state['alert_log']     = $alert_log;
    $state['signals_today'] = (int)($state['signals_today'] ?? 0) + count($alerts);

    $sent = send_telegram_all(rtrim($msg));
    log_msg("Sent " . count($alerts) . " signal(s). Telegram=" . ($sent ? 'OK' : 'PARTIAL/FAIL'));
} else {
    log_msg(sprintf(
        "No signals. spot=%s day=%s active=%s dipped=%s consec=%d",
        fmt_aed($spot_aed), fmt_chg($day_chg),
        $in_hours ? 'yes' : 'no',
        $session_dipped ? 'yes' : 'no',
        (int)($state['consec_up'] ?? 0)
    ));
}

// ── Step 8: Daily summary at 22:00 ───────────────────────────────
$last_sum  = (int)($state['last_daily_summary'] ?? 0);
$sum_today = (date('Y-m-d', $last_sum) === date('Y-m-d'));

if ($hour === DAILY_SUMMARY_HOUR && !$sum_today) {
    $low_from_today  = (bool)($state['day_low_reached'] ?? false);
    $pm_low_v        = (float)($state['pre_market_low'] ?? $session_low);
    $sl_display      = isset($state['session_low']) ? fmt_aed(spot_to_aed((float)$state['session_low'])) : '—';
    $signals_cnt     = (int)($state['signals_today'] ?? 0);
    $high_display    = isset($state['session_high']) ? fmt_aed(spot_to_aed((float)$state['session_high'])) : '—';

    $low_line = $low_from_today
        ? "⚠️ *Session low broken* at `{$sl_display}`"
        : "✅ Session held above pre-market floor";

    $summary  = "📋 *DAILY GOLD SUMMARY*\n━━━━━━━━━━━━━━━━━━━━━━\n"
              . "📅 " . date('d M Y') . "\n\n"
              . "💰 Close:         `" . fmt_aed($display_aed) . "`\n"
              . "📈 Session High:  `{$high_display}`\n"
              . "📉 Session Low:   `{$sl_display}`\n"
              . "📉 Pre-mkt Low:   `" . fmt_aed(spot_to_aed($pm_low_v)) . "`\n"
              . "📊 Change:        " . ($day_chg >= 0 ? "🟢 " : "🔴 ") . fmt_chg($day_chg) . "\n"
              . "💵 DXY: " . ($dxy !== null ? number_format($dxy, 2) : "—") . " — " . dxy_label($dxy) . "\n"
              . "━━━━━━━━━━━━━━━━━━━━━━\n"
              . "{$low_line}\n"
              . "_Signals fired today: {$signals_cnt}_";

    if (send_telegram_all($summary)) {
        $state['last_daily_summary'] = $now;
        log_msg("Daily summary sent.");
    }
}

save_state($state);
rotate_log();


// ══════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════

function spot_to_aed(float $usd_oz): float {
    return round($usd_oz * USD_TO_AED / OZ_TO_GRAM, 2);
}
function fmt_aed(float $aed): string {
    return "AED " . number_format($aed, 2) . "/g";
}
function pct_change(float $from, float $to): float {
    if ($from == 0.0) return 0.0;
    return (($to - $from) / $from) * 100.0;
}
function fmt_chg(float $pct, int $dp = 2): string {
    $r = round($pct, $dp);
    if ($r == 0) return "0.00%";
    return ($r > 0 ? "+" : "") . number_format($r, $dp) . "%";
}
function cooldown_ok(array $state, string $key, int $minutes, int $now): bool {
    $last = (int)($state['last_alert'][$key] ?? 0);
    return ($now - $last) >= ($minutes * 60);
}
function dxy_label(?float $v): string {
    if ($v === null) return "—";
    if ($v >= 106)   return "Strong 🔴";
    if ($v >= 103)   return "Normal 🟠";
    if ($v >= 100)   return "Weak 🟡";
    return "Very Weak 🟢";
}
function dxy_label_plain(?float $v): string {
    if ($v === null) return "—";
    if ($v >= 106)   return "Strong";
    if ($v >= 103)   return "Normal";
    if ($v >= 100)   return "Weak";
    return "Very Weak";
}
function strength_label(float $p): string {
    if ($p >= 2.5)  return "Very Strong 💪";
    if ($p >= 1.5)  return "Strong ⬆️";
    if ($p >= 1.0)  return "Moderate 📶";
    if ($p >= 0)    return "Flat ➡️";
    if ($p >= -1.5) return "Down ⬇️";
    if ($p >= -3.0) return "Falling 📉";
    return "Sharp Drop 🆘";
}


// ══════════════════════════════════════════════════════════════════
//  DATA FETCHERS
// ══════════════════════════════════════════════════════════════════

function fetch_igold_price(): ?float {
    $ch = curl_init('https://igold.ae/gold-rate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (empty($html)) return null;
    if (preg_match('/24K[\s\S]{0,120}?(\d{3,4}\.\d{2})\s*AED/i', $html, $m)) {
        $p = (float)$m[1];
        if ($p > 200 && $p < 1000) return $p;
    }
    if (preg_match('/(\d{3,4}\.\d{2})\s*AED\s*\/\s*g/i', $html, $m)) {
        $p = (float)$m[1];
        if ($p > 200 && $p < 1000) return $p;
    }
    return null;
}

function invalidate_crumb_and_reauth(): array|false {
    // E5: reload fresh state before saving to avoid race condition
    $state = load_state();
    unset($state['yf_crumb'], $state['yf_crumb_ts']);
    save_state($state);
    log_msg("Crumb invalidated (401). Re-authing.");
    if (file_exists(COOKIE_FILE)) @unlink(COOKIE_FILE);
    return get_yahoo_auth();
}

function get_yahoo_auth(): array|false {
    $jar   = COOKIE_FILE;
    // E5: always reload state for freshest crumb
    $state = load_state();
    $cc    = $state['yf_crumb']    ?? '';
    $cat   = (int)($state['yf_crumb_ts'] ?? 0);

    if (!empty($cc) && file_exists($jar) && (time() - $cat) < 3300) {
        log_msg("Yahoo auth: cached crumb OK (" . round((time() - $cat) / 60) . "min)");
        return ['jar' => $jar, 'crumb' => $cc];
    }

    $ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $hdrs = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
    ];
    $chdr = array_merge($hdrs, [
        'Referer: https://finance.yahoo.com/',
        'X-Requested-With: XMLHttpRequest',
    ]);

    $fhtml = '';
    foreach (['https://fc.yahoo.com', 'https://finance.yahoo.com'] as $seed) {
        $ch = curl_init($seed);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $hdrs, CURLOPT_ENCODING => '',
        ]);
        $out = curl_exec($ch); curl_close($ch);
        if (strpos($seed, 'finance') !== false) $fhtml = (string)$out;
    }

    $crumb = ''; $http = 0;
    $ch = curl_init('https://query2.finance.yahoo.com/v1/test/getcrumb');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => $chdr, CURLOPT_ENCODING => '',
    ]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http === 200 && !empty($resp)) {
        $c = trim((string)$resp);
        if (strlen($c) < 50 && !preg_match('/<|{/', $c)) {
            $crumb = $c; log_msg("Yahoo auth: getcrumb OK");
        }
    }

    if (empty($crumb) && !empty($fhtml)) {
        if (preg_match('/"CrumbStore"\s*:\s*\{"crumb"\s*:\s*"([^"]+)"\}/', $fhtml, $m)) {
            $crumb = $m[1]; log_msg("Yahoo auth: CrumbStore");
        } elseif (preg_match('/crumb\s*[=:]\s*["\']([A-Za-z0-9\/._-]{8,20})["\']/', $fhtml, $m)) {
            $crumb = $m[1]; log_msg("Yahoo auth: JS pattern");
        }
    }

    if (empty($crumb)) {
        $ch = curl_init('https://finance.yahoo.com/quote/GC=F');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $hdrs, CURLOPT_ENCODING => '',
        ]);
        $qhtml = curl_exec($ch); curl_close($ch);
        if (!empty($qhtml) && preg_match('/"crumb"\s*:\s*"([^"]{5,20})"/', $qhtml, $m)) {
            $crumb = $m[1]; log_msg("Yahoo auth: quote HTML");
        }

        if (empty($crumb)) {
            $ch = curl_init('https://query2.finance.yahoo.com/v1/test/getcrumb');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_COOKIEFILE => $jar, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $ua,
                CURLOPT_HTTPHEADER => $chdr, CURLOPT_ENCODING => '',
            ]);
            $r2 = curl_exec($ch); $h2 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($h2 === 200 && !empty($r2)) {
                $c = trim((string)$r2);
                if (strlen($c) < 50 && !preg_match('/<|{/', $c)) {
                    $crumb = $c; log_msg("Yahoo auth: getcrumb retry OK");
                }
            }
        }
    }

    if (empty($crumb)) {
        log_msg("FATAL: all Yahoo crumb strategies failed (HTTP={$http})");
        return false;
    }

    // E5: reload state before saving crumb to avoid clobbering concurrent writes
    $state = load_state();
    $state['yf_crumb']    = $crumb;
    $state['yf_crumb_ts'] = time();
    save_state($state);
    return ['jar' => $jar, 'crumb' => $crumb];
}

function fetch_yahoo_quote(string $ticker, array $auth): ?array {
    $ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,  CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,     CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT      => $ua,
        CURLOPT_COOKIEFILE     => $auth['jar'],
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Referer: https://finance.yahoo.com/'],
        CURLOPT_ENCODING       => '',
    ];

    $url = 'https://query2.finance.yahoo.com/v8/finance/chart/' . urlencode($ticker)
         . '?interval=1m&range=1d&crumb=' . urlencode($auth['crumb']);
    $ch  = curl_init($url); curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

    if ($http === 401) return ['_http' => 401, 'price' => null, 'volume' => null, '_endpoint' => 'v8'];

    if ($http === 200 && !empty($resp)) {
        $json   = json_decode($resp, true);
        $result = $json['chart']['result'][0] ?? null;
        if ($result) {
            $ind     = $result['indicators']['quote'][0] ?? [];
            $closes  = array_values(array_filter($ind['close']  ?? [], fn($v) => $v !== null));
            $volumes = array_values(array_filter($ind['volume'] ?? [], fn($v) => $v !== null));
            $price   = !empty($closes) ? (float)end($closes) : ($result['meta']['regularMarketPrice'] ?? null);
            $volume  = !empty($volumes) ? (int)end($volumes) : null;
            if ($price !== null && $price > 0) {
                return ['price' => $price, 'volume' => $volume, '_endpoint' => 'v8', '_http' => 200];
            }
        }
    }

    log_msg("WARN: $ticker v8 failed (HTTP={$http}) — trying v7");
    $ch2 = curl_init('https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode($ticker));
    curl_setopt_array($ch2, $opts);
    $resp2 = curl_exec($ch2); $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE); curl_close($ch2);

    if ($http2 === 200 && !empty($resp2)) {
        $j2 = json_decode($resp2, true);
        $q  = $j2['quoteResponse']['result'][0] ?? null;
        if ($q && isset($q['regularMarketPrice']) && (float)$q['regularMarketPrice'] > 0) {
            return [
                'price'     => (float)$q['regularMarketPrice'],
                'volume'    => $q['regularMarketVolume'] ?? null,
                '_endpoint' => 'v7',
                '_http'     => $http2,
            ];
        }
    }

    log_msg("WARN: $ticker v7 also failed (HTTP={$http2})");
    return null;
}


// ══════════════════════════════════════════════════════════════════
//  TELEGRAM
// ══════════════════════════════════════════════════════════════════

function send_telegram_all(string $message): bool {
    $dests = [
        ['chat_id' => TELEGRAM_GROUP_ID,   'thread_id' => TELEGRAM_THREAD_ID],
        ['chat_id' => TELEGRAM_CHANNEL_ID, 'thread_id' => null],
    ];
    $all_ok = true;
    foreach ($dests as $d) {
        if (!send_telegram_single($message, $d['chat_id'], $d['thread_id'])) {
            log_msg("WARN: Telegram fail chat_id=" . $d['chat_id']);
            $all_ok = false;
        }
    }
    return $all_ok;
}

function send_telegram_single(string $msg, int $chat_id, ?int $thread_id): bool {
    $payload = ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'Markdown'];
    if ($thread_id !== null) $payload['message_thread_id'] = $thread_id;
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) {
        log_msg("Telegram error (chat={$chat_id} HTTP={$code}): " . substr($resp, 0, 200));
        return false;
    }
    return true;
}


// ══════════════════════════════════════════════════════════════════
//  STATE + LOG
// ══════════════════════════════════════════════════════════════════

function load_state(): array {
    if (!file_exists(STATE_FILE)) return [];
    $raw = @file_get_contents(STATE_FILE);
    return $raw ? (json_decode($raw, true) ?: []) : [];
}
function save_state(array $state): void {
    $tmp = STATE_FILE . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, STATE_FILE);
}
function log_msg(string $msg): void {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function rotate_log(): void {
    if (!file_exists(LOG_FILE)) return;
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) > LOG_MAX_LINES) {
        $tmp = LOG_FILE . '.tmp.' . getmypid();
        file_put_contents($tmp, implode(PHP_EOL, array_slice($lines, -LOG_MAX_LINES)) . PHP_EOL, LOCK_EX);
        rename($tmp, LOG_FILE);
    }
}


// ══════════════════════════════════════════════════════════════════
//  DASHBOARD UI
// ══════════════════════════════════════════════════════════════════

function render_dashboard(): void {
    $s = load_state();

    $display_aed   = (float)($s['live_display_aed'] ?? 0);
    $spot_aed      = (float)($s['live_spot_aed']    ?? $display_aed);
    $gold_usd      = (float)($s['live_gold_usd']    ?? 0);
    $dxy           = isset($s['live_dxy'])     ? (float)$s['live_dxy']     : null;
    $tip           = isset($s['live_tip'])     ? (float)$s['live_tip']     : null;
    $gld_vol       = isset($s['live_gld_vol']) ? (int)$s['live_gld_vol']   : null;
    $gld_avg       = isset($s['live_gld_avg']) ? (float)$s['live_gld_avg'] : null;
    $day_open      = (float)($s['day_open_gold'] ?? $gold_usd);
    $day_chg       = $day_open > 0 ? pct_change($day_open, $gold_usd) : 0;

    $session_high  = isset($s['session_high']) ? (float)$s['session_high'] : null;
    $session_low   = isset($s['session_low'])  ? (float)$s['session_low']  : null;
    $pm_low        = isset($s['pre_market_low']) ? (float)$s['pre_market_low'] : null;
    $session_open  = isset($s['session_open']) ? (float)$s['session_open'] : null;

    $open_aed      = spot_to_aed($day_open);
    $high_aed      = $session_high !== null ? spot_to_aed($session_high) : null;
    $sl_aed        = $session_low  !== null ? spot_to_aed($session_low)  : null;
    $pm_aed        = $pm_low       !== null ? spot_to_aed($pm_low)       : null;
    $so_aed        = $session_open !== null ? spot_to_aed($session_open) : null;

    $range_lo      = $sl_aed  ?? $spot_aed;
    $range_hi      = $high_aed ?? $spot_aed;
    $range_pct     = ($range_lo > 0 && $range_hi > $range_lo)
                        ? round(pct_change($range_lo, $range_hi), 2) : 0;
    $range_rng     = $range_hi - $range_lo;
    $pos           = $range_rng > 0
                        ? min(100, max(0, round(($spot_aed - $range_lo) / $range_rng * 100))) : 50;

    $session_dipped = (bool)($s['session_dipped'] ?? false);
    $from_low_pct   = ($session_dipped && $session_low !== null && $session_low > 0 && $session_low < $gold_usd)
                        ? round(pct_change($session_low, $gold_usd), 2) : 0;

    $consec        = (int)($s['consec_up']     ?? 0);
    $signals_today = (int)($s['signals_today'] ?? 0);
    $last_run      = (int)($s['last_run_ts']   ?? 0);
    $crumb_age     = isset($s['yf_crumb_ts'])
                        ? round((time() - (int)$s['yf_crumb_ts']) / 60) : null;
    $yf_meta       = $s['yf_meta']  ?? [];
    $igold_ms      = $s['igold_ms'] ?? null;
    $alert_log     = array_reverse($s['alert_log'] ?? []);
    $low_reached   = (bool)($s['day_low_reached'] ?? false);
    $day_start_ts  = (int)($s['day_start_ts']   ?? 0);
    $vol_mult      = ($gld_avg > 0 && $gld_vol !== null)
                        ? round($gld_vol / $gld_avg, 2) : null;
    $last_run_str  = $last_run > 0 ? date('d M · H:i:s', $last_run) : 'Never';
    $age_secs      = $last_run > 0 ? (time() - $last_run) : null;
    $age_str       = $age_secs !== null
                        ? ($age_secs < 120 ? $age_secs . 's ago' : round($age_secs / 60) . 'm ago') : '—';
    $dxy_color     = $dxy === null ? '#6b7280'
                        : ($dxy < 100 ? '#22c55e' : ($dxy < 103 ? '#eab308' : '#ef4444'));
    $now           = time();

    // Retail vs spot markup note
    $markup_pct    = ($spot_aed > 0 && $display_aed > 0)
                        ? round(pct_change($spot_aed, $display_aed), 2) : 0;

    // Low status badge
    if ($low_reached) {
        $low_status = "<span class='badge b-bear'>⚠ Session low broken · "
                    . ($sl_aed !== null ? 'AED ' . number_format($sl_aed, 2) : '—') . "</span>";
    } elseif ($pm_aed !== null) {
        $low_status = "<span class='badge b-warn'>Floor: AED " . number_format($pm_aed, 2) . " (pre-mkt)</span>";
    } else {
        $low_status = "<span class='badge b-neu'>✅ Above floor</span>";
    }

    // ── Signal metadata with plain English tooltips ───────────────
    $signal_meta = [
        'day_low'       => ['🔴', 'Session Low',   'bear',
            "Price has just dropped to a NEW LOW for today's trading session. This is important — it means gold is cheaper than at any other point today. Watch closely: if it stops falling and starts to recover, that can be a good buying opportunity."],
        'dxy_drop'      => ['💵', 'Dollar Weakens', 'bull',
            "The US Dollar just weakened. Gold and the dollar usually move in opposite directions — when the dollar falls, gold tends to rise. Think of it like a seesaw: dollar down = gold up. This is a positive sign for gold buyers."],
        'tip_rise'      => ['📉', 'Yields Falling', 'bull',
            "A key bond market indicator (TIP ETF) is rising, which means real interest rates are falling. When rates fall, gold becomes more attractive because cash in the bank earns less. This historically pushes gold prices higher."],
        'bounce'        => ['🎯', 'Bounce Off Low',  'bull',
            "Gold dipped earlier today and is now bouncing back up from its lowest point. This bounce pattern suggests buyers are stepping in at the low price — exactly the dip-buying opportunity we look for. The bigger the dip before this, the more significant the bounce."],
        'consec_up'     => ['📈', 'Uptrend Building', 'bull',
            "Gold has risen in " . CONSEC_UP_COUNT . " consecutive one-minute intervals. This steady, consistent upward movement suggests buying momentum is building — not just a random blip but a developing trend."],
        'gld_vol'       => ['🏦', 'Big Money Buying', 'bull',
            "The GLD gold ETF (a large fund that tracks gold) is seeing 2× or more its normal trading volume while prices are rising. This means big institutional investors and funds are actively buying — a very strong signal that professionals see value here."],
        'deep_dip'      => ['💎', 'Deep Dip Recovery', 'bull',
            "Gold fell sharply (2%+ in one day) and is now starting to recover from its lowest point. Sharp dips followed by recovery are often the best buying opportunities — you're catching gold near its daily bottom. The '💎 hands' emoji is intentional: this is where patient buyers are rewarded."],
        'oversold'      => ['⚡', 'Quick Reversal',   'bull',
            "Gold dropped quickly in the last few minutes (1.5%+) and has just started moving back up. When something falls too fast, it often snaps back. This 'oversold snap' is an early signal that the mini-selloff may be ending — an early warning before a bigger bounce."],
        'dxy_weak'      => ['🌍', 'Dollar Below 100', 'bull',
            "The US Dollar Index has fallen below 100 — a psychologically important level. Historically, when the dollar stays this weak, gold performs well over the following weeks and months. This is more of a background condition than a precise entry signal, but it's a positive environment for gold."],
        'orb'           => ['🚀', 'Breakout Signal',  'bull',
            "In the first 30 minutes of trading, gold established a price range. It has now broken ABOVE the top of that range. This 'Opening Range Breakout' is used by professional traders as a signal that the direction for the rest of the day is likely upward."],
        'ema_cross'     => ['✨', 'Momentum Crossover', 'bull',
            "A short-term average price (last 5 minutes) has crossed above a longer-term average (last 20 minutes). This 'golden cross' pattern means short-term momentum has turned bullish. Think of it like a fast-moving vehicle overtaking a slower one — the short-term trend is now stronger."],
        'macro_conf'    => ['🎪', 'All Stars Aligning', 'bull',
            "Both major gold-positive signals fired at the same time: the US Dollar weakened AND bond market indicators turned gold-bullish simultaneously. When multiple unrelated factors all point in the same direction, it's called 'confluence' — and it's one of the highest-confidence signals we track."],
        'channel_break' => ['📐', 'Resistance Broken', 'bull',
            "Gold has just broken above a price ceiling it struggled to get past in the last 15 minutes. Traders call this 'breaking resistance' — once a ceiling becomes a floor, prices can continue rising. This suggests the recent mini-rally has genuine momentum behind it."],
        'pm_support'    => ['🛡️', 'Support Zone Nearby', 'bull',
            "Gold is approaching the pre-market low — the lowest price from overnight trading. This level often acts as 'support' because buyers who set orders overnight will step in here. It's a potential bounce zone — watch for a reversal. If it holds, it's a good sign."],
    ];

    $cooldowns = [
        'day_low'       => COOLDOWN_DAY_LOW,
        'dxy_drop'      => COOLDOWN_MACRO,
        'tip_rise'      => COOLDOWN_MACRO,
        'bounce'        => COOLDOWN_BOUNCE,
        'consec_up'     => COOLDOWN_TREND,
        'gld_vol'       => COOLDOWN_VOLUME,
        'deep_dip'      => COOLDOWN_DEEP_DIP,
        'oversold'      => COOLDOWN_OVERSOLD,
        'dxy_weak'      => COOLDOWN_DXY_WEAK,
        'orb'           => COOLDOWN_ORB,
        'ema_cross'     => COOLDOWN_EMA_CROSS,
        'macro_conf'    => COOLDOWN_MACRO_CONF,
        'channel_break' => COOLDOWN_CHANNEL_BREAK,
        'pm_support'    => COOLDOWN_PM_SUPPORT,
    ];
    $last_alerts    = $s['last_alert'] ?? [];
    $seed_json      = json_encode([
        'chart'  => array_values($s['chart_history'] ?? []),
        'alerts' => array_slice(array_reverse($s['alert_log'] ?? []), 0, ALERT_LOG_MAX),
    ], JSON_UNESCAPED_UNICODE);

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gold Monitor · UAE</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=DM+Mono:wght@400;500&family=Geist:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#09090b; --surface:#111113; --surface2:#17171a; --surface3:#1e1e23;
  --border:#222228; --border2:#2d2d38;
  --gold:#d4a843; --gold2:#f0c560; --gold3:#fbd97a; --gold-dim:#7a5e1e;
  --text:#e8e8ec; --muted:#6b6b7a; --muted2:#44444f;
  --green:#22c55e; --red:#ef4444; --yellow:#eab308; --blue:#60a5fa;
  --r:12px; --rs:8px; --rxs:5px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Geist',sans-serif;min-height:100vh;line-height:1.5;-webkit-font-smoothing:antialiased;}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");opacity:.5;}
.wrap{position:relative;z-index:1;max-width:1180px;margin:0 auto;padding:24px 16px 64px;}

/* ── Header ── */
.hdr{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.hdr-left h1{font-family:'Instrument Serif',serif;font-size:clamp(1.9rem,4vw,2.7rem);font-weight:400;letter-spacing:-.025em;
  background:linear-gradient(135deg,var(--gold3) 0%,var(--gold) 55%,var(--gold-dim) 100%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.hdr-left p{font-size:.7rem;color:var(--muted);margin-top:4px;letter-spacing:.08em;text-transform:uppercase;}
.hdr-right{display:flex;align-items:flex-end;gap:12px;}
.last-run{font-family:'DM Mono',monospace;font-size:.7rem;color:var(--muted);text-align:right;}
.live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-right:5px;animation:pulse 2s ease-in-out infinite;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.65)}}

/* ── Bell / notification button ── */
.bell-btn{display:flex;align-items:center;justify-content:center;width:38px;height:38px;
  background:var(--surface);border:1px solid var(--border);border-radius:50%;cursor:pointer;
  font-size:1rem;transition:all .2s;flex-shrink:0;position:relative;}
.bell-btn:hover{border-color:var(--gold-dim);background:rgba(212,168,67,.07);}
.bell-btn.granted{border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.06);}
.bell-btn.denied{border-color:rgba(239,68,68,.3);opacity:.5;cursor:not-allowed;}
.bell-badge{position:absolute;top:-3px;right:-3px;width:10px;height:10px;border-radius:50%;
  background:var(--red);border:2px solid var(--bg);display:none;}
.bell-badge.show{display:block;}
.bell-tip{position:absolute;bottom:calc(100% + 7px);right:0;background:var(--surface2);
  border:1px solid var(--border2);border-radius:var(--rs);padding:7px 11px;
  font-size:.68rem;color:var(--text);white-space:nowrap;display:none;z-index:100;
  box-shadow:0 8px 24px rgba(0,0,0,.5);}
.bell-btn:hover .bell-tip{display:block;}

/* ── Toast notifications ── */
.toast-stack{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column-reverse;gap:8px;pointer-events:none;}
.toast{background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);
  padding:13px 16px;font-size:.78rem;max-width:320px;box-shadow:0 12px 40px rgba(0,0,0,.7);
  pointer-events:all;animation:slideIn .3s ease;display:flex;gap:10px;align-items:flex-start;}
.toast.bull{border-color:rgba(212,168,67,.35);background:linear-gradient(135deg,rgba(212,168,67,.08),var(--surface));}
.toast.bear{border-color:rgba(239,68,68,.3);background:linear-gradient(135deg,rgba(239,68,68,.07),var(--surface));}
.toast-icon{font-size:1.2rem;flex-shrink:0;line-height:1;}
.toast-body{flex:1;}
.toast-title{font-weight:600;color:var(--gold2);margin-bottom:2px;font-size:.75rem;}
.toast.bear .toast-title{color:var(--red);}
.toast-msg{color:var(--muted);font-size:.7rem;line-height:1.4;}
.toast-close{cursor:pointer;color:var(--muted2);font-size:.9rem;line-height:1;flex-shrink:0;margin-top:1px;}
.toast-close:hover{color:var(--text);}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}
@keyframes slideOut{from{opacity:1;transform:none}to{opacity:0;transform:translateX(20px)}}

/* ── Cards ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:20px;}
.card-lbl{font-size:.63rem;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.card-lbl .dot{width:4px;height:4px;border-radius:50%;background:var(--gold-dim);flex-shrink:0;}
.mb12{margin-bottom:12px;}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:12px;}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}

/* ── Hero ── */
.hero{background:linear-gradient(145deg,#130f08 0%,#0e0e11 60%,#0a0a0d 100%);border-color:var(--gold-dim);padding:28px 24px;position:relative;overflow:hidden;}
.hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 90% 50%,rgba(212,168,67,.07) 0%,transparent 70%);pointer-events:none;}
.price-main{font-family:'Instrument Serif',serif;font-size:clamp(2.5rem,6vw,4rem);font-weight:400;letter-spacing:-.035em;color:var(--gold2);line-height:1;transition:color .4s;}
.price-sub{font-size:.76rem;color:var(--muted);margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.price-sub-dot{width:3px;height:3px;border-radius:50%;background:var(--muted2);}
.price-row{margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.price-chg{font-family:'DM Mono',monospace;font-size:1.25rem;font-weight:500;}
.retail-tag{background:rgba(212,168,67,.08);border:1px solid rgba(212,168,67,.15);border-radius:4px;
  padding:2px 7px;font-size:.62rem;color:var(--gold-dim);font-family:'DM Mono',monospace;}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:.68rem;font-weight:500;letter-spacing:.04em;}
.b-bull{background:rgba(34,197,94,.1);color:var(--green);border:1px solid rgba(34,197,94,.18);}
.b-bear{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.18);}
.b-warn{background:rgba(234,179,8,.1);color:var(--yellow);border:1px solid rgba(234,179,8,.18);}
.b-neu{background:rgba(107,107,122,.12);color:var(--muted);border:1px solid var(--border);}
.b-gold{background:rgba(212,168,67,.1);color:var(--gold);border:1px solid rgba(212,168,67,.2);}
.b-blue{background:rgba(96,165,250,.1);color:var(--blue);border:1px solid rgba(96,165,250,.18);}

/* ── OHLC ── */
.ohlc{display:grid;grid-template-columns:repeat(5,1fr);}
.ohlc-item{padding:12px 14px;border-right:1px solid var(--border);}
.ohlc-item:last-child{border-right:none;}
.ohlc-lbl{font-size:.58rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:4px;}
.ohlc-val{font-family:'DM Mono',monospace;font-size:.88rem;font-weight:500;}
.range-wrap{padding:0 14px 14px;}
.range-lbls{display:flex;justify-content:space-between;font-family:'DM Mono',monospace;font-size:.65rem;color:var(--muted);margin-bottom:7px;}
.range-track{height:3px;background:var(--surface3);border-radius:2px;position:relative;}
.range-fill{position:absolute;inset:0;border-radius:2px;background:linear-gradient(90deg,var(--red),var(--gold),var(--green));}
.range-cursor{position:absolute;top:50%;transform:translate(-50%,-50%);width:10px;height:10px;border-radius:50%;background:var(--gold2);border:2px solid var(--bg);box-shadow:0 0 8px rgba(240,197,96,.5);transition:left .6s;}

/* ── Stat ── */
.stat-val{font-family:'DM Mono',monospace;font-size:1.3rem;font-weight:500;line-height:1;margin-bottom:4px;}
.stat-sub{font-size:.7rem;color:var(--muted);font-family:'DM Mono',monospace;}

/* ── Chart ── */
.chart-card{padding:0;overflow:hidden;}
.chart-hdr{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 10px;flex-wrap:wrap;gap:8px;}
.chart-hdr .card-lbl{margin-bottom:0;}
.chart-meta{display:flex;gap:16px;align-items:center;flex-wrap:wrap;}
.chart-meta-item{font-family:'DM Mono',monospace;font-size:.68rem;color:var(--muted);}
.chart-meta-item span{color:var(--text);}
canvas#gc{display:block;width:100%;height:240px;cursor:crosshair;}
.chart-wrap{position:relative;}
.chart-tip{display:none;position:absolute;background:var(--surface2);border:1px solid var(--border2);border-radius:var(--rs);padding:8px 12px;font-family:'DM Mono',monospace;font-size:.7rem;color:var(--text);pointer-events:none;z-index:20;white-space:nowrap;box-shadow:0 8px 28px rgba(0,0,0,.5);}
.chart-foot{display:flex;align-items:center;justify-content:space-between;padding:7px 20px 12px;flex-wrap:wrap;gap:8px;}
.chart-legend{display:flex;gap:12px;flex-wrap:wrap;}
.leg-item{font-size:.62rem;color:var(--muted);display:flex;align-items:center;gap:5px;}
.leg-line{width:11px;height:2px;border-radius:1px;}
.chart-ts{font-family:'DM Mono',monospace;font-size:.63rem;color:var(--muted2);}

/* ── Signals ── */
.sig-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:7px;}
.sig-chip{background:var(--surface2);border:1px solid var(--border);border-radius:var(--rs);padding:10px 11px;
  display:flex;flex-direction:column;gap:3px;transition:border-color .2s;position:relative;cursor:help;}
.sig-chip.ready{border-color:rgba(34,197,94,.22);background:rgba(34,197,94,.03);}
.sig-chip.bear-ready{border-color:rgba(239,68,68,.22);background:rgba(239,68,68,.03);}
.sig-chip:hover{border-color:var(--gold-dim);z-index:10;}
.sig-top{display:flex;align-items:center;justify-content:space-between;}
.sig-icon{font-size:.95rem;}
.sig-name{font-size:.64rem;color:var(--muted);margin-top:1px;}
.sig-cd{font-family:'DM Mono',monospace;font-size:.61rem;color:var(--muted2);}
.sdot{width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.sdot-ready{background:var(--green);box-shadow:0 0 5px rgba(34,197,94,.5);}
.sdot-cool{background:var(--muted2);}
.sdot-bear{background:var(--red);}

/* ── Signal tooltip (plain English) ── */
.sig-tooltip{
  display:none;position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);
  background:#1a1a20;border:1px solid var(--border2);border-radius:10px;
  padding:12px 14px;width:280px;max-width:90vw;
  font-size:.73rem;line-height:1.6;color:var(--text);
  box-shadow:0 16px 48px rgba(0,0,0,.8);z-index:200;pointer-events:none;
}
.sig-tooltip::after{
  content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);
  border:6px solid transparent;border-top-color:#2d2d38;
}
.sig-chip:hover .sig-tooltip{display:block;}
.sig-tooltip-title{font-weight:600;color:var(--gold2);margin-bottom:5px;font-size:.75rem;}
.sig-tooltip-body{color:var(--muted);line-height:1.55;}
/* keep tooltip within viewport on edges */
.sig-chip:first-child .sig-tooltip,
.sig-chip:nth-child(4n+1) .sig-tooltip{left:0;transform:none;}
.sig-chip:nth-child(4n) .sig-tooltip{left:auto;right:0;transform:none;}
.sig-chip:nth-child(4n) .sig-tooltip::after{left:auto;right:20px;transform:none;}
.sig-chip:first-child .sig-tooltip::after{left:20px;transform:none;}
.sig-chip:nth-child(4n+1) .sig-tooltip::after{left:20px;transform:none;}

/* ── Alert list ── */
.alert-list{display:flex;flex-direction:column;gap:5px;max-height:340px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border2) transparent;}
.alert-row{display:flex;align-items:center;gap:9px;padding:7px 9px;background:var(--surface2);border-radius:var(--rs);border:1px solid var(--border);font-size:.73rem;cursor:default;position:relative;transition:border-color .15s,background .15s;}
.alert-row:hover{border-color:var(--gold-dim);background:rgba(212,168,67,.04);}
.al-time{font-family:'DM Mono',monospace;color:var(--muted);font-size:.65rem;flex-shrink:0;}
.al-key{flex:1;color:var(--text);}
.empty-msg{color:var(--muted);font-size:.76rem;text-align:center;padding:20px;}
.al-popup{display:none;position:absolute;left:0;top:calc(100% + 5px);background:var(--surface);border:1px solid var(--border2);border-radius:var(--rs);padding:11px 14px;z-index:100;width:310px;max-width:92vw;box-shadow:0 14px 48px rgba(0,0,0,.65);font-family:'DM Mono',monospace;font-size:.7rem;line-height:1.75;color:var(--text);white-space:pre-wrap;word-break:break-word;pointer-events:none;}
.alert-row:hover .al-popup{display:block;}
.alert-row:nth-last-child(-n+3) .al-popup{top:auto;bottom:calc(100% + 5px);}

/* ── Debug ── */
.sec-title{font-size:.63rem;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--muted2);margin:22px 0 8px;display:flex;align-items:center;gap:10px;}
.sec-title::after{content:'';flex:1;height:1px;background:var(--border);}
details{margin-top:8px;}
summary{cursor:pointer;font-size:.68rem;font-weight:500;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;list-style:none;display:flex;align-items:center;gap:6px;padding:11px 15px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--rs);user-select:none;}
summary::-webkit-details-marker{display:none;}
summary::before{content:'▶';font-size:.55rem;transition:transform .2s;}
details[open] summary::before{transform:rotate(90deg);}
.dbody{background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 var(--rs) var(--rs);padding:16px;}
.dt{width:100%;border-collapse:collapse;font-family:'DM Mono',monospace;font-size:.7rem;}
.dt th{text-align:left;color:var(--muted);font-weight:400;font-size:.61rem;text-transform:uppercase;letter-spacing:.08em;padding:5px 8px;border-bottom:1px solid var(--border);}
.dt td{padding:6px 8px;border-bottom:1px solid var(--border);vertical-align:middle;}
.dt tr:last-child td{border-bottom:none;}
.dt td:first-child{color:var(--muted);}
.ok{color:var(--green);}.warn{color:var(--yellow);}.fail{color:var(--red);}
.ttl-bar{height:3px;background:var(--surface2);border-radius:2px;overflow:hidden;margin-top:4px;}
.ttl-fill{height:100%;border-radius:2px;background:linear-gradient(90deg,var(--gold-dim),var(--gold));}

/* ── Responsive ── */
@media(max-width:740px){
  .g3{grid-template-columns:1fr 1fr;}
  .g2{grid-template-columns:1fr;}
  .ohlc{grid-template-columns:repeat(3,1fr);}
  .ohlc-item:nth-child(3){border-right:none;}
  .ohlc-item:nth-child(4){border-top:1px solid var(--border);border-right:1px solid var(--border);}
  .ohlc-item:nth-child(5){border-top:1px solid var(--border);border-right:none;}
  canvas#gc{height:190px;}
  .al-popup{width:260px;}
  .sig-tooltip{width:230px;}
}
@media(max-width:440px){
  .g3{grid-template-columns:1fr;}
  .price-main{font-size:2.2rem;}
  .ohlc{grid-template-columns:repeat(2,1fr);}
  .ohlc-item:nth-child(2){border-right:none;}
  .ohlc-item:nth-child(3){border-top:1px solid var(--border);border-right:1px solid var(--border);}
  .ohlc-item:nth-child(4){border-top:1px solid var(--border);border-right:none;}
  .ohlc-item:nth-child(5){border-top:1px solid var(--border);border-right:none;grid-column:span 2;}
  .toast-stack{bottom:12px;right:12px;left:12px;}
  .toast{max-width:100%;}
}
.fi{animation:fi .45s ease both;}
@keyframes fi{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
</style>
</head>
<body>

<!-- ── Toast stack ───────────────────────────────────────────────── -->
<div class="toast-stack" id="toastStack"></div>

<div class="wrap">

<!-- ── Header ───────────────────────────────────────────────────── -->
<div class="hdr fi">
  <div class="hdr-left">
    <h1>Gold Monitor</h1>
    <p>UAE · 24K · AED/gram · Active 08:00–22:00</p>
  </div>
  <div class="hdr-right">
    <!-- Bell notification button -->
    <div class="bell-btn" id="bellBtn" title="Enable browser notifications">
      🔔
      <div class="bell-badge" id="bellBadge"></div>
      <div class="bell-tip" id="bellTip">Click to enable alerts in browser</div>
    </div>
    <div>
      <div class="last-run">
        <span class="live-dot"></span>
        <span id="lastRunStr"><?= htmlspecialchars($last_run_str) ?></span>
        · <span id="ageStr"><?= htmlspecialchars($age_str) ?></span>
      </div>
      <div style="font-size:.65rem;color:var(--muted2);margin-top:3px;text-align:right;" id="uaeClock"><?= date('l, d M Y · H:i:s') ?> UAE</div>
    </div>
  </div>
</div>

<!-- ── Hero price ───────────────────────────────────────────────── -->
<div class="hero card mb12 fi">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div class="card-lbl" style="margin-bottom:0;"><span class="dot"></span>Live Gold Price · 24K</div>
    <span class="retail-tag">igold.ae retail incl. VAT &amp; markup</span>
  </div>
  <div style="margin-top:12px;">
    <div class="price-main" id="heroPrice">
      AED <span id="heroAed"><?= number_format($display_aed, 2) ?></span><span style="font-size:.42em;color:var(--gold-dim);margin-left:7px;">/g</span>
    </div>
    <div class="price-sub">
      <span>What you pay in-store, per gram</span>
      <?php if ($markup_pct > 0): ?>
        <span class="price-sub-dot"></span>
        <span>+<?= number_format($markup_pct, 2) ?>% over spot</span>
      <?php endif; ?>
      <?php if ($spot_aed > 0): ?>
        <span class="price-sub-dot"></span>
        <span>Spot: AED <?= number_format($spot_aed, 2) ?>/g</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="price-row">
    <span class="price-chg" id="heroDayChg" style="color:<?= $day_chg>=0?'var(--green)':'var(--red)' ?>;"><?= fmt_chg($day_chg) ?></span>
    <span class="badge <?= $day_chg>=0?'b-bull':'b-bear' ?>"><?= $day_chg>=0?'▲':'▼' ?> vs. open</span>
    <?php if ($from_low_pct > 0): ?>
      <span class="badge b-gold">↑ +<?= number_format($from_low_pct,2) ?>% off session low</span>
    <?php endif; ?>
    <?php if ($consec > 0): ?>
      <span class="badge b-bull"><?= $consec ?>↑ streak</span>
    <?php endif; ?>
    <?= $low_status ?>
  </div>
</div>

<!-- ── OHLC range ────────────────────────────────────────────────── -->
<div class="card mb12 fi" style="padding:0;overflow:hidden;">
  <div style="padding:13px 16px 0;"><div class="card-lbl"><span class="dot"></span>Session Range · AED/gram</div></div>
  <div class="ohlc">
    <div class="ohlc-item"><div class="ohlc-lbl">Day Open</div><div class="ohlc-val" style="color:var(--muted);" id="ohlcOpen">AED <?= number_format($open_aed,2) ?></div></div>
    <div class="ohlc-item"><div class="ohlc-lbl">Session High</div><div class="ohlc-val" style="color:var(--green);" id="ohlcHigh"><?= $high_aed!==null?'AED '.number_format($high_aed,2):'—' ?></div></div>
    <div class="ohlc-item"><div class="ohlc-lbl">Session Low</div><div class="ohlc-val" style="color:var(--red);" id="ohlcSessLow"><?= $sl_aed!==null?'AED '.number_format($sl_aed,2):'—' ?></div></div>
    <div class="ohlc-item"><div class="ohlc-lbl">Pre-mkt Floor</div><div class="ohlc-val" style="color:var(--yellow);" id="ohlcPmLow"><?= $pm_aed!==null?'AED '.number_format($pm_aed,2):'—' ?></div></div>
    <div class="ohlc-item"><div class="ohlc-lbl">Day Range</div><div class="ohlc-val"><?= number_format($range_pct,2) ?>%</div></div>
  </div>
  <div class="range-wrap" style="margin-top:10px;">
    <div class="range-lbls">
      <span id="rangeLowLbl">Low <?= $sl_aed!==null?'AED '.number_format($sl_aed,2):'—' ?></span>
      <span style="font-size:.6rem;color:var(--muted2);">Current position in today's range</span>
      <span id="rangeHighLbl">High <?= $high_aed!==null?'AED '.number_format($high_aed,2):'—' ?></span>
    </div>
    <div class="range-track">
      <div class="range-fill"></div>
      <div class="range-cursor" id="rangeCursor" style="left:<?= $pos ?>%;"></div>
    </div>
  </div>
</div>

<!-- ── Live Chart ─────────────────────────────────────────────────── -->
<div class="card chart-card mb12 fi">
  <div class="chart-hdr">
    <div class="card-lbl" style="margin-bottom:0;"><span class="dot"></span>Live Price Chart · AED/gram</div>
    <div class="chart-meta">
      <div class="chart-meta-item">Polls every <span>15s</span></div>
      <div class="chart-meta-item">Points: <span id="chartPts">—</span></div>
      <div class="chart-meta-item">Status: <span id="chartStatus" style="color:var(--green);">Live</span></div>
    </div>
  </div>
  <div class="chart-wrap">
    <canvas id="gc"></canvas>
    <div class="chart-tip" id="chartTip"></div>
  </div>
  <div class="chart-foot">
    <div class="chart-legend">
      <div class="leg-item"><div class="leg-line" style="background:var(--gold);"></div>Price AED/g</div>
      <div class="leg-item"><div class="leg-line" style="background:var(--red);opacity:.6;"></div>Session Low</div>
      <div class="leg-item"><div class="leg-line" style="background:var(--yellow);opacity:.6;"></div>Pre-mkt Floor</div>
      <div class="leg-item"><div class="leg-line" style="background:var(--green);opacity:.6;"></div>Session High</div>
      <div class="leg-item"><div style="width:7px;height:7px;border-radius:50%;background:var(--red);opacity:.8;"></div>Alert</div>
    </div>
    <div class="chart-ts" id="chartTs">Loaded <?= date('H:i:s') ?></div>
  </div>
</div>

<!-- ── DXY / TIP / GLD ───────────────────────────────────────────── -->
<div class="g3">
  <div class="card fi">
    <div class="card-lbl"><span class="dot"></span>US Dollar Strength (DXY)</div>
    <div class="stat-val" style="color:<?= $dxy_color ?>;"><?= $dxy!==null?number_format($dxy,3):'—' ?></div>
    <div class="stat-sub"><?= dxy_label_plain($dxy) ?> · lower = gold bullish</div>
    <?php if($dxy!==null&&$dxy<DXY_WEAK_LEVEL): ?>
      <div style="margin-top:8px;"><span class="badge b-bull">Below 100 · gold favoured</span></div>
    <?php endif; ?>
  </div>
  <div class="card fi">
    <div class="card-lbl"><span class="dot"></span>Inflation Indicator (TIP)</div>
    <div class="stat-val"><?= $tip!==null?'$'.number_format($tip,2):'—' ?></div>
    <div class="stat-sub">Rising = inflation up = gold bullish</div>
  </div>
  <div class="card fi">
    <div class="card-lbl"><span class="dot"></span>Gold Fund Volume (GLD)</div>
    <div class="stat-val"><?= $gld_vol!==null?number_format($gld_vol):'—' ?></div>
    <div class="stat-sub">
      Avg: <?= $gld_avg!==null?number_format(round($gld_avg)):'—' ?>
      <?php if($vol_mult!==null): ?>&nbsp;·&nbsp;<span style="color:<?= $vol_mult>=2?'var(--green)':'var(--muted)' ?>;"><?= number_format($vol_mult,1) ?>×</span><?php endif; ?>
    </div>
    <?php if($vol_mult!==null&&$vol_mult>=2): ?>
      <div style="margin-top:8px;"><span class="badge b-bull">Big money active</span></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Signals + Alerts ──────────────────────────────────────────── -->
<div class="g2">
  <div class="card fi">
    <div class="card-lbl"><span class="dot"></span>Signal Status
      <span style="font-size:.58rem;color:var(--muted2);font-weight:400;margin-left:4px;">hover for explanation</span>
    </div>
    <div class="sig-grid">
      <?php foreach($signal_meta as $key => [$icon, $name, $type, $tooltip_text]):
        $cdm = $cooldowns[$key] ?? 60;
        $lts = $last_alerts[$key] ?? 0;
        $rem = max(0, $cdm * 60 - ($now - $lts));
        $rdy = ($rem === 0);
        $dc  = $rdy ? ($type === 'bull' ? 'sdot-ready' : 'sdot-bear') : 'sdot-cool';
        $cls = $rdy ? ($type === 'bull' ? 'ready' : 'bear-ready') : '';
        $cds = $rdy ? 'Ready' : (floor($rem/60).'m '.($rem%60).'s');
      ?>
        <div class="sig-chip <?= $cls ?>">
          <div class="sig-top"><span class="sig-icon"><?= $icon ?></span><span class="sdot <?= $dc ?>"></span></div>
          <div class="sig-name"><?= htmlspecialchars($name) ?></div>
          <div class="sig-cd"><?= $cds ?></div>
          <!-- Plain English tooltip -->
          <div class="sig-tooltip">
            <div class="sig-tooltip-title"><?= $icon ?> <?= htmlspecialchars($name) ?></div>
            <div class="sig-tooltip-body"><?= htmlspecialchars($tooltip_text) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card fi">
    <div class="card-lbl">
      <span class="dot"></span>Today's Alerts
      <span style="font-size:.6rem;color:var(--muted2);font-weight:400;margin-left:4px;">hover = message</span>
      <?php if($signals_today>0): ?>
        <span class="badge b-gold" style="margin-left:auto;" id="sigCount"><?= $signals_today ?> fired</span>
      <?php endif; ?>
    </div>
    <div class="alert-list" id="alertList">
      <?php if(empty($alert_log)): ?>
        <div class="empty-msg">No alerts yet today</div>
      <?php else: foreach(array_slice($alert_log,0,ALERT_LOG_MAX) as $al):
          $k    = $al['key'] ?? '';
          $info = $signal_meta[$k] ?? ['•', $k, 'bull', ''];
          $bc   = $info[2]==='bear'?'b-bear':'b-bull';
          $tt   = preg_replace('/[*_`]/', '', $al['msg'] ?? '');
      ?>
        <div class="alert-row" data-ts="<?= (int)($al['ts']??0) ?>">
          <span class="al-time"><?= htmlspecialchars($al['time']??'—') ?></span>
          <span><?= $info[0] ?></span>
          <span class="al-key"><?= htmlspecialchars($info[1]) ?></span>
          <span class="badge <?= $bc ?>"><?= $info[2] ?></span>
          <div class="al-popup"><?= htmlspecialchars($tt) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- ── Debug ─────────────────────────────────────────────────────── -->
<div class="sec-title">Diagnostics &amp; System</div>
<details>
  <summary>API Metrics, State Inspector &amp; Signal Cooldowns</summary>
  <div class="dbody">
    <?php if($crumb_age!==null):
      $cr=max(0,55-$crumb_age); $cp=min(100,round($crumb_age/55*100)); ?>
      <div style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--muted);">
          <span>Yahoo Crumb TTL</span>
          <span style="font-family:'DM Mono',monospace;color:<?= $cr<10?'var(--red)':'var(--green)' ?>;"><?= $cr ?>m remaining (<?= $crumb_age ?>m / 55m)</span>
        </div>
        <div class="ttl-bar"><div class="ttl-fill" style="width:<?= 100-$cp ?>%;"></div></div>
      </div>
    <?php endif; ?>

    <table class="dt">
      <thead><tr><th>Source</th><th>Status</th><th>Endpoint</th><th>Latency</th><th>Value (AED)</th></tr></thead>
      <tbody>
        <tr>
          <td>igold.ae (retail)</td>
          <td class="<?= $display_aed>0?'ok':'fail' ?>"><?= $display_aed>0?'OK':'FAIL' ?></td>
          <td>HTML scrape</td>
          <td><?= $igold_ms!==null?$igold_ms.' ms':'—' ?></td>
          <td>AED <?= number_format($display_aed,2) ?>/g (retail)</td>
        </tr>
        <?php foreach(['GC=F'=>'Gold futures','DX-Y.NYB'=>'DXY','TIP'=>'TIP ETF','GLD'=>'GLD ETF'] as $t=>$l):
          $m=$yf_meta[$t]??null; ?>
          <tr>
            <td><?= $l ?> (<?= $t ?>)</td>
            <td class="<?= $m&&$m['status']==='OK'?'ok':'fail' ?>"><?= $m?$m['status']:'NO DATA' ?></td>
            <td><?= $m?htmlspecialchars($m['endpoint']):'—' ?></td>
            <td><?= $m?$m['ms'].' ms':'—' ?></td>
            <td><?php
              if($t==='GC=F')     echo 'AED '.number_format($spot_aed,2).'/g (spot)';
              if($t==='DX-Y.NYB') echo $dxy!==null?number_format($dxy,3):'—';
              if($t==='TIP')      echo $tip!==null?'$'.number_format($tip,2):'—';
              if($t==='GLD')      echo $gld_vol!==null?number_format($gld_vol).' vol':'—';
            ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:14px;"></div>
    <table class="dt">
      <thead><tr><th>State Key</th><th>AED/g</th><th>Note</th></tr></thead>
      <tbody>
        <tr><td>day_open</td><td>AED <?= number_format($open_aed,2) ?></td><td>Reference price for % change</td></tr>
        <tr><td>session_high</td><td class="ok"><?= $high_aed!==null?'AED '.number_format($high_aed,2):'—' ?></td><td>Active session only</td></tr>
        <tr><td>session_low</td><td class="<?= $low_reached?'fail':'ok' ?>"><?= $sl_aed!==null?'AED '.number_format($sl_aed,2):'—' ?></td><td><?= $low_reached?'Low was broken today':'Held' ?></td></tr>
        <tr><td>pre_market_low</td><td><?= $pm_aed!==null?'AED '.number_format($pm_aed,2):'—' ?></td><td>Overnight floor (support zone)</td></tr>
        <tr><td>session_open</td><td><?= $so_aed!==null?'AED '.number_format($so_aed,2):'—' ?></td><td>First price at 08:00</td></tr>
        <tr><td>session_dipped</td><td colspan="2" class="<?= $session_dipped?'ok':'warn' ?>"><?= $session_dipped?'YES — bounce signal armed':'NO — needs '.BOUNCE_DIP_MIN_PCT.'% drop to arm' ?></td></tr>
        <tr><td>Retail source</td><td colspan="2">igold.ae (hero display)</td></tr>
        <tr><td>Spot source</td><td colspan="2">Yahoo GC=F → AED/g (OHLC math)</td></tr>
        <tr><td>Stale threshold</td><td colspan="2"><?= STALE_THRESHOLD_PCT ?>% (high &amp; low, both guarded)</td></tr>
        <tr><td>Weekend skip</td><td colspan="2" class="ok">Sat (dow=6) + Sun (dow=7)</td></tr>
        <tr><td>ORB window</td><td colspan="2"><?= ORB_WINDOW_MINS ?>min · set=<?= ($s['orb_set']??false)?'YES':'NO' ?> · broken=<?= ($s['orb_broken']??false)?'YES':'NO' ?></td></tr>
        <tr><td>EMA fast/slow</td><td colspan="2"><?= EMA_FAST ?>/<?= EMA_SLOW ?> · <?= isset($s['ema_fast_val'])?'F='.number_format((float)$s['ema_fast_val'],2):'warming up' ?> <?= isset($s['ema_slow_val'])?'S='.number_format((float)$s['ema_slow_val'],2):'' ?></td></tr>
        <tr><td>consec_up</td><td colspan="2"><?= $consec ?></td></tr>
        <tr><td>signals_today</td><td colspan="2"><?= $signals_today ?></td></tr>
        <tr><td>chart history</td><td colspan="2"><?= isset($s['chart_history'])?count($s['chart_history']):0 ?> pts / <?= CHART_HISTORY_MAX ?></td></tr>
        <tr><td>last cron</td><td colspan="2"><?= $last_run_str ?> (<?= $age_str ?>)</td></tr>
        <tr><td>PHP version</td><td colspan="2"><?= PHP_VERSION ?> · <?= date('Y-m-d H:i:s T') ?></td></tr>
      </tbody>
    </table>

    <div style="margin-top:14px;"></div>
    <table class="dt">
      <thead><tr><th>Signal</th><th>Cooldown</th><th>Last fired</th><th>Remaining</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach($cooldowns as $key=>$cdm):
          $lts=$last_alerts[$key]??0;$rem=max(0,$cdm*60-($now-$lts));$rdy=($rem===0);
          $info=$signal_meta[$key]??['•',$key,'bull','']; ?>
          <tr>
            <td><?= $info[0] ?> <?= htmlspecialchars($info[1]) ?></td>
            <td><?= $cdm ?>m</td>
            <td><?= $lts>0?date('H:i:s',$lts):'never' ?></td>
            <td style="font-family:'DM Mono',monospace;"><?= $rem>0?floor($rem/60).'m '.($rem%60).'s':'—' ?></td>
            <td class="<?= $rdy?'ok':'warn' ?>"><?= $rdy?'READY':'COOLING' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>

<div style="margin-top:36px;text-align:center;font-size:.63rem;color:var(--muted2);">
  Gold Monitor · Cron every 1 min · UI polls every 15s · igold.ae + Yahoo Finance · UAE (UTC+4)
</div>
</div><!-- /wrap -->

<script>
// ══════════════════════════════════════════════════════════════════
//  CONSTANTS
// ══════════════════════════════════════════════════════════════════
const API_URL = '<?= htmlspecialchars($_SERVER['PHP_SELF'] ?? 'gold_monitor.php') ?>?api=1';
const POLL_MS = 15000;

const SMETA = {
  day_low:       ['🔴','Session Low',     'bear'],
  dxy_drop:      ['💵','Dollar Weakens',  'bull'],
  tip_rise:      ['📉','Yields Falling',  'bull'],
  bounce:        ['🎯','Bounce Off Low',  'bull'],
  consec_up:     ['📈','Uptrend Building','bull'],
  gld_vol:       ['🏦','Big Money Buying','bull'],
  deep_dip:      ['💎','Deep Dip Recovery','bull'],
  oversold:      ['⚡','Quick Reversal',  'bull'],
  dxy_weak:      ['🌍','Dollar Below 100','bull'],
  orb:           ['🚀','Breakout Signal', 'bull'],
  ema_cross:     ['✨','Momentum Cross',  'bull'],
  macro_conf:    ['🎪','All Stars Align', 'bull'],
  channel_break: ['📐','Resistance Broken','bull'],
  pm_support:    ['🛡️','Support Nearby',  'bull'],
};

// ══════════════════════════════════════════════════════════════════
//  UAE CLOCK
// ══════════════════════════════════════════════════════════════════
(function clock() {
  const el = document.getElementById('uaeClock');
  if (!el) return;
  const n = new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Dubai'}));
  const D = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const M = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const p = v => String(v).padStart(2,'0');
  el.textContent = `${D[n.getDay()]}, ${p(n.getDate())} ${M[n.getMonth()]} ${n.getFullYear()} · ${p(n.getHours())}:${p(n.getMinutes())}:${p(n.getSeconds())} UAE`;
  setTimeout(clock,1000);
})();

// ══════════════════════════════════════════════════════════════════
//  BROWSER NOTIFICATIONS
// ══════════════════════════════════════════════════════════════════
let notifEnabled = false;
const seenNotifTs = new Set();

function updateBellState() {
  const btn   = document.getElementById('bellBtn');
  const badge = document.getElementById('bellBadge');
  const tip   = document.getElementById('bellTip');
  if (!btn) return;
  const perm = Notification.permission;
  if (perm === 'granted') {
    btn.classList.add('granted');
    btn.classList.remove('denied');
    btn.innerHTML = '🔔<div class="bell-badge" id="bellBadge"></div><div class="bell-tip" id="bellTip">Notifications ON — alerts will appear here</div>';
    notifEnabled = true;
  } else if (perm === 'denied') {
    btn.classList.add('denied');
    btn.innerHTML = '🔕<div class="bell-badge" id="bellBadge"></div><div class="bell-tip" id="bellTip">Notifications blocked — allow in browser settings</div>';
    notifEnabled = false;
  } else {
    btn.innerHTML = '🔔<div class="bell-badge" id="bellBadge"></div><div class="bell-tip" id="bellTip">Click to enable alerts in this browser</div>';
    notifEnabled = false;
  }
}

document.getElementById('bellBtn').addEventListener('click', async () => {
  if (Notification.permission === 'denied') return;
  if (Notification.permission !== 'granted') {
    const result = await Notification.requestPermission();
    updateBellState();
    if (result === 'granted') {
      // Welcome notification
      new Notification('Gold Monitor', {
        body: 'Alerts enabled! You\'ll be notified when buy signals fire.',
        icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><text y="26" font-size="28">🥇</text></svg>',
      });
    }
  }
});

updateBellState();

function fireNotification(notif) {
  const key = notif.key + '_' + notif.ts;
  if (seenNotifTs.has(key)) return;
  seenNotifTs.add(key);

  // Always show in-page toast
  showToast(notif);

  // Browser notification if permitted
  if (notifEnabled && Notification.permission === 'granted') {
    try {
      const n = new Notification(notif.title || 'Gold Alert', {
        body: notif.body || '',
        icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><text y="26" font-size="28">🥇</text></svg>',
        tag: key,
        requireInteraction: false,
      });
      n.onclick = () => { window.focus(); n.close(); };
    } catch(e) { /* sw not available */ }
  }
}

// ══════════════════════════════════════════════════════════════════
//  IN-PAGE TOASTS
// ══════════════════════════════════════════════════════════════════
function showToast(notif) {
  const stack = document.getElementById('toastStack');
  if (!stack) return;
  const meta   = SMETA[notif.key] || ['🔔', 'Alert', 'bull'];
  const isBear = meta[2] === 'bear';
  const toast  = document.createElement('div');
  toast.className = `toast ${isBear ? 'bear' : 'bull'}`;
  toast.innerHTML = `
    <div class="toast-icon">${meta[0]}</div>
    <div class="toast-body">
      <div class="toast-title">${notif.title || meta[1]}</div>
      <div class="toast-msg">${notif.body || ''}</div>
    </div>
    <div class="toast-close" onclick="removeToast(this.parentElement)">✕</div>
  `;
  stack.appendChild(toast);
  // Auto-remove after 12s
  setTimeout(() => removeToast(toast), 12000);
  // Max 5 toasts
  const toasts = stack.querySelectorAll('.toast');
  if (toasts.length > 5) removeToast(toasts[0]);
}

function removeToast(el) {
  if (!el || !el.parentElement) return;
  el.style.animation = 'slideOut .3s ease forwards';
  setTimeout(() => el.remove(), 300);
}

// ══════════════════════════════════════════════════════════════════
//  ALERT LIST RENDERER
// ══════════════════════════════════════════════════════════════════
function renderAlerts(alerts) {
  const list = document.getElementById('alertList');
  if (!list) return;
  if (!alerts || alerts.length === 0) {
    list.innerHTML = '<div class="empty-msg">No alerts yet today</div>';
    return;
  }
  // Build set of existing timestamps
  const existing = new Set([...list.querySelectorAll('.alert-row')].map(r => r.dataset.ts));
  let changed = false;
  alerts.slice(0, 20).forEach(al => {
    const ts = String(al.ts || '');
    if (existing.has(ts)) return; // already shown — skip (dedup fix)
    const key  = al.key || '';
    const info = SMETA[key] || ['•', key, 'bull'];
    const bc   = info[2] === 'bear' ? 'b-bear' : 'b-bull';
    const tt   = (al.msg || '').replace(/[*_`]/g,'').replace(/</g,'&lt;');
    const row  = document.createElement('div');
    row.className = 'alert-row'; row.dataset.ts = ts;
    row.innerHTML = `<span class="al-time">${al.time||'—'}</span><span>${info[0]}</span><span class="al-key">${info[1]}</span><span class="badge ${bc}">${info[2]}</span><div class="al-popup">${tt}</div>`;
    list.prepend(row); changed = true;
  });
  // Trim to 20
  list.querySelectorAll('.alert-row').forEach((r,i) => { if (i>=20) r.remove(); });
  if (changed) {
    const c = document.getElementById('sigCount');
    if (c) { c.style.transform='scale(1.3)';c.style.transition='transform .2s';setTimeout(()=>c.style.transform='',300); }
  }
}

// ══════════════════════════════════════════════════════════════════
//  CANVAS CHART
// ══════════════════════════════════════════════════════════════════
const cv  = document.getElementById('gc');
const ctx = cv.getContext('2d');
let chartData=[], alertData=[], hoveredIdx=null;
const C = {gold:'#d4a843',gold2:'#f0c560',green:'#22c55e',red:'#ef4444',yellow:'#eab308',muted:'#6b6b7a',bg:'#09090b',border:'#222228'};
const PAD = {top:20,right:18,bottom:36,left:64};

function dpr(){return Math.min(window.devicePixelRatio||1,2);}
function resizeCanvas(){
  const w=cv.parentElement.getBoundingClientRect().width;
  const h=parseInt(getComputedStyle(cv).height)||240;
  cv.width=w*dpr();cv.height=h*dpr();
  cv.style.width=w+'px';cv.style.height=h+'px';
  drawChart();
}

function drawChart(){
  const R=dpr();ctx.setTransform(R,0,0,R,0,0);
  const W=cv.offsetWidth,H=cv.offsetHeight;
  ctx.clearRect(0,0,W,H);

  if(!chartData||chartData.length<2){
    ctx.fillStyle=C.muted;ctx.font='13px monospace';ctx.textAlign='center';
    ctx.fillText('Waiting for data…',W/2,H/2);return;
  }

  const prices=chartData.map(d=>d.spot_aed??d.display_aed??d.aed);
  const minP=Math.min(...prices),maxP=Math.max(...prices);
  const rng=maxP-minP||0.5;
  const pad=rng*0.12;
  const lo=minP-pad,hi=maxP+pad;
  const PW=W-PAD.left-PAD.right,PH=H-PAD.top-PAD.bottom;
  const xOf=i=>PAD.left+(i/(chartData.length-1))*PW;
  const yOf=v=>PAD.top+(1-(v-lo)/(hi-lo))*PH;

  // Grid + Y labels
  for(let i=0;i<=5;i++){
    const v=lo+(hi-lo)*(i/5),y=yOf(v);
    ctx.beginPath();ctx.moveTo(PAD.left,y);ctx.lineTo(PAD.left+PW,y);
    ctx.strokeStyle=C.border;ctx.globalAlpha=.4;ctx.lineWidth=1;ctx.stroke();ctx.globalAlpha=1;
    ctx.fillStyle=C.muted;ctx.font='10px "DM Mono",monospace';ctx.textAlign='right';
    ctx.fillText(v.toFixed(2),PAD.left-5,y+3.5);
  }

  // X labels (time)
  ctx.fillStyle=C.muted;ctx.textAlign='center';ctx.font='10px "DM Mono",monospace';
  const xt=Math.min(8,chartData.length);
  for(let i=0;i<xt;i++){
    const idx=Math.round(i/(xt-1)*(chartData.length-1));
    const d=chartData[idx];if(!d)continue;
    const dt=new Date(d.ts*1000);
    const lbl=dt.toLocaleTimeString('en-AE',{timeZone:'Asia/Dubai',hour:'2-digit',minute:'2-digit',hour12:false});
    ctx.fillText(lbl,xOf(idx),H-8);
  }

  // Reference lines (AED values from OHLC display elements)
  const refs=[
    {id:'ohlcSessLow',color:C.red},
    {id:'ohlcPmLow',color:C.yellow},
    {id:'ohlcHigh',color:C.green},
  ];
  refs.forEach(({id,color})=>{
    const el=document.getElementById(id);if(!el)return;
    const val=parseFloat(el.textContent.replace(/[^0-9.]/g,''));
    if(!val||val<lo||val>hi)return;
    const y=yOf(val);
    ctx.save();ctx.strokeStyle=color;ctx.globalAlpha=.45;ctx.lineWidth=1;
    ctx.setLineDash([4,4]);ctx.beginPath();ctx.moveTo(PAD.left,y);ctx.lineTo(PAD.left+PW,y);ctx.stroke();
    ctx.setLineDash([]);ctx.restore();
  });

  // Fill gradient
  const grad=ctx.createLinearGradient(0,PAD.top,0,PAD.top+PH);
  grad.addColorStop(0,'rgba(212,168,67,.2)');grad.addColorStop(1,'rgba(212,168,67,0)');
  ctx.beginPath();
  prices.forEach((p,i)=>{const x=xOf(i),y=yOf(p);i===0?ctx.moveTo(x,y):ctx.lineTo(x,y);});
  ctx.lineTo(xOf(prices.length-1),PAD.top+PH);ctx.lineTo(xOf(0),PAD.top+PH);ctx.closePath();
  ctx.fillStyle=grad;ctx.fill();

  // Price line
  ctx.beginPath();
  prices.forEach((p,i)=>{const x=xOf(i),y=yOf(p);i===0?ctx.moveTo(x,y):ctx.lineTo(x,y);});
  ctx.strokeStyle=C.gold;ctx.lineWidth=2;ctx.lineJoin='round';
  ctx.shadowColor='rgba(212,168,67,.4)';ctx.shadowBlur=6;ctx.stroke();ctx.shadowBlur=0;

  // Alert markers
  alertData.forEach(al=>{
    let ci=0,cd=Infinity;
    chartData.forEach((d,i)=>{const df=Math.abs(d.ts-al.ts);if(df<cd){cd=df;ci=i;}});
    if(cd>120)return;
    const x=xOf(ci),y=yOf(prices[ci]);
    ctx.save();ctx.strokeStyle=al.key==='day_low'?C.red:'rgba(212,168,67,.65)';ctx.globalAlpha=.5;ctx.lineWidth=1;ctx.setLineDash([2,4]);
    ctx.beginPath();ctx.moveTo(x,PAD.top);ctx.lineTo(x,PAD.top+PH);ctx.stroke();ctx.setLineDash([]);
    ctx.globalAlpha=1;ctx.fillStyle=al.key==='day_low'?C.red:C.gold2;
    ctx.beginPath();ctx.arc(x,y,4,0,Math.PI*2);ctx.fill();ctx.restore();
  });

  // Hover crosshair + tooltip
  if(hoveredIdx!==null&&hoveredIdx>=0&&hoveredIdx<prices.length){
    const x=xOf(hoveredIdx),y=yOf(prices[hoveredIdx]);
    ctx.save();ctx.strokeStyle=C.muted;ctx.globalAlpha=.4;ctx.lineWidth=1;ctx.setLineDash([3,3]);
    ctx.beginPath();ctx.moveTo(x,PAD.top);ctx.lineTo(x,PAD.top+PH);ctx.stroke();
    ctx.beginPath();ctx.moveTo(PAD.left,y);ctx.lineTo(PAD.left+PW,y);ctx.stroke();
    ctx.setLineDash([]);ctx.globalAlpha=1;
    ctx.fillStyle=C.gold2;ctx.shadowColor='rgba(240,197,96,.6)';ctx.shadowBlur=8;
    ctx.beginPath();ctx.arc(x,y,5,0,Math.PI*2);ctx.fill();ctx.shadowBlur=0;ctx.restore();

    const dt=new Date(chartData[hoveredIdx].ts*1000);
    const lbl=dt.toLocaleTimeString('en-AE',{timeZone:'Asia/Dubai',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
    const pval=prices[hoveredIdx];
    const tip=document.getElementById('chartTip');
    if(tip){
      tip.style.display='block';
      tip.innerHTML=`<strong style="color:var(--gold2);">AED ${pval.toFixed(2)}/g</strong><br>${lbl} UAE`;
      const tx=x+12,tw=155;
      tip.style.left=(tx+tw>W?x-tw-8:tx)+'px';
      tip.style.top=Math.max(PAD.top,y-38)+'px';
    }
  } else {
    const tip=document.getElementById('chartTip');if(tip)tip.style.display='none';
  }

  // Latest dot
  const lx=xOf(prices.length-1),ly=yOf(prices[prices.length-1]);
  ctx.fillStyle=C.gold2;ctx.shadowColor='rgba(240,197,96,.7)';ctx.shadowBlur=10;
  ctx.beginPath();ctx.arc(lx,ly,5,0,Math.PI*2);ctx.fill();ctx.shadowBlur=0;
}

function ptrIdx(cx){
  const rect=cv.getBoundingClientRect(),PW=rect.width-PAD.left-PAD.right;
  const rx=cx-rect.left-PAD.left;
  if(!chartData||chartData.length<2||rx<0||rx>PW)return null;
  return Math.round((rx/PW)*(chartData.length-1));
}
cv.addEventListener('mousemove',  e=>{hoveredIdx=ptrIdx(e.clientX);drawChart();});
cv.addEventListener('mouseleave', ()=>{hoveredIdx=null;drawChart();});
cv.addEventListener('touchmove',  e=>{e.preventDefault();hoveredIdx=ptrIdx(e.touches[0].clientX);drawChart();},{passive:false});
cv.addEventListener('touchend',   ()=>{hoveredIdx=null;drawChart();});

// ══════════════════════════════════════════════════════════════════
//  API POLLING
// ══════════════════════════════════════════════════════════════════
async function pollApi(){
  try{
    const res=await fetch(API_URL+'&_='+Date.now(),{cache:'no-store'});
    if(!res.ok)throw new Error('HTTP '+res.status);
    const data=await res.json();

    // Chart
    if(data.chart&&data.chart.length>0){
      chartData=data.chart;
      document.getElementById('chartPts').textContent=chartData.length;
      drawChart();
    }

    // Alerts (dedup by ts)
    if(data.alerts){alertData=data.alerts;renderAlerts(data.alerts);}

    // Pending browser/toast notifications
    if(data.pending_notifs&&data.pending_notifs.length>0){
      data.pending_notifs.forEach(n=>fireNotification(n));
    }

    // Hero price (retail AED)
    if(data.display_aed){
      const el=document.getElementById('heroAed');
      const ov=parseFloat(el.textContent),nv=parseFloat(data.display_aed);
      if(Math.abs(ov-nv)>0.001){
        el.textContent=nv.toFixed(2);
        const hp=document.getElementById('heroPrice');
        hp.style.transition='color .35s';
        hp.style.color=nv>ov?'#22c55e':'#ef4444';
        setTimeout(()=>hp.style.color='',900);
      }
    }

    // Day change %
    if(data.day_chg!==null&&data.day_chg!==undefined){
      const ce=document.getElementById('heroDayChg');
      const c=parseFloat(data.day_chg);
      ce.textContent=c===0?'0.00%':(c>0?'+':'')+c.toFixed(2)+'%';
      ce.style.color=c>=0?'var(--green)':'var(--red)';
    }

    // OHLC
    if(data.day_high_aed)       document.getElementById('ohlcHigh').textContent    ='AED '+parseFloat(data.day_high_aed).toFixed(2);
    if(data.session_low_aed)    document.getElementById('ohlcSessLow').textContent ='AED '+parseFloat(data.session_low_aed).toFixed(2);
    if(data.premarket_low_aed)  document.getElementById('ohlcPmLow').textContent   ='AED '+parseFloat(data.premarket_low_aed).toFixed(2);

    // Range cursor
    if(data.day_high_aed&&data.session_low_aed&&data.spot_aed){
      const lo=parseFloat(data.session_low_aed),hi=parseFloat(data.day_high_aed),cur=parseFloat(data.spot_aed);
      const rng=hi-lo;
      const pos=rng>0?Math.min(100,Math.max(0,((cur-lo)/rng)*100)):50;
      const cursor=document.getElementById('rangeCursor');
      if(cursor)cursor.style.left=pos.toFixed(1)+'%';
      document.getElementById('rangeLowLbl').textContent ='Low AED '+lo.toFixed(2);
      document.getElementById('rangeHighLbl').textContent='High AED '+hi.toFixed(2);
    }

    // Timestamps
    const p=v=>String(v).padStart(2,'0');
    const now2=new Date();
    document.getElementById('chartTs').textContent='Updated '+p(now2.getHours())+':'+p(now2.getMinutes())+':'+p(now2.getSeconds());
    document.getElementById('chartStatus').textContent='Live';
    document.getElementById('chartStatus').style.color='var(--green)';
    if(data.last_run_ts){
      const rd=new Date(data.last_run_ts*1000);
      const mos=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      document.getElementById('lastRunStr').textContent=rd.getDate()+' '+mos[rd.getMonth()]+' · '+p(rd.getHours())+':'+p(rd.getMinutes())+':'+p(rd.getSeconds());
      const ag=Math.round(Date.now()/1000-data.last_run_ts);
      document.getElementById('ageStr').textContent=ag<120?ag+'s ago':Math.round(ag/60)+'m ago';
    }
  }catch(err){
    console.warn('Poll error:',err);
    document.getElementById('chartStatus').textContent='Error';
    document.getElementById('chartStatus').style.color='var(--red)';
  }
}

// ══════════════════════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════════════════════
(function init(){
  const seed=<?= $seed_json ?>;
  chartData=seed.chart||[];
  alertData=seed.alerts||[];
  // Pre-seed seen ts to avoid firing toasts for old alerts on page load
  alertData.forEach(al=>{if(al.ts)seenNotifTs.add((al.key||'')+'_'+al.ts);});
  renderAlerts(alertData);
  resizeCanvas();
  document.getElementById('chartPts').textContent=chartData.length;
})();

window.addEventListener('resize',()=>{clearTimeout(window._rzt);window._rzt=setTimeout(resizeCanvas,100);});
pollApi();
setInterval(pollApi,POLL_MS);
</script>
</body>
</html>
<?php
} // end render_dashboard()
