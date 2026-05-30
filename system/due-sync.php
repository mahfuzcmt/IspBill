<?php
/**
 * Due Users Sync Script
 * Run via cron to sync users with unpaid credit sales to Mikrotik address list
 * Usage: php due-sync.php
 */

// Include boot
require_once dirname(__FILE__) . '/boot.php';

// Get router
$router = ORM::for_table('tbl_routers')
    ->where('enabled', 'yes')
    ->find_one();

if (!$router) {
    echo "No router configured\n";
    exit(1);
}

try {
    $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
    if (!$client) {
        echo "Cannot connect to router\n";
        exit(1);
    }

    // Get all customers with unpaid credit sales (status = 'due')
    $dueCredits = ORM::for_table('tbl_credit_sales')
        ->where('status', 'due')
        ->find_many();

    $dueUsernames = [];
    foreach ($dueCredits as $c) {
        $dueUsernames[$c['username']] = $c['amount'];
    }

    echo "Found " . count($dueUsernames) . " customers with unpaid dues\n";

    // Get active PPPoE sessions
    $request = new \RouterOS\Request('/ppp/active/print');
    $request->setArgument('.proplist', 'name,address');
    $activeSessions = $client->sendSync($request)->toArray();

    // Find IPs of due users
    $dueIPs = [];
    foreach ($activeSessions as $session) {
        $username = $session['name'] ?? '';
        $address = $session['address'] ?? '';
        if ($username && $address && isset($dueUsernames[$username])) {
            $dueIPs[$address] = $username;
        }
    }

    echo "Found " . count($dueIPs) . " active sessions with unpaid dues\n";

    // Clear existing due-warning address list
    $request = new \RouterOS\Request('/ip/firewall/address-list/print');
    $request->setArgument('.proplist', '.id');
    $request->setQuery(\RouterOS\Query::where('list', 'due-warning'));
    $existing = $client->sendSync($request)->toArray();

    foreach ($existing as $entry) {
        $removeRequest = new \RouterOS\Request('/ip/firewall/address-list/remove');
        $removeRequest->setArgument('.id', $entry['.id']);
        $client->sendSync($removeRequest);
    }

    echo "Cleared existing address list entries\n";

    // Add current due IPs to address list
    foreach ($dueIPs as $ip => $username) {
        $addRequest = new \RouterOS\Request('/ip/firewall/address-list/add');
        $addRequest->setArgument('list', 'due-warning');
        $addRequest->setArgument('address', $ip);
        $addRequest->setArgument('comment', 'Due: ' . $username . ' (' . number_format($dueUsernames[$username], 0) . ' BDT)');
        $addRequest->setArgument('timeout', '5m'); // Auto-expire in 5 minutes
        $client->sendSync($addRequest);
        echo "Added $ip ($username) to due-warning list\n";
    }

    echo "Sync complete\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
