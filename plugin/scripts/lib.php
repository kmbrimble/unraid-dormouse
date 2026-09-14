<?php
/**
 * Dormouse shared library — config, manifest db, and the two event sources.
 * Required by dormoused, dormouse-api.php, and tests/run.php. Must not
 * reference STDOUT, STDERR or $argv anywhere (CLAUDE.md WebGUI rule) —
 * everything here is reachable from the web endpoint too.
 */
declare(strict_types=1);

function dormouse_default_config(): array
{
    return [
        'watched_shares' => ['Content', 'Kieren', 'Teegan', 'Downloads', 'Filing Cabinet', 'Photos'],
        'pool_root' => '/mnt/snowflake',
        'cache_root' => '/mnt/cache',
        'smb_poll_seconds' => 15,
        'watch_depth' => 2,
        'watch_refresh_minutes' => 15,
        'plex_ip' => '192.168.0.13',
        'activity_retain_days' => 30,
        'db_path' => '/mnt/cache/appdata/dormouse/manifest.db',
        'pool_disk_prefix' => 'snowflake',
    ];
}

/** Parses ini-style "key=value" lines. Comments (#) and blanks ignored. */
function dormouse_parse_config_string(string $contents): array
{
    $out = [];
    foreach (explode("\n", $contents) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($key !== '') {
            $out[$key] = $value;
        }
    }
    return $out;
}

function dormouse_load_config(string $path): array
{
    $config = dormouse_default_config();
    if (!is_file($path)) {
        return $config;
    }
    $raw = dormouse_parse_config_string((string) file_get_contents($path));
    foreach ($raw as $key => $value) {
        if (!array_key_exists($key, $config)) {
            continue;
        }
        if ($key === 'watched_shares') {
            $config[$key] = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($s) => $s !== ''));
        } elseif (is_int($config[$key])) {
            $config[$key] = (int) $value;
        } else {
            $config[$key] = $value;
        }
    }
    return $config;
}

// --- Manifest db -------------------------------------------------------------

function dormouse_open_db(string $dbPath): SQLite3
{
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS activity (
        ts INTEGER NOT NULL,
        rel_path TEXT NOT NULL,
        share TEXT NOT NULL,
        client_ip TEXT NOT NULL,
        source TEXT NOT NULL,
        event TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 1
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_activity_ts ON activity(ts)');
    $db->exec('CREATE TABLE IF NOT EXISTS stats (
        key TEXT PRIMARY KEY,
        value INTEGER NOT NULL DEFAULT 0
    )');
    dormouse_migrate_activity_schema($db);
    return $db;
}

/**
 * Adds the reads_delta/writes_delta columns (Source C, 0.2.1) to an activity
 * table created by 0.2.0's schema, guarded by PRAGMA table_info so it is
 * idempotent — running it again against an already-migrated db is a no-op,
 * never a duplicate-column error. Existing rows keep NULL for both columns.
 */
function dormouse_migrate_activity_schema(SQLite3 $db): void
{
    $cols = [];
    $result = $db->query('PRAGMA table_info(activity)');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cols[$row['name']] = true;
    }
    if (!isset($cols['reads_delta'])) {
        $db->exec('ALTER TABLE activity ADD COLUMN reads_delta INTEGER');
    }
    if (!isset($cols['writes_delta'])) {
        $db->exec('ALTER TABLE activity ADD COLUMN writes_delta INTEGER');
    }
}

function dormouse_record_activity(
    SQLite3 $db,
    int $ts,
    string $relPath,
    string $share,
    string $clientIp,
    string $source,
    string $event,
    int $count = 1,
    ?int $readsDelta = null,
    ?int $writesDelta = null
): void {
    $stmt = $db->prepare('INSERT INTO activity (ts, rel_path, share, client_ip, source, event, count, reads_delta, writes_delta) VALUES (:ts, :rel_path, :share, :client_ip, :source, :event, :count, :reads_delta, :writes_delta)');
    $stmt->bindValue(':ts', $ts, SQLITE3_INTEGER);
    $stmt->bindValue(':rel_path', $relPath, SQLITE3_TEXT);
    $stmt->bindValue(':share', $share, SQLITE3_TEXT);
    $stmt->bindValue(':client_ip', $clientIp, SQLITE3_TEXT);
    $stmt->bindValue(':source', $source, SQLITE3_TEXT);
    $stmt->bindValue(':event', $event, SQLITE3_TEXT);
    $stmt->bindValue(':count', $count, SQLITE3_INTEGER);
    $stmt->bindValue(':reads_delta', $readsDelta, $readsDelta === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':writes_delta', $writesDelta, $writesDelta === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->execute();
}

function dormouse_trim_activity(SQLite3 $db, int $retainDays, ?int $nowTs = null): void
{
    $nowTs ??= time();
    $cutoff = $nowTs - ($retainDays * 86400);
    $stmt = $db->prepare('DELETE FROM activity WHERE ts < :cutoff');
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
    $stmt->execute();
}

function dormouse_stat_incr(SQLite3 $db, string $key, int $by = 1): void
{
    $stmt = $db->prepare('INSERT INTO stats (key, value) VALUES (:key, :by)
        ON CONFLICT(key) DO UPDATE SET value = value + :by2');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':by', $by, SQLITE3_INTEGER);
    $stmt->bindValue(':by2', $by, SQLITE3_INTEGER);
    $stmt->execute();
}

function dormouse_set_watch_stats(SQLite3 $db, int $watchCount): void
{
    $stmt = $db->prepare('INSERT INTO stats (key, value) VALUES (\'watch_count\', :count)
        ON CONFLICT(key) DO UPDATE SET value = :count2');
    $stmt->bindValue(':count', $watchCount, SQLITE3_INTEGER);
    $stmt->bindValue(':count2', $watchCount, SQLITE3_INTEGER);
    $stmt->execute();

    $stmt = $db->prepare('INSERT INTO stats (key, value) VALUES (\'watch_last_refresh_ts\', :ts)
        ON CONFLICT(key) DO UPDATE SET value = :ts2');
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':ts2', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function dormouse_stat_set(SQLite3 $db, string $key, int $value): void
{
    $stmt = $db->prepare('INSERT INTO stats (key, value) VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = :value2');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_INTEGER);
    $stmt->bindValue(':value2', $value, SQLITE3_INTEGER);
    $stmt->execute();
}

function dormouse_stat_get(SQLite3 $db, string $key): int
{
    $stmt = $db->prepare('SELECT value FROM stats WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? (int) $row['value'] : 0;
}

function dormouse_daemon_running(string $pidFile): bool
{
    if (!is_file($pidFile)) {
        return false;
    }
    $pid = (int) trim((string) file_get_contents($pidFile));
    return $pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0);
}

/** Read-only view for the settings page: status, watch info, per-share 24h counts, last 50 rows. */
function dormouse_build_status(SQLite3 $db, array $config, string $pidFile): array
{
    $dayAgo = time() - 86400;

    $perShare = [];
    foreach ($config['watched_shares'] as $share) {
        $perShare[$share] = ['smb_open' => 0, 'inotify_access' => 0];
    }
    $stmt = $db->prepare("SELECT share, source, event, SUM(count) AS total
        FROM activity WHERE ts >= :since GROUP BY share, source, event");
    $stmt->bindValue(':since', $dayAgo, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!isset($perShare[$row['share']])) {
            continue;
        }
        if ($row['source'] === 'smb' && $row['event'] === 'open') {
            $perShare[$row['share']]['smb_open'] += (int) $row['total'];
        }
        if ($row['source'] === 'inotify' && $row['event'] === 'access') {
            $perShare[$row['share']]['inotify_access'] += (int) $row['total'];
        }
    }

    $recent = [];
    $result = $db->query('SELECT ts, rel_path, share, client_ip, source, event, count FROM activity ORDER BY ts DESC LIMIT 50');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $recent[] = $row;
    }

    return [
        'daemon_running' => dormouse_daemon_running($pidFile),
        'watched_shares' => $config['watched_shares'],
        'watch_count' => dormouse_stat_get($db, 'watch_count'),
        'watch_last_refresh_ts' => dormouse_stat_get($db, 'watch_last_refresh_ts'),
        'overflow_count' => dormouse_stat_get($db, 'inotify_overflow_count'),
        'per_share_24h' => $perShare,
        'recent' => $recent,
        'disk_last_poll_ts' => dormouse_stat_get($db, 'disk_last_poll_ts'),
        'disk_state_disagreements' => dormouse_stat_get($db, 'disk_state_disagreements'),
        'disk_states' => dormouse_build_disk_states($db),
        'spin_events' => dormouse_build_spin_events($db),
    ];
}

// --- Source A: smbstatus -j ---------------------------------------------------

/**
 * Maps each tcon's server_id.unique_id -> client machine IP, so an open
 * file's server_id can be resolved to the connecting client without ever
 * keying on username (guests are all "nobody").
 */
function dormouse_smb_machine_map(array $tcons): array
{
    $map = [];
    foreach ($tcons as $tcon) {
        $uid = $tcon['server_id']['unique_id'] ?? null;
        $machine = $tcon['machine'] ?? '';
        if ($uid !== null) {
            $map[$uid] = $machine;
        }
    }
    return $map;
}

/**
 * Parses `smbstatus -j` JSON into a flat list of currently-open handles on
 * watched shares. Root-share handles (Plex's long-lived directory watches)
 * come back with is_dirwatch=true and must not be treated as file reads.
 *
 * @return array<int, array{share:string, rel_path:string, client_ip:string, is_dirwatch:bool}>
 */
function dormouse_parse_smbstatus_json(string $json, array $watchedShares): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    $machineMap = dormouse_smb_machine_map($data['tcons'] ?? []);
    $watched = array_flip($watchedShares);
    $out = [];
    foreach ($data['open_files'] ?? [] as $file) {
        $share = basename((string) ($file['service_path'] ?? ''));
        if (!isset($watched[$share])) {
            continue;
        }
        $relPath = (string) ($file['filename'] ?? '');
        // Samba reports a share-root directory handle's filename as "." (some
        // versions/paths as ""); treat both as the root, never guessed from
        // one live sample alone.
        $isDirwatch = $relPath === '' || $relPath === '.';
        if ($isDirwatch) {
            $relPath = '';
        }
        foreach ($file['opens'] ?? [] as $open) {
            $uid = $open['server_id']['unique_id'] ?? null;
            $clientIp = $uid !== null ? ($machineMap[$uid] ?? '') : '';
            $out[] = [
                'share' => $share,
                'rel_path' => $relPath,
                'client_ip' => $clientIp,
                'is_dirwatch' => $isDirwatch,
            ];
        }
    }
    return $out;
}

/**
 * Diffs the currently-open handle set against the previous poll's set and
 * returns the activity rows to record: 'open'/'dirwatch' for newly-seen
 * handles, 'close' for handles that disappeared. $previous and the return
 * value are keyed the same way so the caller can carry it to the next poll.
 *
 * @return array{0: array<int,array>, 1: array<string,array>} [rows, newSeenSet]
 */
function dormouse_smb_diff(array $currentHandles, array $previousSeen): array
{
    $rows = [];
    $nowSeen = [];
    foreach ($currentHandles as $h) {
        $key = $h['share'] . "\0" . $h['rel_path'] . "\0" . $h['client_ip'];
        $nowSeen[$key] = $h;
        if (!isset($previousSeen[$key])) {
            $rows[] = [
                'share' => $h['share'],
                'rel_path' => $h['rel_path'],
                'client_ip' => $h['client_ip'],
                'event' => $h['is_dirwatch'] ? 'dirwatch' : 'open',
            ];
        }
    }
    foreach ($previousSeen as $key => $h) {
        if (!isset($nowSeen[$key])) {
            $rows[] = [
                'share' => $h['share'],
                'rel_path' => $h['rel_path'],
                'client_ip' => $h['client_ip'],
                'event' => 'close',
            ];
        }
    }
    return [$rows, $nowSeen];
}

// --- Source B: inotifywait -m --------------------------------------------------

/**
 * Builds the pool-side watch list for `inotifywait --fromfile`: each watched
 * share directory plus subdirectories down to $depth. This is the ONE
 * deliberate exception to "never stat pool paths on a timer" (CLAUDE.md) —
 * it must stay bounded to $depth and its caller must log duration + count.
 *
 * @return string[] absolute directory paths
 */
function dormouse_build_watch_dirs(string $poolRoot, array $shares, int $depth): array
{
    $dirs = [];
    foreach ($shares as $share) {
        $root = $poolRoot . '/' . $share;
        if (is_dir($root)) {
            dormouse_collect_dirs($root, $depth, $dirs);
        }
    }
    return $dirs;
}

function dormouse_collect_dirs(string $dir, int $depthRemaining, array &$out): void
{
    $out[] = $dir;
    if ($depthRemaining <= 0) {
        return;
    }
    $entries = @scandir($dir);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            dormouse_collect_dirs($path, $depthRemaining - 1, $out);
        }
    }
}

/**
 * Parses one line of `inotifywait -m --format '%w|%f|%e'` output.
 * Returns null for lines that don't parse, and for IN_Q_OVERFLOW is
 * reported separately via dormouse_inotify_line_is_overflow().
 *
 * ponytail: a literal "|" inside a filename would corrupt this split (none
 * of the watched libraries use one); switch to a NUL-separated --format if
 * that ever stops holding.
 *
 * @return array{dir:string, file:string, events:string[]}|null
 */
function dormouse_parse_inotify_line(string $line): ?array
{
    $parts = explode('|', rtrim($line, "\r\n"), 3);
    if (count($parts) !== 3) {
        return null;
    }
    [$dir, $file, $eventStr] = $parts;
    return [
        'dir' => $dir,
        'file' => $file,
        'events' => explode(',', $eventStr),
    ];
}

function dormouse_inotify_line_is_overflow(string $line): bool
{
    return str_contains($line, 'OVERFLOW');
}

/** Buffers one ACCESS event for coalescing; caller flushes expired entries separately. */
function dormouse_accumulate_access(array &$buffer, string $share, string $relPath, int $now): void
{
    $key = $share . "\0" . $relPath;
    if (!isset($buffer[$key])) {
        $buffer[$key] = ['share' => $share, 'rel_path' => $relPath, 'first_ts' => $now, 'count' => 0];
    }
    $buffer[$key]['count']++;
}

/** Flushes (and removes) buffered access entries whose window has elapsed, recording one row each. */
function dormouse_flush_expired_access(SQLite3 $db, array &$buffer, int $now, int $windowSeconds): void
{
    foreach ($buffer as $key => $entry) {
        if ($now - $entry['first_ts'] >= $windowSeconds) {
            dormouse_record_activity($db, $now, $entry['rel_path'], $entry['share'], '', 'inotify', 'access', $entry['count']);
            unset($buffer[$key]);
        }
    }
}

/** Flushes every buffered entry regardless of window — used on shutdown. */
function dormouse_flush_all_access(SQLite3 $db, array &$buffer, int $now): void
{
    foreach ($buffer as $entry) {
        dormouse_record_activity($db, $now, $entry['rel_path'], $entry['share'], '', 'inotify', 'access', $entry['count']);
    }
    $buffer = [];
}

/**
 * Maps an absolute pool-side directory to [share, rel_path_prefix], or null
 * if it's outside pool_root or not a watched share. String-only — never
 * stat()s the path (the caller's own refresh walk already did that once,
 * deliberately; this function must not add a second one per event).
 */
function dormouse_inotify_dir_to_share(string $dir, string $poolRoot, array $watchedShares): ?array
{
    $poolRoot = rtrim($poolRoot, '/');
    // inotifywait's %w always ends in a trailing slash for a directory watch
    // (confirmed live: `%w|%f|%e` against the pool prints
    // ".../.dormouse-probe-fmt/|a b.txt|OPEN") — strip it before splitting,
    // or every event inside a subdirectory gets a doubled slash in rel_path.
    $dir = rtrim($dir, '/');
    if (!str_starts_with($dir, $poolRoot . '/')) {
        return null;
    }
    $rel = ltrim(substr($dir, strlen($poolRoot) + 1), '/');
    $slash = strpos($rel, '/');
    $share = $slash === false ? $rel : substr($rel, 0, $slash);
    $subPath = $slash === false ? '' : substr($rel, $slash + 1);
    if (!in_array($share, $watchedShares, true)) {
        return null;
    }
    return [$share, $subPath];
}

// --- Source C: disks.ini spin state --------------------------------------------

/**
 * Parses /var/local/emhttp/disks.ini's ["section"]\nkey="value" shape into
 * sections keyed by name. Tolerant of a trailing \r per line even though a
 * live capture on 2026-09-14 showed plain LF only (CLAUDE.md) — cheap
 * insurance against a future emhttpd build changing that.
 *
 * @return array<string, array<string,string>>
 */
function dormouse_parse_disks_ini(string $contents): array
{
    $sections = [];
    $current = null;
    foreach (explode("\n", $contents) as $line) {
        $line = trim($line, " \t\r");
        if ($line === '') {
            continue;
        }
        if (preg_match('/^\["(.*)"\]$/', $line, $m)) {
            $current = $m[1];
            $sections[$current] = [];
            continue;
        }
        if ($current === null) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = substr($line, 0, $eq);
        $value = substr($line, $eq + 1);
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        }
        $sections[$current][$key] = $value;
    }
    return $sections;
}

/** Section names belonging to the pool, i.e. starting with $prefix (default "snowflake"). */
function dormouse_pool_disk_names(array $sections, string $prefix): array
{
    $names = [];
    foreach (array_keys($sections) as $name) {
        if (str_starts_with($name, $prefix)) {
            $names[] = $name;
        }
    }
    sort($names);
    return $names;
}

/** A delta that would come out negative means the counter reset/wrapped — record NULL, never a huge unsigned number. */
function dormouse_disk_delta(int $current, int $previous): ?int
{
    $delta = $current - $previous;
    return $delta < 0 ? null : $delta;
}

/**
 * Advances disk spin-state tracking by one poll tick. Disks unseen in
 * $prevState get a baseline 'state' row (daemon startup). A spundown
 * transition (0->1 spindown, 1->0 spinup) gets one row carrying the
 * reads/writes deltas since the previous tick. No change: no row.
 *
 * @param array<string,array{spundown:int,numReads:int,numWrites:int,device:string}> $prevState
 * @param array<string,array<string,string>> $sections raw dormouse_parse_disks_ini() output
 * @param string[] $poolDisks section names to track
 * @return array{0: array<int,array{rel_path:string,event:string,reads_delta:?int,writes_delta:?int}>, 1: array<string,array>}
 */
function dormouse_disk_poll_tick(array $prevState, array $sections, array $poolDisks): array
{
    $rows = [];
    $newState = [];
    foreach ($poolDisks as $name) {
        if (!isset($sections[$name])) {
            continue;
        }
        $sec = $sections[$name];
        $spundown = (int) ($sec['spundown'] ?? 0);
        $numReads = (int) ($sec['numReads'] ?? 0);
        $numWrites = (int) ($sec['numWrites'] ?? 0);
        $device = (string) ($sec['device'] ?? '');
        $relPath = $name . '/' . $device;

        if (!isset($prevState[$name])) {
            $rows[] = ['rel_path' => $relPath, 'event' => 'state', 'reads_delta' => null, 'writes_delta' => null];
        } elseif ($prevState[$name]['spundown'] !== $spundown) {
            $rows[] = [
                'rel_path' => $relPath,
                'event' => $spundown === 1 ? 'spindown' : 'spinup',
                'reads_delta' => dormouse_disk_delta($numReads, $prevState[$name]['numReads']),
                'writes_delta' => dormouse_disk_delta($numWrites, $prevState[$name]['numWrites']),
            ];
        }

        $newState[$name] = ['spundown' => $spundown, 'numReads' => $numReads, 'numWrites' => $numWrites, 'device' => $device];
    }
    return [$rows, $newState];
}

/** True if disks.ini's spundown flag agrees with a read-only smartctl standby probe, for the transition cross-check. */
function dormouse_smartctl_agrees(bool $disksIniSpundown, bool $smartctlStandby): bool
{
    return $disksIniSpundown === $smartctlStandby;
}

/**
 * Runs `smartctl -n standby -i` against a device, read-only (rc 2 = standby,
 * rc 0 = active, without waking the disk — verified live 2026-09-14). Only
 * ever called on a detected transition, never as a regular poll (CLAUDE.md).
 * Returns null if smartctl isn't installed or the probe itself errored.
 */
function dormouse_smartctl_standby(string $device): ?bool
{
    $binary = trim((string) shell_exec('command -v smartctl 2>/dev/null'));
    if ($binary === '') {
        return null;
    }
    exec(sprintf('smartctl -n standby -i %s >/dev/null 2>&1', escapeshellarg('/dev/' . $device)), $out, $rc);
    if ($rc === 2) {
        return true;
    }
    if ($rc === 0) {
        return false;
    }
    return null;
}

/** Distinct activity rows (excluding other disk rows) in [$fromTs, $toTs). */
function dormouse_activity_window(SQLite3 $db, int $fromTs, int $toTs): array
{
    $stmt = $db->prepare("SELECT DISTINCT share, rel_path, source, event, client_ip FROM activity
        WHERE ts >= :from AND ts < :to AND source != 'disk'");
    $stmt->bindValue(':from', $fromTs, SQLITE3_INTEGER);
    $stmt->bindValue(':to', $toTs, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

/** Last $limit disk spin transitions, each with the distinct activity in the 60s window before and after it. */
function dormouse_build_spin_events(SQLite3 $db, int $limit = 30): array
{
    $stmt = $db->prepare("SELECT ts, rel_path, event, reads_delta, writes_delta FROM activity
        WHERE source = 'disk' AND event IN ('spinup', 'spindown')
        ORDER BY ts DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $events = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ts = (int) $row['ts'];
        $events[] = [
            'ts' => $ts,
            'disk' => $row['rel_path'],
            'event' => $row['event'],
            'reads_delta' => $row['reads_delta'] !== null ? (int) $row['reads_delta'] : null,
            'writes_delta' => $row['writes_delta'] !== null ? (int) $row['writes_delta'] : null,
            'before' => dormouse_activity_window($db, $ts - 60, $ts),
            'after' => dormouse_activity_window($db, $ts, $ts + 60),
        ];
    }
    return $events;
}

/** Disk names ever seen in the activity table (source='disk'), from their "name/device" rel_path. */
function dormouse_known_disks(SQLite3 $db): array
{
    $names = [];
    $result = $db->query("SELECT DISTINCT rel_path FROM activity WHERE source = 'disk'");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $slash = strpos($row['rel_path'], '/');
        $names[$slash === false ? $row['rel_path'] : substr($row['rel_path'], 0, $slash)] = true;
    }
    $names = array_keys($names);
    sort($names);
    return $names;
}

/** Per-disk current spundown flag and the timestamp it was last set, from the disk_state_* stats keys dormoused maintains every tick. */
function dormouse_build_disk_states(SQLite3 $db): array
{
    $out = [];
    foreach (dormouse_known_disks($db) as $name) {
        $since = dormouse_stat_get($db, "disk_state_{$name}_since");
        $out[$name] = [
            'spundown' => (bool) dormouse_stat_get($db, "disk_state_{$name}"),
            'since' => $since ?: null,
        ];
    }
    return $out;
}
