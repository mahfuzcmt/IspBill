<?php
/**
 * OLT Dashboard — ONU monitoring, optical signal levels, status overview.
 *
 *  /olt                 → dashboard
 *  /olt/add             → add new OLT
 *  /olt/edit/ID         → edit OLT
 *  /olt/delete/ID       → delete OLT
 *  /olt/onus/ID         → list ONUs for OLT
 *  /olt/data            → JSON API for live data
 *  /olt/link-customer   → link ONU to customer
 */
_admin();
$ui->assign('_title', 'OLT Dashboard');
$ui->assign('_system_menu', 'olt');
$admin = Admin::_info();
$ui->assign('_admin', $admin);

$action = isset($routes['1']) ? $routes['1'] : 'index';

// JSON API for AJAX polling
if ($action === 'data') {
    header('Content-Type: application/json');
    $oltId = isset($_GET['olt_id']) ? (int)$_GET['olt_id'] : null;

    $out = ['olts' => [], 'onus' => [], 'alerts' => []];

    try {
        // Get all OLTs
        $olts = ORM::for_table('tbl_olt')->where('enabled', 1)->find_array();
        $out['olts'] = $olts;

        // Get ONUs with issues (low signal, offline)
        $alertOnus = ORM::for_table('tbl_olt_onus')
            ->select_many('tbl_olt_onus.*')
            ->select('tbl_olt.name', 'olt_name')
            ->join('tbl_olt', ['tbl_olt_onus.olt_id', '=', 'tbl_olt.id'])
            ->where_raw("(status != 'online' OR rx_power < -27 OR olt_rx_power < -27)")
            ->order_by_asc('olt_rx_power')
            ->limit(50)
            ->find_array();
        $out['alerts'] = $alertOnus;

        // If specific OLT requested, get all its ONUs
        if ($oltId) {
            $onus = ORM::for_table('tbl_olt_onus')
                ->select_many('tbl_olt_onus.*')
                ->select('tbl_customers.fullname', 'customer_name')
                ->left_outer_join('tbl_customers', ['tbl_olt_onus.customer_id', '=', 'tbl_customers.id'])
                ->where('tbl_olt_onus.olt_id', $oltId)
                ->order_by_asc('pon_port')
                ->order_by_asc('onu_id')
                ->find_array();
            $out['onus'] = $onus;
        }

        // Summary stats
        $out['summary'] = [
            'total_onus'   => ORM::for_table('tbl_olt_onus')->count(),
            'online_onus'  => ORM::for_table('tbl_olt_onus')->where('status', 'online')->count(),
            'offline_onus' => ORM::for_table('tbl_olt_onus')->where('status', 'offline')->count(),
            'low_signal'   => ORM::for_table('tbl_olt_onus')->where_lt('olt_rx_power', -27)->count(),
        ];

    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }

    echo json_encode($out);
    exit;
}

// Add OLT
if ($action === 'add') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = _post('name');
        $ip = _post('ip_address');
        $type = _post('type') ?: 'media';
        $community = _post('snmp_community') ?: 'public';
        $version = _post('snmp_version') ?: '2c';
        $webUrl = _post('web_url');
        $webUser = _post('web_user');
        $webPass = _post('web_pass');

        if (empty($name) || empty($ip)) {
            r2(U . 'olt/add', 'e', 'Name and IP address are required');
        }

        $olt = ORM::for_table('tbl_olt')->create();
        $olt->name = $name;
        $olt->ip_address = $ip;
        $olt->type = $type;
        $olt->snmp_community = $community;
        $olt->snmp_version = $version;
        $olt->web_url = $webUrl;
        $olt->web_user = $webUser;
        $olt->web_pass = $webPass;
        $olt->enabled = 1;
        $olt->save();

        _log("Added OLT: $name ($ip)", 'Admin', $admin['id']);
        r2(U . 'olt', 's', "OLT '$name' added successfully");
    }

    $ui->display('olt-add.tpl');
    exit;
}

// Edit OLT
if ($action === 'edit') {
    $id = isset($routes['2']) ? (int)$routes['2'] : 0;
    $olt = ORM::for_table('tbl_olt')->find_one($id);

    if (!$olt) {
        r2(U . 'olt', 'e', 'OLT not found');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $olt->name = _post('name');
        $olt->ip_address = _post('ip_address');
        $olt->type = _post('type') ?: 'media';
        $olt->snmp_community = _post('snmp_community') ?: 'public';
        $olt->snmp_version = _post('snmp_version') ?: '2c';
        $olt->web_url = _post('web_url');
        $olt->web_user = _post('web_user');
        if (_post('web_pass')) {
            $olt->web_pass = _post('web_pass');
        }
        $olt->enabled = _post('enabled') ? 1 : 0;
        $olt->save();

        _log("Updated OLT: {$olt->name}", 'Admin', $admin['id']);
        r2(U . 'olt', 's', "OLT updated successfully");
    }

    $ui->assign('olt', $olt);
    $ui->display('olt-edit.tpl');
    exit;
}

// Delete OLT
if ($action === 'delete') {
    $id = isset($routes['2']) ? (int)$routes['2'] : 0;
    $olt = ORM::for_table('tbl_olt')->find_one($id);

    if ($olt) {
        $name = $olt->name;
        // Delete related ONUs and samples
        ORM::for_table('tbl_olt_onu_samples')->where('olt_id', $id)->delete_many();
        ORM::for_table('tbl_olt_onus')->where('olt_id', $id)->delete_many();
        $olt->delete();

        _log("Deleted OLT: $name", 'Admin', $admin['id']);
        r2(U . 'olt', 's', "OLT '$name' deleted");
    } else {
        r2(U . 'olt', 'e', 'OLT not found');
    }
}

// List ONUs for specific OLT
if ($action === 'onus') {
    $id = isset($routes['2']) ? (int)$routes['2'] : 0;
    $olt = ORM::for_table('tbl_olt')->find_one($id);

    if (!$olt) {
        r2(U . 'olt', 'e', 'OLT not found');
    }

    $onus = ORM::for_table('tbl_olt_onus')
        ->select_many('tbl_olt_onus.*')
        ->select('tbl_customers.fullname', 'customer_name')
        ->select('tbl_customers.username', 'customer_username')
        ->left_outer_join('tbl_customers', ['tbl_olt_onus.customer_id', '=', 'tbl_customers.id'])
        ->where('tbl_olt_onus.olt_id', $id)
        ->order_by_asc('pon_port')
        ->order_by_asc('onu_id')
        ->find_many();

    $ui->assign('olt', $olt);
    $ui->assign('onus', $onus);
    $ui->display('olt-onus.tpl');
    exit;
}

// Link ONU to customer
if ($action === 'link-customer') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $onuId = (int)_post('onu_id');
        $customerId = (int)_post('customer_id');

        $onu = ORM::for_table('tbl_olt_onus')->find_one($onuId);
        if ($onu) {
            $onu->customer_id = $customerId ?: null;
            $onu->save();
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'ONU not found']);
        }
        exit;
    }
}

// Default: Dashboard view
$olts = ORM::for_table('tbl_olt')->find_many();

// Get summary stats
$totalOnus = ORM::for_table('tbl_olt_onus')->count();
$onlineOnus = ORM::for_table('tbl_olt_onus')->where('status', 'online')->count();
$offlineOnus = ORM::for_table('tbl_olt_onus')->where('status', 'offline')->count();
$lowSignalOnus = ORM::for_table('tbl_olt_onus')->where_lt('olt_rx_power', -27)->count();

// Get alerts (ONUs with issues)
$alerts = ORM::for_table('tbl_olt_onus')
    ->select_many('tbl_olt_onus.*')
    ->select('tbl_olt.name', 'olt_name')
    ->select('tbl_customers.fullname', 'customer_name')
    ->join('tbl_olt', ['tbl_olt_onus.olt_id', '=', 'tbl_olt.id'])
    ->left_outer_join('tbl_customers', ['tbl_olt_onus.customer_id', '=', 'tbl_customers.id'])
    ->where_raw("(tbl_olt_onus.status != 'online' OR tbl_olt_onus.olt_rx_power < -27)")
    ->order_by_asc('tbl_olt_onus.olt_rx_power')
    ->limit(20)
    ->find_many();

$ui->assign('olts', $olts);
$ui->assign('totalOnus', $totalOnus);
$ui->assign('onlineOnus', $onlineOnus);
$ui->assign('offlineOnus', $offlineOnus);
$ui->assign('lowSignalOnus', $lowSignalOnus);
$ui->assign('alerts', $alerts);

$ui->display('olt.tpl');
