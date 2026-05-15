<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>মেয়াদ শেষ — {$company}</title>
<link rel="shortcut icon" href="/ui/ui/images/logo.png" type="image/x-icon">
<style>
  :root { --brand:#0F2742; --accent:#E8900A; --bad:#DC2626; --bg:#0A1928; --card:#FFFFFF; --soft:#F1F5F9; --ink:#0F2742; --muted:#64748B; }
  * { box-sizing: border-box }
  body { margin:0; min-height:100vh; font-family: 'Hind Siliguri','Segoe UI','Noto Sans Bengali','Inter','Roboto',sans-serif;
         color: var(--ink); background: linear-gradient(135deg,#0A1928 0%,#0F2742 60%,#1E3A5F 100%); padding: 24px; }
  .card { max-width: 720px; margin: 24px auto; background: var(--card); border-radius: 16px;
          box-shadow: 0 30px 60px rgba(0,0,0,.4); overflow: hidden; }
  .top { background: var(--bad); color:#fff; padding: 28px 32px; }
  .top h1 { margin:0 0 6px; font-size: 26px; font-weight: 700; }
  .top .sub { opacity: .9; font-size: 15px }
  .body { padding: 28px 32px; line-height: 1.7; font-size: 17px; }
  .bn-big { font-size: 22px; font-weight: 600; color: var(--ink); margin: 0 0 16px; }
  .id-row { background: var(--soft); border-left: 4px solid var(--accent); padding: 14px 18px; border-radius: 8px;
            margin: 18px 0; font-size: 18px }
  .id-row .label { color: var(--muted); font-size: 13px; display: block; margin-bottom: 4px }
  .id-row .value { font-weight: 700; color: var(--brand); font-family: 'JetBrains Mono','Consolas',monospace; font-size: 22px }
  .pay { background: #FFF6E6; border:1px solid var(--accent); border-radius: 10px; padding: 18px; margin: 20px 0; }
  .pay h3 { margin:0 0 10px; color: var(--brand); font-size: 18px }
  .pay-num { font-size: 26px; font-weight: 700; color: var(--bad); letter-spacing: 1px; font-family: 'JetBrains Mono',monospace; }
  .pay-num a { color: inherit; text-decoration: none }
  .pay-methods { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px }
  .pay-methods span { background:#fff; border:1px solid #ddd; border-radius:6px; padding:6px 12px; font-size: 14px; font-weight:600 }
  .footer { padding: 18px 32px; background: var(--soft); color: var(--muted); font-size: 13px; text-align: center }
  .brand { color: var(--brand); font-weight: 700 }
  ul.steps { padding-left: 22px; margin: 6px 0 0; }
  ul.steps li { margin-bottom: 6px; }
  .call { display: inline-block; background: var(--brand); color:#fff; padding: 14px 22px; border-radius: 10px;
          font-size: 18px; font-weight: 700; text-decoration: none; margin-top: 12px; letter-spacing: 1px }
</style>
</head>
<body>
<div class="card">
    <div class="top">
        <h1>⚠ আপনার ইন্টারনেট মেয়াদ শেষ হয়ে গেছে</h1>
        <div class="sub">Your internet subscription has expired. Please recharge to continue.</div>
    </div>
    <div class="body">
        <p class="bn-big">অনুগ্রহ করে রিচার্জ করুন।</p>

        <div class="id-row">
            <span class="label">আপনার আইডি / Your ID</span>
            <span class="value">{$username}</span>
            {if $customer}
                <div style="margin-top:6px; font-size:14px; color:var(--muted)">
                    {if $customer['fullname']}{$customer['fullname']} &middot; {/if}
                    {if $recharge && $recharge['namebp']}প্ল্যান / Plan: {$recharge['namebp']}{/if}
                    {if $recharge && $recharge['expiration']} &middot; মেয়াদ শেষ / Expired: {$recharge['expiration']}{/if}
                </div>
            {/if}
        </div>

        <div class="pay">
            <h3>সেন্ড মানি করুন / Send money to</h3>
            <div class="pay-num"><a href="tel:{$pay_number}">{$pay_number}</a></div>
            <div class="pay-methods">
                <span>bKash</span> <span>Nagad</span> <span>Rocket</span>
            </div>
            <ul class="steps">
                <li>Send money করার সময় <strong>Reference</strong>-এ আপনার আইডি <strong style="color:var(--bad)">{$username}</strong> দিন।</li>
                <li>রিচার্জ সম্পন্ন হলে কয়েক মিনিটের মধ্যে ইন্টারনেট চালু হয়ে যাবে।</li>
            </ul>
        </div>

        <p style="margin:18px 0 4px"><strong>অথবা সরাসরি কল করুন / Or call</strong></p>
        <a class="call" href="tel:{$support_number}">📞 {$support_number}</a>

        <p style="margin-top: 22px; font-size: 14px; color: var(--muted)">
            ধন্যবাদ আমাদের সেবায় থাকার জন্য। &middot;
            Thank you for choosing <span class="brand">{$company}</span>.
        </p>
    </div>
    <div class="footer">
        <span class="brand">{$company}</span> &middot; If you believe this message is shown by mistake, please call us.
    </div>
</div>
</body>
</html>
