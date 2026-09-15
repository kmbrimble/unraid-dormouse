<?php
require '/usr/local/emhttp/plugins/dormouse/scripts/lib.php';
$config = dormouse_load_config('/boot/config/plugins/dormouse/dormouse.cfg');
$db = new SQLite3($config['db_path'], SQLITE3_OPEN_READONLY);
$status = dormouse_build_status($db, $config, '/var/run/dormouse.pid');
echo "=== zfs_24h ===\n";
echo json_encode($status['zfs_24h'], JSON_PRETTY_PRINT), "\n";
echo "=== zfs_dataset_count / zfs_last_poll_ts ===\n";
echo $status['zfs_dataset_count'], ' / ', $status['zfs_last_poll_ts'], "\n";
echo "=== one spin_events[].zfs_window (first event with a non-empty window) ===\n";
$found = false;
foreach ($status['spin_events'] as $ev) {
    if (!empty($ev['zfs_window'])) {
        echo json_encode($ev, JSON_PRETTY_PRINT), "\n";
        $found = true;
        break;
    }
}
if (!$found) {
    echo "(no spin event in this run's data had zfs_io overlap yet -- zfs_io only started recording at install; showing the most recent spin event's shape instead)\n";
    if (!empty($status['spin_events'])) {
        echo json_encode($status['spin_events'][0], JSON_PRETTY_PRINT), "\n";
    }
}
