<?php
/**
 * API Controller for Hotspot Integration
 * Handles registration and other API calls from hotspot portal
 */

// Allow CORS for hotspot requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Needed for the PEAR2 RouterOS client used by Mikrotik:: helpers; this is
// not registered globally, so each controller that talks to Mikrotik loads it.
require_once 'system/autoload/PEAR2/Autoload.php';

$action = $routes['1'];

switch ($action) {
    case 'hotspot-register':
        hotspot_register();
        break;

    default:
        json_response(false, 'Invalid API endpoint');
}

/**
 * Handle hotspot user registration
 */
function hotspot_register() {
    // Get POST data
    $phone = trim($_POST['phone'] ?? '');
    $name = trim($_POST['name'] ?? '');

    // Validate phone number (11 digits starting with 01)
    if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) {
        json_response(false, 'সঠিক মোবাইল নম্বর দিন');
        return;
    }

    // Validate name
    if (strlen($name) < 3) {
        json_response(false, 'আপনার নাম দিন');
        return;
    }

    // Check if user already exists
    $existing = ORM::for_table('tbl_customers')
        ->where('username', $phone)
        ->find_one();

    if ($existing) {
        json_response(false, 'এই নম্বর দিয়ে আগেই রেজিস্টার করা হয়েছে। সাইন ইন করুন।');
        return;
    }

    // Also check by phone number field
    $existingPhone = ORM::for_table('tbl_customers')
        ->where('phonenumber', $phone)
        ->find_one();

    if ($existingPhone) {
        json_response(false, 'এই নম্বর দিয়ে আগেই রেজিস্টার করা হয়েছে। সাইন ইন করুন।');
        return;
    }

    // Generate password from last 6 digits
    $password = substr($phone, -6);

    try {
        // Create customer in database
        $customer = ORM::for_table('tbl_customers')->create();
        $customer->username = $phone;
        $customer->password = $password;
        $customer->fullname = $name;
        $customer->phonenumber = $phone;
        $customer->email = $phone . '@hotspot.local';
        $customer->address = 'Hotspot Registration';
        $customer->created_at = date('Y-m-d H:i:s');
        $customer->save();

        // Create hotspot user on Mikrotik. The enabled column is a tinyint(1),
        // so match 1 (not the legacy 'yes'). Try each enabled router until one
        // connects — tryClient() returns null instead of die()-ing, so an
        // unreachable router never aborts the JSON response or rolls back the
        // customer we just created in the database.
        $routers = ORM::for_table('tbl_routers')
            ->where('enabled', 1)
            ->find_many();

        foreach ($routers as $router) {
            try {
                Mikrotik::addHotspotUserRest(
                    $router['ip_address'], $router['username'], $router['password'],
                    $phone, $password, 'default', $name
                );
                break; // created (or already present) on a reachable router
            } catch (Throwable $e) {
                _log("Hotspot Registration: Mikrotik add failed on {$router['ip_address']}: " . $e->getMessage());
                continue;
            }
        }

        // Log the registration
        _log("Hotspot Registration: {$phone} ({$name}) registered successfully");

        json_response(true, 'রেজিস্ট্রেশন সফল! এখন সাইন ইন করুন। পাসওয়ার্ড: ' . $password);

    } catch (Exception $e) {
        _log("Hotspot Registration Error: " . $e->getMessage());
        json_response(false, 'সার্ভার সমস্যা, আবার চেষ্টা করুন');
    }
}

/**
 * Send JSON response
 */
function json_response($success, $message, $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
