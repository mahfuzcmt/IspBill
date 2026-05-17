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
            $name = $iface ?: 'ether1-Sterlink Uplink';
            if ($client) try {
                $req = new RouterOS\Request('/interface/monitor-traffic');
                $req->setArgument('interface', $name);
                $req->setArgument('once', '');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $out['live'] = [
                        'ts'    => time() * 1000,
                        'iface' => $name,
                        'rxBps' => (int)$r->getProperty('rx-bits-per-second'),
                        'txBps' => (int)$r->getProperty('tx-bits-per-second'),
                    ];
                    break;
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
$ui->assign('ifaces', $ifaces);
$ui->assign('lastErrors', $lastErrors[0] ?? null);
$ui->display('wan.tpl');
