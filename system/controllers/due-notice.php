<?php
/**
 * Due Notice Controller
 * Shows friendly payment reminder to users with credit sales (unpaid dues)
 * Auto-redirects to original URL after 30 seconds
 * Shows max 4 times per day per user
 */

// No admin check - this is public facing
header('Content-Type: text/html; charset=utf-8');

// Get user IP
$userIP = $_SERVER['REMOTE_ADDR'];
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $userIP = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
}
$userIP = trim($userIP);

// Get original URL from query string
$originalUrl = isset($_GET['url']) ? $_GET['url'] : 'https://www.google.com';
$originalUrl = filter_var($originalUrl, FILTER_SANITIZE_URL);

// Find customer by checking Mikrotik for active PPPoE user with this IP
$customer = null;
$creditSale = null;
$dueAmount = 0;

$router = ORM::for_table('tbl_routers')
    ->where('enabled', 'yes')
    ->find_one();

if ($router) {
    try {
        $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
        if ($client) {
            $request = new \RouterOS\Request('/ppp/active/print');
            $request->setArgument('.proplist', 'name,address,caller-id');
            $activeUsers = $client->sendSync($request)->toArray();

            foreach ($activeUsers as $user) {
                if (isset($user['address']) && $user['address'] === $userIP) {
                    // Found the user by IP
                    $customer = ORM::for_table('tbl_customers')
                        ->where('username', $user['name'])
                        ->find_one();

                    if ($customer) {
                        // Check if they have unpaid credit sales
                        $creditSale = ORM::for_table('tbl_credit_sales')
                            ->where('customer_id', $customer['id'])
                            ->where('status', 'due')
                            ->order_by_desc('created_at')
                            ->find_one();
                    }
                    break;
                }
            }
        }
    } catch (Exception $e) {
        // Silently fail
    }
}

// If no customer found or no dues, redirect immediately
if (!$customer || !$creditSale) {
    header('Location: ' . $originalUrl);
    exit;
}

$dueAmount = $creditSale['amount'];
$customerName = $customer['fullname'] ?: $customer['username'];

// Track view count - store in a simple file-based system
$viewCountFile = sys_get_temp_dir() . '/due_notice_views_' . date('Y-m-d') . '.json';
$viewCounts = [];

if (file_exists($viewCountFile)) {
    $viewCounts = json_decode(file_get_contents($viewCountFile), true) ?: [];
}

$customerId = $customer['id'];
$currentViews = isset($viewCounts[$customerId]) ? $viewCounts[$customerId] : 0;

// If already shown 4 times today, redirect immediately
if ($currentViews >= 4) {
    header('Location: ' . $originalUrl);
    exit;
}

// Increment view count
$viewCounts[$customerId] = $currentViews + 1;
file_put_contents($viewCountFile, json_encode($viewCounts));

// Show the notice page
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<meta http-equiv="refresh" content="30;url=<?php echo htmlspecialchars($originalUrl); ?>">
<title>বিল পরিশোধের অনুরোধ - AHAD Network</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Noto Sans Bengali','Hind Siliguri',sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:15px}
.box{background:#fff;border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,0.3);width:100%;max-width:420px;overflow:hidden}
.head{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:25px;text-align:center;color:#fff}
.head h1{font-size:22px;margin-bottom:8px}
.head p{font-size:14px;opacity:0.9}
.content{padding:25px}
.hello-icon{text-align:center;margin-bottom:20px}
.hello-icon span{display:inline-block;width:70px;height:70px;background:linear-gradient(135deg,#f39c12 0%,#e67e22 100%);border-radius:50%;line-height:70px;font-size:32px;color:#fff}
.due-box{background:linear-gradient(135deg,#e74c3c 0%,#c0392b 100%);border-radius:12px;padding:20px;text-align:center;color:#fff;margin-bottom:20px}
.due-label{font-size:14px;opacity:0.9;margin-bottom:5px}
.due-amount{font-size:36px;font-weight:bold}
.due-amount span{font-size:18px}
.customer-name{font-size:13px;opacity:0.8;margin-top:8px}
.msg{text-align:center;color:#2d3436;font-size:15px;margin-bottom:20px;line-height:1.7}
.payment-box{background:#f8f9fa;border-radius:12px;padding:20px;margin-bottom:20px}
.payment-box h3{color:#2d3436;font-size:16px;margin-bottom:15px;text-align:center}
.payment-method{display:flex;align-items:center;padding:12px;background:#fff;border-radius:8px;margin-bottom:10px;border:2px solid #e0e0e0}
.payment-method:last-child{margin-bottom:0}
.payment-method .icon{width:50px;height:50px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:12px;margin-right:12px;color:#fff}
.bkash{background:linear-gradient(135deg,#E2136E 0%,#A4126A 100%)}
.nagad{background:linear-gradient(135deg,#F6921E 0%,#ED1C24 100%)}
.rocket{background:linear-gradient(135deg,#8B2D87 0%,#5C1F5C 100%)}
.store{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%)}
.payment-method .details{flex-grow:1}
.payment-method .name{font-weight:600;color:#2d3436;font-size:14px}
.payment-method .number{color:#667eea;font-size:16px;font-weight:bold;letter-spacing:1px}
.payment-method .note{font-size:11px;color:#666;margin-top:2px}
.timer-box{background:#e8f5e9;border:2px solid #4caf50;border-radius:10px;padding:15px;text-align:center;margin-bottom:15px}
.timer-box p{color:#2e7d32;font-size:13px;margin-bottom:5px}
.timer{font-size:28px;font-weight:bold;color:#388e3c}
.skip-btn{display:block;width:100%;padding:14px;background:linear-gradient(135deg,#00b894 0%,#00cec9 100%);color:#fff;text-decoration:none;border-radius:10px;text-align:center;font-weight:600;font-size:15px}
.skip-btn:hover{opacity:0.9}
.foot{padding:12px;background:#f8f9fa;text-align:center;font-size:11px;color:#636e72}
.view-count{font-size:11px;color:#999;text-align:center;margin-top:10px}
</style>
</head>
<body>
<div class="box">
<div class="head">
<h1>বিল পরিশোধের অনুরোধ</h1>
<p>প্রিয় গ্রাহক, আপনার জন্য একটি বার্তা</p>
</div>
<div class="content">
<div class="hello-icon"><span>৳</span></div>

<div class="due-box">
<div class="due-label">বকেয়া বিল</div>
<div class="due-amount"><span>৳</span> <?php echo number_format($dueAmount, 0); ?></div>
<div class="customer-name"><?php echo htmlspecialchars($customerName); ?></div>
</div>

<p class="msg">আপনার ইন্টারনেট সেবা চালু আছে। বিল পরিশোধ করতে ভুলে গেছেন? সুবিধামতো সময়ে পরিশোধ করে দিন। ধন্যবাদ!</p>

<div class="payment-box">
<h3>পেমেন্ট করুন</h3>

<div class="payment-method">
<div class="icon bkash">bKash</div>
<div class="details">
<div class="name">বিকাশ (Personal)</div>
<div class="number">01975 585960</div>
<div class="note">Send Money করুন</div>
</div>
</div>

<div class="payment-method">
<div class="icon nagad">Nagad</div>
<div class="details">
<div class="name">নগদ (Personal)</div>
<div class="number">01975 585960</div>
<div class="note">Send Money করুন</div>
</div>
</div>

<div class="payment-method">
<div class="icon rocket">Rocket</div>
<div class="details">
<div class="name">রকেট (Personal)</div>
<div class="number">01975 5859601</div>
<div class="note">Send Money করুন (শেষে 1 যোগ করুন)</div>
</div>
</div>

<div class="payment-method">
<div class="icon store">Store</div>
<div class="details">
<div class="name">আহাদ টেলিকম</div>
<div class="number">সরাসরি অফিসে</div>
<div class="note">আলিফ সুপার মার্কেট, নলডাঙ্গা</div>
</div>
</div>

</div>

<div class="timer-box">
<p>স্বয়ংক্রিয়ভাবে আপনার পেজে যাচ্ছে</p>
<div class="timer"><span id="countdown">30</span> সেকেন্ড</div>
</div>

<a href="<?php echo htmlspecialchars($originalUrl); ?>" class="skip-btn">এখনই যান</a>

<p class="view-count">আজকে <?php echo $currentViews + 1; ?>/৪ বার দেখানো হয়েছে</p>
</div>
<div class="foot">
AHAD Network | আহাদ নেটওয়ার্ক<br>
কল করুন: ০১৯৭৫ ৫৮ ৫৯ ৬০
</div>
</div>

<script>
var seconds = 30;
var countdown = document.getElementById('countdown');
var timer = setInterval(function() {
    seconds--;
    countdown.textContent = seconds;
    if (seconds <= 0) {
        clearInterval(timer);
        window.location.href = '<?php echo addslashes($originalUrl); ?>';
    }
}, 1000);
</script>
</body>
</html>
<?php
exit;
