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
        $username    = _post('username');
        // Service-type filter: PPPoE (default), Hotspot, or All. Accepts POST or GET.
        $serviceType = _post('service_type');
        if ($serviceType === '' || $serviceType === null) {
            $serviceType = isset($_GET['service_type']) ? trim((string)$_GET['service_type']) : 'PPPoE';
        }
        // The actual DB value stored in tbl_user_recharges.type is 'PPPoE' or 'Hotspot' (case-sensitive).
        if (!in_array($serviceType, ['PPPoE', 'Hotspot', 'All'])) $serviceType = 'PPPoE';
        // Sort option: 'last_usage' orders by the most recent traffic sample
        // (last time the customer was actually online) first. Empty = default
        // (soonest expiry first). Accepts POST (search form) or GET (pagination).
        $sort = _post('sort');
        if ($sort === '' || $sort === null) {
            $sort = isset($_GET['sort']) ? trim((string)$_GET['sort']) : '';
        }
        if (!in_array($sort, ['last_usage'])) $sort = '';
        run_hook('list_customers'); #HOOK

        // Hotspot customers may have self-registered and exist as a hotspot user
        // on the router without ever buying a plan — so they have no
        // tbl_user_recharges row, and a plain `r.type='Hotspot'` filter hides
        // them (only customers with a Hotspot recharge would show). For the
        // Hotspot view, also include customers with no recharge whose username
        // is a live hotspot user on the router.
        $hsNames = [];
        if ($serviceType === 'Hotspot') {
            try {
                $rtH     = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
                $clientH = $rtH ? Mikrotik::tryClient($rtH['ip_address'], $rtH['username'], $rtH['password']) : null;
                if ($clientH) {
                    $reqH = new RouterOS\Request('/ip/hotspot/user/print');
                    $reqH->setArgument('.proplist', 'name');
                    foreach ($clientH->sendSync($reqH) as $rr) {
                        if ($rr->getType() === RouterOS\Response::TYPE_DATA) {
                            $nm = $rr->getProperty('name');
                            if ($nm !== null && $nm !== '') $hsNames[] = $nm;
                        }
                    }
                }
            } catch (Throwable $e) { error_log('customers/list hotspot names: ' . $e->getMessage()); }
        }

        // Build WHERE — both filters compose on top of the same JOINed view.
        $whereParts = [];
        $params     = [];
        if ($username !== '') {
            $whereParts[] = 'c.username LIKE ?';
            $params[]     = '%' . $username . '%';
        }
        if ($serviceType === 'Hotspot') {
            if (!empty($hsNames)) {
                $ph = implode(',', array_fill(0, count($hsNames), '?'));
                $whereParts[] = "(r.type = 'Hotspot' OR (r.id IS NULL AND c.username IN ($ph)))";
                foreach ($hsNames as $n) $params[] = $n;
            } else {
                $whereParts[] = "r.type = 'Hotspot'";
            }
        } elseif ($serviceType === 'PPPoE') {
            $whereParts[] = 'r.type = ?';
            $params[]     = 'PPPoE';
        }
        $whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

        // Count for paginator (same JOIN shape as the main query)
        $countSql = "
            SELECT COUNT(*) FROM tbl_customers c
            LEFT JOIN (
                SELECT customer_id, MAX(id) AS last_id FROM tbl_user_recharges GROUP BY customer_id
            ) lr ON lr.customer_id = c.id
            LEFT JOIN tbl_user_recharges r ON r.id = lr.last_id
            $whereSql
        ";
        $cstmt = ORM::get_db()->prepare($countSql);
        $cstmt->execute($params);
        $total = (int) $cstmt->fetchColumn();

        $per_page  = 10;
        $page      = isset($routes['2']) && (int)$routes['2'] > 0 ? (int)$routes['2'] : 1;
        $startpoint = ($page - 1) * $per_page;
        $lastpage   = max(1, (int) ceil(max($total, 1) / $per_page));
        $linkBase   = U . $routes[0] . '/' . $routes[1] . '/';
        $contents   = '<ul class="pagination pagination-sm">';
        $stq        = '&service_type=' . urlencode($serviceType);
        if ($sort !== '') $stq .= '&sort=' . urlencode($sort);
        for ($i = 1; $i <= $lastpage; $i++) {
            $contents .= $i == $page
                ? "<li class='active'><a href='javascript:void(0);'>$i</a></li>"
                : "<li><a href='{$linkBase}{$i}{$stq}'>$i</a></li>";
        }
        $contents .= '</ul>';
        $paginator = [
            'startpoint' => $startpoint, 'limit' => $per_page, 'found' => $total,
            'page' => $page, 'lastpage' => $lastpage, 'contents' => $contents,
        ];
        $ui->assign('service_type', $serviceType);
        $ui->assign('sort', $sort);

        // Latest recharge per customer (LEFT JOIN); sort by expiration ASC so
        // customers expiring soonest float to the top, NULLs (no plan) last.
        // Defensive defaults in case paginator returns null (e.g. zero rows).
        $limit  = isset($paginator['limit'])      ? (int) $paginator['limit']      : 10;
        $offset = isset($paginator['startpoint']) ? (int) $paginator['startpoint'] : 0;
        if ($limit < 1) $limit = 10;
        // tbl_traffic_samples.ts is stored in DB time (UTC on this deployment);
        // convert it to the app's configured local offset for display. date('P')
        // yields a safe fixed "+HH:MM" string (not user input), so interpolating
        // it is injection-safe. Ordering still uses the raw ts (relative order is
        // timezone-independent).
        $tzOffset = date('P');
        // Sort clause: by last usage (most recent traffic sample) or by expiry.
        if ($sort === 'last_usage') {
            $orderSql = 'ORDER BY (ts.last_seen IS NULL) ASC, ts.last_seen DESC, c.id DESC';
        } else {
            $orderSql = 'ORDER BY (r.expiration IS NULL) ASC, r.expiration ASC, c.id DESC';
        }
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
                DATEDIFF(r.expiration, CURDATE()) AS days_left,
                CONVERT_TZ(ts.last_seen, '+00:00', '$tzOffset') AS last_seen
            FROM tbl_customers c
            LEFT JOIN (
                SELECT customer_id, MAX(id) AS last_id
                FROM tbl_user_recharges
                GROUP BY customer_id
            ) lr ON lr.customer_id = c.id
            LEFT JOIN tbl_user_recharges r ON r.id = lr.last_id
            LEFT JOIN (
                SELECT username, MAX(ts) AS last_seen
                FROM tbl_traffic_samples
                GROUP BY username
            ) ts ON ts.username = c.username
            $whereSql
            $orderSql
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
            // tryClient() returns null on failure; getClient() would die() and
            // replace the page body with "Unable to connect to the router."
            $client = $rt ? Mikrotik::tryClient($rt['ip_address'], $rt['username'], $rt['password']) : null;
            if ($client) {
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

                // Hotspot active sessions key on the "user" property (not "name").
                // Without this, hotspot users seeded above always stay active=false
                // and therefore always render as "Offline".
                $req = new RouterOS\Request('/ip/hotspot/active/print');
                $req->setArgument('.proplist', 'user,address,uptime');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() === RouterOS\Response::TYPE_DATA) {
                        $name = $r->getProperty('user');
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
                $client = Mikrotik::tryClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                if ($client) {
                    if ($latest['type'] === 'Hotspot') {
                        try { Mikrotik::removeHotspotUser($client, $d['username']); } catch (Throwable $e) {}
                        try { Mikrotik::removeHotspotActiveUser($client, $d['username']); } catch (Throwable $e) {}
                    } else {
                        try { Mikrotik::removePpoeUser($client, $d['username']); } catch (Throwable $e) {}
                        try { Mikrotik::removePpoeActive($client, $d['username']); } catch (Throwable $e) {}
                    }
                } else {
                    $routerWarning = ' (router unreachable, router cleanup skipped)';
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
                $client = Mikrotik::tryClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
                if (!$client) {
                    r2(U . 'customers/list', 'e',
                        'Customer ' . $username . ' created in DB, but router unreachable — re-push from the Billing UI when the router is back.');
                }
                try {
                    $custData = ['username' => $username, 'password' => $password];
                    if ($service === 'PPPoE') {
                        Mikrotik::addPpoeUser($client, $plan, $custData);
                    } else {
                        Mikrotik::addHotspotUser($client, $plan, $custData);
                    }
                } catch (Throwable $e) {
                    // Don't roll back the DB row — the admin can re-push from the Billing UI.
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
        // On status off→on with no admin-supplied date, auto-extend expiration by 30 days
        // (renew). Base the extension on max(today, oldExp) so adding days to an already-
        // future exp doesn't shorten it; suspending then resuming the same day adds 30d.
        if ($plan) {
            $isResume = ($oldStatus === 'off' && $newStatus === 'on');
            if ($newExp === '') {
                if ($isResume) {
                    $base = max(strtotime(date('Y-m-d')), strtotime($oldExp ?: 'today'));
                    $newExp = date('Y-m-d', strtotime('+30 days', $base));
                } else {
                    $newExp = $oldExp ?: date('Y-m-d', strtotime('+30 days'));
                }
            }
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

            // Credit-sale record — admin ticked "Is this a credit sale?" on a renewal
            // (off→on). Money hasn't arrived yet; track it as due so it shows up on
            // the customer's billing page and can be marked paid later.
            if (_post('credit_sale') === '1' && $isResume) {
                $cs = ORM::for_table('tbl_credit_sales')->create();
                $cs->customer_id = $id;
                $cs->username    = $username;
                $cs->plan_name   = $plan['name_plan'];
                $cs->bill_month  = date('Y-m');
                $cs->amount      = $plan['price'];
                $cs->status      = 'due';
                $cs->created_at  = date('Y-m-d H:i:s');
                $cs->save();
            }
        }

        // ---- Push changes to Mikrotik (best-effort, individual try/catch) ----
        $routerWarning = '';
        if ($pushToRouter && $mikrotik && empty($config['radius_mode']) && $service === 'PPPoE') {
            $client = Mikrotik::tryClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
            if (!$client) {
                $routerWarning = ' (router unreachable, edits not pushed)';
            } else try {
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
                    // Status changed — use the "Suspended" PPP profile when status=off.
                    // Suspended profile auto-adds the active client IP to address-list
                    // "expired-users"; a firewall NAT rule then redirects their HTTP
                    // traffic to /notice/<username>. They stay connected (at 256 kbps)
                    // so they actually SEE the notice instead of just failing to dial.
                    if ($oldStatus !== $newStatus) {
                        try {
                            if ($newStatus === 'on') {
                                // Re-enable the secret (in case previously disabled by the
                                // old cron) and put them back on their real plan profile.
                                try { Mikrotik::enablePpoeUser($client, $username); } catch (Throwable $e) {}
                                if ($plan) {
                                    try { Mikrotik::setPpoeUserProfile($client, $username, $plan['name_plan']); } catch (Throwable $e) {}
                                }
                                try { Mikrotik::removePpoeActive($client, $username); } catch (Throwable $e) {}
                            } else {
                                try { Mikrotik::setPpoeUserProfile($client, $username, 'Suspended'); } catch (Throwable $e) {}
                                try { Mikrotik::removePpoeActive($client, $username); } catch (Throwable $e) {}
                            }
                        } catch (Throwable $e) { $routerWarning .= ' (status push: ' . $e->getMessage() . ')'; }
                    }
                }
            } catch (Throwable $e) {
                $routerWarning = ' (router connection failed: ' . $e->getMessage() . ')';
            }
        }

        // ---- Notify customer via SMS on status flip (opt-in per submission) ----
        // The "Send SMS to customer" checkbox in the form is unchecked by default, so
        // routine status flips (e.g. auto-corrections, internal updates) don't spam
        // the customer. Admin must tick it explicitly to send.
        $sendSms = _post('send_sms') === '1';
        $smsNote = '';
        if ($sendSms && $oldStatus !== $newStatus && !empty($phonenumber)) {
            $smsVars = [
                'company'    => isset($config['CompanyName']) ? $config['CompanyName'] : 'NetPulse',
                'fullname'   => $fullname,
                'username'   => $username,
                'plan'       => $plan ? $plan['name_plan'] : ($r ? $r['namebp'] : ''),
                'price'      => $plan ? $plan['price'] : '',
                'expiration' => $r ? $r['expiration'] : ($newExp ?: ''),
            ];
            $tplKey = ($newStatus === 'off') ? 'sms_template_expiry' : 'sms_template_recharge';
            $res = SmsSender::sendTemplate($phonenumber, $tplKey, $smsVars);
            $smsNote = $res['ok']
                ? ' (' . ($newStatus === 'off' ? 'suspend' : 'resume') . ' SMS sent)'
                : ' (' . ($newStatus === 'off' ? 'suspend' : 'resume') . ' SMS failed: ' . $res['error'] . ')';
        }

        _log("$oldUsername edited by " . ($admin['username'] ?? '?') .
            ($oldUsername !== $username ? " → $username" : '') .
            ($plan ? "; plan=$oldPlanName→{$plan['name_plan']}" : '') .
            "; status=$oldStatus→$newStatus; exp=$oldExp→$newExp", 'User', $id);

        r2(U . 'customers/list', 's', 'Customer updated' . $routerWarning . $smsNote);
        break;

    case 'diagnose':
    case 'diag':
        $id = (int) $routes['2'];
        $c  = ORM::for_table('tbl_customers')->find_one($id);
        if (!$c) { r2(U . 'customers/list', 'e', $_L['Account_Not_Found']); }
        $r = ORM::for_table('tbl_user_recharges')
                ->where('customer_id', $id)->order_by_desc('id')->find_one();

        $checks = []; $active = null; $secret = null; $queue = null; $logs = [];

        // 1) Subscription state from DB
        if (!$r) {
            $checks[] = ['name'=>'Subscription','status'=>'warn',
                'msg'=>'No recharge / plan',
                'detail'=>'Customer has never been put on a plan. Use Edit to assign one.'];
        } elseif ($r['status'] === 'off') {
            $checks[] = ['name'=>'Subscription','status'=>'bad',
                'msg'=>'Suspended (status = off)',
                'detail'=>'Plan ' . $r['namebp'] . ', expired ' . $r['expiration'] . '. Customer needs to recharge.'];
        } elseif (strtotime($r['expiration']) < strtotime(date('Y-m-d'))) {
            $checks[] = ['name'=>'Subscription','status'=>'bad',
                'msg'=>'Expired on ' . $r['expiration'],
                'detail'=>'Recharge ' . $r['namebp'] . ' to reactivate. Customer is being redirected to the notice page.'];
        } else {
            $daysLeft = (int) ((strtotime($r['expiration']) - strtotime(date('Y-m-d'))) / 86400);
            $cls = $daysLeft <= 3 ? 'warn' : 'ok';
            $checks[] = ['name'=>'Subscription','status'=>$cls,
                'msg'=>'Active on ' . $r['namebp'] . ' — expires ' . $r['expiration'] . " ($daysLeft day(s) left)"];
        }

        try {
            $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
            if (!$rt) { throw new Exception('No router configured'); }
            $client = Mikrotik::tryClient($rt['ip_address'], $rt['username'], $rt['password']);
            if (!$client) { throw new Exception('Router unreachable (' . $rt['ip_address'] . ')'); }

            // 2) PPP secret
            try {
                $req = new RouterOS\Request('/ppp/secret/print');
                $req->setArgument('.proplist', '.id,name,disabled,profile,last-logged-out,last-caller-id');
                $req->setQuery(RouterOS\Query::where('name', $c['username']));
                foreach ($client->sendSync($req) as $rr) {
                    if ($rr->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $secret = [
                        'disabled'      => $rr->getProperty('disabled') === 'true',
                        'profile'       => $rr->getProperty('profile'),
                        'lastLoggedOut' => $rr->getProperty('last-logged-out'),
                        'lastCallerId'  => $rr->getProperty('last-caller-id'),
                    ];
                }
            } catch (Throwable $e) {}
            if (!$secret) {
                $checks[] = ['name'=>'Router secret','status'=>'bad',
                    'msg'=>'No /ppp/secret for "' . $c['username'] . '"',
                    'detail'=>'Customer cannot authenticate at all. Use Edit and push to router.'];
            } elseif ($secret['disabled']) {
                $checks[] = ['name'=>'Router secret','status'=>'bad',
                    'msg'=>'PPP secret is DISABLED on the router',
                    'detail'=>'Re-enable via Edit, set Status = Active, push to router.'];
            } elseif ($secret['profile'] === 'Suspended') {
                $checks[] = ['name'=>'Router secret','status'=>'warn',
                    'msg'=>'On the Suspended profile (256 kbps + notice redirect)',
                    'detail'=>'After recharge, set Status = Active. The plan profile is restored automatically.'];
            } else {
                $checks[] = ['name'=>'Router secret','status'=>'ok',
                    'msg'=>'Enabled on profile "' . $secret['profile'] . '"'];
            }

            // 3) Active session
            try {
                $req = new RouterOS\Request('/ppp/active/print');
                $req->setArgument('.proplist', 'name,address,uptime,caller-id');
                $req->setQuery(RouterOS\Query::where('name', $c['username']));
                foreach ($client->sendSync($req) as $rr) {
                    if ($rr->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $active = [
                        'address'  => $rr->getProperty('address'),
                        'uptime'   => $rr->getProperty('uptime'),
                        'callerId' => $rr->getProperty('caller-id'),
                    ];
                }
            } catch (Throwable $e) {}
            if ($active) {
                $checks[] = ['name'=>'Connection','status'=>'ok',
                    'msg'=>'ONLINE from ' . $active['address'] . ', uptime ' . $active['uptime'],
                    'detail'=>'MAC: ' . $active['callerId']];
            } else {
                $detail = '';
                if ($secret) {
                    if ($secret['lastLoggedOut']) $detail .= 'Last logged out: ' . $secret['lastLoggedOut'];
                    if ($secret['lastCallerId'])  $detail .= ($detail ? ', ' : '') . 'last MAC: ' . $secret['lastCallerId'];
                }
                $checks[] = ['name'=>'Connection','status'=>'warn',
                    'msg'=>'Currently OFFLINE',
                    'detail'=>$detail ?: 'No connection history. Customer router/ONU may be powered off, mis-configured, or never connected.'];
            }

            // 4) Current rate
            try {
                $req = new RouterOS\Request('/queue/simple/print');
                $req->setArgument('stats', 'yes');
                $req->setArgument('.proplist', 'name,bytes,rate');
                $req->setQuery(RouterOS\Query::where('name', '<pppoe-' . $c['username'] . '>'));
                foreach ($client->sendSync($req) as $rr) {
                    if ($rr->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $bytes = explode('/', $rr->getProperty('bytes') ?: '0/0');
                    $rate  = explode('/', $rr->getProperty('rate')  ?: '0/0');
                    $queue = [
                        'rateIn'   => (int)($rate[0]  ?? 0),
                        'rateOut'  => (int)($rate[1]  ?? 0),
                        'bytesIn'  => (int)($bytes[0] ?? 0),
                        'bytesOut' => (int)($bytes[1] ?? 0),
                    ];
                }
            } catch (Throwable $e) {}

            // 5) Recent logs mentioning this username
            try {
                $req = new RouterOS\Request('/log/print');
                $req->setArgument('.proplist', 'time,topics,message');
                $count = 0;
                foreach ($client->sendSync($req) as $rr) {
                    if ($rr->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $m = (string)$rr->getProperty('message');
                    if (stripos($m, $c['username']) === false) continue;
                    $logs[] = ['time'=>$rr->getProperty('time'),'topics'=>$rr->getProperty('topics'),'message'=>$m];
                    if (++$count >= 30) break;
                }
            } catch (Throwable $e) {}

            // 6) Pattern detection
            $authFails = 0; $disconnects = 0;
            foreach ($logs as $l) {
                $m = $l['message'];
                if (preg_match('/auth(entication)? failed|wrong password|invalid user/i', $m)) $authFails++;
                if (preg_match('/disconnect(ed)?|terminat|timeout|hangup|lcp.*down/i', $m)) $disconnects++;
            }
            if ($authFails > 0) {
                $checks[] = ['name'=>'Authentication','status'=>'warn',
                    'msg'=>"$authFails recent auth failure(s)",
                    'detail'=>"Customer's router probably has the wrong PPP password. Open Edit → Show password to share/reset."];
            }
            if ($disconnects > 3) {
                $checks[] = ['name'=>'Stability','status'=>'warn',
                    'msg'=>"$disconnects recent disconnects",
                    'detail'=>'Frequent disconnects usually point to physical-layer issues (optical signal, cable, ONU power). See checklist below.'];
            }

            // 7) Recent interface errors from tbl_traffic_samples (last hour delta).
            //    Growing rx-error / rx-drop count on the <pppoe-USER> interface is
            //    a strong hint of optical-layer issues.
            try {
                $stmt = ORM::for_table('tbl_traffic_samples')->raw_query(
                    "SELECT MAX(rx_error)-MIN(rx_error) AS d_rx_err,
                            MAX(tx_error)-MIN(tx_error) AS d_tx_err,
                            MAX(rx_drop) -MIN(rx_drop)  AS d_rx_drop,
                            MAX(tx_drop) -MIN(tx_drop)  AS d_tx_drop,
                            COUNT(*) AS n
                       FROM tbl_traffic_samples
                      WHERE username = ? AND ts >= NOW() - INTERVAL 60 MINUTE",
                    [$c['username']]
                )->find_array();
                if (!empty($stmt) && (int)$stmt[0]['n'] > 1) {
                    $rxErr = (int)$stmt[0]['d_rx_err'];
                    $txErr = (int)$stmt[0]['d_tx_err'];
                    $rxDrp = (int)$stmt[0]['d_rx_drop'];
                    $txDrp = (int)$stmt[0]['d_tx_drop'];
                    if ($rxErr + $txErr + $rxDrp + $txDrp > 0) {
                        $checks[] = ['name'=>'Interface errors (1h)','status'=>'warn',
                            'msg'=>"rx-err=$rxErr  tx-err=$txErr  rx-drop=$rxDrp  tx-drop=$txDrp",
                            'detail'=>'Errors / drops on the PPP interface in the last hour. Likely optical / fiber / ONU issue. Check the physical checklist below.'];
                    } else {
                        $checks[] = ['name'=>'Interface errors (1h)','status'=>'ok',
                            'msg'=>'No interface errors / drops'];
                    }
                }
            } catch (Throwable $e) { /* tbl_traffic_samples missing columns on first deploy */ }
        } catch (Throwable $e) {
            $checks[] = ['name'=>'Router','status'=>'bad','msg'=>'Cannot reach Mikrotik','detail'=>$e->getMessage()];
        }

        $ui->assign('c', $c);
        $ui->assign('r', $r);
        $ui->assign('checks', $checks);
        $ui->assign('active', $active);
        $ui->assign('secret', $secret);
        $ui->assign('queue', $queue);
        $ui->assign('logs', $logs);
        $ui->display('customers-diagnose.tpl');
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

            // Determine service type so the live snapshot queries the right
            // source (hotspot users have no <pppoe-USER> interface/queue).
            $rtype = ORM::for_table('tbl_user_recharges')
                        ->where('username', $username)->order_by_desc('id')->find_one();
            $isHotspot = $rtype && $rtype['type'] === 'Hotspot';

            // Live snapshot from Mikrotik using interface/monitor-traffic for accurate rates.
            $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
            if ($rt) {
                $client = Mikrotik::tryClient($rt['ip_address'], $rt['username'], $rt['password']);
                if ($client) try {
                    if ($isHotspot) {
                        // Hotspot: no per-user interface/queue — read the live session.
                        $req = new RouterOS\Request('/ip/hotspot/active/print');
                        $req->setQuery(RouterOS\Query::where('user', $username));
                        $req->setArgument('.proplist', 'user,address,uptime,bytes-in,bytes-out');
                        foreach ($client->sendSync($req) as $r) {
                            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                            $out['live'] = [
                                'ts'       => time() * 1000,
                                'rateIn'   => 0,
                                'rateOut'  => 0,
                                'bytesIn'  => (int) $r->getProperty('bytes-in'),
                                'bytesOut' => (int) $r->getProperty('bytes-out'),
                                'address'  => $r->getProperty('address'),
                                'uptime'   => $r->getProperty('uptime'),
                            ];
                        }
                    } else {
                    // Get cumulative bytes from queue
                    try {
                        $req = new RouterOS\Request('/queue/simple/print');
                        $req->setArgument('stats', 'yes');
                        $req->setArgument('.proplist', 'name,bytes,rate');
                        $req->setQuery(RouterOS\Query::where('name', '<pppoe-' . $username . '>'));
                        foreach ($client->sendSync($req) as $r) {
                            if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                            $bytes = explode('/', $r->getProperty('bytes') ?: '0/0');
                            // Instantaneous queue rate (bits/sec) — matches Winbox.
                            // The byte-delta below is only a ~60s average; using the
                            // queue rate makes "Current ↓/↑" reflect real speed.
                            $rate  = explode('/', $r->getProperty('rate') ?: '0/0');
                            $out['live'] = [
                                'ts'       => time() * 1000,
                                'rateIn'   => (int) ($rate[0] ?? 0),
                                'rateOut'  => (int) ($rate[1] ?? 0),
                                'bytesIn'  => (int) ($bytes[0] ?? 0),
                                'bytesOut' => (int) ($bytes[1] ?? 0),
                            ];
                        }
                    } catch (Throwable $e) { /* user has no dynamic queue (offline) */ }

                    // Derive current rate from the byte delta vs the most recent
                    // stored sample. /interface/monitor-traffic reads 0 for normal
                    // traffic on this RouterOS, so it cannot drive the rate. Live
                    // bytes here come from the queue (cumulative), matching the
                    // stored samples, so the delta is valid.
                    if ($out['live']) {
                        try {
                            $prev = ORM::for_table('tbl_traffic_samples')->raw_query(
                                "SELECT bytes_in, bytes_out, UNIX_TIMESTAMP(ts) AS t
                                 FROM tbl_traffic_samples WHERE username = ?
                                 ORDER BY id DESC LIMIT 1", [$username]
                            )->find_one();
                            if ($prev) {
                                $dt = time() - (int) $prev['t'];
                                if ($dt > 0) {
                                    $dIn  = (int) $out['live']['bytesIn']  - (int) $prev['bytes_in'];
                                    $dOut = (int) $out['live']['bytesOut'] - (int) $prev['bytes_out'];
                                    // Prefer the higher of the instantaneous queue
                                    // rate (set above) and the ~60s byte-delta average.
                                    if ($dIn  > 0) $out['live']['rateIn']  = max((int) $out['live']['rateIn'],  (int) ($dIn  * 8 / $dt));
                                    if ($dOut > 0) $out['live']['rateOut'] = max((int) $out['live']['rateOut'], (int) ($dOut * 8 / $dt));
                                }
                            }
                        } catch (Throwable $e) { /* no prior sample to delta against */ }
                    }

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
                    } // end else (PPPoE live snapshot)
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
        // Uses /interface/monitor-traffic for accurate real-time rates (matches Winbox).
        // Queue rate property gives averaged/delayed values that don't match Winbox.
        header('Content-Type: application/json');
        $out = ['ts' => time(), 'sessions' => [], 'error' => null];
        try {
            $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
            if (!$rt) { throw new Exception('no enabled router'); }
            $client = Mikrotik::tryClient($rt['ip_address'], $rt['username'], $rt['password']);
            if (!$client) { throw new Exception('router unreachable (' . $rt['ip_address'] . ')'); }

            $custMap = [];
            foreach (ORM::for_table('tbl_customers')->find_many() as $c) {
                $custMap[$c['username']] = $c['fullname'];
            }

            // 1. Cumulative session bytes from the PPPoE interface itself.
            //    We used to read /queue/simple bytes here, but that counter resets
            //    whenever the dynamic queue is touched (profile change, brief PPP
            //    flap, any external /queue write), so the totals were not really
            //    session-cumulative. /interface stats rx-byte/tx-byte is set when
            //    the dynamic <pppoe-USER> interface is created at session start
            //    and isn't touched again until disconnect.
            $bytesByUser = [];
            try {
                $req = new RouterOS\Request('/interface/print');
                $req->setArgument('stats', 'yes');
                $req->setArgument('.proplist', 'name,rx-byte,tx-byte');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $ifName = (string) $r->getProperty('name');
                    if (!preg_match('/^<pppoe-(.+)>$/', $ifName, $m)) continue;
                    $bytesByUser[$m[1]] = [
                        'bytesRx' => (int) $r->getProperty('rx-byte'),
                        'bytesTx' => (int) $r->getProperty('tx-byte'),
                    ];
                }
            } catch (Throwable $e) { /* no pppoe interfaces visible */ }

            // 2. Active PPP sessions (IP/uptime/caller-id)
            $sessions = [];
            try {
                $req = new RouterOS\Request('/ppp/active/print');
                $req->setArgument('.proplist', 'name,service,address,uptime,caller-id');
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $sessions[] = [
                        'name'     => $r->getProperty('name'),
                        'service'  => $r->getProperty('service'),
                        'address'  => $r->getProperty('address'),
                        'uptime'   => $r->getProperty('uptime'),
                        'callerId' => $r->getProperty('caller-id'),
                    ];
                }
            } catch (Throwable $e) { /* no active PPP sessions */ }

            // 3. Get real-time interface rates using monitor-traffic (matches Winbox)
            $ifaceRates = [];
            if (!empty($sessions)) {
                // Build comma-separated interface list for bulk monitor
                $ifaceNames = array_map(function($s) { return '<pppoe-' . $s['name'] . '>'; }, $sessions);
                try {
                    $req = new RouterOS\Request('/interface/monitor-traffic');
                    $req->setArgument('interface', implode(',', $ifaceNames));
                    $req->setArgument('once', '');
                    foreach ($client->sendSync($req) as $r) {
                        if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
                        $ifName = $r->getProperty('name');
                        if (preg_match('/^<pppoe-(.+)>$/', $ifName, $m)) {
                            $user = $m[1];
                            // rx = upload from user, tx = download to user
                            $ifaceRates[$user] = [
                                'rateRx' => (int) ($r->getProperty('rx-bits-per-second') ?? 0),
                                'rateTx' => (int) ($r->getProperty('tx-bits-per-second') ?? 0),
                            ];
                        }
                    }
                } catch (Throwable $e) { /* interface monitor failed */ }
            }

            // 4. Combine session data with bytes and rates
            foreach ($sessions as $s) {
                $name = $s['name'];
                $q = $bytesByUser[$name] ?? ['bytesRx'=>0,'bytesTx'=>0];
                $r = $ifaceRates[$name] ?? ['rateRx'=>0,'rateTx'=>0];
                $out['sessions'][] = [
                    'username' => $name,
                    'fullname' => $custMap[$name] ?? '',
                    'service'  => $s['service'],
                    'address'  => $s['address'],
                    'uptime'   => $s['uptime'],
                    'callerId' => $s['callerId'],
                    'bytesIn'  => $q['bytesRx'],
                    'bytesOut' => $q['bytesTx'],
                    'rateIn'   => $r['rateRx'],
                    'rateOut'  => $r['rateTx'],
                ];
            }

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

        // Credit sales for this customer (renewals where money is pending).
        $creditSales = ORM::for_table('tbl_credit_sales')
            ->where('customer_id', $id)
            ->order_by_desc('created_at')
            ->find_many();

        $ui->assign('c', $c);
        $ui->assign('r', $r);
        $ui->assign('plans', $plans);
        $ui->assign('service_type', $serviceType);
        $ui->assign('creditSales', $creditSales);
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

        // Validate expiration. Allow blank: on off→on (resume), auto-extend by 30 days.
        if ($newExpiration === '' && $r && $r['status'] === 'off' && $newStatus === 'on') {
            $base = max(strtotime(date('Y-m-d')), strtotime($r['expiration'] ?: 'today'));
            $newExpiration = date('Y-m-d', strtotime('+30 days', $base));
        }
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

        // Credit sale on a renewal (off→on with checkbox ticked).
        if (_post('credit_sale') === '1' && $oldStatus === 'off' && $newStatus === 'on') {
            $cs = ORM::for_table('tbl_credit_sales')->create();
            $cs->customer_id = $id;
            $cs->username    = $c['username'];
            $cs->plan_name   = $newPlan['name_plan'];
            $cs->bill_month  = date('Y-m');
            $cs->amount      = $newPlan['price'];
            $cs->status      = 'due';
            $cs->created_at  = date('Y-m-d H:i:s');
            $cs->save();
        }

        // Sync the router — only for PPPoE in this iteration; Hotspot would be similar
        if (!$config['radius_mode'] && $serviceType === 'PPPoE') {
            $client = Mikrotik::tryClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
            if (!$client) {
                r2(U . 'customers/billing/' . $id, 'e', 'DB updated, but router unreachable — router sync skipped.');
            }
            try {
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

        // SMS on status flip — same opt-in pattern as edit-post (checkbox in form).
        $sendSms = _post('send_sms') === '1';
        $smsNote = '';
        if ($sendSms && $oldStatus !== $newStatus && !empty($c['phonenumber'])) {
            $smsVars = [
                'company'    => isset($config['CompanyName']) ? $config['CompanyName'] : 'NetPulse',
                'fullname'   => $c['fullname'],
                'username'   => $c['username'],
                'plan'       => $newPlan['name_plan'],
                'price'      => $newPlan['price'],
                'expiration' => $newExpiration,
            ];
            $tplKey = ($newStatus === 'off') ? 'sms_template_expiry' : 'sms_template_recharge';
            $res = SmsSender::sendTemplate($c['phonenumber'], $tplKey, $smsVars);
            $smsNote = $res['ok']
                ? ' (' . ($newStatus === 'off' ? 'suspend' : 'resume') . ' SMS sent)'
                : ' (' . ($newStatus === 'off' ? 'suspend' : 'resume') . ' SMS failed: ' . $res['error'] . ')';
        }

        // Audit-ish log row
        _log($c['username'] . ' billing updated by ' . $admin['username']
            . ' (plan: ' . $oldPlanName . ' → ' . $newPlan['name_plan']
            . ', exp: ' . $oldExp . ' → ' . $newExpiration
            . ', status: ' . $oldStatus . ' → ' . $newStatus . ')', 'User', $id);

        r2(U . 'customers/list', 's', 'Billing updated for ' . $c['username'] . $smsNote);
        break;

    case 'credits':
        // Cross-customer view of all credit sales (lifetime). Filterable by status
        // via ?status=due|paid|all. Default = due (most useful — admin wants to chase).
        $statusFilter = $_GET['status'] ?? 'due';
        if (!in_array($statusFilter, ['due','paid','all'], true)) $statusFilter = 'due';

        $sql = "
            SELECT cs.id, cs.customer_id, cs.username, cs.plan_name, cs.bill_month,
                   cs.amount, cs.status, cs.created_at, cs.paid_at, cs.notes,
                   c.fullname, c.phonenumber
            FROM tbl_credit_sales cs
            LEFT JOIN tbl_customers c ON c.id = cs.customer_id
        ";
        $params = [];
        if ($statusFilter !== 'all') {
            $sql .= " WHERE cs.status = ?";
            $params[] = $statusFilter;
        }
        $sql .= " ORDER BY cs.status='due' DESC, cs.created_at DESC LIMIT 500";
        $rows = ORM::for_table('tbl_credit_sales')->raw_query($sql, $params)->find_array();

        // Totals across the current filter view.
        $totalDue  = ORM::for_table('tbl_credit_sales')->where('status','due')
                        ->select_expr('COALESCE(SUM(amount),0)','t')->find_one();
        $totalPaid = ORM::for_table('tbl_credit_sales')->where('status','paid')
                        ->select_expr('COALESCE(SUM(amount),0)','t')->find_one();

        $ui->assign('rows', $rows);
        $ui->assign('statusFilter', $statusFilter);
        $ui->assign('totalDue',  $totalDue ? (float) $totalDue['t']  : 0);
        $ui->assign('totalPaid', $totalPaid ? (float) $totalPaid['t'] : 0);
        $ui->display('customers-credits.tpl');
        break;

    case 'credit-paid':
        // Mark a credit-sale row as paid. Routes: customers/credit-paid/{credit_id}
        $creditId = (int) ($routes['2'] ?? 0);
        $cs = ORM::for_table('tbl_credit_sales')->find_one($creditId);
        if (!$cs) {
            r2(U . 'customers/list', 'e', 'Credit sale not found');
        }
        $custId = (int) $cs['customer_id'];
        if ($cs['status'] !== 'paid') {
            $cs->status  = 'paid';
            $cs->paid_at = date('Y-m-d H:i:s');
            $cs->save();
            _log('Credit #' . $creditId . ' (' . $cs['username'] . ', '
                . $cs['plan_name'] . ', ' . $cs['amount'] . ' BDT) marked paid by '
                . ($admin['username'] ?? '?'), 'User', $custId);
        }
        r2(U . 'customers/billing/' . $custId, 's', 'Credit marked as paid');
        break;

    case 'credit-edit':
        // Edit a credit-sale amount. POST: credit_id, amount
        $creditId = (int) _post('credit_id');
        $newAmount = (float) _post('amount');
        $cs = ORM::for_table('tbl_credit_sales')->find_one($creditId);
        if (!$cs) {
            r2(U . 'customers/credits', 'e', 'Credit sale not found');
        }
        if ($cs['status'] === 'paid') {
            r2(U . 'customers/credits', 'e', 'Cannot edit a paid credit sale');
        }
        $oldAmount = $cs['amount'];
        $cs->amount = $newAmount;
        $cs->save();
        _log('Credit #' . $creditId . ' (' . $cs['username'] . ') amount changed from '
            . $oldAmount . ' to ' . $newAmount . ' BDT by '
            . ($admin['username'] ?? '?'), 'User', (int) $cs['customer_id']);
        r2(U . 'customers/credits', 's', 'Credit amount updated to ' . number_format($newAmount, 0) . ' BDT');
        break;

    case 'credit-delete':
        // Delete a settled credit-sale row. Routes: customers/credit-delete/{credit_id}
        // Used to clear out paid entries that no longer need tracking.
        $creditId = (int) ($routes['2'] ?? 0);
        $cs = ORM::for_table('tbl_credit_sales')->find_one($creditId);
        if (!$cs) {
            r2(U . 'customers/credits&status=paid', 'e', 'Credit sale not found');
        }
        $info   = $cs['username'] . ', ' . $cs['plan_name'] . ', ' . $cs['amount'] . ' BDT';
        $custId = (int) $cs['customer_id'];
        $cs->delete();
        _log('Credit #' . $creditId . ' (' . $info . ') deleted by '
            . ($admin['username'] ?? '?'), 'User', $custId);
        r2(U . 'customers/credits&status=paid', 's', 'Credit sale deleted');
        break;

    case 'browsing':
    case 'browsing-history':
        // Per-customer DNS query history from the dnsmasq-resolver log file
        // (/var/log/dnsmasq/queries.log + .1 + .2.gz ...), bind-mounted
        // read-only into this container. Strict input validation, NO
        // shell_exec, NO docker socket access.
        $id = (int) $routes['2'];
        $c  = ORM::for_table('tbl_customers')->find_one($id);
        if (!$c) { r2(U . 'customers/list', 'e', $_L['Account_Not_Found']); }

        $hours = isset($_GET['hours']) ? max(1, min(168, (int) $_GET['hours'])) : 24;

        // Optional manual IP override (when customer is offline and operator
        // knows their last-known address).
        $manualIp = '';
        if (!empty($_GET['ip'])) {
            $rawIp = trim((string) $_GET['ip']);
            if (filter_var($rawIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $manualIp = $rawIp;
            }
        }

        // Optional substring filter applied to qname.
        $filter = isset($_GET['filter']) ? trim((string) $_GET['filter']) : '';
        if (strlen($filter) > 100) $filter = substr($filter, 0, 100);

        // Resolve current customer IP from Mikrotik /ppp/active unless
        // the operator passed an explicit IP.
        $clientIp   = $manualIp;
        $ipSource   = $manualIp !== '' ? 'manual' : 'mikrotik';
        $resolveErr = null;
        if ($clientIp === '') {
            try {
                $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
                if (!$rt) { throw new Exception('No router configured'); }
                $client = Mikrotik::tryClient($rt['ip_address'], $rt['username'], $rt['password']);
                if (!$client) { throw new Exception('Router unreachable (' . $rt['ip_address'] . ')'); }
                $req = new RouterOS\Request('/ppp/active/print');
                $req->setArgument('.proplist', 'name,address');
                $req->setQuery(RouterOS\Query::where('name', $c['username']));
                foreach ($client->sendSync($req) as $rr) {
                    if ($rr->getType() !== RouterOS\Response::TYPE_DATA) continue;
                    $clientIp = (string) $rr->getProperty('address');
                    break;
                }
                if ($clientIp === '') {
                    $resolveErr = 'Customer is currently offline (no /ppp/active session). '
                                . 'Pass a last-known IP using the IP override below.';
                }
            } catch (Throwable $e) {
                $resolveErr = $e->getMessage();
            }
        }

        $logDir       = '/var/log/dnsmasq';
        $logBase      = $logDir . '/queries.log';
        $rows         = [];
        $domains      = [];
        $totalScanned = 0;
        $readErr      = null;
        $sinceEpoch   = time() - ($hours * 3600);
        $maxRows      = 5000;
        $currentYear  = (int) date('Y');

        if ($clientIp === '') {
            // Already surfaced via $resolveErr; render the template with empty rows.
        } elseif (!is_dir($logDir)) {
            $readErr = 'DNS log directory not mounted at ' . $logDir . '. Confirm '
                     . 'dnsmasq-resolver is running on the VPS and that '
                     . './dnsmasq-resolver/logs is bind-mounted into phpnuxbill-app.';
        } else {
            // Collect candidate files: current + numbered rotations + .gz
            $files = [];
            if (is_file($logBase)) $files[] = $logBase;
            for ($i = 1; $i <= 7; $i++) {
                if (is_file($logBase . '.' . $i))         $files[] = $logBase . '.' . $i;
                if (is_file($logBase . '.' . $i . '.gz')) $files[] = $logBase . '.' . $i . '.gz';
            }

            // dnsmasq line shape:
            //   May 18 14:30:01 dnsmasq[42]: 1234 172.16.16.243/55432 query[A] youtube.com from 10.99.0.1
            $needleIp = $clientIp . '/';

            foreach ($files as $f) {
                // mtime fast-skip — if entire file is older than the window
                // (plus a day's grace), don't open it.
                $mtime = @filemtime($f);
                if ($mtime !== false && $mtime < ($sinceEpoch - 86400)) continue;

                $isGz = substr($f, -3) === '.gz';
                $fh   = $isGz ? @gzopen($f, 'r') : @fopen($f, 'r');
                if (!$fh) continue;

                while (true) {
                    $line = $isGz ? gzgets($fh) : fgets($fh);
                    if ($line === false) break;
                    $totalScanned++;

                    // Fast substring filters before regex
                    if (strpos($line, $needleIp) === false) continue;
                    if (strpos($line, 'query[')   === false) continue;

                    // Timestamp parse — syslog format has no year, assume current
                    if (!preg_match('/^([A-Z][a-z]{2}\s+\d+\s+\d{2}:\d{2}:\d{2})/', $line, $tm)) continue;
                    $ts = strtotime($tm[1] . ' ' . $currentYear);
                    if ($ts === false) continue;
                    // Year-rollover guard: future-dated → previous year
                    if ($ts > time() + 86400) $ts = strtotime($tm[1] . ' ' . ($currentYear - 1));
                    if ($ts < $sinceEpoch) continue;

                    // Extract qtype + qname
                    if (!preg_match('/query\[([A-Z]+)\]\s+(\S+)/', $line, $qm)) continue;
                    $qtype = $qm[1];
                    $qname = strtolower($qm[2]);

                    if ($filter !== '' && stripos($qname, $filter) === false) continue;

                    $rows[] = ['ts' => $ts, 'qtype' => $qtype, 'qname' => $qname];

                    if (!isset($domains[$qname])) $domains[$qname] = 0;
                    $domains[$qname]++;

                    if (count($rows) >= $maxRows) break 2;
                }
                $isGz ? gzclose($fh) : fclose($fh);
            }
        }

        // Newest first; top-20 unique domains for the summary chips
        usort($rows, function($a, $b) { return $b['ts'] - $a['ts']; });
        arsort($domains);
        $topDomains = array_slice($domains, 0, 20, true);

        $ui->assign('c',            $c);
        $ui->assign('hours',        $hours);
        $ui->assign('clientIp',     $clientIp);
        $ui->assign('manualIp',     $manualIp);
        $ui->assign('ipSource',     $ipSource);
        $ui->assign('resolveErr',   $resolveErr);
        $ui->assign('readErr',      $readErr);
        $ui->assign('filter',       $filter);
        $ui->assign('rows',         $rows);
        $ui->assign('topDomains',   $topDomains);
        $ui->assign('totalScanned', $totalScanned);
        $ui->assign('maxRows',      $maxRows);
        $ui->display('customers-browsing.tpl');
        break;

    default:
    r2(U . 'customers/list', 'e', 'action not defined');
}
