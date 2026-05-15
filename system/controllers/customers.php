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
        // Provide routers + plans so the form can offer Service / Router / Plan selectors
        $routers = ORM::for_table('tbl_routers')->where('enabled', 1)->find_many();
        $plans   = ORM::for_table('tbl_plans')->where('enabled', 1)->order_by_asc('name_plan')->find_many();
        $ui->assign('routers', $routers);
        $ui->assign('plans',   $plans);
        $ui->display('customers-add.tpl');
        break;

    case 'edit':
        $id  = (int) $routes['2'];
        run_hook('edit_customer'); #HOOK
        $d = ORM::for_table('tbl_customers')->find_one($id);
        if (!$d) { r2(U . 'customers/list', 'e', $_L['Account_Not_Found']); }
        $r = ORM::for_table('tbl_user_recharges')
                ->where('customer_id', $id)->order_by_desc('id')->find_one();
        $plans   = ORM::for_table('tbl_plans')->where('enabled', 1)->order_by_asc('name_plan')->find_many();
        $routers = ORM::for_table('tbl_routers')->where('enabled', 1)->find_many();
        $ui->assign('d', $d);
        $ui->assign('r', $r);
        $ui->assign('plans', $plans);
        $ui->assign('routers', $routers);
        $ui->display('customers-edit.tpl');
        break;

    case 'delete':
        $id  = (int) $routes['2'];
        run_hook('delete_customer'); #HOOK
        $d = ORM::for_table('tbl_customers')->find_one($id);
        if (!$d) { r2(U . 'customers/list', 'e', $_L['Account_Not_Found']); }

        // Best-effort: try to remove the user from the router. Never let a
        // Mikrotik failure block the DB deletion. The original code:
        //   - hard-coded Mikrotik::info($c['routers']) which fails when the
        //     routers field stores an ID (legacy) instead of a name
        //   - used $user['username'] which was always undefined
        //   - called Mikrotik::getClient (which die()s) without a try/catch
        // All three are corrected here.
        $routerWarning = '';
        $recharges = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $id)
            ->find_many();
        if (empty($recharges)) {
            // Fall back to username match for legacy rows
            $recharges = ORM::for_table('tbl_user_recharges')
                ->where('username', $d['username'])
                ->find_many();
        }

        if (!empty($recharges) && empty($config['radius_mode'])) {
            // Pick a router from the most recent recharge
            $latest = $recharges[0];
            foreach ($recharges as $rr) {
                if ($rr['id'] > $latest['id']) $latest = $rr;
            }
            $mikrotik = ORM::for_table('tbl_routers')
                ->where('name', $latest['routers'])->find_one();
            if (!$mikrotik && is_numeric($latest['routers'])) {
                $mikrotik = ORM::for_table('tbl_routers')->find_one((int) $latest['routers']);
            }
            if (!$mikrotik) {
                $mikrotik = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
            }
            if ($mikrotik) {
                try {
                    $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                    if ($latest['type'] === 'Hotspot') {
                        try { Mikrotik::removeHotspotUser($client, $d['username']); } catch (Throwable $e) {}
                        try { Mikrotik::removeHotspotActiveUser($client, $d['username']); } catch (Throwable $e) {}
                    } else {
                        try { Mikrotik::removePpoeUser($client, $d['username']); } catch (Throwable $e) {}
                        try { Mikrotik::removePpoeActive($client, $d['username']); } catch (Throwable $e) {}
                    }
                } catch (Throwable $e) {
                    $routerWarning = ' (router push skipped: ' . $e->getMessage() . ')';
                }
            }
        }

        // Delete recharges, then the customer
        foreach ($recharges as $rr) {
            try { $rr->delete(); } catch (Throwable $e) {}
        }
        try { $d->delete(); } catch (Throwable $e) {}

        r2(U . 'customers/list', 's', $_L['User_Delete_Ok'] . $routerWarning);
        break;

    case 'add-post':
        $username     = trim((string) _post('username'));
        $fullname     = trim((string) _post('fullname'));
        $password     = (string) _post('password');
        $cpassword    = (string) _post('cpassword');
        $address      = (string) _post('address');
        $phonenumber  = trim((string) _post('phonenumber'));
        $email        = trim((string) _post('email'));
        $planId       = (int) _post('plan_id');
        $pushToRouter = _post('push_to_router') ? true : false;
        $expiration   = trim((string) _post('expiration')); // optional YYYY-MM-DD; default: 30d
        run_hook('add_customer'); #HOOK

        $msg = '';
        if (Validator::Length($username, 35, 2) == false)  $msg .= 'Username should be 3 to 35 characters<br>';
        if (Validator::Length($fullname, 36, 2) == false)  $msg .= 'Full Name should be 3 to 35 characters<br>';
        if (!Validator::Length($password, 35, 2))          $msg .= 'Password should be 3 to 35 characters<br>';
        if ($password !== $cpassword)                      $msg .= 'Passwords do not match<br>';
        if (ORM::for_table('tbl_customers')->where('username', $username)->find_one())
                                                           $msg .= $_L['account_already_exist'] . '<br>';
        $plan = $planId > 0 ? ORM::for_table('tbl_plans')->find_one($planId) : null;
        if ($planId && !$plan)                             $msg .= 'Selected plan not found<br>';

        if ($msg !== '') { r2(U . 'customers/add', 'e', $msg); }

        // Create customer
        $d = ORM::for_table('tbl_customers')->create();
        $d->username    = $username;
        $d->password    = $password;       // tbl_customers stores plaintext (Password::_uverify)
        $d->fullname    = $fullname;
        $d->address     = $address;
        $d->phonenumber = $phonenumber !== '' ? $phonenumber : $username;
        $d->email       = $email       !== '' ? $email       : ($username . '@local');
        $d->save();
        $customerId = (int) $d->id();

        // If a plan was selected, set up recharge + push to router
        if ($plan) {
            $service = $plan['type'] === 'Hotspot' ? 'Hotspot' : 'PPPoE';

            // Resolve router for this plan (handles routers field being ID or name)
            $rtField  = $plan['routers'];
            $mikrotik = ORM::for_table('tbl_routers')->where('name', $rtField)->find_one();
            if (!$mikrotik && is_numeric($rtField)) {
                $mikrotik = ORM::for_table('tbl_routers')->find_one((int) $rtField);
            }
            if (!$mikrotik) {
                $mikrotik = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
            }

            // Push to Mikrotik
            if ($pushToRouter && $mikrotik && !$config['radius_mode']) {
                try {
                    $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                    $custData = ['username' => $username, 'password' => $password];
                    if ($service === 'PPPoE') {
                        Mikrotik::addPpoeUser($client, $plan, $custData);
                    } else {
                        Mikrotik::addHotspotUser($client, $plan, $custData);
                    }
                } catch (Throwable $e) {
                    // Don't roll back the DB row — the admin can re-push from the Billing UI.
                    // Surface the warning via the flash message.
                    r2(U . 'customers/list', 'e',
                        'Customer ' . $username . ' created in DB, but router push failed: ' . $e->getMessage());
                }
            }

            // Recharge record
            if (!$expiration || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration)) {
                // Default 30 days from now
                $expiration = date('Y-m-d', strtotime('+30 days'));
            }
            $r = ORM::for_table('tbl_user_recharges')->create();
            $r->customer_id  = $customerId;
            $r->username     = $username;
            $r->plan_id      = $plan['id'];
            $r->namebp       = $plan['name_plan'];
            $r->recharged_on = date('Y-m-d');
            $r->expiration   = $expiration;
            $r->time         = date('H:i:s');
            $r->status       = 'on';
            $r->method       = 'admin';
            $r->routers      = $mikrotik ? $mikrotik['name'] : '';
            $r->type         = $service;
            $r->save();

            // Transaction (audit)
            $t = ORM::for_table('tbl_transactions')->create();
            $t->invoice       = 'INV-' . _raid(5);
            $t->username      = $username;
            $t->plan_name     = $plan['name_plan'];
            $t->price         = $plan['price'];
            $t->recharged_on  = date('Y-m-d');
            $t->expiration    = $expiration;
            $t->time          = date('H:i:s');
            $t->method        = 'admin';
            $t->routers       = $mikrotik ? $mikrotik['name'] : '';
            $t->type          = $service;
            $t->save();

            _log("$username created with plan {$plan['name_plan']}, exp $expiration"
                . ($pushToRouter ? ' (pushed to router)' : ' (DB only)'),
                'User', $customerId);
        }

        // Auto-send welcome SMS (best-effort)
        $smsNote = '';
        if (!empty($config['sms_enabled']) && $config['sms_enabled'] !== '0'
            && $phonenumber !== '' && $plan) {
            $vars = [
                'company'    => isset($config['CompanyName']) ? $config['CompanyName'] : 'NetPulse',
                'fullname'   => $fullname,
                'username'   => $username,
                'password'   => $password,
                'phonenumber'=> $phonenumber,
                'plan'       => $plan['name_plan'],
                'price'      => $plan['price'],
                'validity'   => $plan['validity'] . ' ' . $plan['validity_unit'],
                'expiration' => $expiration,
            ];
            $res = SmsSender::sendTemplate($phonenumber, 'sms_template_welcome', $vars);
            $smsNote = $res['ok'] ? ' (welcome SMS sent)' : ' (welcome SMS failed: ' . $res['error'] . ')';
        }

        r2(U . 'customers/list', 's',
            ($plan ? "Customer $username created on plan {$plan['name_plan']}" : $_L['account_created_successfully'])
            . $smsNote);
        break;

    case 'edit-post':
        $id           = (int) _post('id');
        $username     = trim((string) _post('username'));
        $fullname     = trim((string) _post('fullname'));
        $password     = (string) _post('password');         // blank = keep existing
        $cpassword    = (string) _post('cpassword');
        $address      = (string) _post('address');
        $phonenumber  = trim((string) _post('phonenumber'));
        $email        = trim((string) _post('email'));
        $planId       = (int) _post('plan_id');
        $newExp       = trim((string) _post('expiration'));
        $newStatus    = _post('status') === 'on' ? 'on' : 'off';
        $pushToRouter = _post('push_to_router') ? true : false;
        run_hook('edit_customer'); #HOOK

        $d = ORM::for_table('tbl_customers')->find_one($id);
        if (!$d) { r2(U . 'customers/list', 'e', $_L['Account_Not_Found']); }

        // Validation
        $msg = '';
        if (Validator::Length($username, 65, 2) == false) $msg .= 'Username should be 3 to 64 characters<br>';
        if (Validator::Length($fullname, 65, 2) == false) $msg .= 'Full Name should be 3 to 64 characters<br>';
        if ($password !== '') {
            if (!Validator::Length($password, 35, 2)) $msg .= 'Password should be 3 to 35 characters<br>';
            if ($password !== $cpassword)             $msg .= 'Passwords do not match<br>';
        }
        // Username uniqueness (if changed)
        if ($d['username'] !== $username) {
            $exists = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
            if ($exists) $msg .= $_L['account_already_exist'] . '<br>';
        }
        if ($newExp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newExp)) {
            $msg .= 'Invalid expiration date<br>';
        }
        if ($msg !== '') { r2(U . 'customers/edit/' . $id, 'e', $msg); }

        // ---- Capture old state for diff against router ----
        $oldUsername = $d['username'];
        $oldPassword = $d['password'];
        $r = ORM::for_table('tbl_user_recharges')
                ->where('customer_id', $id)->order_by_desc('id')->find_one();
        $oldPlanName = $r ? $r['namebp']     : '';
        $oldStatus   = $r ? $r['status']     : 'off';
        $oldExp      = $r ? $r['expiration'] : '';
        $oldType     = $r ? $r['type']       : 'PPPoE';

        // ---- Update customer row ----
        $d->username    = $username;
        if ($password !== '') $d->password = $password;
        $d->fullname    = $fullname;
        $d->address     = $address;
        $d->phonenumber = $phonenumber;
        if ($email !== '') $d->email = $email;
        $d->save();

        // ---- Resolve plan + router (if a plan was selected) ----
        $plan = $planId > 0 ? ORM::for_table('tbl_plans')->find_one($planId) : null;
        $service = $plan ? ($plan['type'] === 'Hotspot' ? 'Hotspot' : 'PPPoE') : $oldType;
        $mikrotik = null;
        if ($plan) {
            $rtField  = $plan['routers'];
            $mikrotik = ORM::for_table('tbl_routers')->where('name', $rtField)->find_one();
            if (!$mikrotik && is_numeric($rtField)) {
                $mikrotik = ORM::for_table('tbl_routers')->find_one((int) $rtField);
            }
        }
        if (!$mikrotik && $r) {
            $mikrotik = ORM::for_table('tbl_routers')->where('name', $r['routers'])->find_one();
        }
        if (!$mikrotik) {
            $mikrotik = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
        }

        // ---- Update recharge row if a plan is in play ----
        if ($plan) {
            if ($newExp === '') $newExp = $oldExp ?: date('Y-m-d', strtotime('+30 days'));
            if ($r) {
                $r->plan_id    = $plan['id'];
                $r->namebp     = $plan['name_plan'];
                $r->expiration = $newExp;
                $r->status     = $newStatus;
                $r->type       = $service;
                $r->routers    = $mikrotik ? $mikrotik['name'] : '';
                $r->save();
            } else {
                $rr = ORM::for_table('tbl_user_recharges')->create();
                $rr->customer_id  = $id;
                $rr->username     = $username;
                $rr->plan_id      = $plan['id'];
                $rr->namebp       = $plan['name_plan'];
                $rr->recharged_on = date('Y-m-d');
                $rr->expiration   = $newExp;
                $rr->time         = date('H:i:s');
                $rr->status       = $newStatus;
                $rr->method       = 'admin';
                $rr->routers      = $mikrotik ? $mikrotik['name'] : '';
                $rr->type         = $service;
                $rr->save();
            }
        }

        // ---- Push changes to Mikrotik (best-effort, individual try/catch) ----
        $routerWarning = '';
        if ($pushToRouter && $mikrotik && empty($config['radius_mode']) && $service === 'PPPoE') {
            try {
                $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);

                // Username changed → recreate the secret with the new name
                if ($oldUsername !== $username) {
                    try { Mikrotik::removePpoeUser($client, $oldUsername); } catch (Throwable $e) {}
                    if ($plan) {
                        try {
                            Mikrotik::addPpoeUser($client, $plan, ['username' => $username, 'password' => $password ?: $oldPassword]);
                        } catch (Throwable $e) { $routerWarning .= ' (rename push: ' . $e->getMessage() . ')'; }
                    }
                } else {
                    // Password changed → setPpoeUser
                    if ($password !== '' && $password !== $oldPassword) {
                        try {
                            Mikrotik::setPpoeUser($client, ['username' => $username], $password);
                        } catch (Throwable $e) { $routerWarning .= ' (password push: ' . $e->getMessage() . ')'; }
                    }
                    // Plan changed → swap profile
                    if ($plan && $oldPlanName !== $plan['name_plan']) {
                        try {
                            Mikrotik::setPpoeUserProfile($client, $username, $plan['name_plan']);
                        } catch (Throwable $e) { $routerWarning .= ' (plan push: ' . $e->getMessage() . ')'; }
                    }
                    // Status changed
                    if ($oldStatus !== $newStatus) {
                        try {
                            if ($newStatus === 'on') {
                                Mikrotik::enablePpoeUser($client, $username);
                            } else {
                                Mikrotik::disablePpoeUser($client, $username);
                                try { Mikrotik::removePpoeActive($client, $username); } catch (Throwable $e) {}
                            }
                        } catch (Throwable $e) { $routerWarning .= ' (status push: ' . $e->getMessage() . ')'; }
                    }
                }
            } catch (Throwable $e) {
                $routerWarning = ' (router connection failed: ' . $e->getMessage() . ')';
            }
        }

        _log("$oldUsername edited by " . ($admin['username'] ?? '?') .
            ($oldUsername !== $username ? " → $username" : '') .
            ($plan ? "; plan=$oldPlanName→{$plan['name_plan']}" : '') .
            "; status=$oldStatus→$newStatus; exp=$oldExp→$newExp", 'User', $id);

        r2(U . 'customers/list', 's', 'Customer updated' . $routerWarning);
        break;

    case 'graph':
        // Per-customer bandwidth graph (historical from DB + live polling)
        $id = (int) $routes['2'];
        $c  = ORM::for_table('tbl_customers')->find_one($id);
        if (!$c) { r2(U . 'customers/list', 'e', $_L['Account_Not_Found']); }
        $r = ORM::for_table('tbl_user_recharges')
                ->where('customer_id', $id)->order_by_desc('id')->find_one();
        $ui->assign('c', $c);
        $ui->assign('r', $r);
        $ui->display('customers-graph.tpl');
        break;

    case 'graph-data':
        // JSON: historical samples from DB for one user + current snapshot.
        // URL: customers/graph-data/<username>?minutes=60
        header('Content-Type: application/json');
        $username = isset($routes['2']) ? $routes['2'] : '';
        $minutes  = isset($_GET['minutes']) ? max(5, min(10080, (int) $_GET['minutes'])) : 60;
        $out = ['username' => $username, 'minutes' => $minutes, 'samples' => [], 'live' => null, 'error' => null];
        if ($username === '') { $out['error'] = 'username required'; echo json_encode($out); exit; }

        try {
            // Historical from DB
            $stmt = ORM::for_table('tbl_traffic_samples')
                ->raw_query(
                    "SELECT UNIX_TIMESTAMP(ts) AS t, rate_in, rate_out, bytes_in, bytes_out
                     FROM tbl_traffic_samples
                     WHERE username = ? AND ts >= NOW() - INTERVAL ? MINUTE
                     ORDER BY ts ASC",
                    [$username, $minutes]
                )->find_array();
            foreach ($stmt as $row) {
                $out['samples'][] = [
                    'ts'        => (int) $row['t'] * 1000,   // ms for JS
                    'rateIn'    => (int) $row['rate_in'],
                    'rateOut'   => (int) $row['rate_out'],
                    'bytesIn'   => (int) $row['bytes_in'],
                    'bytesOut'  => (int) $row['bytes_out'],
                ];
            }

            // Live snapshot from Mikrotik. Each call is wrapped because PEAR2 throws
            // "Unrecognized response type" when a query returns no data (e.g. user offline).
            $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
            if ($rt) {
                try {
                    $client = Mikrotik::getClient($rt['ip_address'], $rt['username'], $rt['password']);

                    try {
                        $req = new RouterOS\Request('/queue/simple/print');
                        $req->setArgument('stats', 'yes');
                        $req->setArgument('.proplist', 'name,bytes,rate');
                        $req->setQuery(RouterOS\Query::where('name', '<pppoe-' . $username . '>'));
                        foreach ($client->sendSync($req) as $r) {
                            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                            $bytes = explode('/', $r->getProperty('bytes') ?: '0/0');
                            $rate  = explode('/', $r->getProperty('rate')  ?: '0/0');
                            $out['live'] = [
                                'ts'       => time() * 1000,
                                'rateIn'   => (int) ($rate[0]  ?? 0),
                                'rateOut'  => (int) ($rate[1]  ?? 0),
                                'bytesIn'  => (int) ($bytes[0] ?? 0),
                                'bytesOut' => (int) ($bytes[1] ?? 0),
                            ];
                        }
                    } catch (Throwable $e) { /* user has no dynamic queue (offline) */ }

                    try {
                        $req = new RouterOS\Request('/ppp/active/print');
                        $req->setQuery(RouterOS\Query::where('name', $username));
                        foreach ($client->sendSync($req) as $r) {
                            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                            if (!$out['live']) $out['live'] = ['ts' => time() * 1000, 'rateIn'=>0,'rateOut'=>0,'bytesIn'=>0,'bytesOut'=>0];
                            $out['live']['address']  = $r->getProperty('address');
                            $out['live']['uptime']   = $r->getProperty('uptime');
                            $out['live']['callerId'] = $r->getProperty('caller-id');
                        }
                    } catch (Throwable $e) { /* user not currently connected */ }
                } catch (Throwable $e) {
                    // Router unreachable — keep DB history, just no live snapshot.
                }
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        echo json_encode($out);
        exit;

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

            // Each Mikrotik call is wrapped — PEAR2 throws "Unrecognized response type"
            // when a query returns no data, which would otherwise fail the whole endpoint.

            // 1. Queue stats map (PPPoE dynamic queues)
            $queueByUser = [];
            try {
                $req = new RouterOS\Request('/queue/simple/print');
                $req->setArgument('stats', 'yes');
                $req->setArgument('.proplist', 'name,bytes,rate');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $qname = $r->getProperty('name');
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
            } catch (Throwable $e) { /* no dynamic queues — leave queueByUser empty */ }

            // 2. Active PPP sessions (IP/uptime/caller-id)
            try {
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
                        'rateIn'   => $q['rateRx'],
                        'rateOut'  => $q['rateTx'],
                    ];
                }
            } catch (Throwable $e) { /* no active PPP sessions */ }

            // 3. Hotspot active (best-effort)
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
            } catch (Throwable $e) { /* no active hotspot sessions */ }
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
