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
        $req->setArgument('.proplist', 'name,bytes,rate');
        foreach ($client->sendSync($req) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
            $qname = $r->getProperty('name');
            $user  = preg_match('/^<pppoe-(.+)>$/', $qname, $m) ? $m[1] : trim($qname, '<>');
            $bytes = explode('/', $r->getProperty('bytes') ?: '0/0');
            // Instantaneous queue rate (bits/sec, upload/download) — this is what
            // Winbox shows. The byte-delta below only yields the average over the
            // whole minute, which flattens bursts; we keep whichever is higher so
            // real peaks are visible on the graph instead of a near-flat line.
            $rate  = explode('/', $r->getProperty('rate') ?: '0/0');
            $dataByUser[$user] = [
                'rate_in'   => (int) ($rate[0] ?? 0),
                'rate_out'  => (int) ($rate[1] ?? 0),
                'bytes_in'  => (int) ($bytes[0] ?? 0),
                'bytes_out' => (int) ($bytes[1] ?? 0),
            ];
            $pppoeInterfaces[] = '<pppoe-' . $user . '>';
        }
    } catch (Throwable $e) { $errors[] = 'queue: ' . $e->getMessage(); }

    // -----------------------------------------------------------------
    // 1b. Per-customer rate from cumulative-byte deltas vs the previous
    //     sample. /interface/monitor-traffic only captures a single instant
    //     and reads ~0 for normal browsing (it returned 0 for every user on
    //     this RouterOS), leaving the historical rate graph a flat zero line.
    //     The per-minute byte delta is the true average throughput.
    // -----------------------------------------------------------------
    if ($dataByUser) {
        $prevStmt = $pdo->prepare(
            "SELECT bytes_in, bytes_out, UNIX_TIMESTAMP(ts) AS t
             FROM tbl_traffic_samples WHERE username = ? ORDER BY id DESC LIMIT 1"
        );
        foreach ($dataByUser as $user => $s) {
            $prevStmt->execute([$user]);
            if ($prev = $prevStmt->fetch(PDO::FETCH_ASSOC)) {
                $dt = $start - (int) $prev['t'];
                if ($dt > 0) {
                    // bytes_in = upload (from customer), bytes_out = download
                    $dIn  = $s['bytes_in']  - (int) $prev['bytes_in'];
                    $dOut = $s['bytes_out'] - (int) $prev['bytes_out'];
                    // Keep the instantaneous queue rate set above unless the
                    // minute-average byte-delta is higher (sustained transfer).
                    if ($dIn  > 0) $dataByUser[$user]['rate_in']  = max($dataByUser[$user]['rate_in'],  (int) ($dIn  * 8 / $dt));
                    if ($dOut > 0) $dataByUser[$user]['rate_out'] = max($dataByUser[$user]['rate_out'], (int) ($dOut * 8 / $dt));
                }
            }
        }
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

    // -----------------------------------------------------------------
    // 2b. Hotspot sessions. PPPoE queues/interfaces don't exist for
    //     hotspot users, so they get no samples from the steps above and
    //     their per-customer graph stays empty. Sample currently-connected
    //     hotspot users here: cumulative bytes from /ip/hotspot/user, rate
    //     derived from the delta vs the user's previous sample (there is no
    //     per-user interface to monitor-traffic for hotspot).
    // -----------------------------------------------------------------
    try {
        $activeHs = [];
        $req = new RouterOS\Request('/ip/hotspot/active/print');
        $req->setArgument('.proplist', 'user');
        foreach ($client->sendSync($req) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
            $u = $r->getProperty('user');
            if ($u !== null && $u !== '') $activeHs[$u] = true;
        }

        if ($activeHs) {
            $prevStmt = $pdo->prepare(
                "SELECT bytes_in, bytes_out, UNIX_TIMESTAMP(ts) AS t
                 FROM tbl_traffic_samples WHERE username = ? ORDER BY id DESC LIMIT 1"
            );
            $req = new RouterOS\Request('/ip/hotspot/user/print');
            $req->setArgument('.proplist', 'name,bytes-in,bytes-out');
            foreach ($client->sendSync($req) as $r) {
                if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                $name = $r->getProperty('name');
                if ($name === null || !isset($activeHs[$name])) continue;
                $bin  = (int) $r->getProperty('bytes-in');   // from client = upload
                $bout = (int) $r->getProperty('bytes-out');  // to client   = download
                $rateIn = 0; $rateOut = 0;
                $prevStmt->execute([$name]);
                if ($prev = $prevStmt->fetch(PDO::FETCH_ASSOC)) {
                    $dt = $start - (int) $prev['t'];
                    if ($dt > 0) {
                        $dIn  = $bin  - (int) $prev['bytes_in'];
                        $dOut = $bout - (int) $prev['bytes_out'];
                        if ($dIn  > 0) $rateIn  = (int) ($dIn  * 8 / $dt);
                        if ($dOut > 0) $rateOut = (int) ($dOut * 8 / $dt);
                    }
                }
                // Keyed by username — same shape as the PPPoE samples so the
                // persist loop below writes them with no special-casing.
                $rateByUser[$name] = [
                    'rate_in'   => $rateIn,
                    'rate_out'  => $rateOut,
                    'bytes_in'  => $bin,
                    'bytes_out' => $bout,
                ];
            }
        }
    } catch (Throwable $e) { $errors[] = 'hotspot: ' . $e->getMessage(); }

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

    // The shared $client above can be left desynced by an "Unrecognized
    // response type" on the hotspot section, which silently zeroes every
    // WAN reading taken on it. Use a dedicated, freshly-opened connection
    // for the WAN sample so it is always clean.
    $wanClient = $client;
    try {
        $wanClient = new RouterOS\Client($rt['ip_address'], $rt['username'], $rt['password']);
    } catch (Throwable $e) { $errors[] = 'wan-conn: ' . $e->getMessage(); }

    // Read interface byte/packet/error counters once.
    $wanRead = function ($cl) use ($wanIface) {
        $q = new RouterOS\Request('/interface/print');
        $q->setArgument('stats', '');
        $q->setArgument('.proplist', 'name,rx-byte,tx-byte,rx-packet,tx-packet,rx-error,tx-error,rx-drop,tx-drop');
        $q->setQuery(RouterOS\Query::where('name', $wanIface));
        foreach ($cl->sendSync($q) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
            return [
                'rb' => (int) $r->getProperty('rx-byte'),   'tb' => (int) $r->getProperty('tx-byte'),
                'rp' => (int) $r->getProperty('rx-packet'), 'tp' => (int) $r->getProperty('tx-packet'),
                're' => (int) $r->getProperty('rx-error'),  'te' => (int) $r->getProperty('tx-error'),
                'rd' => (int) $r->getProperty('rx-drop'),   'td' => (int) $r->getProperty('tx-drop'),
            ];
        }
        return null;
    };

    // 3a. Current rx/tx bps from a 1-second byte-counter delta. monitor-traffic
    //     'once' under-reports badly over the API (~5% of real), so derive the
    //     rate from counters instead — same approach as the PPPoE samples.
    $wanRxBps = 0; $wanTxBps = 0; $wanRxPps = 0; $wanTxPps = 0;
    $wanRxError = 0; $wanTxError = 0; $wanRxDrop = 0; $wanTxDrop = 0;
    try {
        $s1 = $wanRead($wanClient);
        usleep(1000000); // 1s window → delta_bytes * 8 = bits/sec
        $s2 = $wanRead($wanClient);
        if ($s1 && $s2) {
            $wanRxBps = max(0, (int) (($s2['rb'] - $s1['rb']) * 8));
            $wanTxBps = max(0, (int) (($s2['tb'] - $s1['tb']) * 8));
            $wanRxPps = max(0, (int) ($s2['rp'] - $s1['rp']));
            $wanTxPps = max(0, (int) ($s2['tp'] - $s1['tp']));
            // 3b. Cumulative error / drop counters (latest snapshot).
            $wanRxError = $s2['re']; $wanTxError = $s2['te'];
            $wanRxDrop  = $s2['rd']; $wanTxDrop  = $s2['td'];
        }
    } catch (Throwable $e) { $errors[] = 'wan-monitor: ' . $e->getMessage(); }

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
