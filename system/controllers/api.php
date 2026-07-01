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

    case 'hotspot-trial':
        hotspot_trial();
        break;

    case 'hotspot-trial-info':
        hotspot_trial_info();
        break;

    default:
        json_response(false, 'Invalid API endpoint');
}

/**
 * Return the configured free-trial duration so the captive portal (login.html,
 * served statically by the router) can show it without being re-uploaded each
 * time the admin changes it. Best-effort: the portal falls back to its static
 * default if this endpoint is unreachable.
 */
function hotspot_trial_info() {
    global $config;
    $min = (int) ($config['hotspot_trial_duration_minutes'] ?? 60);
    if ($min < 1) $min = 60;

    // Bengali label to match the portal copy: whole hours -> "X ঘন্টা", else "X মিনিট".
    $toBn = function ($n) {
        return strtr((string) $n, [
            '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
            '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
        ]);
    };
    $label = ($min % 60 === 0) ? ($toBn($min / 60) . ' ঘন্টা') : ($toBn($min) . ' মিনিট');

    json_response(true, $label, ['minutes' => $min, 'label' => $label]);
}

/**
 * Record a free-trial start (name + mobile) before the captive portal hands
 * off to the Mikrotik native trial login. One row per trial session; repeated
 * clicks within the same 1-hour trial window reuse the open row so the
 * per-number "times used" count reflects real trial sessions, not button taps.
 * Per-session bandwidth + end time are filled in later by the cron sweep
 * (system/hotspot-voucher-expiry.php) which matches the active trial session
 * to this row by MAC address.
 */
function hotspot_trial() {
    $phone = trim($_POST['phone'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    $mac   = strtoupper(trim($_POST['mac'] ?? ''));
    $ip    = trim($_POST['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));

    if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) {
        json_response(false, 'সঠিক মোবাইল নম্বর দিন');
        return;
    }
    if (mb_strlen($name) < 3) {
        json_response(false, 'আপনার নাম দিন');
        return;
    }
    // Normalise MAC (accept AA:BB:.. or AA-BB-..); blank if not a valid MAC.
    $mac = str_replace('-', ':', $mac);
    if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
        $mac = '';
    }

    global $config;
    $trialMin = (int) ($config['hotspot_trial_duration_minutes'] ?? 60);
    if ($trialMin < 1) $trialMin = 60;

    try {
        // Reuse the current open trial row for this MAC if it started within the
        // trial window — avoids double-counting rapid re-submits/relogins.
        $open = null;
        if ($mac !== '') {
            $open = ORM::for_table('tbl_hotspot_trials')
                ->where('mac', $mac)
                ->where_null('ended_at')
                ->where_gt('started_at', date('Y-m-d H:i:s', strtotime("-{$trialMin} minutes")))
                ->order_by_desc('id')
                ->find_one();
        }

        if (!$open) {
            $t = ORM::for_table('tbl_hotspot_trials')->create();
            $t->name       = $name;
            $t->phone      = $phone;
            $t->mac        = $mac ?: null;
            $t->ip         = $ip ?: null;
            $t->started_at = date('Y-m-d H:i:s');
            $t->save();
            _log("Hotspot Trial: {$phone} ({$name}) started trial, MAC {$mac}");
        } else {
            // Keep the latest name/phone in case they changed it.
            $open->name  = $name;
            $open->phone = $phone;
            if ($ip) $open->ip = $ip;
            $open->save();
        }

        json_response(true, 'ট্রায়াল শুরু হচ্ছে...');
    } catch (Exception $e) {
        _log("Hotspot Trial Error: " . $e->getMessage());
        // Never block the trial on a logging failure — let the portal proceed.
        json_response(true, 'ট্রায়াল শুরু হচ্ছে...');
    }
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
        // Create the billing customer account ONLY. Internet access is NOT
        // granted at registration — no Mikrotik hotspot user is created here.
        // Access is provisioned exclusively when the customer redeems a valid
        // voucher (voucher/activation-post) or is recharged (Package::recharge),
        // which creates the hotspot user with the paid plan's profile + limits.
        // This closes the "register = free internet" business loss.
        $customer = ORM::for_table('tbl_customers')->create();
        $customer->username = $phone;
        $customer->password = $password;
        $customer->fullname = $name;
        $customer->phonenumber = $phone;
        $customer->email = $phone . '@hotspot.local';
        $customer->address = 'Hotspot Registration';
        $customer->created_at = date('Y-m-d H:i:s');
        $customer->save();

        // Log the registration
        _log("Hotspot Registration: {$phone} ({$name}) registered (no access until a voucher is redeemed)");

        json_response(true, 'রেজিস্ট্রেশন সফল! ইন্টারনেট চালু করতে একটি ভাউচার রিডিম করুন। আপনার পাসওয়ার্ড: ' . $password);

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
