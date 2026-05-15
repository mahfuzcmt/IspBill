<?php
/**
 * Traffic sample poller — runs from host cron via:
 *     docker exec phpnuxbill-app php /var/www/html/system/traffic-poller.php
 * Snapshots /queue/simple/print stats=yes into tbl_traffic_samples.
 * Retention: rows older than 7 days are deleted on each run.
 */
error_reporting(E_ERROR | E_PARSE);
spl_autoload_register(function ($c) {
    $f = '/var/www/html/system/autoload/' . str_replace('\\', '/', $c) . '.php';
    if (file_exists($f)) include $f;
});
use PEAR2\Net\RouterOS;
require '/var/www/html/config.php';

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$start = microtime(true);
$rows = 0;

try {
    $rt = $pdo->query("SELECT * FROM tbl_routers WHERE enabled=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$rt) { exit("no enabled router\n"); }
    $client = new RouterOS\Client($rt['ip_address'], $rt['username'], $rt['password']);

    $req = new RouterOS\Request('/queue/simple/print');
    $req->setArgument('stats', 'yes');
    $req->setArgument('.proplist', 'name,bytes,rate');
    $samples = [];
    foreach ($client->sendSync($req) as $r) {
        if ($r->getType() !== RouterOS\Response::TYPE_DATA) continue;
        $qname = $r->getProperty('name');
        $user  = preg_match('/^<pppoe-(.+)>$/', $qname, $m) ? $m[1] : trim($qname, '<>');
        $bytes = explode('/', $r->getProperty('bytes') ?: '0/0');
        $rate  = explode('/', $r->getProperty('rate')  ?: '0/0');
        $samples[] = [
            'username' => $user,
            'rate_in'  => (int) ($rate[0]  ?? 0),
            'rate_out' => (int) ($rate[1]  ?? 0),
            'bytes_in' => (int) ($bytes[0] ?? 0),
            'bytes_out'=> (int) ($bytes[1] ?? 0),
        ];
    }

    if ($samples) {
        $stmt = $pdo->prepare(
            "INSERT INTO tbl_traffic_samples (username, rate_in, rate_out, bytes_in, bytes_out)
             VALUES (:u, :ri, :ro, :bi, :bo)"
        );
        foreach ($samples as $s) {
            $stmt->execute([
                ':u'  => $s['username'],
                ':ri' => $s['rate_in'],
                ':ro' => $s['rate_out'],
                ':bi' => $s['bytes_in'],
                ':bo' => $s['bytes_out'],
            ]);
            $rows++;
        }
    }

    // Retention: prune > 7 days
    $deleted = $pdo->exec("DELETE FROM tbl_traffic_samples WHERE ts < NOW() - INTERVAL 7 DAY");

    $ms = (int) ((microtime(true) - $start) * 1000);
    echo date('c') . " inserted=$rows pruned=$deleted in {$ms}ms\n";
} catch (Throwable $e) {
    echo date('c') . " ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
