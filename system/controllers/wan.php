<?php
/**
 * WAN dashboard — utilisation graph + recent error/drop counters.
 *
 *  /wan                 → page
 *  /wan/data?minutes=N  → JSON for the chart
 */
_admin();
$ui->assign('_title', 'WAN Dashboard');
$ui->assign('_system_menu', 'wan');
$admin = Admin::_info();
$ui->assign('_admin', $admin);

$action = isset($routes['1']) ? $routes['1'] : 'index';

if ($action === 'data') {
    // Buffer everything so any stray warning/notice/echo from ORM, the
    // RouterOS client, or autoloaded code can't corrupt the JSON body.
    ob_start();
    $minutes = isset($_GET['minutes']) ? max(5, min(10080, (int)$_GET['minutes'])) : 360;
    $iface   = isset($_GET['iface']) ? $_GET['iface'] : null;
    $out = ['minutes' => $minutes, 'samples' => [], 'live' => null, 'iface' => $iface, 'error' => null];
    try {
        $sql = "SELECT UNIX_TIMESTAMP(ts) t, interface, rx_bps, tx_bps, rx_error, tx_error, rx_drop, tx_drop
                FROM tbl_wan_samples
                WHERE ts >= NOW() - INTERVAL ? MINUTE"
              . ($iface ? " AND interface = ?" : "")
              . " ORDER BY ts ASC";
        $params = [$minutes];
        if ($iface) $params[] = $iface;

        $rows = ORM::for_table('tbl_wan_samples')->raw_query($sql, $params)->find_array();
        foreach ($rows as $row) {
            $out['samples'][] = [
                'ts'      => (int)$row['t'] * 1000,
                'iface'   => $row['interface'],
                'rxBps'   => (int)$row['rx_bps'],
                'txBps'   => (int)$row['tx_bps'],
                'rxError' => (int)$row['rx_error'],
                'txError' => (int)$row['tx_error'],
                'rxDrop'  => (int)$row['rx_drop'],
                'txDrop'  => (int)$row['tx_drop'],
            ];
        }

        // Live snapshot — tryClient() returns null on connection failure
        // (getClient() would die() and corrupt the JSON response).
        $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
        if ($rt) {
            $client = Mikrotik::tryClient($rt['ip_address'], $rt['username'], $rt['password']);
            $name = $iface ?: ($GLOBALS['config']['wan_interface'] ?? '') ?: 'ether2-Starlink';
            // Derive the current rate from byte counters. monitor-traffic
            // 'once' under-reports heavily over the API.
            $readBytes = function ($cl) use ($name) {
                $q = new RouterOS\Request('/interface/print');
                $q->setArgument('stats', '');
                $q->setArgument('.proplist', 'name,rx-byte,tx-byte');
                $q->setQuery(RouterOS\Query::where('name', $name));
                foreach ($cl->sendSync($q) as $r) {
                    if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    return ['rb' => (int)$r->getProperty('rx-byte'), 'tb' => (int)$r->getProperty('tx-byte')];
                }
                return null;
            };
            if ($client) try {
                // Average since a stored sample at least 30s old. The router's
                // counters advance in bursts, so a 1s delta reads wildly high
                // (up to ~1 Gbps on this uplink); a 30s+ window is stable.
                $ref = ORM::for_table('tbl_wan_samples')->raw_query(
                    "SELECT rx_bytes, tx_bytes, UNIX_TIMESTAMP(ts) t FROM tbl_wan_samples
                     WHERE interface = ? AND rx_bytes > 0 AND ts <= NOW() - INTERVAL 30 SECOND
                     ORDER BY id DESC LIMIT 1", [$name])->find_array();
                $now = $readBytes($client);
                $dt = $ref ? microtime(true) - (int)$ref[0]['t'] : 0;
                if ($now && $dt > 0 && $now['rb'] >= $ref[0]['rx_bytes'] && $now['tb'] >= $ref[0]['tx_bytes']) {
                    $out['live'] = [
                        'ts'    => time() * 1000,
                        'iface' => $name,
                        'rxBps' => (int) round(($now['rb'] - $ref[0]['rx_bytes']) * 8 / $dt),
                        'txBps' => (int) round(($now['tb'] - $ref[0]['tx_bytes']) * 8 / $dt),
                    ];
                }
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
    // Discard any stray output captured during the try block, then emit JSON.
    if (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

// /wan index — render the dashboard. The template polls /wan/data via JS.
$ifaces = ORM::for_table('tbl_wan_samples')
    ->raw_query("SELECT DISTINCT interface FROM tbl_wan_samples ORDER BY interface")
    ->find_array();
$lastErrors = ORM::for_table('tbl_wan_samples')
    ->raw_query("SELECT interface, rx_error, tx_error, rx_drop, tx_drop, ts
                 FROM tbl_wan_samples ORDER BY ts DESC LIMIT 1")
    ->find_array();
// Usage over the last 30 days from the daily totals the poller keeps.
$since = date('Y-m-d', strtotime('-29 days'));
$wanDaily = ORM::for_table('tbl_wan_usage_daily')
    ->raw_query("SELECT day, SUM(rx_bytes) rx, SUM(tx_bytes) tx FROM tbl_wan_usage_daily
                 WHERE day >= ? GROUP BY day ORDER BY day DESC", [$since])
    ->find_array();
$topUsers = ORM::for_table('tbl_usage_daily')
    ->raw_query("SELECT username, SUM(bytes_out) download, SUM(bytes_in) upload FROM tbl_usage_daily
                 WHERE day >= ? GROUP BY username
                 ORDER BY SUM(bytes_out + bytes_in) DESC", [$since])
    ->find_array();
$usageTotals = ['wan_rx' => 0, 'wan_tx' => 0, 'users' => 0, 'first_day' => null];
foreach ($wanDaily as $d) { $usageTotals['wan_rx'] += $d['rx']; $usageTotals['wan_tx'] += $d['tx']; }
foreach ($topUsers as $u) { $usageTotals['users'] += $u['download'] + $u['upload']; }
$first = ORM::for_table('tbl_usage_daily')
    ->raw_query("SELECT MIN(day) d FROM tbl_usage_daily WHERE day >= ?", [$since])->find_array();
$usageTotals['first_day'] = $first[0]['d'] ?? null;

$ui->assign('ifaces', $ifaces);
$ui->assign('lastErrors', $lastErrors[0] ?? null);
$ui->assign('wanDaily', $wanDaily);
$ui->assign('topUsers', array_slice($topUsers, 0, 20));
$ui->assign('usageTotals', $usageTotals);
$ui->display('wan.tpl');
