<?php
/**
 * Public expired-subscription notice page.
 *   /notice/<username>      — show message with this username inlined
 *   /notice?ip=10.x.x.x     — look the username up from /ppp/active by IP
 *
 * No auth required — this is what suspended customers see when they
 * try to browse and the Mikrotik firewall redirects their HTTP traffic
 * here. The page should work even when the customer is not logged
 * into anything.
 */

// Username from path segment or query string
$username = '';
if (isset($routes['1']) && $routes['1'] !== '') $username = $routes['1'];
elseif (isset($_GET['user'])) $username = $_GET['user'];
elseif (isset($_GET['u']))    $username = $_GET['u'];
$username = trim((string) $username);

// Optional: resolve from IP if username wasn't supplied
$lookupIp = isset($_GET['ip']) ? trim((string) $_GET['ip']) : '';
if ($username === '' && $lookupIp === '') {
    // Use the requester's IP (whatever Mikrotik forwarded)
    $lookupIp = isset($_SERVER['HTTP_X_REAL_IP']) ? $_SERVER['HTTP_X_REAL_IP']
              : (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR']
              : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''));
    // Take first IP in case of comma-list
    if (strpos($lookupIp, ',') !== false) $lookupIp = trim(explode(',', $lookupIp)[0]);
}

if ($username === '' && $lookupIp !== '') {
    // Best-effort: ask Mikrotik who has this IP right now
    try {
        $rt = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
        if ($rt) {
            require_once __DIR__ . '/../autoload/PEAR2/Autoload.php';
            $client = Mikrotik::tryClient($rt['ip_address'], $rt['username'], $rt['password']);
            if ($client) {
                $req = new PEAR2\Net\RouterOS\Request('/ppp/active/print');
                $req->setArgument('.proplist', 'name,address');
                $req->setQuery(PEAR2\Net\RouterOS\Query::where('address', $lookupIp));
                foreach ($client->sendSync($req) as $r) {
                    if ($r->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        $username = $r->getProperty('name'); break;
                    }
                }
            }
        }
    } catch (Throwable $e) { /* swallow, fall back to blank username */ }
}

// Look up customer details if we have a username
$customer = null;
$recharge = null;
if ($username !== '') {
    $customer = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
    if ($customer) {
        $recharge = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $customer['id'])
            ->order_by_desc('id')->find_one();
    }
}

// bKash / Nagad / Rocket payment info from config (with sensible defaults)
$payNumber = isset($config['expiry_pay_number']) && $config['expiry_pay_number'] !== ''
    ? $config['expiry_pay_number'] : '01975585960';
$supportNumber = isset($config['expiry_support_number']) && $config['expiry_support_number'] !== ''
    ? $config['expiry_support_number'] : '01975585960';

// Discourage caches / search indexers — this page is per-customer state and
// changes the moment they recharge.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$ui->assign('username',       $username !== '' ? $username : 'Unknown');
$ui->assign('customer',       $customer);
$ui->assign('recharge',       $recharge);
$ui->assign('pay_number',     $payNumber);
$ui->assign('support_number', $supportNumber);
$ui->assign('company',        isset($config['CompanyName']) ? $config['CompanyName'] : 'NetPulse');
$ui->assign('logo_url',       '/ui/ui/images/logo.png');
$ui->display('expired-notice.tpl');
exit;
