<?php
/**
 * Hotspot voucher expiry sweep — runs every minute from the cron container,
 * alongside traffic-poller.php.
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
    if (!$vouchers) {
        echo date('c') . " ok no manageable hotspot vouchers\n";
        exit(0);
    }

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

    $ms = (int) ((microtime(true) - $start) * 1000);
    echo date('c') . " ok stamped=$stamped expired=$expired vouchers=" . count($vouchers)
       . (count($errors) ? " errs=" . implode(' | ', array_slice($errors, 0, 5)) : '')
       . " in {$ms}ms\n";
} catch (Throwable $e) {
    echo date('c') . " FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
