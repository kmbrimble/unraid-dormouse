<?php
declare(strict_types=1);

require __DIR__ . '/../scripts/version-sorts-after.php';

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
    $rc = $repoRoot . '/plugin/scripts/rc.dormouse';
    $env = sprintf('DORMOUSE_PIDFILE=%s DORMOUSE_LOG=%s', escapeshellarg($pidFile), escapeshellarg($logFile));

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

    @unlink($pidFile);
    @unlink($logFile);
    @rmdir($tmpDir);
});

// --- report -------------------------------------------------------------------

printf("%d passed, %d failed\n", $passed, count($failures));
if ($failures) {
    foreach ($failures as $f) {
        echo "FAIL: $f\n";
    }
    exit(1);
}
