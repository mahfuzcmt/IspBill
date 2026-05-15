<?php

/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)

 **/
_admin();
$ui->assign('_title', $_L['Customers']);
$ui->assign('_system_menu', 'customers');

$action = $routes['1'];
$admin = Admin::_info();
$ui->assign('_admin', $admin);

use PEAR2\Net\RouterOS;

require_once 'system/autoload/PEAR2/Autoload.php';

if ($admin['user_type'] != 'Admin' and $admin['user_type'] != 'Sales') {
    r2(U . "dashboard", 'e', $_L['Do_Not_Access']);
}

switch ($action) {
    case 'list':
        $ui->assign('xfooter', '<script type="text/javascript" src="ui/lib/c/customers.js"></script>');
        $username = _post('username');
        run_hook('list_customers'); #HOOK
        if ($username != '') {
            // Paginator::bootstrap uses exact-match where() and breaks with wildcards,
            // returning null when no row's username equals literally '%arif%'. Build
            // a paginator struct directly using where_like for the real count.
            $whereSql = "WHERE c.username LIKE ?";
            $params   = ['%' . $username . '%'];
            $per_page = 10;
            $page     = isset($routes['2']) && (int)$routes['2'] > 0 ? (int)$routes['2'] : 1;
            $total    = ORM::for_table('tbl_customers')
                        ->where_like('username', '%' . $username . '%')->count();
            $startpoint = ($page - 1) * $per_page;
            $lastpage   = max(1, (int) ceil($total / $per_page));
            // Build a paginator HTML strip ourselves; Paginator class can't help here.
            $linkBase  = U . $routes[0] . '/' . $routes[1] . '/';
            $contents  = '<ul class="pagination pagination-sm">';
            for ($i = 1; $i <= $lastpage; $i++) {
                $contents .= $i == $page
                    ? "<li class='active'><a href='javascript:void(0);'>$i</a></li>"
                    : "<li><a href='{$linkBase}$i'>$i</a></li>";
            }
            $contents .= '</ul>';
            $paginator = [
                'startpoint' => $startpoint,
                'limit'      => $per_page,
                'found'      => $total,
                'page'       => $page,
                'lastpage'   => $lastpage,
                'contents'   => $contents,
            ];
        } else {
            $paginator = Paginator::bootstrap('tbl_customers');
            $whereSql  = "";
            $params    = [];
        }

        // Latest recharge per customer (LEFT JOIN); sort by expiration ASC so
        // customers expiring soonest float to the top, NULLs (no plan) last.
        // Defensive defaults in case paginator returns null (e.g. zero rows).
        $limit  = isset($paginator['limit'])      ? (int) $paginator['limit']      : 10;
        $offset = isset($paginator['startpoint']) ? (int) $paginator['startpoint'] : 0;
        if ($limit < 1) $limit = 10;
        $sql = "
            SELECT
                c.id, c.username, c.fullname, c.phonenumber, c.email, c.created_at,
                r.namebp        AS plan_name,
                r.expiration    AS expiration,
                r.status        AS recharge_status,
                r.type          AS service_type,
                CASE
                    WHEN r.id IS NULL              THEN 'Pending'
                    WHEN r.status = 'off'          THEN 'Suspended'
                    WHEN r.expiration < CURDATE()  THEN 'Expired'
                    ELSE 'Active'
                END             AS computed_status,
                DATEDIFF(r.expiration, CURDATE()) AS days_left
            FROM tbl_customers c
            LEFT JOIN (
                SELECT customer_id, MAX(id) AS last_id
                FROM tbl_user_recharges
                GROUP BY customer_id
            ) lr ON lr.customer_id = c.id
            LEFT JOIN tbl_user_recharges r ON r.id = lr.last_id
            $whereSql
            ORDER BY (r.expiration IS NULL) ASC, r.expiration ASC, c.id DESC
            LIMIT $limit OFFSET $offset
        ";
        $d = ORM::for_table('tbl_customers')->raw_query($sql, $params)->find_many();

        // -----------------------------------------------------------
        // Live router state — one query each for secrets/hotspot/active
        // so the Status column reflects the router truth (e.g. an admin
        // manually re-enabled a PPP secret won't keep showing "Expired").
        // -----------------------------------------------------------
        $liveState = [];
        $routerReachable = false;
        try {
            $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
            if ($rt) {
                $client = Mikrotik::getClient($rt['ip_address'], $rt['username'], $rt['password']);

                $req = new RouterOS\Request('/ppp/secret/print');
                $req->setArgument('.proplist', 'name,disabled,profile,last-logged-out');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() === RouterOS\Response::TYPE_DATA) {
                        $liveState[$r->getProperty('name')] = [
                            'kind'           => 'PPP',
                            'disabled'       => $r->getProperty('disabled') === 'true',
                            'profile'        => $r->getProperty('profile'),
                            'active'         => false,
                            'address'        => null,
                            'uptime'         => null,
                            'lastLoggedOut'  => $r->getProperty('last-logged-out'),
                        ];
                    }
                }

                $req = new RouterOS\Request('/ip/hotspot/user/print');
                $req->setArgument('.proplist', 'name,disabled,profile');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() === RouterOS\Response::TYPE_DATA) {
                        $name = $r->getProperty('name');
                        $liveState[$name] = [
                            'kind'           => 'Hotspot',
                            'disabled'       => $r->getProperty('disabled') === 'true',
                            'profile'        => $r->getProperty('profile'),
                            'active'         => false,
                            'address'        => null,
                            'uptime'         => null,
                            'lastLoggedOut'  => null,
                        ];
                    }
                }

                $req = new RouterOS\Request('/ppp/active/print');
                $req->setArgument('.proplist', 'name,address,uptime');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() === RouterOS\Response::TYPE_DATA) {
                        $name = $r->getProperty('name');
                        if (isset($liveState[$name])) {
                            $liveState[$name]['active']  = true;
                            $liveState[$name]['address'] = $r->getProperty('address');
                            $liveState[$name]['uptime']  = $r->getProperty('uptime');
                        }
                    }
                }
                $routerReachable = true;
            }
        } catch (Exception $e) {
            // Router unreachable — fall back to DB-derived status.
            error_log('customers/list: live router state failed: ' . $e->getMessage());
        }

        // Router Web UI URL — admin-configurable via tbl_appconfig.router_web_url.
        // Falls back to APP_URL with port 8090 if unset (matching the current proxy setup).
        $routerWebUrl = isset($config['router_web_url']) ? trim($config['router_web_url']) : '';
        if ($routerWebUrl === '') {
            $routerWebUrl = preg_replace('#:\d+(/.*)?$#', ':8090/', APP_URL);
            if ($routerWebUrl === APP_URL) {
                $routerWebUrl = rtrim(APP_URL, '/') . ':8090/';
            }
        }

        $ui->assign('d', $d);
        $ui->assign('paginator', $paginator);
        $ui->assign('liveState', $liveState);
        $ui->assign('routerReachable', $routerReachable);
        $ui->assign('routerWebUrl', $routerWebUrl);
        $ui->display('customers.tpl');
        break;

    case 'add':
        run_hook('view_add_customer'); #HOOK
        $ui->display('customers-add.tpl');
        break;

    case 'edit':
        $id  = $routes['2'];
        run_hook('edit_customer'); #HOOK
        $d = ORM::for_table('tbl_customers')->find_one($id);
        if ($d) {
            $ui->assign('d', $d);
            $ui->display('customers-edit.tpl');
        } else {
            r2(U . 'customers/list', 'e', $_L['Account_Not_Found']);
        }
        break;

    case 'delete':
        $id  = $routes['2'];
        run_hook('delete_customer'); #HOOK
        $d = ORM::for_table('tbl_customers')->find_one($id);
        if ($d) {
            $c = ORM::for_table('tbl_user_recharges')->where('username', $d['username'])->find_one();
            if ($c) {
                $mikrotik = Mikrotik::info($c['routers']);
                if ($c['type'] == 'Hotspot') {
                    if(!$config['radius_mode']){
                        $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                        Mikrotik::removeHotspotUser($client,$c['username']);
                        Mikrotik::removeHotspotActiveUser($client,$user['username']);
                    }
                } else {
                    if(!$config['radius_mode']){
                        $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                        Mikrotik::removePpoeUser($client,$c['username']);
                        Mikrotik::removePpoeActive($client,$user['username']);
                    }
                }
                try {
                    $d->delete();
                } catch (Exception $e) {
                } catch(Throwable $e){
                }
                try {
                    $c->delete();
                } catch (Exception $e) {
                }
            } else {
                try {
                    $d->delete();
                } catch (Exception $e) {
                } catch(Throwable $e){
                }
                try {
                    $c->delete();
                } catch (Exception $e) {
                } catch(Throwable $e){
                }
            }

            r2(U . 'customers/list', 's', $_L['User_Delete_Ok']);
        }
        break;

    case 'add-post':
        $username = _post('username');
        $fullname = _post('fullname');
        $password = _post('password');
        $cpassword = _post('cpassword');
        $address = _post('address');
        $phonenumber = _post('phonenumber');
        run_hook('add_customer'); #HOOK
        $msg = '';
        if (Validator::Length($username, 35, 2) == false) {
            $msg .= 'Username should be between 3 to 55 characters' . '<br>';
        }
        if (Validator::Length($fullname, 36, 2) == false) {
            $msg .= 'Full Name should be between 3 to 25 characters' . '<br>';
        }
        if (!Validator::Length($password, 35, 2)) {
            $msg .= 'Password should be between 3 to 35 characters' . '<br>';
        }
        if ($password != $cpassword) {
            $msg .= 'Passwords does not match' . '<br>';
        }

        $d = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
        if ($d) {
            $msg .= $_L['account_already_exist'] . '<br>';
        }

        if ($msg == '') {
            $d = ORM::for_table('tbl_customers')->create();
            $d->username = $username;
            $d->password = $password;
            $d->fullname = $fullname;
            $d->address = $address;
            $d->phonenumber = $username;
            $d->save();
            r2(U . 'customers/list', 's', $_L['account_created_successfully']);
        } else {
            r2(U . 'customers/add', 'e', $msg);
        }
        break;

    case 'edit-post':
        $username = _post('username');
        $fullname = _post('fullname');
        $password = _post('password');
        $cpassword = _post('cpassword');
        $address = _post('address');
        $phonenumber = _post('phonenumber');
        run_hook('edit_customer'); #HOOK
        $msg = '';
        if (Validator::Length($username, 16, 2) == false) {
            $msg .= 'Username should be between 3 to 15 characters' . '<br>';
        }
        if (Validator::Length($fullname, 26, 2) == false) {
            $msg .= 'Full Name should be between 3 to 25 characters' . '<br>';
        }
        if ($password != '') {
            if (!Validator::Length($password, 15, 2)) {
                $msg .= 'Password should be between 3 to 15 characters' . '<br>';
            }
            if ($password != $cpassword) {
                $msg .= 'Passwords does not match' . '<br>';
            }
        }

        $id = _post('id');
        $d = ORM::for_table('tbl_customers')->find_one($id);
        if (!$d) {
            $msg .= $_L['Data_Not_Found'] . '<br>';
        }

        if ($d['username'] != $username) {
            $c = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
            if ($c) {
                $msg .= $_L['account_already_exist'] . '<br>';
            }
        }

        if ($msg == '') {
            $c = ORM::for_table('tbl_user_recharges')->where('username', $username)->find_one();
            if ($c) {
                $mikrotik = Mikrotik::info($c['routers']);
                if ($c['type'] == 'Hotspot') {
                    if(!$config['radius_mode']){
                        $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                        Mikrotik::setHotspotUser($client,$c['username'],$password);
                        Mikrotik::removeHotspotActiveUser($client,$user['username']);
                    }

                    $d->password = $password;
                    $d->save();
                } else {
                    if(!$config['radius_mode']){
                        $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                        Mikrotik::setPpoeUser($client,$c['username'],$password);
                        Mikrotik::removePpoeActive($client,$user['username']);
                    }

                    $d->password = $password;
                    $d->save();
                }
                $d->username = $username;
                if ($password != '') {
                    $d->password = $password;
                }
                $d->fullname = $fullname;
                $d->address = $address;
                $d->phonenumber = $phonenumber;
                $d->save();
            } else {
                $d->username = $username;
                if ($password != '') {
                    $d->password = $password;
                }
                $d->fullname = $fullname;
                $d->address = $address;
                $d->phonenumber = $phonenumber;
                $d->save();
            }
            r2(U . 'customers/list', 's', 'User Updated Successfully');
        } else {
            r2(U . 'customers/edit/' . $id, 'e', $msg);
        }
        break;

    case 'live-traffic':
        // Live bandwidth monitor page. Auto-refreshes via JS calling live-traffic-data.
        try {
            $ui->display('customers-live-traffic.tpl');
        } catch (Throwable $e) {
            header('Content-Type: text/plain');
            echo "live-traffic display error: " . $e->getMessage() . "\n";
            echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
            echo $e->getTraceAsString();
        }
        break;

    case 'live-traffic-data':
        // JSON endpoint: current PPP active sessions with real-time rate.
        // RouterOS 7 doesn't expose bytes-in/out on /ppp/active; we pull both
        // cumulative bytes AND current rate from /queue/simple (dynamic PPPoE
        // queues), keyed on "<pppoe-USERNAME>".
        header('Content-Type: application/json');
        $out = ['ts' => time(), 'sessions' => [], 'error' => null];
        try {
            $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
            if (!$rt) { throw new Exception('no enabled router'); }
            $client = Mikrotik::getClient($rt['ip_address'], $rt['username'], $rt['password']);

            $custMap = [];
            foreach (ORM::for_table('tbl_customers')->find_many() as $c) {
                $custMap[$c['username']] = $c['fullname'];
            }

            // 1. Build queue stats map: username → {rateRx, rateTx, bytesRx, bytesTx}
            $queueByUser = [];
            $req = new RouterOS\Request('/queue/simple/print');
            $req->setArgument('stats', 'yes');
            $req->setArgument('.proplist', 'name,bytes,rate');
            foreach ($client->sendSync($req) as $r) {
                if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                $qname = $r->getProperty('name');
                // Strip "<pppoe-…>" wrapper
                if (preg_match('/^<pppoe-(.+)>$/', $qname, $m)) {
                    $user = $m[1];
                } else {
                    $user = trim($qname, '<>');
                }
                $bytes = explode('/', $r->getProperty('bytes') ?: '0/0');
                $rate  = explode('/', $r->getProperty('rate')  ?: '0/0');
                $queueByUser[$user] = [
                    'bytesRx' => (int) ($bytes[0] ?? 0),
                    'bytesTx' => (int) ($bytes[1] ?? 0),
                    'rateRx'  => (int) ($rate[0]  ?? 0),
                    'rateTx'  => (int) ($rate[1]  ?? 0),
                ];
            }

            // 2. Pull active sessions for IP / uptime / caller-id
            $req = new RouterOS\Request('/ppp/active/print');
            $req->setArgument('.proplist', 'name,service,address,uptime,caller-id');
            foreach ($client->sendSync($req) as $r) {
                if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                $name = $r->getProperty('name');
                $q = $queueByUser[$name] ?? ['bytesRx'=>0,'bytesTx'=>0,'rateRx'=>0,'rateTx'=>0];
                $out['sessions'][] = [
                    'username' => $name,
                    'fullname' => $custMap[$name] ?? '',
                    'service'  => $r->getProperty('service'),
                    'address'  => $r->getProperty('address'),
                    'uptime'   => $r->getProperty('uptime'),
                    'callerId' => $r->getProperty('caller-id'),
                    'bytesIn'  => $q['bytesRx'],
                    'bytesOut' => $q['bytesTx'],
                    'rateIn'   => $q['rateRx'],   // bytes/sec from router
                    'rateOut'  => $q['rateTx'],
                ];
            }

            // 3. Hotspot active (best-effort; skip on empty/error)
            try {
                $req = new RouterOS\Request('/ip/hotspot/active/print');
                $req->setArgument('.proplist', 'user,address,uptime,bytes-in,bytes-out,mac-address');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $name = $r->getProperty('user');
                    $out['sessions'][] = [
                        'username' => $name,
                        'fullname' => $custMap[$name] ?? '',
                        'service'  => 'hotspot',
                        'address'  => $r->getProperty('address'),
                        'uptime'   => $r->getProperty('uptime'),
                        'callerId' => $r->getProperty('mac-address'),
                        'bytesIn'  => (int) $r->getProperty('bytes-in'),
                        'bytesOut' => (int) $r->getProperty('bytes-out'),
                        'rateIn'   => 0,
                        'rateOut'  => 0,
                    ];
                }
            } catch (Exception $e) { /* no active hotspot sessions, skip */ }
        } catch (Exception $e) {
            $out['error'] = $e->getMessage();
        }
        echo json_encode($out);
        exit;

    case 'billing':
        // Edit billing / plan / status for a customer's latest recharge.
        // Combines Features 2 (edit billing) and 3 (migrate plan).
        $id = (int) $routes['2'];
        $c  = ORM::for_table('tbl_customers')->find_one($id);
        if (!$c) { r2(U . 'customers/list', 'e', $_L['Account_Not_Found']); }

        // Latest recharge for this customer (may be NULL = no plan yet)
        $r = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $id)
            ->order_by_desc('id')
            ->find_one();

        // Plans available for the customer's service type, falling back to all if no recharge
        $serviceType = $r ? $r['type'] : 'PPPoE';
        // tbl_plans uses 'PPPOE' (caps) for type column
        $planType = ($serviceType === 'Hotspot') ? 'Hotspot' : 'PPPOE';
        $plans = ORM::for_table('tbl_plans')
            ->where('type', $planType)
            ->where('enabled', 1)
            ->order_by_asc('name_plan')
            ->find_many();

        $ui->assign('c', $c);
        $ui->assign('r', $r);
        $ui->assign('plans', $plans);
        $ui->assign('service_type', $serviceType);
        $ui->display('customers-billing.tpl');
        break;

    case 'billing-save':
        $id              = (int) _post('customer_id');
        $newPlanId       = (int) _post('plan_id');
        $newExpiration   = trim((string) _post('expiration'));
        $newStatus       = _post('status') === 'on' ? 'on' : 'off';
        run_hook('save_billing'); #HOOK

        $c = ORM::for_table('tbl_customers')->find_one($id);
        if (!$c) { r2(U . 'customers/list', 'e', $_L['Account_Not_Found']); }

        $r = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $id)
            ->order_by_desc('id')
            ->find_one();

        $newPlan = ORM::for_table('tbl_plans')->find_one($newPlanId);
        if (!$newPlan) { r2(U . 'customers/billing/' . $id, 'e', 'Invalid plan selected'); }

        // Validate expiration
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newExpiration)) {
            r2(U . 'customers/billing/' . $id, 'e', 'Invalid expiration date (need YYYY-MM-DD)');
        }

        // Determine the router. tbl_plans.routers may be a router ID (legacy) or a name
        // (newer rows). Try both, fall back to the customer's current recharge router, and
        // finally to the single enabled router as a last resort.
        $rtField   = $newPlan['routers'];
        $mikrotik  = ORM::for_table('tbl_routers')->where('name', $rtField)->find_one();
        if (!$mikrotik && is_numeric($rtField)) {
            $mikrotik = ORM::for_table('tbl_routers')->find_one((int) $rtField);
        }
        if (!$mikrotik && $r) {
            $mikrotik = ORM::for_table('tbl_routers')->where('name', $r['routers'])->find_one();
        }
        if (!$mikrotik) {
            $mikrotik = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
        }
        if (!$mikrotik) { r2(U . 'customers/billing/' . $id, 'e', 'No enabled router configured'); }
        $routerName = $mikrotik['name'];

        $oldPlanName = $r ? $r['namebp']         : '';
        $oldStatus   = $r ? $r['status']         : 'off';
        $oldExp      = $r ? $r['expiration']     : '';
        $serviceType = $newPlan['type'] === 'Hotspot' ? 'Hotspot' : 'PPPoE';

        // Persist the recharge (insert new or update existing)
        if ($r) {
            $r->plan_id     = $newPlan['id'];
            $r->namebp      = $newPlan['name_plan'];
            $r->expiration  = $newExpiration;
            $r->status      = $newStatus;
            $r->routers     = $routerName;
            $r->type        = $serviceType;
            $r->save();
        } else {
            $r = ORM::for_table('tbl_user_recharges')->create();
            $r->customer_id  = $id;
            $r->username     = $c['username'];
            $r->plan_id      = $newPlan['id'];
            $r->namebp       = $newPlan['name_plan'];
            $r->recharged_on = date('Y-m-d');
            $r->expiration   = $newExpiration;
            $r->time         = date('H:i:s');
            $r->status       = $newStatus;
            $r->method       = 'admin';
            $r->routers      = $routerName;
            $r->type         = $serviceType;
            $r->save();
        }

        // Sync the router — only for PPPoE in this iteration; Hotspot would be similar
        if (!$config['radius_mode'] && $serviceType === 'PPPoE') {
            try {
                $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                // (a) Plan migration → swap profile if changed
                if ($oldPlanName !== $newPlan['name_plan']) {
                    Mikrotik::setPpoeUserProfile($client, $c['username'], $newPlan['name_plan']);
                }
                // (b) Status change → enable/disable secret
                if ($oldStatus !== $newStatus) {
                    if ($newStatus === 'on') {
                        Mikrotik::enablePpoeUser($client, $c['username']);
                    } else {
                        Mikrotik::disablePpoeUser($client, $c['username']);
                        Mikrotik::removePpoeActive($client, $c['username']);
                    }
                }
            } catch (Exception $e) {
                r2(U . 'customers/billing/' . $id, 'e', 'DB updated, but router sync failed: ' . $e->getMessage());
            }
        }

        // Audit-ish log row
        _log($c['username'] . ' billing updated by ' . $admin['username']
            . ' (plan: ' . $oldPlanName . ' → ' . $newPlan['name_plan']
            . ', exp: ' . $oldExp . ' → ' . $newExpiration
            . ', status: ' . $oldStatus . ' → ' . $newStatus . ')', 'User', $id);

        r2(U . 'customers/list', 's', 'Billing updated for ' . $c['username']);
        break;

    default:
    r2(U . 'customers/list', 'e', 'action not defined');
}
