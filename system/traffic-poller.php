<?php
/**
 * NetPulse traffic poller — runs every minute from host cron.
 *
 * Snapshots:
 *   1. Per-PPPoE-session: rate (bits/sec) from /interface/monitor-traffic
 *      (accurate, matches Winbox live display), bytes from /queue/simple,
 *      and rx-error / rx-drop from /interface/print on the
 *      <pppoe-USER> interface. Written to tbl_traffic_samples.
 *
 *   2. WAN interface stats (rx-bps, tx-bps, rx-error, tx-error, drops)
 *      from /interface/monitor-traffic + /interface/print. Written to
 *      tbl_wan_samples. Gives the 24h view of uplink utilisation.
 *
 * Retention: rows older than 7 days are deleted on each run.
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

$start = microtime(true);
$inserts = 0;
$errors  = [];

try {
    $rt = $pdo->query("SELECT * FROM tbl_routers WHERE enabled=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$rt) { exit("no enabled router\n"); }
    $client = new RouterOS\Client($rt['ip_address'], $rt['username'], $rt['password']);

    // -----------------------------------------------------------------
    // 1. Per-customer bytes from /queue/simple/print stats=yes
    //    (bytes are cumulative and accurate from queue)
    // -----------------------------------------------------------------
    $dataByUser = [];
    $pppoeInterfaces = [];
    try {
        $req = new RouterOS\Request('/queue/simple/print');
        $req->setArgument('stats', 'yes');
        $req->setArgument('.proplist', 'name,bytes');
        foreach ($client->sendSync($req) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
            $qname = $r->getProperty('name');
            $user  = preg_match('/^<pppoe-(.+)>$/', $qname, $m) ? $m[1] : trim($qname, '<>');
            $bytes = explode('/', $r->getProperty('bytes') ?: '0/0');
            $dataByUser[$user] = [
                'rate_in'   => 0,  // Will be filled from interface/monitor-traffic
                'rate_out'  => 0,
                'bytes_in'  => (int) ($bytes[0] ?? 0),
                'bytes_out' => (int) ($bytes[1] ?? 0),
            ];
            $pppoeInterfaces[] = '<pppoe-' . $user . '>';
        }
    } catch (Throwable $e) { $errors[] = 'queue: ' . $e->getMessage(); }

    // -----------------------------------------------------------------
    // 1b. Per-customer rates from /interface/monitor-traffic (accurate,
    //     matches Winbox live display)
    // -----------------------------------------------------------------
    if (!empty($pppoeInterfaces)) {
        try {
            $req = new RouterOS\Request('/interface/monitor-traffic');
            $req->setArgument('interface', implode(',', $pppoeInterfaces));
            $req->setArgument('once', '');
            foreach ($client->sendSync($req) as $r) {
                if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                $ifName = $r->getProperty('name');
                if (preg_match('/^<pppoe-(.+)>$/', $ifName, $m)) {
                    $user = $m[1];
                    if (isset($dataByUser[$user])) {
                        // Interface rx = traffic INTO router = customer upload
                        // Interface tx = traffic OUT of router = customer download
                        $dataByUser[$user]['rate_in']  = (int) ($r->getProperty('rx-bits-per-second') ?? 0);
                        $dataByUser[$user]['rate_out'] = (int) ($r->getProperty('tx-bits-per-second') ?? 0);
                    }
                }
            }
        } catch (Throwable $e) { $errors[] = 'iface-monitor: ' . $e->getMessage(); }
    }
    $rateByUser = $dataByUser;

    // -----------------------------------------------------------------
    // 2. Per-customer interface errors (rx-error, rx-drop) per session
    // -----------------------------------------------------------------
    $errByUser = [];
    try {
        $req = new RouterOS\Request('/interface/print');
        $req->setArgument('.proplist', 'name,rx-error,tx-error,rx-drop,tx-drop');
        foreach ($client->sendSync($req) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
            $name = $r->getProperty('name');
            if (!preg_match('/^<pppoe-(.+)>$/', $name, $m)) continue;
            $user = $m[1];
            $errByUser[$user] = [
                'rx_error' => (int) $r->getProperty('rx-error'),
                'tx_error' => (int) $r->getProperty('tx-error'),
                'rx_drop'  => (int) $r->getProperty('rx-drop'),
                'tx_drop'  => (int) $r->getProperty('tx-drop'),
            ];
        }
    } catch (Throwable $e) { $errors[] = 'iface: ' . $e->getMessage(); }

    // Persist per-user samples
    if ($rateByUser) {
        $stmt = $pdo->prepare(
            "INSERT INTO tbl_traffic_samples
                (username, rate_in, rate_out, bytes_in, bytes_out, rx_error, tx_error, rx_drop, tx_drop)
             VALUES (:u, :ri, :ro, :bi, :bo, :re, :te, :rd, :td)"
        );
        foreach ($rateByUser as $user => $s) {
            $e = $errByUser[$user] ?? ['rx_error'=>0,'tx_error'=>0,'rx_drop'=>0,'tx_drop'=>0];
            $stmt->execute([
                ':u'  => $user,
                ':ri' => $s['rate_in'],   ':ro' => $s['rate_out'],
                ':bi' => $s['bytes_in'],  ':bo' => $s['bytes_out'],
                ':re' => $e['rx_error'],  ':te' => $e['tx_error'],
                ':rd' => $e['rx_drop'],   ':td' => $e['tx_drop'],
            ]);
            $inserts++;
        }
    }

    // -----------------------------------------------------------------
    // 3. WAN uplink — find the interface, sample its current rate +
    //    error / drop counters.
    // -----------------------------------------------------------------
    $wanIface = isset($GLOBALS['config']['wan_interface']) && $GLOBALS['config']['wan_interface']
                ? $GLOBALS['config']['wan_interface']
                : 'ether2-Starlink';

    // 3a. Current rx-bps / tx-bps via /interface/monitor-traffic once=yes
    $wanRxBps = 0; $wanTxBps = 0; $wanRxPps = 0; $wanTxPps = 0;
    try {
        $req = new RouterOS\Request('/interface/monitor-traffic');
        $req->setArgument('interface', $wanIface);
        $req->setArgument('once', '');
        foreach ($client->sendSync($req) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
            $wanRxBps = (int) $r->getProperty('rx-bits-per-second');
            $wanTxBps = (int) $r->getProperty('tx-bits-per-second');
            $wanRxPps = (int) $r->getProperty('rx-packets-per-second');
            $wanTxPps = (int) $r->getProperty('tx-packets-per-second');
            break;
        }
    } catch (Throwable $e) { $errors[] = 'wan-monitor: ' . $e->getMessage(); }

    // 3b. Cumulative error / drop counters from /interface/print
    $wanRxError = 0; $wanTxError = 0; $wanRxDrop = 0; $wanTxDrop = 0;
    try {
        $req = new RouterOS\Request('/interface/print');
        $req->setArgument('.proplist', 'name,rx-error,tx-error,rx-drop,tx-drop');
        $req->setQuery(RouterOS\Query::where('name', $wanIface));
        foreach ($client->sendSync($req) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
            $wanRxError = (int) $r->getProperty('rx-error');
            $wanTxError = (int) $r->getProperty('tx-error');
            $wanRxDrop  = (int) $r->getProperty('rx-drop');
            $wanTxDrop  = (int) $r->getProperty('tx-drop');
        }
    } catch (Throwable $e) { $errors[] = 'wan-print: ' . $e->getMessage(); }

    $pdo->prepare(
        "INSERT INTO tbl_wan_samples (interface, rx_bps, tx_bps, rx_pps, tx_pps, rx_error, tx_error, rx_drop, tx_drop)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $wanIface, $wanRxBps, $wanTxBps, $wanRxPps, $wanTxPps,
        $wanRxError, $wanTxError, $wanRxDrop, $wanTxDrop,
    ]);
    $inserts++;

    // -----------------------------------------------------------------
    // Retention: 7 days
    // -----------------------------------------------------------------
    $pruned1 = $pdo->exec("DELETE FROM tbl_traffic_samples WHERE ts < NOW() - INTERVAL 7 DAY");
    $pruned2 = $pdo->exec("DELETE FROM tbl_wan_samples     WHERE ts < NOW() - INTERVAL 7 DAY");

    $ms = (int) ((microtime(true) - $start) * 1000);
    echo date('c') . " ok inserts=$inserts pruned_traffic=$pruned1 pruned_wan=$pruned2"
       . " wan_rx=" . round($wanRxBps/1e6, 2) . "Mbps wan_tx=" . round($wanTxBps/1e6, 2) . "Mbps"
       . (count($errors) ? " errs=" . implode(' | ', $errors) : '')
       . " in {$ms}ms\n";
} catch (Throwable $e) {
    echo date('c') . " FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
