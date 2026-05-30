<?php
/**
 * PPPoE Plans Cleanup Script
 * Removes duplicate and unwanted PPPoE plans
 * Usage: docker exec phpnuxbill php /var/www/html/system/cleanup-pppoe.php
 */

require_once dirname(__FILE__) . '/boot.php';

echo "=== PPPoE Plans Cleanup ===\n\n";

// Show current plans
echo "Current PPPoE Plans:\n";
$plans = ORM::for_table('tbl_plans')
    ->where('type', 'PPPOE')
    ->order_by_asc('name_plan')
    ->find_many();

foreach ($plans as $p) {
    echo "  ID: {$p['id']} | {$p['name_plan']} | {$p['price']} BDT | {$p['validity']} Days | Router: {$p['routers']}\n";
}
echo "\n";

// Plans to delete
$toDelete = [];

// Find duplicates with router "1" (should delete these, keep "Main PPPoE Router" ones)
$duplicates1 = ORM::for_table('tbl_plans')
    ->where('type', 'PPPOE')
    ->where('routers', '1')
    ->find_many();

foreach ($duplicates1 as $d) {
    $toDelete[] = ['id' => $d['id'], 'reason' => "Duplicate with router '1'", 'name' => $d['name_plan']];
}

// Find 100Mbps, 150Mbps, 200Mbps plans
$unwanted = ['100Mbps', '150Mbps', '200Mbps'];
foreach ($unwanted as $name) {
    $plan = ORM::for_table('tbl_plans')
        ->where('type', 'PPPOE')
        ->where('name_plan', $name)
        ->find_one();
    if ($plan) {
        $toDelete[] = ['id' => $plan['id'], 'reason' => "Unwanted plan", 'name' => $plan['name_plan']];
    }
}

if (empty($toDelete)) {
    echo "No plans to delete. Already clean!\n";
    exit(0);
}

echo "Plans to delete:\n";
foreach ($toDelete as $item) {
    echo "  - {$item['name']} (ID: {$item['id']}) - {$item['reason']}\n";
}
echo "\n";

// Delete the plans
$deleted = 0;
foreach ($toDelete as $item) {
    $plan = ORM::for_table('tbl_plans')->find_one($item['id']);
    if ($plan) {
        $plan->delete();
        echo "  Deleted: {$item['name']} (ID: {$item['id']})\n";
        $deleted++;
    }
}

echo "\nDeleted $deleted plans.\n";

// Show remaining plans
echo "\nRemaining PPPoE Plans:\n";
$plans = ORM::for_table('tbl_plans')
    ->where('type', 'PPPOE')
    ->order_by_asc('name_plan')
    ->find_many();

foreach ($plans as $p) {
    echo "  ID: {$p['id']} | {$p['name_plan']} | {$p['price']} BDT | {$p['validity']} Days | Router: {$p['routers']}\n";
}

echo "\n=== Cleanup Complete ===\n";
