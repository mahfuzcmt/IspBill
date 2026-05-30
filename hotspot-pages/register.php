<?php
/**
 * Hotspot Registration Proxy
 *
 * Place this file on any PHP-enabled web server accessible from the hotspot network.
 * Configure the router credentials below.
 *
 * Usage: POST to this file with phone=01XXXXXXXXX&name=YourName
 */

// === CONFIGURATION ===
$ROUTER_IP = '103.187.22.131';
$ROUTER_PORT = '8090';
$ROUTER_USER = 'admin';
$ROUTER_PASS = '889946aa';
$HOTSPOT_PROFILE = 'default';
// === END CONFIGURATION ===

// Allow CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

// Get data
$phone = trim($_POST['phone'] ?? $_GET['phone'] ?? '');
$name = trim($_POST['name'] ?? $_GET['name'] ?? '');

// Validate phone (11 digits starting with 01)
if (!preg_match('/^01[0-9]{9}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'সঠিক মোবাইল নম্বর দিন (01XXXXXXXXX)']);
    exit;
}

// Validate name
if (strlen($name) < 2) {
    echo json_encode(['success' => false, 'message' => 'আপনার নাম দিন']);
    exit;
}

// Generate password from last 6 digits
$password = substr($phone, -6);

// Check if user already exists
$checkUrl = "http://{$ROUTER_IP}:{$ROUTER_PORT}/rest/ip/hotspot/user?name=" . urlencode($phone);
$ch = curl_init($checkUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "{$ROUTER_USER}:{$ROUTER_PASS}");
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $users = json_decode($response, true);
    if (!empty($users)) {
        echo json_encode([
            'success' => false,
            'message' => 'এই নম্বর আগেই রেজিস্টার করা হয়েছে। সাইন ইন করুন। পাসওয়ার্ড: ' . $password
        ]);
        exit;
    }
}

// Create new user
$createUrl = "http://{$ROUTER_IP}:{$ROUTER_PORT}/rest/ip/hotspot/user";
$userData = [
    'name' => $phone,
    'password' => $password,
    'profile' => $HOTSPOT_PROFILE,
    'comment' => $name
];

$ch = curl_init($createUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "{$ROUTER_USER}:{$ROUTER_PASS}");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($userData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 201 || $httpCode === 200) {
    echo json_encode([
        'success' => true,
        'message' => 'রেজিস্ট্রেশন সফল!',
        'phone' => $phone,
        'password' => $password
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'রেজিস্ট্রেশন ব্যর্থ। আবার চেষ্টা করুন।'
    ]);
}
