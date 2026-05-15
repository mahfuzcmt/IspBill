<?php
/**
 * SMS settings + manual-send admin routes.
 *   /sms/settings        — view + edit API + templates
 *   /sms/save-settings   — POST handler
 *   /sms/send-test       — POST: send a one-off test SMS
 *   /sms/send-customer   — POST: send to a specific customer using a template or freeform text
 */
_admin();
$ui->assign('_title', 'SMS Settings');
$ui->assign('_system_menu', 'sms');

$action = isset($routes['1']) ? $routes['1'] : 'settings';
$admin = Admin::_info();
$ui->assign('_admin', $admin);
if ($admin['user_type'] !== 'Admin') {
    r2(U . 'dashboard', 'e', $_L['Do_Not_Access']);
}

// Setting keys handled by this page
$KEYS = [
    'sms_enabled'           => 'Enable SMS sending',
    'sms_api_url'           => 'API URL',
    'sms_api_key'           => 'API Key',
    'sms_sender_id'         => 'Sender ID',
    'sms_template_welcome'  => 'Welcome SMS (new customer)',
    'sms_template_recharge' => 'Recharge confirmation SMS',
    'sms_template_expiry'   => 'Expiry reminder SMS',
    'sms_template_voucher'  => 'Voucher delivery SMS',
];

switch ($action) {
    case 'send-test':
        $to = trim((string) _post('to'));
        $msg = trim((string) _post('message'));
        if ($to === '' || $msg === '') {
            r2(U . 'sms/settings', 'e', 'Phone number and message are both required');
        }
        $r = SmsSender::send($to, $msg);
        if ($r['ok']) {
            r2(U . 'sms/settings', 's', 'Sent OK. Gateway HTTP ' . $r['http'] . ' — ' . substr($r['body'], 0, 200));
        }
        r2(U . 'sms/settings', 'e', 'Send failed: ' . $r['error'] . ' (HTTP ' . $r['http'] . ', body: ' . substr($r['body'], 0, 200) . ')');
        break;

    case 'send-customer':
        $id  = (int) _post('customer_id');
        $tpl = (string) _post('template');
        $body = (string) _post('message');
        $c = ORM::for_table('tbl_customers')->find_one($id);
        if (!$c) { r2(U . 'customers/list', 'e', 'Customer not found'); }
        $r = ORM::for_table('tbl_user_recharges')
                ->where('customer_id', $id)->order_by_desc('id')->find_one();

        $vars = [
            'company'    => isset($config['CompanyName']) ? $config['CompanyName'] : 'NetPulse',
            'fullname'   => $c['fullname'],
            'username'   => $c['username'],
            'password'   => $c['password'],
            'phonenumber'=> $c['phonenumber'],
            'plan'       => $r ? $r['namebp']     : '-',
            'expiration' => $r ? $r['expiration'] : '-',
            'price'      => '',
            'validity'   => '',
        ];
        if ($r) {
            $plan = ORM::for_table('tbl_plans')->find_one($r['plan_id']);
            if ($plan) {
                $vars['price']    = $plan['price'];
                $vars['validity'] = $plan['validity'] . ' ' . $plan['validity_unit'];
            }
        }

        // Render: if template key supplied, render that; else use the literal body.
        if ($tpl !== '' && isset($config[$tpl])) {
            $text = SmsSender::render($config[$tpl], $vars);
        } else {
            $text = SmsSender::render($body, $vars);
        }

        if ($c['phonenumber'] === '' || $c['phonenumber'] === null) {
            r2(U . 'customers/edit/' . $id, 'e', 'Customer has no phone number');
        }
        $res = SmsSender::send($c['phonenumber'], $text);
        if ($res['ok']) {
            r2(U . 'customers/edit/' . $id, 's',
                'SMS sent to ' . $c['phonenumber'] . ' — gateway HTTP ' . $res['http']);
        }
        r2(U . 'customers/edit/' . $id, 'e',
            'SMS send failed: ' . $res['error'] . ' (HTTP ' . $res['http'] . ')');
        break;

    case 'save-settings':
        run_hook('save_sms_settings');
        foreach (array_keys($KEYS) as $k) {
            // Allow blanks: treat unchecked checkbox as '0'
            $val = $k === 'sms_enabled'
                ? (_post($k) ? '1' : '0')
                : (string) _post($k);
            $row = ORM::for_table('tbl_appconfig')->where('setting', $k)->find_one();
            if ($row) {
                $row->value = $val;
                $row->save();
            } else {
                $row = ORM::for_table('tbl_appconfig')->create();
                $row->setting = $k;
                $row->value   = $val;
                $row->save();
            }
        }
        r2(U . 'sms/settings', 's', 'SMS settings saved');
        break;

    case 'settings':
    default:
        $settings = [];
        $rows = ORM::for_table('tbl_appconfig')
            ->where_in('setting', array_keys($KEYS))
            ->find_many();
        foreach ($rows as $r) { $settings[$r['setting']] = $r['value']; }
        // Defaults shown in the form when no row exists
        $defaults = [
            'sms_enabled'   => '1',
            'sms_api_url'   => 'https://bulksmsbd.net/api/smsapi',
            'sms_api_key'   => '',
            'sms_sender_id' => '',
            'sms_template_welcome'  => "Welcome to {company}, {fullname}!\nUsername: {username}\nPassword: {password}\nPlan: {plan} ({price} BDT / {validity})\nExpires: {expiration}",
            'sms_template_recharge' => "Hi {fullname}, recharge successful.\nPlan: {plan} ({price} BDT)\nExpires: {expiration}\n- {company}",
            'sms_template_expiry'   => "Hi {fullname}, your {company} plan ({plan}) expires on {expiration}. Please recharge to avoid service interruption.",
            'sms_template_voucher'  => "Your {company} hotspot voucher:\nCode: {code}\nPlan: {plan} ({validity})\nUse this code as both username and password on the WiFi login page.",
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($settings[$k])) $settings[$k] = $v;
        }
        $ui->assign('settings', $settings);
        $ui->assign('keys', $KEYS);
        $ui->display('sms-settings.tpl');
        break;
}
