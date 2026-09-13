<?php
/**
 * Read-only status endpoint for Dormouse.page. Plain urlencoded POST only —
 * no FormData/fetch (incompatible with the host's nginx auth_request setup)
 * and no custom CSRF check (local_prepend.php already consumed csrf_token
 * before this file ran). Must not reference STDOUT/STDERR/$argv — this file
 * is web-only but shares lib.php with the CLI daemon.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json');

$cfgFile = getenv('DORMOUSE_CFG') ?: '/boot/config/plugins/dormouse/dormouse.cfg';
$pidFile = getenv('DORMOUSE_PIDFILE') ?: '/var/run/dormouse.pid';
$config = dormouse_load_config($cfgFile);

if (!is_file($config['db_path'])) {
    echo json_encode([
        'daemon_running' => dormouse_daemon_running($pidFile),
        'error' => 'manifest database not found yet',
    ]);
    return;
}

$db = new SQLite3($config['db_path'], SQLITE3_OPEN_READONLY);
echo json_encode(dormouse_build_status($db, $config, $pidFile));
