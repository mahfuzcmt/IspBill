<?php
/**
 * OLT Poller — runs every minute from host cron.
 *
 * Polls OLT via SNMP to collect:
 *   - ONU registration status
 *   - Optical signal levels (RX power)
 *   - ONU online/offline status
 *   - Traffic statistics
 *
 * Supports: Media, VSOL, BDCOM, C-Data OLTs (GPON/EPON)
 *
 * Run: php /var/www/html/system/olt-poller.php
 * Cron: * * * * * php /var/www/html/system/olt-poller.php >> /var/log/olt-poller.log 2>&1
 */
error_reporting(E_ERROR | E_PARSE);

// Autoload
spl_autoload_register(function ($c) {
    $f = __DIR__ . '/autoload/' . str_replace('\\', '/', $c) . '.php';
    if (file_exists($f)) include $f;
});

require __DIR__ . '/../config.php';

$pdo = new PDO(
    "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
    $db_user,
    $db_password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$start = microtime(true);
$inserts = 0;
$errors = [];

// Common SNMP OIDs for GPON/EPON OLTs
$OID_MAP = [
    // Generic GPON OIDs (works with Media, VSOL, C-Data)
    'onu_status'     => '.1.3.6.1.4.1.17409.2.3.4.1.1.7',   // ONU registration status
    'onu_rx_power'   => '.1.3.6.1.4.1.17409.2.3.4.1.1.5',   // ONU RX optical power (0.01 dBm)
    'onu_tx_power'   => '.1.3.6.1.4.1.17409.2.3.4.1.1.4',   // ONU TX optical power
    'olt_rx_power'   => '.1.3.6.1.4.1.17409.2.3.4.1.1.3',   // OLT RX from ONU
    'onu_distance'   => '.1.3.6.1.4.1.17409.2.3.4.1.1.14',  // ONU distance (meters)
    'onu_mac'        => '.1.3.6.1.4.1.17409.2.3.4.1.1.1',   // ONU MAC address
    'onu_sn'         => '.1.3.6.1.4.1.17409.2.3.4.1.1.2',   // ONU serial number

    // Alternative OIDs for some OLTs
    'onu_desc'       => '.1.3.6.1.4.1.17409.2.3.4.1.1.17',  // ONU description
    'pon_port_status'=> '.1.3.6.1.4.1.17409.2.3.3.1.1.2',   // PON port status
];

// BDCOM OIDs (if needed)
$BDCOM_OID_MAP = [
    'onu_status'   => '.1.3.6.1.4.1.3320.101.10.1.1.26',
    'onu_rx_power' => '.1.3.6.1.4.1.3320.101.10.5.1.5',
    'olt_rx_power' => '.1.3.6.1.4.1.3320.101.10.5.1.6',
];

try {
    // Get OLT configurations
    $olts = $pdo->query("SELECT * FROM tbl_olt WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($olts)) {
        echo date('c') . " No enabled OLTs found.\n";
        exit(0);
    }

    foreach ($olts as $olt) {
        $oltId = $olt['id'];
        $oltIp = $olt['ip_address'];
        $community = $olt['snmp_community'] ?: 'public';
        $snmpVer = $olt['snmp_version'] ?: '2c';
        $oltName = $olt['name'];

        echo date('c') . " Polling OLT: $oltName ($oltIp)\n";

        // Select OID map based on OLT type
        $oids = ($olt['type'] === 'bdcom') ? $BDCOM_OID_MAP : $OID_MAP;

        // Test SNMP connectivity
        $testResult = @snmp2_get($oltIp, $community, '.1.3.6.1.2.1.1.1.0', 5000000, 2);
        if ($testResult === false) {
            $errors[] = "OLT $oltName: SNMP unreachable";
            continue;
        }

        // Walk ONU registration status
        $onuData = [];

        try {
            // Get ONU RX power (optical signal)
            $rxPowers = @snmp2_real_walk($oltIp, $community, $oids['onu_rx_power'], 10000000, 3);
            if ($rxPowers) {
                foreach ($rxPowers as $oid => $value) {
                    // Extract PON port and ONU ID from OID
                    // OID format: base.ponPort.onuId
                    preg_match('/\.(\d+)\.(\d+)$/', $oid, $matches);
                    if (count($matches) >= 3) {
                        $ponPort = (int)$matches[1];
                        $onuId = (int)$matches[2];
                        $key = "$ponPort:$onuId";

                        // Parse value (usually INTEGER: xxxx or STRING: "xxxx")
                        $rxPower = parseSnmpValue($value);
                        // Convert to dBm (most OLTs report in 0.01 dBm units)
                        $rxDbm = $rxPower / 100.0;

                        $onuData[$key]['pon_port'] = $ponPort;
                        $onuData[$key]['onu_id'] = $onuId;
                        $onuData[$key]['rx_power'] = $rxDbm;
                    }
                }
            }

            // Get OLT RX power (from ONU)
            $oltRxPowers = @snmp2_real_walk($oltIp, $community, $oids['olt_rx_power'], 10000000, 3);
            if ($oltRxPowers) {
                foreach ($oltRxPowers as $oid => $value) {
                    preg_match('/\.(\d+)\.(\d+)$/', $oid, $matches);
                    if (count($matches) >= 3) {
                        $key = $matches[1] . ':' . $matches[2];
                        if (isset($onuData[$key])) {
                            $onuData[$key]['olt_rx_power'] = parseSnmpValue($value) / 100.0;
                        }
                    }
                }
            }

            // Get ONU status
            $statuses = @snmp2_real_walk($oltIp, $community, $oids['onu_status'], 10000000, 3);
            if ($statuses) {
                foreach ($statuses as $oid => $value) {
                    preg_match('/\.(\d+)\.(\d+)$/', $oid, $matches);
                    if (count($matches) >= 3) {
                        $key = $matches[1] . ':' . $matches[2];
                        $status = parseSnmpValue($value);
                        // Status codes: 1=online, 2=offline, 3=power-off, etc.
                        if (isset($onuData[$key])) {
                            $onuData[$key]['status'] = mapOnuStatus($status);
                        } else {
                            $onuData[$key] = [
                                'pon_port' => (int)$matches[1],
                                'onu_id' => (int)$matches[2],
                                'status' => mapOnuStatus($status),
                            ];
                        }
                    }
                }
            }

            // Get ONU distance
            $distances = @snmp2_real_walk($oltIp, $community, $oids['onu_distance'], 10000000, 3);
            if ($distances) {
                foreach ($distances as $oid => $value) {
                    preg_match('/\.(\d+)\.(\d+)$/', $oid, $matches);
                    if (count($matches) >= 3) {
                        $key = $matches[1] . ':' . $matches[2];
                        if (isset($onuData[$key])) {
                            $onuData[$key]['distance'] = parseSnmpValue($value);
                        }
                    }
                }
            }

        } catch (Throwable $e) {
            $errors[] = "OLT $oltName SNMP walk: " . $e->getMessage();
        }

        // Store ONU samples
        if (!empty($onuData)) {
            $stmt = $pdo->prepare(
                "INSERT INTO tbl_olt_onu_samples
                 (olt_id, pon_port, onu_id, status, rx_power, olt_rx_power, distance)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($onuData as $onu) {
                $stmt->execute([
                    $oltId,
                    $onu['pon_port'] ?? 0,
                    $onu['onu_id'] ?? 0,
                    $onu['status'] ?? 'unknown',
                    $onu['rx_power'] ?? null,
                    $onu['olt_rx_power'] ?? null,
                    $onu['distance'] ?? null,
                ]);
                $inserts++;
            }

            // Update tbl_olt_onus with latest data
            $upsertStmt = $pdo->prepare(
                "INSERT INTO tbl_olt_onus
                 (olt_id, pon_port, onu_id, status, rx_power, olt_rx_power, distance, last_seen)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 rx_power = VALUES(rx_power),
                 olt_rx_power = VALUES(olt_rx_power),
                 distance = VALUES(distance),
                 last_seen = NOW()"
            );

            foreach ($onuData as $onu) {
                $upsertStmt->execute([
                    $oltId,
                    $onu['pon_port'] ?? 0,
                    $onu['onu_id'] ?? 0,
                    $onu['status'] ?? 'unknown',
                    $onu['rx_power'] ?? null,
                    $onu['olt_rx_power'] ?? null,
                    $onu['distance'] ?? null,
                ]);
            }
        }

        // Update OLT last_polled timestamp
        $pdo->prepare("UPDATE tbl_olt SET last_polled = NOW() WHERE id = ?")->execute([$oltId]);
    }

    // Retention: delete samples older than 7 days
    $pruned = $pdo->exec("DELETE FROM tbl_olt_onu_samples WHERE ts < NOW() - INTERVAL 7 DAY");

    $ms = (int)((microtime(true) - $start) * 1000);
    echo date('c') . " ok inserts=$inserts pruned=$pruned"
        . (count($errors) ? " errs=" . implode(' | ', $errors) : '')
        . " in {$ms}ms\n";

} catch (Throwable $e) {
    echo date('c') . " FATAL: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Parse SNMP value from various formats
 */
function parseSnmpValue($value) {
    if (preg_match('/INTEGER:\s*(-?\d+)/', $value, $m)) return (int)$m[1];
    if (preg_match('/Gauge32:\s*(\d+)/', $value, $m)) return (int)$m[1];
    if (preg_match('/Counter32:\s*(\d+)/', $value, $m)) return (int)$m[1];
    if (preg_match('/STRING:\s*"?([^"]*)"?/', $value, $m)) return trim($m[1]);
    if (preg_match('/Hex-STRING:\s*(.+)/', $value, $m)) return trim($m[1]);
    return trim($value);
}

/**
 * Map ONU status code to readable string
 */
function mapOnuStatus($code) {
    $map = [
        1 => 'online',
        2 => 'offline',
        3 => 'power-off',
        4 => 'low-signal',
        5 => 'unregistered',
    ];
    return $map[$code] ?? 'unknown';
}
