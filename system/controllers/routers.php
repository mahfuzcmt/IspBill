<?php
/**
* PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
**/
_admin();
$ui->assign('_title', $_L['Network']);
$ui->assign('_system_menu', 'network');

$action = $routes['1'];
$admin = Admin::_info();
$ui->assign('_admin', $admin);

use PEAR2\Net\RouterOS;

require_once 'system/autoload/PEAR2/Autoload.php';

if($admin['user_type'] != 'Admin'){
	r2(U."dashboard",'e',$_L['Do_Not_Access']);
}

switch ($action) {
    case 'list':
		$ui->assign('xfooter', '<script type="text/javascript" src="ui/lib/c/routers.js"></script>');

		$name = _post('name');
		if ($name != ''){
			$paginator = Paginator::bootstrap('tbl_routers','name','%'.$name.'%');
			$d = ORM::for_table('tbl_routers')->where_like('name','%'.$name.'%')->offset($paginator['startpoint'])->limit($paginator['limit'])->order_by_desc('id')->find_many();
		}else{
			$paginator = Paginator::bootstrap('tbl_routers');
			$d = ORM::for_table('tbl_routers')->offset($paginator['startpoint'])->limit($paginator['limit'])->order_by_desc('id')->find_many();
		}

		// Flatten: render primary + secondary as separate rows sharing one DB id.
		// Edit/Delete operate on the underlying record; Remote Login targets the endpoint.
		$rows = [];
		foreach ($d as $ds) {
			$rows[] = [
				'id'          => $ds['id'],
				'name'        => $ds['name'],
				'ip_address'  => $ds['ip_address'],
				'username'    => $ds['username'],
				'description' => $ds['description'],
				'enabled'     => $ds['enabled'],
				'role'        => 'primary',
				'is_primary'  => true,
				'webfig_url'  => $ds['webfig_url'],
			];
			if (!empty($ds['secondary_ip_address'])) {
				$rows[] = [
					'id'          => $ds['id'],
					'name'        => $ds['name'] . ' (Secondary)',
					'ip_address'  => $ds['secondary_ip_address'],
					'username'    => $ds['secondary_username'],
					'description' => $ds['description'],
					'enabled'     => $ds['secondary_enabled'],
					'role'        => 'secondary',
					'is_primary'  => false,
					'webfig_url'  => $ds['secondary_webfig_url'],
				];
			}
		}

		$ui->assign('rows', $rows);
		$ui->assign('paginator',$paginator);
        run_hook('view_list_routers'); #HOOK
        $ui->display('routers.tpl');
        break;

    case 'add':
        run_hook('view_add_routers'); #HOOK
        $ui->display('routers-add.tpl');
        break;

    case 'edit':
        $id  = $routes['2'];
        $d = ORM::for_table('tbl_routers')->find_one($id);
        if($d){
            $ui->assign('d',$d);
            run_hook('view_router_edit'); #HOOK
            $ui->display('routers-edit.tpl');
        }else{
            r2(U . 'routers/list', 'e', $_L['Account_Not_Found']);
        }
        break;

    case 'delete':
        $id  = $routes['2'];
        run_hook('router_delete'); #HOOK
        $d = ORM::for_table('tbl_routers')->find_one($id);
        if($d){
            $d->delete();
            r2(U . 'routers/list', 's', $_L['Delete_Successfully']);
        }
        break;

    case 'add-post':
        $name = _post('name');
        $ip_address = _post('ip_address');
        $username = _post('username');
        $password = _post('password');
        $description = _post('description');
        $enabled = _post('enabled')*1;
        $secondary_ip_address = _post('secondary_ip_address');
        $secondary_username = _post('secondary_username');
        $secondary_password = _post('secondary_password');
        $secondary_enabled = _post('secondary_enabled')*1;
        $webfig_url = _post('webfig_url');
        $secondary_webfig_url = _post('secondary_webfig_url');

        $msg = '';
        if(Validator::Length($name,30,4) == false){
            $msg .= 'Name should be between 5 to 30 characters'. '<br>';
        }
        if ($ip_address == '' OR $username == ''){
			$msg .= $_L['All_field_is_required']. '<br>';
		}
        if ($secondary_enabled && ($secondary_ip_address == '' OR $secondary_username == '')){
            $msg .= Lang::T('Secondary router enabled but IP or username is empty'). '<br>';
        }

        $d = ORM::for_table('tbl_routers')->where('ip_address',$ip_address)->find_one();
        if($d){
            $msg .= $_L['Router_already_exist']. '<br>';
        }

        if(!$config['radius_mode']){
            if (!Mikrotik::tryClient($ip_address,$username,$password)) {
                $msg .= 'Cannot connect to router at ' . htmlspecialchars($ip_address) . ' with the supplied credentials.<br>';
            }
        }

        if($msg == ''){
            run_hook('add_router'); #HOOK
            $d = ORM::for_table('tbl_routers')->create();
            $d->name = $name;
            $d->ip_address = $ip_address;
            $d->username = $username;
            $d->password = $password;
			$d->description = $description;
			$d->enabled = $enabled;
            $d->secondary_ip_address = $secondary_ip_address;
            $d->secondary_username = $secondary_username;
            $d->secondary_password = $secondary_password;
            $d->secondary_enabled = $secondary_enabled;
            $d->webfig_url = $webfig_url;
            $d->secondary_webfig_url = $secondary_webfig_url;
            $d->save();

            r2(U . 'routers/list', 's', $_L['Created_Successfully']);
        }else{
            r2(U . 'routers/add', 'e', $msg);
        }
        break;


    case 'edit-post':
        $name = _post('name');
        $ip_address = _post('ip_address');
        $username = _post('username');
        $password = _post('password');
        $description = _post('description');
        $enabled = $_POST['enabled']*1;
        $secondary_ip_address = _post('secondary_ip_address');
        $secondary_username = _post('secondary_username');
        $secondary_password = _post('secondary_password');
        $secondary_enabled = _post('secondary_enabled')*1;
        $webfig_url = _post('webfig_url');
        $secondary_webfig_url = _post('secondary_webfig_url');
        $msg = '';
        if(Validator::Length($name,30,4) == false){
            $msg .= 'Name should be between 5 to 30 characters'. '<br>';
        }
        if ($ip_address == '' OR $username == ''){
			$msg .= $_L['All_field_is_required']. '<br>';
		}
        if ($secondary_enabled && ($secondary_ip_address == '' OR $secondary_username == '')){
            $msg .= Lang::T('Secondary router enabled but IP or username is empty'). '<br>';
        }

        $id = _post('id');
        $d = ORM::for_table('tbl_routers')->find_one($id);
        if($d){

        }else{
            $msg .= $_L['Data_Not_Found']. '<br>';
        }

        if($d['name'] != $name){
            $c = ORM::for_table('tbl_routers')->where('name',$name)->where_not_equal('id',$id)->find_one();
            if($c){
                $msg .= 'Name Already Exists<br>';
            }
        }
        $oldname = $d['name'];

        if($d['ip_address'] != $ip_address){
            $c = ORM::for_table('tbl_routers')->where('ip_address',$ip_address)->where_not_equal('id',$id)->find_one();
            if($c){
                $msg .= 'IP Already Exists<br>';
            }
        }


        if(!$config['radius_mode']){
            if (!Mikrotik::tryClient($ip_address,$username,$password)) {
                $msg .= 'Cannot connect to router at ' . htmlspecialchars($ip_address) . ' with the supplied credentials.<br>';
            }
        }


        if($msg == ''){
            run_hook('router_edit'); #HOOK
            $d->name = $name;
            $d->ip_address = $ip_address;
            $d->username = $username;
            $d->password = $password;
			$d->description = $description;
			$d->enabled = $enabled;
            $d->secondary_ip_address = $secondary_ip_address;
            $d->secondary_username = $secondary_username;
            $d->secondary_password = $secondary_password;
            $d->secondary_enabled = $secondary_enabled;
            $d->webfig_url = $webfig_url;
            $d->secondary_webfig_url = $secondary_webfig_url;
            $d->save();
            if($name!=$oldname){
                $p = ORM::for_table('tbl_plans')->where('routers',$oldname)->find_result_set();
                $p->set('routers',$name);
                $p->save();
                $p = ORM::for_table('tbl_payment_gateway')->where('routers',$oldname)->find_result_set();
                $p->set('routers',$name);
                $p->save();
                $p = ORM::for_table('tbl_pool')->where('routers',$oldname)->find_result_set();
                $p->set('routers',$name);
                $p->save();
                $p = ORM::for_table('tbl_transactions')->where('routers',$oldname)->find_result_set();
                $p->set('routers',$name);
                $p->save();
                $p = ORM::for_table('tbl_user_recharges')->where('routers',$oldname)->find_result_set();
                $p->set('routers',$name);
                $p->save();
                $p = ORM::for_table('tbl_voucher')->where('routers',$oldname)->find_result_set();
                $p->set('routers',$name);
                $p->save();
            }
            r2(U . 'routers/list', 's', $_L['Updated_Successfully']);
        }else{
            r2(U . 'routers/edit/'.$id, 'e', $msg);
        }
        break;

    case 'import-plans':
        $id     = $routes['2'];
        $target = isset($routes['3']) ? $routes['3'] : 'primary';
        if (!in_array($target, ['primary','secondary'], true)) {
            r2(U . 'routers/list', 'e', Lang::T('Invalid endpoint target'));
        }
        $d = ORM::for_table('tbl_routers')->find_one($id);
        if (!$d) {
            r2(U . 'routers/list', 'e', $_L['Account_Not_Found']);
        }
        if (!empty($config['radius_mode'])) {
            r2(U . 'routers/list', 'e', Lang::T('Plan import is not available in RADIUS mode'));
        }
        run_hook('router_import_plans'); #HOOK

        list($client, $used, $err) = Mikrotik::getClientForRouter($d->as_array(), $target);
        if (!$client) {
            r2(U . 'routers/list', 'e', $err);
        }

        // Plans on the secondary endpoint get tagged with "::secondary".
        $selector = $d['name'] . ($target === 'secondary' ? '::secondary' : '');

        // Choose first existing bandwidth row as a default — required NOT NULL field.
        $bw = ORM::for_table('tbl_bandwidth')->find_one();
        if (!$bw) {
            r2(U . 'routers/list', 'e', Lang::T('Create at least one Bandwidth row first (Services > Bandwidth)'));
        }
        $bwId = (int) $bw['id'];

        $createdHotspot = 0; $skippedHotspot = 0;
        $createdPpp     = 0; $skippedPpp     = 0;

        try {
            $resp = $client->sendSync(new RouterOS\Request('/ip/hotspot/user/profile/print'));
            foreach ($resp->getAllOfType(RouterOS\Response::TYPE_DATA) as $row) {
                $name = (string) $row->getProperty('name');
                if ($name === '' || strtolower($name) === 'default') continue;
                $existing = ORM::for_table('tbl_plans')
                    ->where('name_plan', $name)
                    ->where('routers',   $selector)
                    ->where('type',      'Hotspot')
                    ->find_one();
                if ($existing) { $skippedHotspot++; continue; }
                $p = ORM::for_table('tbl_plans')->create();
                $p->name_plan     = $name;
                $p->type          = 'Hotspot';
                $p->typebp        = 'Unlimited';
                // limit_type / time_unit / data_unit are ENUMs without ''; leave NULL.
                $p->time_limit    = 0;
                $p->data_limit    = 0;
                $p->id_bw         = $bwId;
                $p->price         = 0;
                $p->shared_users  = (int) ($row->getProperty('shared-users') ?: 1);
                $p->validity      = 1;
                $p->validity_unit = 'Days';
                $p->routers       = $selector;
                $p->enabled       = 1; // enabled immediately so it appears in voucher/recharge dropdowns; admin sets price right after.
                $p->save();
                $createdHotspot++;
            }
        } catch (Exception $e) {
            // Hotspot menu may not be available (device-mode block) — note and continue.
        }

        try {
            $resp = $client->sendSync(new RouterOS\Request('/ppp/profile/print'));
            foreach ($resp->getAllOfType(RouterOS\Response::TYPE_DATA) as $row) {
                $name = (string) $row->getProperty('name');
                if ($name === '' || $name === 'default' || $name === 'default-encryption') continue;
                $existing = ORM::for_table('tbl_plans')
                    ->where('name_plan', $name)
                    ->where('routers',   $selector)
                    ->where('type',      'PPPOE')
                    ->find_one();
                if ($existing) { $skippedPpp++; continue; }
                $p = ORM::for_table('tbl_plans')->create();
                $p->name_plan     = $name;
                $p->type          = 'PPPOE';
                $p->typebp        = 'Unlimited';
                $p->time_limit    = 0;
                $p->data_limit    = 0;
                $p->id_bw         = $bwId;
                $p->price         = 0;
                $p->shared_users  = 1;
                $p->validity      = 30;
                $p->validity_unit = 'Days';
                $p->routers       = $selector;
                $p->enabled       = 1;
                $p->save();
                $createdPpp++;
            }
        } catch (Exception $e) {
            // PPP menu may not be available — note and continue.
        }

        _log(
            "Imported plans from router #{$d['id']} ({$used}): "
            . "$createdHotspot hotspot, $createdPpp pppoe (skipped {$skippedHotspot}+{$skippedPpp})",
            'Router', $admin['id']
        );

        if ($createdHotspot + $createdPpp === 0 && $skippedHotspot + $skippedPpp === 0) {
            r2(U . 'routers/list', 'e', Lang::T('No importable profiles found on the router.'));
        }
        $msg = Lang::T('Imported from ') . htmlspecialchars($used) . ': '
             . "<strong>{$createdHotspot}</strong> " . Lang::T('new hotspot plan(s)')
             . ($skippedHotspot ? " ({$skippedHotspot} " . Lang::T('already present') . ")" : '')
             . ", <strong>{$createdPpp}</strong> " . Lang::T('new PPPoE plan(s)')
             . ($skippedPpp ? " ({$skippedPpp} " . Lang::T('already present') . ")" : '')
             . '. ' . Lang::T('Plans are enabled with price=0 — set real prices in Services before selling vouchers.');
        r2(U . 'routers/list', 's', $msg);
        break;

    case 'remote-login':
        $id     = $routes['2'];
        $target = isset($routes['3']) ? $routes['3'] : 'auto';
        if (!in_array($target, ['primary','secondary','auto'], true)) {
            $target = 'auto';
        }
        $d = ORM::for_table('tbl_routers')->find_one($id);
        if (!$d) {
            r2(U . 'routers/list', 'e', $_L['Account_Not_Found']);
        }
        if (!empty($config['radius_mode'])) {
            r2(U . 'routers/list', 'e', Lang::T('Remote Login is not available in RADIUS mode'));
        }
        run_hook('router_remote_login'); #HOOK

        list($client, $used, $err) = Mikrotik::getClientForRouter($d->as_array(), $target);
        if (!$client) {
            r2(U . 'routers/list', 'e', $err);
        }

        $identity = '';
        $resource = [];
        $activeCount = ['hotspot' => 0, 'pppoe' => 0];
        $hotspotActive = [];
        $pppoeActive = [];
        $sectionErrors = [];

        try {
            $r = $client->sendSync(new RouterOS\Request('/system/identity/print'));
            $identity = (string) $r->getProperty('name');
        } catch (Exception $e) {
            $sectionErrors['identity'] = $e->getMessage();
        }

        try {
            $r = $client->sendSync(new RouterOS\Request('/system/resource/print'));
            $row = $r->current();
            if ($row) {
                foreach (['board-name','version','uptime','cpu-load','free-memory','total-memory','architecture-name'] as $k) {
                    $resource[$k] = (string) $row->getProperty($k);
                }
            }
        } catch (Exception $e) {
            $sectionErrors['resource'] = $e->getMessage();
        }

        // Pre-check: only query hotspot/active if at least one hotspot server is enabled+valid.
        // Routers where /ip hotspot is empty, all-disabled, or all-invalid (device-mode block)
        // get this section hidden rather than showing a confusing PEAR2 protocol error.
        $hotspotAvailable = false;
        try {
            $hs = $client->sendSync(new RouterOS\Request('/ip/hotspot/print'));
            foreach ($hs->getAllOfType(RouterOS\Response::TYPE_DATA) as $row) {
                $invalid  = (string) $row->getProperty('invalid');
                $disabled = (string) $row->getProperty('disabled');
                if ($invalid !== 'true' && $disabled !== 'true') {
                    $hotspotAvailable = true;
                    break;
                }
            }
        } catch (Exception $e) {
            // Menu absent on minimal RouterOS builds — treat as not available, no error
        }

        if ($hotspotAvailable) {
            try {
                $hs = $client->sendSync(new RouterOS\Request('/ip/hotspot/active/print'));
                foreach ($hs->getAllOfType(RouterOS\Response::TYPE_DATA) as $row) {
                    $hotspotActive[] = [
                        'user'    => (string) $row->getProperty('user'),
                        'address' => (string) $row->getProperty('address'),
                        'uptime'  => (string) $row->getProperty('uptime'),
                    ];
                }
                $activeCount['hotspot'] = count($hotspotActive);
            } catch (Exception $e) {
                $sectionErrors['hotspot'] = $e->getMessage();
            }
        }

        try {
            $pp = $client->sendSync(new RouterOS\Request('/ppp/active/print'));
            foreach ($pp->getAllOfType(RouterOS\Response::TYPE_DATA) as $row) {
                $pppoeActive[] = [
                    'name'    => (string) $row->getProperty('name'),
                    'address' => (string) $row->getProperty('address'),
                    'uptime'  => (string) $row->getProperty('uptime'),
                    'service' => (string) $row->getProperty('service'),
                ];
            }
            $activeCount['pppoe'] = count($pppoeActive);
        } catch (Exception $e) {
            $sectionErrors['pppoe'] = $e->getMessage();
        }

        _log('Remote login to router #' . $d['id'] . ' (' . $used . ')', 'Router', $admin['id']);

        $ui->assign('d', $d);
        $ui->assign('used_target', $used);
        $ui->assign('identity', $identity);
        $ui->assign('resource', $resource);
        $ui->assign('activeCount', $activeCount);
        $ui->assign('hotspotActive', $hotspotActive);
        $ui->assign('pppoeActive', $pppoeActive);
        $ui->assign('sectionErrors', $sectionErrors);
        $ui->assign('hotspotAvailable', $hotspotAvailable);
        $ui->display('routers-console.tpl');
        break;

    default:
        echo 'action not defined';
}