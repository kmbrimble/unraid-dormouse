<?php
declare(strict_types=1);

require __DIR__ . '/../scripts/version-sorts-after.php';
require __DIR__ . '/../plugin/scripts/lib.php';

$failures = [];
$passed = 0;

function t(string $name, callable $fn): void
{
    global $failures, $passed;
    try {
        $fn();
        $passed++;
    } catch (\Throwable $e) {
        $failures[] = sprintf("%s\n    %s: %s", $name, get_class($e), $e->getMessage());
    }
}

function assert_true($cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $msg !== '' ? $msg . ': ' : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

$repoRoot = dirname(__DIR__);
$plgPath = $repoRoot . '/dormouse.plg';
$plgRaw = file_get_contents($plgPath);

// --- .plg structure -------------------------------------------------------

t('plg parses as XML with entities resolved', function () use ($plgPath) {
    $xml = simplexml_load_file($plgPath, \SimpleXMLElement::class, LIBXML_NOENT);
    assert_true($xml !== false, 'dormouse.plg failed to parse as XML');
    assert_eq('dormouse', (string) $xml['name']);
    assert_eq('kmbrimble', (string) $xml['author']);
    assert_eq('Settings/Dormouse', (string) $xml['launch']);
    assert_eq('7.0', (string) $xml['min']);
    assert_true((string) $xml['version'] !== '', 'version entity did not resolve');
    assert_true((string) $xml['pluginURL'] !== '', 'pluginURL entity did not resolve');
});

t('plg carries a CHANGES entry matching the version entity', function () use ($plgRaw) {
    preg_match('/<!ENTITY version\s+"([^"]+)">/', $plgRaw, $m);
    assert_true(isset($m[1]), 'could not extract version entity');
    $version = $m[1];
    assert_true(
        str_contains($plgRaw, "###$version"),
        "no ###$version entry found in <CHANGES>"
    );
});

t('the .txz FILE block has an MD5 and no sidecar/md5 FILE exists', function () use ($plgPath, $plgRaw) {
    $xml = simplexml_load_file($plgPath, \SimpleXMLElement::class, LIBXML_NOENT);
    $txzFile = null;
    foreach ($xml->FILE as $file) {
        if (str_ends_with((string) $file['Name'], '.txz')) {
            $txzFile = $file;
        }
    }
    assert_true($txzFile !== null, 'no FILE block found for the .txz');
    assert_true((bool) preg_match('/^[a-f0-9]{32}$/', (string) $txzFile->MD5), 'MD5 on the .txz FILE block is not a 32-char hex string');
    assert_true(!str_contains($plgRaw, '.md5'), 'found a reference to a .md5 sidecar file');
});

// --- strcmp version guard ---------------------------------------------------

t('install block stops the daemon before upgradepkg, so an upgrade cannot leave the old process running', function () use ($plgRaw) {
    $stopPos = strpos($plgRaw, 'rc.dormouse stop');
    $upgradePos = strpos($plgRaw, 'upgradepkg --install-new');
    assert_true($stopPos !== false, 'no rc.dormouse stop call found');
    assert_true($upgradePos !== false, 'no upgradepkg call found');
    assert_true($stopPos < $upgradePos, 'rc.dormouse stop must run before upgradepkg, or an upgrade leaves the old daemon running under the new files');
});

t('version guard accepts 0.1.0 -> 0.1.1', function () {
    assert_true(version_sorts_after('0.1.0', '0.1.1'));
});

t('version guard rejects 0.1.9 -> 0.1.10 (strcmp, not semver)', function () {
    assert_true(!version_sorts_after('0.1.9', '0.1.10'));
});

t('version guard rejects 2026.08.25 -> 1.0.0', function () {
    assert_true(!version_sorts_after('2026.08.25', '1.0.0'));
});

t('version guard accepts 0.1.9 -> 0.2.0', function () {
    assert_true(version_sorts_after('0.1.9', '0.2.0'));
});

// --- plugin/README.md -------------------------------------------------------

t('plugin/README.md starts with **Dormouse** and has no heading lines', function () use ($repoRoot) {
    $readme = file_get_contents($repoRoot . '/plugin/README.md');
    assert_true(str_starts_with($readme, "**Dormouse**"), 'README does not start with **Dormouse**');
    foreach (explode("\n", $readme) as $line) {
        assert_true(!str_starts_with($line, '#'), "README contains a heading line: $line");
    }
});

// --- shebangs ---------------------------------------------------------------

t('every script has a shebang', function () use ($repoRoot) {
    $scripts = [
        $repoRoot . '/plugin/scripts/rc.dormouse',
        $repoRoot . '/plugin/scripts/dormoused',
        $repoRoot . '/scripts/build-plugin.sh',
        $repoRoot . '/scripts/install-on-host.sh',
        $repoRoot . '/scripts/uninstall-on-host.sh',
    ];
    foreach ($scripts as $script) {
        $first = fgets(fopen($script, 'r'));
        assert_true(str_starts_with($first, '#!'), "$script has no shebang");
    }
});

// --- build script output -----------------------------------------------------

t('build-plugin.sh produces a txz with the expected layout and executable bits', function () use ($repoRoot) {
    $version = '0.0.0-test';
    $out = [];
    $rc = 0;
    exec(sprintf('bash %s %s 2>&1', escapeshellarg($repoRoot . '/scripts/build-plugin.sh'), escapeshellarg($version)), $out, $rc);
    assert_eq(0, $rc, "build-plugin.sh failed:\n" . implode("\n", $out));

    $txz = $repoRoot . "/dist/dormouse-$version.txz";
    assert_true(file_exists($txz), 'txz was not created');

    $listing = [];
    exec(sprintf('tar -tvJf %s', escapeshellarg($txz)), $listing, $rc);
    assert_eq(0, $rc, 'tar failed to list txz contents');
    $blob = implode("\n", $listing);

    foreach ([
        'usr/local/emhttp/plugins/dormouse/README.md',
        'usr/local/emhttp/plugins/dormouse/Dormouse.page',
        'usr/local/emhttp/plugins/dormouse/scripts/rc.dormouse',
        'usr/local/emhttp/plugins/dormouse/scripts/dormoused',
    ] as $path) {
        assert_true(str_contains($blob, $path), "txz missing $path");
    }

    foreach ($listing as $line) {
        if (str_contains($line, 'scripts/rc.dormouse') || str_contains($line, 'scripts/dormoused')) {
            assert_true((bool) preg_match('/^-rwx/', $line), "not executable in txz: $line");
        }
    }

    @unlink($txz);
});

// --- rc.dormouse behaviour ----------------------------------------------------

t('rc.dormouse start/status/stop works against a temp pid/log path', function () use ($repoRoot) {
    $tmpDir = sys_get_temp_dir() . '/dormouse-test-' . uniqid();
    mkdir($tmpDir);
    $pidFile = "$tmpDir/dormouse.pid";
    $logFile = "$tmpDir/dormouse.log";
    $runDir = "$tmpDir/run";
    $cfgFile = "$tmpDir/dormouse.cfg";
    file_put_contents($cfgFile, "pool_root=$tmpDir/pool\ncache_root=$tmpDir/cache\ndb_path=$tmpDir/manifest.db\nwatched_shares=Test\n");
    $rc = $repoRoot . '/plugin/scripts/rc.dormouse';
    $env = sprintf(
        'DORMOUSE_PIDFILE=%s DORMOUSE_LOG=%s DORMOUSE_CFG=%s DORMOUSE_RUNDIR=%s',
        escapeshellarg($pidFile),
        escapeshellarg($logFile),
        escapeshellarg($cfgFile),
        escapeshellarg($runDir)
    );

    exec("$env bash $rc start", $out, $exit);
    assert_eq(0, $exit, 'start failed');
    usleep(200000);
    assert_true(file_exists($pidFile), 'pid file not created after start');

    exec("$env bash $rc status", $out, $exit);
    assert_eq(0, $exit, 'status should report running after start');

    exec("$env bash $rc start", $out, $exit);
    assert_eq(0, $exit, 'start should be idempotent when already running');

    exec("$env bash $rc stop", $out, $exit);
    assert_eq(0, $exit, 'stop failed');
    assert_true(!file_exists($pidFile), 'pid file should be removed after stop');

    exec("$env bash $rc status", $out, $exit);
    assert_eq(1, $exit, 'status should report stopped after stop');

    exec('rm -rf ' . escapeshellarg($tmpDir));
});

// --- Phase 2: config -----------------------------------------------------------

t('config parsing applies defaults for absent keys and parses shares with spaces', function () {
    $cfg = dormouse_parse_config_string("watched_shares=Content,Filing Cabinet,Photos\nsmb_poll_seconds=30\n");
    assert_eq('Content,Filing Cabinet,Photos', $cfg['watched_shares']);
    assert_eq('30', $cfg['smb_poll_seconds']);
});

t('config parsing ignores comments and blank lines', function () {
    $cfg = dormouse_parse_config_string("# a comment\n\nfoo=bar\n   # indented comment\n");
    assert_eq(['foo' => 'bar'], $cfg);
});

t('dormouse_load_config reads a real file and splits watched_shares on commas, preserving spaces', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'dormouse-cfg-');
    file_put_contents($tmp, "watched_shares=Content,Filing Cabinet,Photos\nwatch_depth=3\n");
    $cfg = dormouse_load_config($tmp);
    assert_eq(['Content', 'Filing Cabinet', 'Photos'], $cfg['watched_shares']);
    assert_true(in_array('Filing Cabinet', $cfg['watched_shares'], true), 'share with a space did not survive parsing');
    assert_eq(3, $cfg['watch_depth']);
    assert_eq('/mnt/snowflake', $cfg['pool_root'], 'unset key should keep its default');
    unlink($tmp);
});

t('dormouse_load_config returns full defaults for a missing file', function () {
    $cfg = dormouse_load_config('/nonexistent/path/dormouse.cfg');
    assert_eq(dormouse_default_config(), $cfg);
});

t('the install block\'s own placeholder-detection grep rejects the 0.1.0 comment-only cfg and accepts a real one', function () {
    $placeholder = tempnam(sys_get_temp_dir(), 'dormouse-placeholder-');
    file_put_contents($placeholder, "# Dormouse configuration - Phase 1 scaffold, no settings yet.\n");
    exec('grep -qE ' . escapeshellarg('^[A-Za-z_]+=') . ' ' . escapeshellarg($placeholder), $o1, $rc1);
    assert_eq(1, $rc1, 'placeholder cfg must NOT match the key=value grep');
    unlink($placeholder);

    $real = tempnam(sys_get_temp_dir(), 'dormouse-real-');
    file_put_contents($real, "watched_shares=Content\n");
    exec('grep -qE ' . escapeshellarg('^[A-Za-z_]+=') . ' ' . escapeshellarg($real), $o2, $rc2);
    assert_eq(0, $rc2, 'a real cfg with key=value lines must match the grep');
    unlink($real);
});

t('dormouse.plg only rewrites the cfg when no real key=value line is present', function () use ($plgRaw) {
    assert_true(
        str_contains($plgRaw, "grep -qE '^[A-Za-z_]+=' \"\$CFG\""),
        'install block must detect a placeholder cfg (no key=value lines) before overwriting it'
    );
    assert_true(str_contains($plgRaw, 'watched_shares=Content,Kieren,Teegan,Downloads,Filing Cabinet,Photos'));
});

// --- Phase 2: manifest db -------------------------------------------------------

t('schema creation is idempotent and uses WAL mode', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'dormouse-db-');
    unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';
    $db1 = dormouse_open_db($tmp);
    $db1->close();
    $db2 = dormouse_open_db($tmp); // must not throw on existing schema
    $mode = $db2->querySingle('PRAGMA journal_mode');
    assert_eq('wal', strtolower((string) $mode));
    $db2->close();
    unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
});

t('dormouse_open_db creates the appdata directory if absent', function () {
    $dir = sys_get_temp_dir() . '/dormouse-appdata-' . uniqid();
    $dbPath = "$dir/manifest.db";
    assert_true(!is_dir($dir));
    $db = dormouse_open_db($dbPath);
    assert_true(is_dir($dir));
    $db->close();
    exec('rm -rf ' . escapeshellarg($dir));
});

t('activity trim removes rows older than the retention window, keeps newer ones', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'dormouse-trim-');
    unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';
    $db = dormouse_open_db($tmp);
    $now = 1_000_000_000;
    dormouse_record_activity($db, $now - (40 * 86400), 'old.mkv', 'Content', '', 'inotify', 'open');
    dormouse_record_activity($db, $now - (5 * 86400), 'new.mkv', 'Content', '', 'inotify', 'open');
    dormouse_trim_activity($db, 30, $now);
    $count = (int) $db->querySingle('SELECT COUNT(*) FROM activity');
    assert_eq(1, $count);
    $remaining = $db->querySingle('SELECT rel_path FROM activity');
    assert_eq('new.mkv', $remaining);
    $db->close();
    unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
});

// --- Phase 2: Source A — smbstatus -----------------------------------------------

t('smbstatus JSON parses watched-share opens, ignores non-watched shares, flags root handle as dirwatch', function () use ($repoRoot) {
    $json = file_get_contents($repoRoot . '/tests/fixtures/smbstatus.json');
    $handles = dormouse_parse_smbstatus_json($json, ['Content', 'Kieren', 'Teegan', 'Downloads', 'Filing Cabinet', 'Photos']);

    assert_eq(2, count($handles), 'Movies is not watched and must be filtered out');

    $root = null;
    $file = null;
    foreach ($handles as $h) {
        if ($h['rel_path'] === '') {
            $root = $h;
        } else {
            $file = $h;
        }
    }
    assert_true($root !== null, 'expected a root-directory handle');
    assert_true($root['is_dirwatch'], 'root handle must be flagged is_dirwatch');
    assert_eq('198.51.100.5', $root['client_ip']);

    assert_true($file !== null, 'expected a real file handle');
    assert_true(!$file['is_dirwatch']);
    assert_eq('Content', $file['share']);
    assert_eq('Example Show/Example Show Season 1/Example Show - S01E01 - Pilot.mkv', $file['rel_path']);
    assert_eq('192.0.2.10', $file['client_ip'], 'client IP must be resolved via tcon.machine, never username');
});

t('smb diff records open on first sight, dirwatch for root handles, and close when a handle disappears', function () {
    $poll1 = [
        ['share' => 'Content', 'rel_path' => '', 'client_ip' => '198.51.100.5', 'is_dirwatch' => true],
        ['share' => 'Content', 'rel_path' => 'show/ep1.mkv', 'client_ip' => '192.0.2.10', 'is_dirwatch' => false],
    ];
    [$rows1, $seen1] = dormouse_smb_diff($poll1, []);
    $events1 = array_column($rows1, 'event');
    sort($events1);
    assert_eq(['dirwatch', 'open'], $events1);

    // second poll: same handles, no new rows expected
    [$rows2, $seen2] = dormouse_smb_diff($poll1, $seen1);
    assert_eq([], $rows2, 'no change between polls should produce no rows');

    // third poll: the file handle disappeared
    $poll3 = [
        ['share' => 'Content', 'rel_path' => '', 'client_ip' => '198.51.100.5', 'is_dirwatch' => true],
    ];
    [$rows3, $seen3] = dormouse_smb_diff($poll3, $seen2);
    assert_eq(1, count($rows3));
    assert_eq('close', $rows3[0]['event']);
    assert_eq('show/ep1.mkv', $rows3[0]['rel_path']);
});

// --- Phase 2: Source B — inotifywait ----------------------------------------------

t('inotify line parsing handles paths with spaces via the pipe-separated format', function () {
    $parsed = dormouse_parse_inotify_line('/mnt/snowflake/Content/Example Show/|a b.txt|OPEN');
    assert_true($parsed !== null);
    assert_eq('/mnt/snowflake/Content/Example Show/', $parsed['dir']);
    assert_eq('a b.txt', $parsed['file']);
    assert_eq(['OPEN'], $parsed['events']);
});

t('inotify line parsing splits multiple comma-joined events', function () {
    $parsed = dormouse_parse_inotify_line('/mnt/snowflake/Content/|s01e01.mkv|CLOSE_NOWRITE,CLOSE');
    assert_eq(['CLOSE_NOWRITE', 'CLOSE'], $parsed['events']);
});

t('an IN_Q_OVERFLOW line is detected as overflow, not parsed as a normal event', function () {
    assert_true(dormouse_inotify_line_is_overflow('/mnt/snowflake/Content/|s01e01.mkv|IN_Q_OVERFLOW'));
    assert_true(!dormouse_inotify_line_is_overflow('/mnt/snowflake/Content/|s01e01.mkv|OPEN'));
});

t('malformed inotify lines return null instead of throwing', function () {
    assert_true(dormouse_parse_inotify_line('garbage no pipes here') === null);
});

t('dormouse_inotify_dir_to_share maps a pool path to share + sub-path, rejects unwatched shares and paths outside pool_root', function () {
    $shares = ['Content', 'Filing Cabinet'];
    assert_eq(['Content', 'Example Show/Season 1'], dormouse_inotify_dir_to_share('/mnt/snowflake/Content/Example Show/Season 1', '/mnt/snowflake', $shares));
    assert_eq(['Filing Cabinet', ''], dormouse_inotify_dir_to_share('/mnt/snowflake/Filing Cabinet', '/mnt/snowflake', $shares));
    assert_true(dormouse_inotify_dir_to_share('/mnt/snowflake/Movies', '/mnt/snowflake', $shares) === null, 'unwatched share must be rejected');
    assert_true(dormouse_inotify_dir_to_share('/mnt/cache/Content', '/mnt/snowflake', $shares) === null, 'path outside pool_root must be rejected');
});

t('dormouse_inotify_dir_to_share strips the trailing slash inotifywait\'s %w always includes', function () {
    // confirmed live against the pool: `inotifywait ... --format '%w|%f|%e'`
    // prints dir with a trailing slash even for a nested subdirectory watch.
    $shares = ['Content'];
    assert_eq(
        ['Content', 'Example Show/Season 1'],
        dormouse_inotify_dir_to_share('/mnt/snowflake/Content/Example Show/Season 1/', '/mnt/snowflake', $shares),
        'a trailing slash on a nested dir must not produce a doubled slash in the sub-path'
    );
    assert_eq(['Content', ''], dormouse_inotify_dir_to_share('/mnt/snowflake/Content/', '/mnt/snowflake', $shares));
});

t('watch-list generation is bounded by depth against a real temp tree', function () {
    $root = sys_get_temp_dir() . '/dormouse-watch-' . uniqid();
    mkdir("$root/Content/Show/Season 1/deep", 0755, true);
    mkdir("$root/Content/Show2", 0755, true);
    mkdir("$root/NotWatched/x", 0755, true);

    $dirs = dormouse_build_watch_dirs($root, ['Content'], 2);

    assert_true(in_array("$root/Content", $dirs, true));
    assert_true(in_array("$root/Content/Show", $dirs, true));
    assert_true(in_array("$root/Content/Show/Season 1", $dirs, true), 'share names with spaces must survive path building');
    assert_true(!in_array("$root/Content/Show/Season 1/deep", $dirs, true), 'watch_depth=2 must not descend a 3rd level');
    assert_true(!in_array("$root/NotWatched", $dirs, true), 'unwatched shares must not be walked at all');

    exec('rm -rf ' . escapeshellarg($root));
});

t('access events coalesce into one activity row per file per window, with a count', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'dormouse-coalesce-');
    unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';
    $db = dormouse_open_db($tmp);
    $buffer = [];
    $t0 = 1_000_000_000;
    for ($i = 0; $i < 20; $i++) {
        dormouse_accumulate_access($buffer, 'Content', 'show/ep1.mkv', $t0 + $i);
    }
    // still within the 60s window: nothing flushed yet
    dormouse_flush_expired_access($db, $buffer, $t0 + 30, 60);
    assert_eq(0, (int) $db->querySingle('SELECT COUNT(*) FROM activity'));

    // window elapsed: exactly one row, with the accumulated count
    dormouse_flush_expired_access($db, $buffer, $t0 + 61, 60);
    assert_eq(1, (int) $db->querySingle('SELECT COUNT(*) FROM activity'));
    assert_eq(20, (int) $db->querySingle('SELECT count FROM activity'));

    $db->close();
    unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
});

t('shutdown flushes buffered access counts even if the window has not elapsed', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'dormouse-flushall-');
    unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';
    $db = dormouse_open_db($tmp);
    $buffer = [];
    dormouse_accumulate_access($buffer, 'Content', 'show/ep1.mkv', time());
    dormouse_flush_all_access($db, $buffer, time());
    assert_eq(1, (int) $db->querySingle('SELECT COUNT(*) FROM activity'));
    assert_eq([], $buffer);
    $db->close();
    unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
});

// --- Phase 2: web endpoint ------------------------------------------------------

t('dormouse-api.php returns an error JSON instead of a fatal when the db is corrupt', function () use ($repoRoot) {
    $tmpDir = sys_get_temp_dir() . '/dormouse-api-' . uniqid();
    mkdir($tmpDir);
    $dbPath = "$tmpDir/manifest.db";
    file_put_contents($dbPath, 'not a sqlite file');
    $cfgFile = "$tmpDir/dormouse.cfg";
    file_put_contents($cfgFile, "db_path=$dbPath\n");

    $env = sprintf('DORMOUSE_CFG=%s DORMOUSE_PIDFILE=%s', escapeshellarg($cfgFile), escapeshellarg("$tmpDir/dormouse.pid"));
    exec("$env php " . escapeshellarg($repoRoot . '/plugin/scripts/dormouse-api.php'), $out, $exit);
    assert_eq(0, $exit, 'endpoint must exit 0 even when the db is corrupt');
    $json = json_decode(implode("\n", $out), true);
    assert_true(is_array($json) && isset($json['error']), 'expected an error JSON body, got: ' . implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmpDir));
});

// --- Phase 2.1: Source C — disks.ini parsing --------------------------------------

t('disks.ini parsing splits sections and strips quotes, against the sanitised live-shaped fixture', function () use ($repoRoot) {
    $contents = file_get_contents($repoRoot . '/tests/fixtures/disks.ini');
    $sections = dormouse_parse_disks_ini($contents);
    assert_true(isset($sections['snowflake']), 'expected a snowflake section');
    assert_eq('sdc', $sections['snowflake']['device']);
    assert_eq('0', $sections['snowflake']['spundown']);
    assert_eq('1640220', $sections['snowflake']['numReads']);
    assert_true(isset($sections['cache']), 'expected a cache section to also parse');
    assert_true(isset($sections['snowflake6']));
});

t('disks.ini parsing tolerates CRLF line endings', function () {
    $sections = dormouse_parse_disks_ini("[\"snowflake\"]\r\nspundown=\"1\"\r\nnumReads=\"5\"\r\n");
    assert_eq('1', $sections['snowflake']['spundown']);
    assert_eq('5', $sections['snowflake']['numReads']);
});

t('pool disk names are derived from pool_disk_prefix, sorted, excluding cache/parity/flash', function () use ($repoRoot) {
    $sections = dormouse_parse_disks_ini(file_get_contents($repoRoot . '/tests/fixtures/disks.ini'));
    $names = dormouse_pool_disk_names($sections, 'snowflake');
    assert_eq(['snowflake', 'snowflake2', 'snowflake3', 'snowflake4', 'snowflake5', 'snowflake6'], $names);
});

t('pool disk name sorting is a plain string sort, not natural sort (a latent trap past 9 disks in one pool)', function () {
    $sections = ['snowflake10' => [], 'snowflake2' => [], 'snowflake' => []];
    assert_eq(['snowflake', 'snowflake10', 'snowflake2'], dormouse_pool_disk_names($sections, 'snowflake'));
});

// --- Phase 2.1: Source C — transition detection -----------------------------------

t('first poll for a disk records a baseline state row, not a transition', function () {
    $sections = ['snowflake' => ['device' => 'sdc', 'spundown' => '0', 'numReads' => '100', 'numWrites' => '10']];
    [$rows, $newState] = dormouse_disk_poll_tick([], $sections, ['snowflake']);
    assert_eq(1, count($rows));
    assert_eq('state', $rows[0]['event']);
    assert_eq('snowflake/sdc', $rows[0]['rel_path']);
    assert_true($rows[0]['reads_delta'] === null, 'baseline row must not carry a delta');
    assert_eq(['spundown' => 0, 'numReads' => 100, 'numWrites' => 10, 'device' => 'sdc'], $newState['snowflake']);
});

t('an unchanged tick produces no rows', function () {
    $prev = ['snowflake' => ['spundown' => 0, 'numReads' => 100, 'numWrites' => 10, 'device' => 'sdc']];
    $sections = ['snowflake' => ['device' => 'sdc', 'spundown' => '0', 'numReads' => '150', 'numWrites' => '12']];
    [$rows, ] = dormouse_disk_poll_tick($prev, $sections, ['snowflake']);
    assert_eq([], $rows, 'reads/writes moving without a spundown change must not emit a row');
});

t('a spundown 0->1 transition is a spindown, 1->0 is a spinup, each carrying reads/writes deltas', function () {
    $prev = ['snowflake' => ['spundown' => 0, 'numReads' => 100, 'numWrites' => 10, 'device' => 'sdc']];
    $sections = ['snowflake' => ['device' => 'sdc', 'spundown' => '1', 'numReads' => '140', 'numWrites' => '25']];
    [$rows, $newState] = dormouse_disk_poll_tick($prev, $sections, ['snowflake']);
    assert_eq(1, count($rows));
    assert_eq('spindown', $rows[0]['event']);
    assert_eq(40, $rows[0]['reads_delta']);
    assert_eq(15, $rows[0]['writes_delta']);
    assert_eq(1, $newState['snowflake']['spundown']);

    $prev2 = $newState;
    $sections2 = ['snowflake' => ['device' => 'sdc', 'spundown' => '0', 'numReads' => '141', 'numWrites' => '25']];
    [$rows2, ] = dormouse_disk_poll_tick($prev2, $sections2, ['snowflake']);
    assert_eq('spinup', $rows2[0]['event']);
    assert_eq(1, $rows2[0]['reads_delta']);
    assert_eq(0, $rows2[0]['writes_delta']);
});

t('a counter reset/wrap (current < previous) records a NULL delta, never a huge number', function () {
    $prev = ['snowflake' => ['spundown' => 0, 'numReads' => 100000, 'numWrites' => 5000, 'device' => 'sdc']];
    $sections = ['snowflake' => ['device' => 'sdc', 'spundown' => '1', 'numReads' => '3', 'numWrites' => '1']];
    [$rows, ] = dormouse_disk_poll_tick($prev, $sections, ['snowflake']);
    assert_true($rows[0]['reads_delta'] === null, 'a reset counter must yield a NULL delta');
    assert_true($rows[0]['writes_delta'] === null);
});

t('a disk missing from the current disks.ini sections is skipped, not fatal', function () {
    [$rows, $newState] = dormouse_disk_poll_tick([], ['other' => ['spundown' => '0']], ['snowflake']);
    assert_eq([], $rows);
    assert_eq([], $newState);
});

t('a previously-tracked disk that briefly vanishes from disks.ini keeps its last known state instead of losing continuity', function () {
    $prev = ['snowflake' => ['spundown' => 0, 'numReads' => 100, 'numWrites' => 10, 'device' => 'sdc']];
    [$rows, $newState] = dormouse_disk_poll_tick($prev, ['other' => ['spundown' => '0']], ['snowflake']);
    assert_eq([], $rows, 'a transient disappearance must not emit a row');
    assert_eq($prev['snowflake'], $newState['snowflake'], 'state must carry forward unchanged, not be dropped');

    // when it reappears, the delta is against the carried-forward state, not a fresh baseline
    $sections = ['snowflake' => ['device' => 'sdc', 'spundown' => '1', 'numReads' => '140', 'numWrites' => '25']];
    [$rows2, ] = dormouse_disk_poll_tick($newState, $sections, ['snowflake']);
    assert_eq('spindown', $rows2[0]['event'], 'a reappearance must not be treated as a fresh baseline');
    assert_eq(40, $rows2[0]['reads_delta']);
});

// --- Phase 2.1: Source C — schema migration ---------------------------------------

t('the reads_delta/writes_delta migration is idempotent on a db created with the 0.2.0 schema, preserving existing rows', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'dormouse-migrate-');
    unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';

    // Recreate the exact 0.2.0 shape by hand, not via dormouse_open_db (which
    // already migrates) — this is the whole point of the test.
    $db = new SQLite3($tmp);
    $db->exec('CREATE TABLE activity (
        ts INTEGER NOT NULL, rel_path TEXT NOT NULL, share TEXT NOT NULL,
        client_ip TEXT NOT NULL, source TEXT NOT NULL, event TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 1
    )');
    $db->exec("INSERT INTO activity (ts, rel_path, share, client_ip, source, event, count) VALUES (1000, 'a.mkv', 'Content', '', 'smb', 'open', 1)");

    dormouse_migrate_activity_schema($db);
    dormouse_migrate_activity_schema($db); // must not throw the second time

    $cols = [];
    $result = $db->query('PRAGMA table_info(activity)');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = $row['name'];
    }
    assert_true(in_array('reads_delta', $cols, true));
    assert_true(in_array('writes_delta', $cols, true));

    assert_eq(1, (int) $db->querySingle('SELECT COUNT(*) FROM activity'));
    assert_eq('a.mkv', $db->querySingle("SELECT rel_path FROM activity"));
    assert_true($db->querySingle('SELECT reads_delta FROM activity') === null, 'pre-existing row must keep a NULL for the new column');

    $db->close();
    unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
});

t('dormouse_open_db applies the migration on a fresh db too, and dormouse_record_activity can write deltas', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'dormouse-freshmigrate-');
    unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';
    $db = dormouse_open_db($tmp);
    dormouse_record_activity($db, 1000, 'snowflake/sdc', '', '', 'disk', 'spinup', 40, 40, 15);
    $row = $db->querySingle('SELECT reads_delta, writes_delta FROM activity', true);
    assert_eq(40, (int) $row['reads_delta']);
    assert_eq(15, (int) $row['writes_delta']);
    $db->close();
    unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
});

// --- Phase 2.1: Source C — smartctl cross-check ------------------------------------

t('smartctl agreement check compares disks.ini spundown against smartctl standby state', function () {
    assert_true(dormouse_smartctl_agrees(true, true));
    assert_true(dormouse_smartctl_agrees(false, false));
    assert_true(!dormouse_smartctl_agrees(true, false));
    assert_true(!dormouse_smartctl_agrees(false, true));
});

// --- Phase 2.1: write visibility (close_write) -------------------------------------

t('inotify line parsing recognises CLOSE_WRITE alongside the existing events', function () {
    $parsed = dormouse_parse_inotify_line('/mnt/snowflake/Content/|new-episode.mkv|CLOSE_WRITE,CLOSE');
    assert_eq(['CLOSE_WRITE', 'CLOSE'], $parsed['events']);
});

t('dormoused watches close_write in its inotifywait event set', function () use ($repoRoot) {
    $src = file_get_contents($repoRoot . '/plugin/scripts/dormoused');
    assert_true(str_contains($src, 'close_write'), 'dormoused must add close_write to the -e event list');
    assert_true(str_contains($src, "'write'"), "dormoused must record CLOSE_WRITE as event='write'");
});

// --- Phase 2.1: spin_events window query -------------------------------------------

t('dormouse_build_spin_events returns transitions with distinct activity in the surrounding 60s windows', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'dormouse-spinevents-');
    unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';
    $db = dormouse_open_db($tmp);

    dormouse_record_activity($db, 940, 'Content/show/ep1.mkv', 'Content', '192.0.2.10', 'smb', 'open');
    dormouse_record_activity($db, 1000, 'snowflake/sdc', '', '', 'disk', 'spinup', 40, 40, 0);
    dormouse_record_activity($db, 1050, 'Content/show/ep1.mkv', 'Content', '192.0.2.10', 'inotify', 'access', 20);
    dormouse_record_activity($db, 1200, 'Content/show/ep2.mkv', 'Content', '192.0.2.10', 'smb', 'open'); // outside the +60s window

    $events = dormouse_build_spin_events($db, 30);
    assert_eq(1, count($events));
    assert_eq('spinup', $events[0]['event']);
    assert_eq('snowflake/sdc', $events[0]['disk']);
    assert_eq(40, $events[0]['reads_delta']);

    $beforePaths = array_column($events[0]['before'], 'rel_path');
    $afterPaths = array_column($events[0]['after'], 'rel_path');
    assert_true(in_array('Content/show/ep1.mkv', $beforePaths, true), 'the open just before the spin-up must be in the before window');
    assert_true(in_array('Content/show/ep1.mkv', $afterPaths, true), 'the access just after the spin-up must be in the after window');
    assert_true(!in_array('Content/show/ep2.mkv', $afterPaths, true), 'activity beyond the 60s window must be excluded');

    $db->close();
    unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
});

t('dormouse_build_disk_states reports current spundown flag and since-timestamp per known disk', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'dormouse-diskstates-');
    unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';
    $db = dormouse_open_db($tmp);

    dormouse_record_activity($db, 1000, 'snowflake/sdc', '', '', 'disk', 'state');
    dormouse_stat_set($db, 'disk_state_snowflake', 0);
    dormouse_stat_set($db, 'disk_state_snowflake_since', 1000);
    dormouse_record_activity($db, 1500, 'snowflake/sdc', '', '', 'disk', 'spindown', 0, null, null);
    dormouse_stat_set($db, 'disk_state_snowflake', 1);
    dormouse_stat_set($db, 'disk_state_snowflake_since', 1500);

    $states = dormouse_build_disk_states($db);
    assert_true(isset($states['snowflake']));
    assert_eq(true, $states['snowflake']['spundown']);
    assert_eq(1500, $states['snowflake']['since']);

    $db->close();
    unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
});

// --- Phase 2: moves must be structurally impossible -------------------------------

t('no move/delete code paths exist anywhere in the shipped plugin tree', function () use ($repoRoot) {
    $forbiddenPhp = '/\b(rename|unlink|copy|link|symlink|rmdir)\s*\(/';
    $forbiddenShell = '/\b(rsync|fuser)\b/';

    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repoRoot . '/plugin', \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isDir()) {
            continue;
        }
        $contents = file_get_contents($fileInfo->getPathname());
        assert_true(!preg_match($forbiddenPhp, $contents), "move/delete call found in {$fileInfo->getPathname()}");
        assert_true(!preg_match($forbiddenShell, $contents), "rsync/fuser reference found in {$fileInfo->getPathname()}");
    }
});

// --- report -------------------------------------------------------------------

printf("%d passed, %d failed\n", $passed, count($failures));
if ($failures) {
    foreach ($failures as $f) {
        echo "FAIL: $f\n";
    }
    exit(1);
}
