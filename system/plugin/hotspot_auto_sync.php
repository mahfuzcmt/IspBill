<?php
/**
 * Hotspot Auto Sync Plugin
 *
 * Automatically creates Mikrotik hotspot users when customers are registered
 * Username: phone number (e.g., 01975585960)
 * Password: last 6 digits (e.g., 585960)
 *
 * @author AHAD Network
 */

// Register hook for customer registration (self-service)
register_hook('register_user', 'hotspot_sync_on_register');

/**
 * Sync customer to Mikrotik hotspot on self-registration
 * This runs after $d->save() in register.php
 */
function hotspot_sync_on_register() {
    global $d; // $d contains the newly created customer ORM object

    if (!isset($d) || !$d->id) {
        return;
    }

    hotspot_create_user_from_phone($d->phonenumber ?: $d->username);
}

/**
 * Create Mikrotik hotspot user from phone number
 *
 * @param string $phone Phone number (username)
 * @param string $profile Hotspot profile name (default: 'default')
 * @return bool Success status
 */
function hotspot_create_user_from_phone($phone, $profile = 'default') {
    // Validate phone number (should be 11 digits starting with 01)
    $phone = trim($phone);
    if (!preg_match('/^01\d{9}$/', $phone)) {
        _log("Hotspot Sync: Invalid phone format: {$phone}");
        return false;
    }

    // Generate password from last 6 digits
    $password = substr($phone, -6);

    // Get default hotspot router (first enabled router)
    $router = ORM::for_table('tbl_routers')
        ->where('enabled', 'yes')
        ->find_one();

    if (!$router) {
        _log("Hotspot Sync: No enabled router found");
        return false;
    }

    try {
        // Connect to Mikrotik
        $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);

        if (!$client) {
            _log("Hotspot Sync: Cannot connect to router {$router['name']}");
            return false;
        }

        // Check if user already exists
        $printRequest = new \RouterOS\Request('/ip/hotspot/user/print');
        $printRequest->setArgument('.proplist', '.id,name');
        $printRequest->setQuery(\RouterOS\Query::where('name', $phone));
        $users = $client->sendSync($printRequest)->toArray();

        if (!empty($users)) {
            // User exists, update password
            $userId = $users[0]['.id'];
            $setRequest = new \RouterOS\Request('/ip/hotspot/user/set');
            $setRequest->setArgument('.id', $userId);
            $setRequest->setArgument('password', $password);
            $client->sendSync($setRequest);
            _log("Hotspot Sync: Updated existing user {$phone}");
        } else {
            // Create new user with specified profile
            $addRequest = new \RouterOS\Request('/ip/hotspot/user/add');
            $addRequest->setArgument('name', $phone);
            $addRequest->setArgument('password', $password);
            $addRequest->setArgument('profile', $profile);
            $client->sendSync($addRequest);
            _log("Hotspot Sync: Created hotspot user {$phone} with profile {$profile}");
        }

        return true;

    } catch (Exception $e) {
        _log("Hotspot Sync Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Sync all existing customers to hotspot
 * Returns array with sync results
 *
 * @return array ['synced' => int, 'failed' => int, 'total' => int]
 */
function hotspot_sync_all_customers() {
    $customers = ORM::for_table('tbl_customers')->find_many();
    $synced = 0;
    $failed = 0;

    foreach ($customers as $customer) {
        $phone = $customer->phonenumber ?: $customer->username;
        if (hotspot_create_user_from_phone($phone)) {
            $synced++;
        } else {
            $failed++;
        }
    }

    return [
        'synced' => $synced,
        'failed' => $failed,
        'total' => count($customers)
    ];
}

/**
 * Sync a single customer by ID
 *
 * @param int $customerId Customer ID
 * @return bool Success status
 */
function hotspot_sync_customer($customerId) {
    $customer = ORM::for_table('tbl_customers')->find_one($customerId);
    if (!$customer) {
        return false;
    }

    $phone = $customer->phonenumber ?: $customer->username;
    return hotspot_create_user_from_phone($phone);
}
