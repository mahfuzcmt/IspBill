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

        // Create hotspot user on Mikrotik
        $router = ORM::for_table('tbl_routers')
            ->where('enabled', 'yes')
            ->find_one();

        if ($router) {
            $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);

            if ($client) {
                // Check if hotspot user already exists
                $printRequest = new \RouterOS\Request('/ip/hotspot/user/print');
                $printRequest->setArgument('.proplist', '.id,name');
                $printRequest->setQuery(\RouterOS\Query::where('name', $phone));
                $users = $client->sendSync($printRequest)->toArray();

                if (empty($users)) {
                    // Create new hotspot user
                    $addRequest = new \RouterOS\Request('/ip/hotspot/user/add');
                    $addRequest->setArgument('name', $phone);
                    $addRequest->setArgument('password', $password);
                    $addRequest->setArgument('profile', 'default');
                    $client->sendSync($addRequest);
                }
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
