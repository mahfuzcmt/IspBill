<?php

/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)

 **/
session_start();
function r2($to, $ntype = 'e', $msg = '')
{
    if ($msg == '') {
        header("location: $to");
        exit;
    }
    $_SESSION['ntype'] = $ntype;
    $_SESSION['notify'] = $msg;
    header("location: $to");
    exit;
}

if (file_exists('config.php')) {
    require('config.php');
} else {
    r2('install');
}


function safedata($value)
{
    $value = trim($value);
    return $value;
}

function _post($param, $defvalue = '')
{
    if (!isset($_POST[$param])) {
        return $defvalue;
    } else {
        return safedata($_POST[$param]);
    }
}

function _get($param, $defvalue = '')
{
    if (!isset($_GET[$param])) {
        return $defvalue;
    } else {
        return safedata($_GET[$param]);
    }
}


require('system/orm.php');

ORM::configure("mysql:host=$db_host;dbname=$db_name");
ORM::configure('username', $db_user);
ORM::configure('password', $db_password);
ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'));
ORM::configure('return_result_sets', true);
if($_app_stage != 'Live'){
    ORM::configure('logging', true);
}

$result = ORM::for_table('tbl_appconfig')->find_many();
foreach ($result as $value) {
    $config[$value['setting']] = $value['value'];
}

// Idempotent schema upgrades for existing installs.
if (empty($config['schema_secondary_router_v1'])) {
    $db = ORM::get_db();
    $cols = $db->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_routers'
           AND COLUMN_NAME IN ('secondary_ip_address','secondary_username','secondary_password','secondary_enabled')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'secondary_ip_address' => "ADD COLUMN `secondary_ip_address` varchar(128) DEFAULT NULL AFTER `enabled`",
        'secondary_username'   => "ADD COLUMN `secondary_username` varchar(50) DEFAULT NULL AFTER `secondary_ip_address`",
        'secondary_password'   => "ADD COLUMN `secondary_password` varchar(60) DEFAULT NULL AFTER `secondary_username`",
        'secondary_enabled'    => "ADD COLUMN `secondary_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `secondary_password`",
    ];
    foreach ($needed as $col => $alter) {
        if (!in_array($col, $cols, true)) {
            $db->exec("ALTER TABLE `tbl_routers` $alter");
        }
    }
    $flag = ORM::for_table('tbl_appconfig')->where('setting','schema_secondary_router_v1')->find_one();
    if (!$flag) {
        $flag = ORM::for_table('tbl_appconfig')->create();
        $flag->setting = 'schema_secondary_router_v1';
    }
    $flag->value = '1';
    $flag->save();
    $config['schema_secondary_router_v1'] = '1';
}

// Credit sales tracking (renew-on-credit, mark-as-paid).
if (empty($config['schema_credit_sales_v1'])) {
    $db = ORM::get_db();
    $exists = $db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_credit_sales'"
    )->fetchColumn();
    if (!$exists) {
        $db->exec("CREATE TABLE `tbl_credit_sales` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT UNSIGNED NOT NULL,
            `username` VARCHAR(64) NOT NULL,
            `plan_name` VARCHAR(128) DEFAULT NULL,
            `bill_month` CHAR(7) DEFAULT NULL,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL DEFAULT 'due',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `paid_at` DATETIME NULL DEFAULT NULL,
            `notes` VARCHAR(255) DEFAULT NULL,
            INDEX `idx_customer_status` (`customer_id`, `status`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $flag = ORM::for_table('tbl_appconfig')->where('setting','schema_credit_sales_v1')->find_one();
    if (!$flag) {
        $flag = ORM::for_table('tbl_appconfig')->create();
        $flag->setting = 'schema_credit_sales_v1';
    }
    $flag->value = '1';
    $flag->save();
    $config['schema_credit_sales_v1'] = '1';
}

// OLT monitoring tables.
if (empty($config['schema_olt_monitoring_v1'])) {
    $db = ORM::get_db();

    // Main OLT table
    $exists = $db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_olt'"
    )->fetchColumn();
    if (!$exists) {
        $db->exec("CREATE TABLE `tbl_olt` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `type` VARCHAR(32) DEFAULT 'media',
            `snmp_community` VARCHAR(64) DEFAULT 'public',
            `snmp_version` VARCHAR(8) DEFAULT '2c',
            `web_url` VARCHAR(255) DEFAULT NULL,
            `web_user` VARCHAR(64) DEFAULT NULL,
            `web_pass` VARCHAR(128) DEFAULT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `last_polled` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_enabled` (`enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ONU registry (current state)
    $exists = $db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_olt_onus'"
    )->fetchColumn();
    if (!$exists) {
        $db->exec("CREATE TABLE `tbl_olt_onus` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `olt_id` INT UNSIGNED NOT NULL,
            `pon_port` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `onu_id` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `serial_number` VARCHAR(32) DEFAULT NULL,
            `mac_address` VARCHAR(17) DEFAULT NULL,
            `customer_id` INT UNSIGNED DEFAULT NULL,
            `description` VARCHAR(128) DEFAULT NULL,
            `status` VARCHAR(16) DEFAULT 'unknown',
            `rx_power` DECIMAL(6,2) DEFAULT NULL COMMENT 'dBm',
            `olt_rx_power` DECIMAL(6,2) DEFAULT NULL COMMENT 'dBm',
            `distance` INT UNSIGNED DEFAULT NULL COMMENT 'meters',
            `last_seen` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_olt_port_onu` (`olt_id`, `pon_port`, `onu_id`),
            INDEX `idx_customer` (`customer_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ONU samples (historical data)
    $exists = $db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_olt_onu_samples'"
    )->fetchColumn();
    if (!$exists) {
        $db->exec("CREATE TABLE `tbl_olt_onu_samples` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `olt_id` INT UNSIGNED NOT NULL,
            `pon_port` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `onu_id` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(16) DEFAULT 'unknown',
            `rx_power` DECIMAL(6,2) DEFAULT NULL COMMENT 'dBm',
            `olt_rx_power` DECIMAL(6,2) DEFAULT NULL COMMENT 'dBm',
            `distance` INT UNSIGNED DEFAULT NULL,
            `ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_ts` (`ts`),
            INDEX `idx_olt_port_onu` (`olt_id`, `pon_port`, `onu_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $flag = ORM::for_table('tbl_appconfig')->where('setting','schema_olt_monitoring_v1')->find_one();
    if (!$flag) {
        $flag = ORM::for_table('tbl_appconfig')->create();
        $flag->setting = 'schema_olt_monitoring_v1';
    }
    $flag->value = '1';
    $flag->save();
    $config['schema_olt_monitoring_v1'] = '1';
}

// Web Login URLs per endpoint.
if (empty($config['schema_router_webfig_url_v1'])) {
    $db = ORM::get_db();
    $cols = $db->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_routers'
           AND COLUMN_NAME IN ('webfig_url','secondary_webfig_url')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'webfig_url'           => "ADD COLUMN `webfig_url` varchar(255) DEFAULT NULL AFTER `secondary_enabled`",
        'secondary_webfig_url' => "ADD COLUMN `secondary_webfig_url` varchar(255) DEFAULT NULL AFTER `webfig_url`",
    ];
    foreach ($needed as $col => $alter) {
        if (!in_array($col, $cols, true)) {
            $db->exec("ALTER TABLE `tbl_routers` $alter");
        }
    }
    $flag = ORM::for_table('tbl_appconfig')->where('setting','schema_router_webfig_url_v1')->find_one();
    if (!$flag) {
        $flag = ORM::for_table('tbl_appconfig')->create();
        $flag->setting = 'schema_router_webfig_url_v1';
    }
    $flag->value = '1';
    $flag->save();
    $config['schema_router_webfig_url_v1'] = '1';
}

// Hotspot voucher validity enforcement: track first redemption + computed expiry
// so a voucher used directly at the captive portal can be expired after its plan
// validity (see system/hotspot-voucher-expiry.php).
if (empty($config['schema_voucher_expiry_v1'])) {
    $db = ORM::get_db();
    $cols = $db->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_voucher'
           AND COLUMN_NAME IN ('first_used_at','expiry')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'first_used_at' => "ADD COLUMN `first_used_at` DATETIME NULL DEFAULT NULL AFTER `status`",
        'expiry'        => "ADD COLUMN `expiry` DATETIME NULL DEFAULT NULL AFTER `first_used_at`",
    ];
    foreach ($needed as $col => $alter) {
        if (!in_array($col, $cols, true)) {
            $db->exec("ALTER TABLE `tbl_voucher` $alter");
        }
    }
    $flag = ORM::for_table('tbl_appconfig')->where('setting','schema_voucher_expiry_v1')->find_one();
    if (!$flag) {
        $flag = ORM::for_table('tbl_appconfig')->create();
        $flag->setting = 'schema_voucher_expiry_v1';
    }
    $flag->value = '1';
    $flag->save();
    $config['schema_voucher_expiry_v1'] = '1';
}

// Hotspot free-trial tracking: who started the 1-hour trial (name + mobile),
// how many times, and per-session usage/time (see api/hotspot-trial +
// system/hotspot-voucher-expiry.php).
if (empty($config['schema_hotspot_trials_v1'])) {
    $db = ORM::get_db();
    $exists = $db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_hotspot_trials'"
    )->fetchColumn();
    if (!$exists) {
        $db->exec("CREATE TABLE `tbl_hotspot_trials` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(128) DEFAULT NULL,
            `phone` VARCHAR(20) NOT NULL,
            `mac` VARCHAR(17) DEFAULT NULL,
            `ip` VARCHAR(45) DEFAULT NULL,
            `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ended_at` DATETIME NULL DEFAULT NULL,
            `bytes_in` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `bytes_out` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            INDEX `idx_phone` (`phone`),
            INDEX `idx_mac_ended` (`mac`, `ended_at`),
            INDEX `idx_started` (`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $flag = ORM::for_table('tbl_appconfig')->where('setting','schema_hotspot_trials_v1')->find_one();
    if (!$flag) {
        $flag = ORM::for_table('tbl_appconfig')->create();
        $flag->setting = 'schema_hotspot_trials_v1';
    }
    $flag->value = '1';
    $flag->save();
    $config['schema_hotspot_trials_v1'] = '1';
}

date_default_timezone_set($config['timezone']);
$_c = $config;

if($config['radius_mode']){
    ORM::configure("mysql:host=$radius_host;dbname=$radius_name", null, 'radius');
    ORM::configure('username', $radius_user, 'radius');
    ORM::configure('password', $radius_password, 'radius');
    ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'), 'radius');
    ORM::configure('return_result_sets', true, 'radius');
}

function _notify($msg, $type = 'e')
{
    $_SESSION['ntype'] = $type;
    $_SESSION['notify'] = $msg;
}

require_once('system/vendors/smarty/libs/Smarty.class.php');
$lan_file = 'system/lan/' . $config['language'] . '/common.lan.php';
require($lan_file);
$ui = new Smarty();
$ui->setTemplateDir('ui/ui/');
$ui->addTemplateDir('system/paymentgateway/ui/', 'pg');
$ui->addTemplateDir('system/plugin/ui/', 'plugin');
$ui->setCompileDir('ui/compiled/');
$ui->setConfigDir('ui/conf/');
$ui->setCacheDir('ui/cache/');
$ui->assign('app_url', APP_URL);
$ui->assign('_domain', str_replace('www.', '', parse_url(APP_URL, PHP_URL_HOST)));
define('U', APP_URL . '/index.php?_route=');
$ui->assign('_url', APP_URL . '/index.php?_route=');
$ui->assign('_path', __DIR__);
$ui->assign('_c', $config);
$ui->assign('_L', $_L);
$ui->assign('_system_menu', 'dashboard');
$ui->assign('_title', $config['CompanyName']);

function _msglog($type, $msg)
{
    $_SESSION['ntype'] = $type;
    $_SESSION['notify'] = $msg;
}

if (isset($_SESSION['notify'])) {
    $notify = $_SESSION['notify'];
    $ntype = $_SESSION['ntype'];
    if ($ntype == 's') {
        $ui->assign('notify', '<div class="alert alert-info">
		<button type="button" class="close" data-dismiss="alert">
		<span aria-hidden="true">×</span>
		</button>
		<div>' . $notify . '</div></div>');
    } else {
        $ui->assign('notify', '<div class="alert alert-danger">
		<button type="button" class="close" data-dismiss="alert">
		<span aria-hidden="true">×</span>
		</button>
		<div>' . $notify . '</div></div>');
    }
    unset($_SESSION['notify']);
    unset($_SESSION['ntype']);
}

include "autoload/Hookers.php";


//register all plugin
foreach (glob("system/plugin/*.php") as $filename)
{
    include $filename;
}

// on some server, it getting error because of slash is backwards
function _autoloader($class)
{
    if (strpos($class, '_') !== false) {
        $class = str_replace('_', DIRECTORY_SEPARATOR, $class);
        if (file_exists('autoload' . DIRECTORY_SEPARATOR . $class . '.php')) {
            include 'autoload' . DIRECTORY_SEPARATOR . $class . '.php';
        } else {
            $class = str_replace("\\", DIRECTORY_SEPARATOR, $class);
            if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'autoload' . DIRECTORY_SEPARATOR . $class . '.php'))
                include __DIR__ . DIRECTORY_SEPARATOR . 'autoload' . DIRECTORY_SEPARATOR . $class . '.php';
        }
    } else {
        if (file_exists('autoload' . DIRECTORY_SEPARATOR . $class . '.php')) {
            include 'autoload' . DIRECTORY_SEPARATOR . $class . '.php';
        } else {
            $class = str_replace("\\", DIRECTORY_SEPARATOR, $class);
            if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'autoload' . DIRECTORY_SEPARATOR . $class . '.php'))
                include __DIR__ . DIRECTORY_SEPARATOR . 'autoload' . DIRECTORY_SEPARATOR . $class . '.php';
        }
    }
}

spl_autoload_register('_autoloader');

function _auth($login = true)
{
    if (isset($_SESSION['uid'])) {
        return true;
    } else {
        if ($login) {
            r2(U . 'login');
        } else {
            return false;
        }
    }
}

function _admin($login = true)
{
    if (isset($_SESSION['aid'])) {
        return true;
    } else {
        if ($login) {
            r2(U . 'login');
        } else {
            return false;
        }
    }
}

function _raid($l)
{
    return substr(str_shuffle(str_repeat('0123456789', $l)), 0, $l);
}

function _log($description, $type = '', $userid = '0')
{
    $d = ORM::for_table('tbl_logs')->create();
    $d->date = date('Y-m-d H:i:s');
    $d->type = $type;
    $d->description = $description;
    $d->userid = $userid;
    $d->ip = $_SERVER["REMOTE_ADDR"];
    $d->save();
}

function Lang($key)
{
    global $_L, $lan_file;
    if (!empty($_L[$key])) {
        return $_L[$key];
    }
    $val = $key;
    $key = alphanumeric($key, " ");
    if (!empty($_L[$key])) {
        return $_L[$key];
    } else if (!empty($_L[str_replace(' ', '_', $key)])) {
        return $_L[str_replace(' ', '_', $key)];
    } else {
        $key = str_replace(' ', '_', $key);
        file_put_contents($lan_file, "$" . "_L['$key'] = '" . addslashes($val) . "';\n", FILE_APPEND);
        return $val;
    }
}

function alphanumeric($str, $tambahan = "")
{
    return preg_replace("/[^a-zA-Z0-9" . $tambahan . "]+/", "", $str);
}


function sendTelegram($txt)
{
    Message::sendTelegram($txt);
}

function sendSMS($phone, $txt)
{
    Message::sendSMS($phone, $txt);
}

function sendWhatsapp($phone, $txt)
{
    Message::sendWhatsapp($phone, $txt);
}


function time_elapsed_string($datetime, $full = false)
{
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// Routing Engine
$req = _get('_route');
$routes = explode('/', $req);
$ui->assign('_routes', $routes);
$handler = $routes[0];
if ($handler == '') {
    $handler = 'default';
}
$sys_render = 'system/controllers/' . $handler . '.php';
if (file_exists($sys_render)) {
    $menus = array();
    // "name" => $name,
    // "admin" => $admin,
    // "position" => $position,
    // "function" => $function
    $ui->assign('_system_menu', $routes[0]);
    foreach ($menu_registered as $menu) {
        if($menu['admin'] && _admin(false)) {
            $menus[$menu['position']] .= '<li'.(($routes[1]==$menu['function'])?' class="active"':'').'><a href="'.U.'plugin/'.$menu['function'].'">';
            if(!empty($menu['icon'])){
                $menus[$menu['position']] .= '<i class="'.$menu['icon'].'"></i>';
            }
            $menus[$menu['position']] .= '<span class="text">'.$menu['name'].'</span></a></li>';
        }else if(!$menu['admin'] && _auth(false)) {
            $menus[$menu['position']] .= '<li'.(($routes[1]==$menu['function'])?' class="active"':'').'><a href="'.U.'plugin/'.$menu['function'].'">';
            if(!empty($menu['icon'])){
                $menus[$menu['position']] .= '<i class="'.$menu['icon'].'"></i>';
            }
            $menus[$menu['position']] .= '<span class="text">'.$menu['name'].'</span></a></li>';
        }
    }
    foreach ($menus as $k => $v) {
        $ui->assign('_MENU_'.$k, $v);
    }
    unset($menus, $menu_registered);
    include($sys_render);
} else {
    r2(U.'dashboard', 'e', 'not found');
}
