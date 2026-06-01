<?php

use PEAR2\Net\RouterOS;

class Mikrotik
{
    /**
     * Resolve a router selector to connection details.
     *
     * The selector may be:
     *   - A router name: "Main PPPoE Router"            -> primary endpoint
     *   - Suffixed:      "Main PPPoE Router::secondary" -> secondary endpoint
     *   - A numeric id:  "1"                            -> primary endpoint (legacy)
     *
     * Returns an associative array with ip_address/username/password/enabled
     * already swapped to the chosen endpoint, plus _role ('primary'|'secondary')
     * for callers that need to know which endpoint they got. Returns null if
     * the router doesn't exist.
     */
    public static function info($selector){
        if ($selector === null || $selector === '') return null;
        $role = 'primary';
        $name = (string) $selector;
        if (strpos($name, '::') !== false) {
            list($name, $role) = explode('::', $name, 2);
        }
        $d = ORM::for_table('tbl_routers')->where('name', $name)->find_one();
        if (!$d && is_numeric($name)) {
            $d = ORM::for_table('tbl_routers')->find_one((int) $name);
        }
        if (!$d) return null;
        $row = $d->as_array();
        if ($role === 'secondary') {
            $row['ip_address'] = $row['secondary_ip_address'];
            $row['username']   = $row['secondary_username'];
            $row['password']   = $row['secondary_password'];
            $row['enabled']    = $row['secondary_enabled'];
        }
        $row['_role'] = $role;
        return $row;
    }

    /**
     * Flattened router options for <select> dropdowns. Each enabled record
     * yields one option for primary plus one for secondary if configured.
     * Returns: [ ['value' => 'Name' | 'Name::secondary', 'label' => 'Name' | 'Name (Secondary)', 'enabled' => bool], ... ]
     */
    public static function dropdownOptions(){
        $opts = [];
        $rows = ORM::for_table('tbl_routers')->find_many();
        foreach ($rows as $r) {
            if ($r['enabled']) {
                $opts[] = ['value' => $r['name'], 'label' => $r['name'], 'enabled' => true];
            }
            if (!empty($r['secondary_ip_address']) && $r['secondary_enabled']) {
                $opts[] = [
                    'value'   => $r['name'] . '::secondary',
                    'label'   => $r['name'] . ' (Secondary)',
                    'enabled' => true,
                ];
            }
        }
        return $opts;
    }

    public static function getClient($ip, $user, $pass)
    {
        try {
            $iport = explode(":", $ip);
            return new RouterOS\Client($iport[0], $user, $pass, ($iport[1]) ? $iport[1] : null);
        } catch (Exception $e) {
            die("Unable to connect to the router.<br>" . $e->getMessage());
        }
    }

    /**
     * Like getClient(), but returns null on failure instead of die()-ing.
     * Used by failover paths and the Remote Login console.
     */
    public static function tryClient($ip, $user, $pass)
    {
        if (empty($ip) || empty($user)) return null;
        try {
            $iport = explode(":", $ip);
            return new RouterOS\Client($iport[0], $user, $pass, ($iport[1]) ? $iport[1] : null);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Connect to a router row, preferring the requested target.
     * $target: 'primary' | 'secondary' | 'auto' (default).
     * 'auto' tries primary first, then secondary if it is enabled.
     * Returns [client, used_target, error_message].
     */
    public static function getClientForRouter($router, $target = 'auto')
    {
        $primary = [
            'ip'   => $router['ip_address'],
            'user' => $router['username'],
            'pass' => $router['password'],
            'on'   => !empty($router['enabled']),
        ];
        $secondary = [
            'ip'   => $router['secondary_ip_address'] ?? '',
            'user' => $router['secondary_username'] ?? '',
            'pass' => $router['secondary_password'] ?? '',
            'on'   => !empty($router['secondary_enabled']),
        ];

        $order = ($target === 'secondary')
            ? [['secondary', $secondary], ['primary', $primary]]
            : [['primary', $primary], ['secondary', $secondary]];

        $errors = [];
        foreach ($order as $entry) {
            list($label, $cfg) = $entry;
            if (!$cfg['on'] || empty($cfg['ip'])) {
                continue;
            }
            $client = self::tryClient($cfg['ip'], $cfg['user'], $cfg['pass']);
            if ($client) {
                return [$client, $label, null];
            }
            $errors[] = "$label ({$cfg['ip']})";
            if ($target === 'primary' || $target === 'secondary') {
                // explicit target requested — do not fall back
                break;
            }
        }
        $msg = empty($errors)
            ? 'No reachable router endpoint is enabled.'
            : 'Unable to reach: ' . implode(', ', $errors);
        return [null, null, $msg];
    }

    public static function addHotspotPlan($client, $name, $sharedusers, $rate){
        $addRequest = new RouterOS\Request('/ip/hotspot/user/profile/add');
        $client->sendSync(
            $addRequest
                ->setArgument('name', $name)
                ->setArgument('shared-users', $sharedusers)
                ->setArgument('rate-limit', $rate)
        );
    }

    public static function setHotspotPlan($client, $name, $sharedusers, $rate){
        $printRequest = new RouterOS\Request(
            '/ip hotspot user profile print .proplist=name',
            RouterOS\Query::where('name', $name)
        );
        $profileName = $client->sendSync($printRequest)->getProperty('name');

        $setRequest = new RouterOS\Request('/ip/hotspot/user/profile/set');
        $client(
            $setRequest
                ->setArgument('numbers', $profileName)
                ->setArgument('shared-users', $sharedusers)
                ->setArgument('rate-limit', $rate)
        );
    }

    public static function removeHotspotPlan($client, $name){
        $printRequest = new RouterOS\Request(
            '/ip hotspot user profile print .proplist=name',
            RouterOS\Query::where('name', $name)
        );
        $profileName = $client->sendSync($printRequest)->getProperty('name');

        $removeRequest = new RouterOS\Request('/ip/hotspot/user/profile/remove');
        $client(
            $removeRequest
                ->setArgument('numbers', $profileName)
        );
    }

    public static function removeHotspotUser($client, $username)
    {
        $printRequest = new RouterOS\Request(
            '/ip hotspot user print .proplist=name',
            RouterOS\Query::where('name', $username)
        );
        $userName = $client->sendSync($printRequest)->getProperty('name');
        $removeRequest = new RouterOS\Request('/ip/hotspot/user/remove');
        $client(
            $removeRequest
                ->setArgument('numbers', $userName)
        );
    }

    public static function addHotspotUser($client, $plan, $customer)
    {
        $addRequest = new RouterOS\Request('/ip/hotspot/user/add');
        if ($plan['typebp'] == "Limited") {
            if ($plan['limit_type'] == "Time_Limit") {
                if ($plan['time_unit'] == 'Hrs')
                    $timelimit = $plan['time_limit'] . ":00:00";
                else
                    $timelimit = "00:" . $plan['time_limit'] . ":00";
                $client->sendSync(
                    $addRequest
                        ->setArgument('name', $customer['username'])
                        ->setArgument('profile', $plan['name_plan'])
                        ->setArgument('password', $customer['password'])
                        ->setArgument('limit-uptime', $timelimit)
                );
            } else if ($plan['limit_type'] == "Data_Limit") {
                if ($plan['data_unit'] == 'GB')
                    $datalimit = $plan['data_limit'] . "000000000";
                else
                    $datalimit = $plan['data_limit'] . "000000";
                $client->sendSync(
                    $addRequest
                        ->setArgument('name', $customer['username'])
                        ->setArgument('profile', $plan['name_plan'])
                        ->setArgument('password', $customer['password'])
                        ->setArgument('limit-bytes-total', $datalimit)
                );
            } else if ($plan['limit_type'] == "Both_Limit") {
                if ($plan['time_unit'] == 'Hrs')
                    $timelimit = $plan['time_limit'] . ":00:00";
                else
                    $timelimit = "00:" . $plan['time_limit'] . ":00";
                if ($plan['data_unit'] == 'GB')
                    $datalimit = $plan['data_limit'] . "000000000";
                else
                    $datalimit = $plan['data_limit'] . "000000";
                $client->sendSync(
                    $addRequest
                        ->setArgument('name', $customer['username'])
                        ->setArgument('profile', $plan['name_plan'])
                        ->setArgument('password', $customer['password'])
                        ->setArgument('limit-uptime', $timelimit)
                        ->setArgument('limit-bytes-total', $datalimit)
                );
            }
        } else {
            $client->sendSync(
                $addRequest
                    ->setArgument('name', $customer['username'])
                    ->setArgument('profile', $plan['name_plan'])
                    ->setArgument('password', $customer['password'])
            );
        }
    }

    public static function setHotspotUser($client, $user, $pass, $nuser= null){
        $printRequest = new RouterOS\Request('/ip/hotspot/user/print');
        $printRequest->setArgument('.proplist', '.id');
        $printRequest->setQuery(RouterOS\Query::where('name', $user));
        $id = $client->sendSync($printRequest)->getProperty('.id');

        $setRequest = new RouterOS\Request('/ip/hotspot/user/set');
        $setRequest->setArgument('numbers', $id);
        $setRequest->setArgument('password', $pass);
        $client->sendSync($setRequest);
    }

    /**
     * Return the set of hotspot usernames that have actually been used
     * (logged in at least once). A freshly created voucher user has zero
     * uptime and zero bytes transferred; the moment a customer redeems it at
     * the captive portal those counters become non-zero. Used to reconcile
     * voucher status back into the billing database, since portal redemptions
     * are consumed on the router directly and never touch the billing flow.
     *
     * @return array Map of username => true for every used hotspot user.
     */
    public static function getUsedHotspotUsers($client)
    {
        $used = [];
        $req = new RouterOS\Request('/ip/hotspot/user/print');
        $req->setArgument('.proplist', 'name,uptime,bytes-in,bytes-out');
        foreach ($client->sendSync($req) as $r) {
            if ($r->getType() !== RouterOS\Response::TYPE_DATA) {
                continue;
            }
            $name = $r->getProperty('name');
            if ($name === null || $name === '') {
                continue;
            }
            $uptime   = (string) $r->getProperty('uptime');
            $bytesIn  = (int) $r->getProperty('bytes-in');
            $bytesOut = (int) $r->getProperty('bytes-out');
            $usedUptime = ($uptime !== '' && $uptime !== '0s' && $uptime !== '00:00:00');
            if ($usedUptime || $bytesIn > 0 || $bytesOut > 0) {
                $used[$name] = true;
            }
        }
        return $used;
    }

    public static function removeHotspotActiveUser($client, $username)
    {
        $onlineRequest = new RouterOS\Request('/ip/hotspot/active/print');
        $onlineRequest->setArgument('.proplist', '.id');
        $onlineRequest->setQuery(RouterOS\Query::where('user', $username));
        $id = $client->sendSync($onlineRequest)->getProperty('.id');

        $removeRequest = new RouterOS\Request('/ip/hotspot/active/remove');
        $removeRequest->setArgument('numbers', $id);
        $client->sendSync($removeRequest);
    }

    public static function setHotspotLimitUptime($client, $username)
    {
        $printRequest = new RouterOS\Request('/ip/hotspot/user/print');
        $printRequest->setArgument('.proplist', '.id');
        $printRequest->setQuery(RouterOS\Query::where('name', $username));
        $id = $client->sendSync($printRequest)->getProperty('.id');

        $setRequest = new RouterOS\Request('/ip/hotspot/user/set');
        $setRequest->setArgument('numbers', $id);
        $setRequest->setArgument('limit-uptime', '00:00:05');
        $client->sendSync($setRequest);
    }

    public static function removePpoeUser($client, $username)
    {
        $printRequest = new RouterOS\Request(
            '/ppp secret print .proplist=name',
            RouterOS\Query::where('name', $username)
        );
        $userName = $client->sendSync($printRequest)->getProperty('name');

        $removeRequest = new RouterOS\Request('/ppp/secret/remove');
        $client(
            $removeRequest
                ->setArgument('numbers', $userName)
        );
    }

    public static function addPpoeUser($client, $plan, $customer)
    {
        $addRequest = new RouterOS\Request('/ppp/secret/add');
        $client->sendSync(
            $addRequest
                ->setArgument('name', $customer['username'])
                ->setArgument('service', 'pppoe')
                ->setArgument('profile', $plan['name_plan'])
                ->setArgument('password', $customer['password'])
        );
    }

    public static function setPpoeUser($client, $user, $pass, $nuser= null){
        $printRequest = new RouterOS\Request('/ppp/secret/print');
        $printRequest->setArgument('.proplist', '.id');
        $printRequest->setQuery(RouterOS\Query::where('name', $user['username']));
        $id = $client->sendSync($printRequest)->getProperty('.id');

        $setRequest = new RouterOS\Request('/ppp/secret/set');
        $setRequest->setArgument('numbers', $id);
        $setRequest->setArgument('password', $pass);
        $client->sendSync($setRequest);
    }

    public static function disablePpoeUser($client, $username)
    {
        $printRequest = new RouterOS\Request('/ppp/secret/print');
        $printRequest->setArgument('.proplist', '.id');
        $printRequest->setQuery(RouterOS\Query::where('name', $username));
        $id = $client->sendSync($printRequest)->getProperty('.id');

        $setRequest = new RouterOS\Request('/ppp/secret/disable');
        $setRequest->setArgument('numbers', $id);
        $client->sendSync($setRequest);
    }

    /** Re-enable a previously disabled PPP secret. */
    public static function enablePpoeUser($client, $username)
    {
        $printRequest = new RouterOS\Request('/ppp/secret/print');
        $printRequest->setArgument('.proplist', '.id');
        $printRequest->setQuery(RouterOS\Query::where('name', $username));
        $id = $client->sendSync($printRequest)->getProperty('.id');
        if (!$id) return;

        $setRequest = new RouterOS\Request('/ppp/secret/enable');
        $setRequest->setArgument('numbers', $id);
        $client->sendSync($setRequest);
    }

    /** Migrate a PPP secret to a different profile (plan change). */
    public static function setPpoeUserProfile($client, $username, $profileName)
    {
        $printRequest = new RouterOS\Request('/ppp/secret/print');
        $printRequest->setArgument('.proplist', '.id');
        $printRequest->setQuery(RouterOS\Query::where('name', $username));
        $id = $client->sendSync($printRequest)->getProperty('.id');
        if (!$id) return;

        $setRequest = new RouterOS\Request('/ppp/secret/set');
        $setRequest->setArgument('numbers', $id);
        $setRequest->setArgument('profile', $profileName);
        $client->sendSync($setRequest);
    }

    public static function removePpoeActive($client, $username)
    {
        $onlineRequest = new RouterOS\Request('/ppp/active/print');
        $onlineRequest->setArgument('.proplist', '.id');
        $onlineRequest->setQuery(RouterOS\Query::where('name', $username));
        $id = $client->sendSync($onlineRequest)->getProperty('.id');

        $removeRequest = new RouterOS\Request('/ppp/active/remove');
        $removeRequest->setArgument('numbers', $id);
        $client->sendSync($removeRequest);
    }

    public static function removePool($client, $name){
        $printRequest = new RouterOS\Request(
            '/ip pool print .proplist=name',
            RouterOS\Query::where('name', $name)
        );
        $poolName = $client->sendSync($printRequest)->getProperty('name');

        $removeRequest = new RouterOS\Request('/ip/pool/remove');
        $client($removeRequest
            ->setArgument('numbers', $poolName)
        );
    }

    public static function addPool($client, $name, $ip_address){
        $addRequest = new RouterOS\Request('/ip/pool/add');
        $client->sendSync($addRequest
            ->setArgument('name', $name)
            ->setArgument('ranges', $ip_address)
        );
    }

    public static function setPool($client, $name, $ip_address){
        $printRequest = new RouterOS\Request(
            '/ip pool print .proplist=name',
            RouterOS\Query::where('name', $name)
        );
        $poolName = $client->sendSync($printRequest)->getProperty('name');

        if(empty($poolName)){
            self::addPool($client, $name, $ip_address);
        }else{
            $setRequest = new RouterOS\Request('/ip/pool/set');
            $client(
                $setRequest
                    ->setArgument('numbers', $poolName)
                    ->setArgument('ranges', $ip_address)
            );
        }
    }


    public static function addPpoePlan($client, $name, $pool, $rate){
        $addRequest = new RouterOS\Request('/ppp/profile/add');
        $client->sendSync(
            $addRequest
                ->setArgument('name', $name)
                ->setArgument('local-address', $pool)
                ->setArgument('remote-address', $pool)
                ->setArgument('rate-limit', $rate)
        );
    }

    public static function setPpoePlan($client, $name, $pool, $rate){
        $printRequest = new RouterOS\Request(
            '/ppp profile print .proplist=name',
            RouterOS\Query::where('name', $name)
        );
        $profileName = $client->sendSync($printRequest)->getProperty('name');
        if(empty($profileName)){
            self::addPpoePlan($client, $name, $pool, $rate);
        }else{
            $setRequest = new RouterOS\Request('/ppp/profile/set');
            $client(
                $setRequest
                    ->setArgument('numbers', $profileName)
                    ->setArgument('local-address', $pool)
                    ->setArgument('remote-address', $pool)
                    ->setArgument('rate-limit', $rate)
            );
        }
    }

    public static function removePpoePlan($client, $name){
        $printRequest = new RouterOS\Request(
            '/ppp profile print .proplist=name',
            RouterOS\Query::where('name', $name)
        );
        $profileName = $client->sendSync($printRequest)->getProperty('name');

        $removeRequest = new RouterOS\Request('/ppp/profile/remove');
        $client(
            $removeRequest
                ->setArgument('numbers', $profileName)
        );
    }
}
