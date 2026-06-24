<?php
/**
 * Hotspot maintenance sweep — runs every minute from the cron container,
 * alongside traffic-poller.php. Handles two things:
 *   (a) Voucher expiry (below).
 *   (b) Free-trial session tracking: fills per-session bytes + end time for
 *       trial rows recorded by api/hotspot-trial (matched by MAC).
 *
 * Why this exists
 * ---------------
 * A hotspot voucher is pushed to the router as a hotspot user (name = code) with
 * the plan's profile, but RouterOS hotspot users carry no calendar validity — the
 * profiles (1-Day / 15-Days / 30-Days) only set rate-limit. A voucher redeemed
 * directly at the captive portal also creates no tbl_user_recharges row, so the
 * main expiry cron never touches it. Result: the code works forever.
 *
 * This sweep enforces validity = first redemption + the plan's validity:
 *   1. The first time a voucher's hotspot user shows real use (uptime > 0 or an
 *      active session), stamp tbl_voucher.first_used_at = now and
 *      tbl_voucher.expiry = now + plan validity.
 *   2. Once now >= expiry, remove the hotspot user from the router and kick any
 *      live session, and mark the voucher status = 'expired'. A second redemption
 *      then fails (the router user is gone) — i.e. shows invalid.
 *
 * Only users whose name matches a Hotspot tbl_voucher.code are ever touched, so
 * phone-number hotspot customers, default-trial and admin are left alone.
 */
error_reporting(E_ERROR | E_PARSE);
spl_autoload_register(function ($c) {
    $f = '/var/www/html/system/autoload/' . str_replace('\\', '/', $c) . '.php';
    if (file_exists($f)) include $f;
});
use PEAR2\Net\RouterOS;
require '/var/www/html/config.php';

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// App config (timezone, radius_mode) from tbl_appconfig.
$cfg = [];
foreach ($pdo->query("SELECT setting, value FROM tbl_appconfig") as $row) {
    $cfg[$row['setting']] = $row['value'];
}
if (!empty($cfg['timezone'])) {
    date_default_timezone_set($cfg['timezone']);
}
$now    = date('Y-m-d H:i:s');
$nowTs  = time();
$start  = microtime(true);
$stamped = 0; $expired = 0; $errors = [];
$trialsUpdated = 0; $trialsClosed = 0;

if (!empty($cfg['radius_mode'])) {
    echo date('c') . " skip (radius_mode)\n";
    exit(0);
}

/**
 * Compute expiry timestamp string from a base time + plan validity.
 */
function voucher_expiry_from($baseTs, $validity, $unit) {
    $validity = (int) $validity;
    if ($validity < 1) $validity = 1;
    switch ($unit) {
        case 'Months': return date('Y-m-d H:i:s', strtotime("+$validity month", $baseTs));
        case 'Days':   return date('Y-m-d H:i:s', strtotime("+$validity day",   $baseTs));
        case 'Hrs':    return date('Y-m-d H:i:s', strtotime("+$validity hour",  $baseTs));
        case 'Mins':   return date('Y-m-d H:i:s', strtotime("+$validity minute",$baseTs));
        default:       return date('Y-m-d H:i:s', strtotime("+$validity day",   $baseTs));
    }
}

try {
    $rt = $pdo->query("SELECT * FROM tbl_routers WHERE enabled=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$rt) { exit("no enabled router\n"); }
    $iport  = explode(':', $rt['ip_address']);
    $client = new RouterOS\Client($iport[0], $rt['username'], $rt['password'], isset($iport[1]) ? $iport[1] : null);

    // Active Hotspot vouchers we still manage (not already expired), with plan validity.
    $vouchers = [];
    $sql = "SELECT v.id, v.code, v.first_used_at, v.expiry,
                   p.validity, p.validity_unit
            FROM tbl_voucher v
            LEFT JOIN tbl_plans p ON p.id = v.id_plan
            WHERE v.type = 'Hotspot' AND (v.status IS NULL OR v.status <> 'expired')";
    foreach ($pdo->query($sql) as $row) {
        $vouchers[$row['code']] = $row;
    }
    // Note: we continue even with no vouchers — trial-session tracking below
    // still needs to run. The voucher loop simply does nothing when empty.

    // Router hotspot users: name -> [.id, uptime]
    $usersOnRouter = [];
    $req = new RouterOS\Request('/ip/hotspot/user/print');
    $req->setArgument('.proplist', '.id,name,uptime,bytes-in,bytes-out');
    foreach ($client->sendSync($req) as $r) {
        if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
        $name = $r->getProperty('name');
        if ($name === null || !isset($vouchers[$name])) continue; // only manage vouchers
        $usersOnRouter[$name] = [
            'id'     => $r->getProperty('.id'),
            'uptime' => $r->getProperty('uptime'),
            'bytes'  => (int) $r->getProperty('bytes-in') + (int) $r->getProperty('bytes-out'),
        ];
    }

    // Currently-active hotspot sessions: user -> active .id (to kick on expiry)
    $activeById = [];
    $req = new RouterOS\Request('/ip/hotspot/active/print');
    $req->setArgument('.proplist', '.id,user');
    foreach ($client->sendSync($req) as $r) {
        if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
        $u = $r->getProperty('user');
        if ($u !== null && isset($vouchers[$u])) $activeById[$u] = $r->getProperty('.id');
    }

    $stampStmt  = $pdo->prepare("UPDATE tbl_voucher SET first_used_at = :fu, expiry = :ex WHERE id = :id");
    $expireStmt = $pdo->prepare("UPDATE tbl_voucher SET status = 'expired' WHERE id = :id");

    foreach ($usersOnRouter as $code => $u) {
        $v = $vouchers[$code];

        // Has this voucher been used? uptime like "0s" means never connected.
        $used = isset($activeById[$code])
                || ($u['uptime'] !== null && $u['uptime'] !== '' && $u['uptime'] !== '0s')
                || $u['bytes'] > 0;

        // 1) Stamp first use + compute expiry.
        if (empty($v['first_used_at']) && $used) {
            $expiry = voucher_expiry_from($nowTs, $v['validity'], $v['validity_unit']);
            $stampStmt->execute([':fu' => $now, ':ex' => $expiry, ':id' => $v['id']]);
            $v['first_used_at'] = $now;
            $v['expiry'] = $expiry;
            $stamped++;
        }

        // 2) Expire if past expiry.
        if (!empty($v['expiry']) && strtotime($v['expiry']) <= $nowTs) {
            // Kick live session first so the customer is dropped immediately.
            if (isset($activeById[$code])) {
                try {
                    $rm = new RouterOS\Request('/ip/hotspot/active/remove');
                    $rm->setArgument('numbers', $activeById[$code]);
                    $client->sendSync($rm);
                } catch (Throwable $e) { $errors[] = "kick $code: " . $e->getMessage(); }
            }
            // Remove the hotspot user so re-login fails (invalid).
            try {
                $rm = new RouterOS\Request('/ip/hotspot/user/remove');
                $rm->setArgument('numbers', $u['id']);
                $client->sendSync($rm);
            } catch (Throwable $e) { $errors[] = "remove $code: " . $e->getMessage(); }

            $expireStmt->execute([':id' => $v['id']]);
            $expired++;
        }
    }

    // -----------------------------------------------------------------
    // Hotspot free-trial session tracking. Fill per-session bytes + end time
    // for trial rows recorded by api/hotspot-trial. The per-MAC active entry
    // carries that session's own counters (the shared 'default-trial' user only
    // holds aggregate totals), so we match active trial sessions to open rows by
    // MAC. login-by='trial' marks a genuine trial login.
    // -----------------------------------------------------------------
    try {
        $trialActive = [];
        $req = new RouterOS\Request('/ip/hotspot/active/print');
        $req->setArgument('.proplist', 'mac-address,address,login-by,bytes-in,bytes-out');
        foreach ($client->sendSync($req) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
            if (strpos((string) $r->getProperty('login-by'), 'trial') === false) continue;
            $mac = strtoupper((string) $r->getProperty('mac-address'));
            if ($mac === '') continue;
            $trialActive[$mac] = [
                'in'  => (int) $r->getProperty('bytes-in'),
                'out' => (int) $r->getProperty('bytes-out'),
                'ip'  => $r->getProperty('address'),
            ];
        }

        // started_at is parsed with strtotime() (PHP timezone, same as it was
        // written) — MySQL UNIX_TIMESTAMP() would read it in the DB server
        // timezone (UTC in the prod container), shifting it ~6h and delaying
        // session close-out by the same amount.
        $openTrials = $pdo->query(
            "SELECT id, mac, ip, started_at
             FROM tbl_hotspot_trials WHERE ended_at IS NULL"
        )->fetchAll(PDO::FETCH_ASSOC);
        $updT   = $pdo->prepare("UPDATE tbl_hotspot_trials SET bytes_in=:bi, bytes_out=:bo, ip=COALESCE(:ip, ip) WHERE id=:id");
        $closeT = $pdo->prepare("UPDATE tbl_hotspot_trials SET ended_at=:ea WHERE id=:id");
        $graceTs = $nowTs - 300; // 5-minute grace for the session to appear

        foreach ($openTrials as $row) {
            $mac = strtoupper((string) $row['mac']);
            $startedTs = (int) strtotime((string) $row['started_at']);
            if ($mac !== '' && isset($trialActive[$mac])) {
                $a = $trialActive[$mac];
                $updT->execute([':bi' => $a['in'], ':bo' => $a['out'], ':ip' => ($a['ip'] ?: $row['ip']), ':id' => $row['id']]);
                $trialsUpdated++;
            } elseif ($startedTs < $graceTs) {
                // No active trial session and past the grace window -> trial ended.
                // Existing bytes (from the last active poll) are kept. ended_at is
                // clamped to start + the configured trial uptime (the router's cap)
                // so a close recorded late — cron downtime, this sweep erroring out
                // — can't inflate the session duration.
                $trialCapMin = (int) ($cfg['hotspot_trial_duration_minutes'] ?? 60);
                if ($trialCapMin < 1) $trialCapMin = 60;
                $endTs = min($nowTs, $startedTs + $trialCapMin * 60);
                $closeT->execute([':ea' => date('Y-m-d H:i:s', $endTs), ':id' => $row['id']]);
                $trialsClosed++;
            }
        }
    } catch (Throwable $e) { $errors[] = 'trial: ' . $e->getMessage(); }

    $ms = (int) ((microtime(true) - $start) * 1000);
    echo date('c') . " ok stamped=$stamped expired=$expired vouchers=" . count($vouchers)
       . " trial_upd=$trialsUpdated trial_closed=$trialsClosed"
       . (count($errors) ? " errs=" . implode(' | ', array_slice($errors, 0, 5)) : '')
       . " in {$ms}ms\n";
} catch (Throwable $e) {
    echo date('c') . " FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
