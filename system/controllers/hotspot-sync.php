<?php
/**
 * Hotspot Sync Admin Controller
 *
 * Provides UI for syncing customers to Mikrotik hotspot
 * Access: /admin/hotspot-sync/
 */

_admin();
$ui->assign('_title', 'Hotspot Sync');
$ui->assign('_system_menu', 'hotspot-sync');

$action = $routes['1'];
$admin = Admin::_info();
$ui->assign('_admin', $admin);

// Require plugin
if (!function_exists('hotspot_sync_all_customers')) {
    r2(U . 'dashboard', 'e', 'Hotspot Sync plugin not loaded');
}

switch ($action) {
    case 'sync-all':
        // Sync all customers
        $result = hotspot_sync_all_customers();
        r2(U . 'hotspot-sync', 's',
            "Sync complete: {$result['synced']} synced, {$result['failed']} failed out of {$result['total']} customers");
        break;

    case 'sync-one':
        // Sync single customer
        $customerId = (int) $routes['2'];
        if ($customerId > 0) {
            if (hotspot_sync_customer($customerId)) {
                r2(U . 'hotspot-sync', 's', "Customer ID {$customerId} synced successfully");
            } else {
                r2(U . 'hotspot-sync', 'e', "Failed to sync customer ID {$customerId}");
            }
        }
        r2(U . 'hotspot-sync', 'e', 'Invalid customer ID');
        break;

    default:
        // Show sync page
        $customers = ORM::for_table('tbl_customers')
            ->order_by_desc('id')
            ->limit(100)
            ->find_many();

        // Get router info
        $router = ORM::for_table('tbl_routers')
            ->where('enabled', 'yes')
            ->find_one();

        $ui->assign('customers', $customers);
        $ui->assign('router', $router);
        $ui->assign('_content', 'hotspot-sync');
        $ui->display('admin.tpl');
        break;
}
