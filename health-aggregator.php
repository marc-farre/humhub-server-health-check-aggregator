<?php

/**
 * =============================================================================
 * HumHub Health Check Aggregator
 * -----------------------------------------------------------------------------
 * Calls the per-instance health-check.php endpoints of several HumHub servers in
 * parallel and condenses them into ONE result, so a single Uptime Kuma monitor
 * covers the whole fleet.
 *
 *   All instances healthy -> first line is exactly:
 *       All server health checks passed
 *   Otherwise the phrase is absent and the failing instances are listed with
 *   their errors.
 *
 * USAGE
 *   CLI:   php health-aggregator.php [-v] [--json] [--quiet] [--no-color]
 *                                    [--env=/path/.env] [--only=label,label]
 *          Exit codes: 0 = all OK, 1 = warnings only, 2 = at least one failure
 *
 *   HTTP:  https://<host>/health-aggregator/health-aggregator.php?token=SECRET
 *          200 = all passed, 503 = at least one instance failed.
 *          Uptime Kuma: keyword monitor on "All server health checks passed".
 *          Add &format=json for machine-readable output.
 *
 * Configuration lives in a `.env` next to this script; protect it with the
 * bundled .htaccess. Never expose this endpoint without a token: it reveals the
 * health of every instance at once.
 * =============================================================================
 */

declare(strict_types=1);

const AGG_VERSION = '1.0.0';

/** The phrase each per-instance health-check.php prints when it is healthy. */
const AGG_REMOTE_OK_KEYWORD = 'Server health check passed';
/** The phrase this script prints when every instance is healthy. */
const AGG_OK_KEYWORD = 'All server health checks passed';

// =============================================================================
// .env loader (same format as health-check.php)
// =============================================================================

final class Env
{
    /** @var array<string,string> */
    private array $vars = [];
    private ?string $file = null;

    public function load(string $file): bool
    {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $this->file = $file;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = strtoupper(trim(substr($line, 0, $pos)));
            $val = trim(substr($line, $pos + 1));
            if (strlen($val) > 1 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
                $val = substr($val, 1, -1);
            } elseif (($hash = strpos($val, ' #')) !== false) {
                $val = rtrim(substr($val, 0, $hash));
            }
            $this->vars[$key] = $val;
        }
        return true;
    }

    public function raw(string $key): ?string
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        return $this->vars[$key] ?? null;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->raw($key);
        return $v === null ? $default : $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->raw($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    public function int(string $key, int $default): int
    {
        $v = $this->raw($key);
        return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int) $v;
    }

    /** @return string[] */
    public function list(string $key, array $default = []): array
    {
        $v = $this->raw($key);
        if ($v === null || trim($v) === '') {
            return $default;
        }
        $parts = preg_split('/[,\s]+/', trim($v)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
    }
}

// =============================================================================
// Helpers
// =============================================================================

/**
 * Read a request header robustly. Apache with mod_php and many FastCGI setups
 * drop `Authorization` before it reaches $_SERVER unless CGIPassAuth is on, so
 * check the redirected copy and the raw header list as well.
 */
function agg_request_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    foreach ([$key, 'REDIRECT_' . $key] as $candidate) {
        if (isset($_SERVER[$candidate]) && is_string($_SERVER[$candidate]) && $_SERVER[$candidate] !== '') {
            return $_SERVER[$candidate];
        }
    }
    foreach (['apache_request_headers', 'getallheaders'] as $fn) {
        if (function_exists($fn)) {
            $headers = @$fn();
            if (is_array($headers)) {
                foreach ($headers as $header => $value) {
                    if (strcasecmp((string) $header, $name) === 0 && is_string($value)) {
                        return $value;
                    }
                }
            }
        }
    }
    return '';
}

/** Never print a token, not even in an error message. */
function agg_redact(string $url): string
{
    $url = (string) preg_replace('/([?&](?:token|api_key|key)=)[^&]*/i', '$1***', $url);
    return $url;
}

/**
 * Read the numbered TARGET_n_* blocks from the .env.
 *
 * @return array<int,array{label:string,url:string,token:string,insecure:bool,host_header:string}>
 */
function agg_targets(Env $env): array
{
    $targets = [];
    $defaultToken = $env->str('DEFAULT_TOKEN');
    $max = $env->int('MAX_TARGETS', 100);

    for ($i = 1; $i <= $max; $i++) {
        $url = trim($env->str("TARGET_{$i}_URL"));
        if ($url === '') {
            continue;
        }
        $label = $env->str("TARGET_{$i}_LABEL");
        if ($label === '') {
            $label = (string) (parse_url($url, PHP_URL_HOST) ?: "target$i");
        }
        $targets[] = [
            'label' => $label,
            'url' => $url,
            'token' => $env->str("TARGET_{$i}_TOKEN", $defaultToken),
            'insecure' => $env->bool("TARGET_{$i}_INSECURE", $env->bool('DEFAULT_INSECURE', false)),
            'host_header' => $env->str("TARGET_{$i}_HOST_HEADER"),
        ];
    }

    return $targets;
}

/**
 * Fetch every target in parallel, so the total time is that of the slowest
 * instance rather than the sum of all of them.
 *
 * @param array<int,array{label:string,url:string,token:string,insecure:bool,host_header:string}> $targets
 * @return array<int,array{body:string,code:int,error:string,time:float}>
 */
function agg_fetch_all(array $targets, Env $env): array
{
    $timeout = $env->int('TIMEOUT', 15);
    $connectTimeout = $env->int('CONNECT_TIMEOUT', 5);
    $maxBody = $env->int('MAX_RESPONSE_KB', 256) * 1024;

    $multi = curl_multi_init();
    $handles = [];

    foreach ($targets as $i => $target) {
        $url = $target['url'];
        $headers = ['Accept: text/plain, application/json'];

        // If the URL already carries the token, leave it alone; otherwise send it
        // as a header so it does not end up in the target's access log.
        if ($target['token'] !== '' && !preg_match('/[?&]token=/i', $url)) {
            $headers[] = 'X-Health-Token: ' . $target['token'];
        }
        if ($target['host_header'] !== '') {
            $headers[] = 'Host: ' . $target['host_header'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'humhub-health-aggregator/' . AGG_VERSION,
            CURLOPT_SSL_VERIFYPEER => !$target['insecure'],
            CURLOPT_SSL_VERIFYHOST => $target['insecure'] ? 0 : 2,
            CURLOPT_ENCODING => '',
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    // Per-handle results only become available once the message queue is drained;
    // without this, curl_error() can come back empty for a refused connection.
    $curlErrors = [];
    while (($msg = curl_multi_info_read($multi)) !== false) {
        if (($msg['result'] ?? CURLE_OK) !== CURLE_OK) {
            $curlErrors[(int) $msg['handle']] = function_exists('curl_strerror')
                ? (string) curl_strerror((int) $msg['result'])
                : 'cURL error ' . (int) $msg['result'];
        }
    }

    $results = [];
    foreach ($handles as $i => $ch) {
        $body = (string) curl_multi_getcontent($ch);
        $error = (string) curl_error($ch);
        if ($error === '' && isset($curlErrors[(int) $ch])) {
            $error = $curlErrors[(int) $ch];
        }
        $results[$i] = [
            'body' => strlen($body) > $maxBody ? substr($body, 0, $maxBody) : $body,
            'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'error' => $error,
            'time' => (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    return $results;
}

/**
 * Describe an instance in our own words. Deliberately never repeats the remote
 * "Server health check passed" phrase: if it appeared on a per-instance line, a
 * keyword monitor configured with the single-server phrase would match even when
 * another instance had failed.
 */
function agg_summary(string $state, int $errors, int $warnings): string
{
    if ($state === 'FAIL') {
        return sprintf('%d error(s), %d warning(s)', $errors, $warnings);
    }
    return $warnings > 0 ? sprintf('healthy, %d warning(s)', $warnings) : 'healthy';
}

/**
 * Turn one raw response into a verdict.
 *
 * @param array{body:string,code:int,error:string,time:float} $res
 * @return array{state:string,summary:string,errors:string[],warnings:string[]}
 */
function agg_classify(array $res): array
{
    $body = trim($res['body']);
    $code = $res['code'];

    // Transport-level failure: DNS, TLS, timeout, connection refused.
    if ($res['error'] !== '' && $body === '') {
        return ['state' => 'DOWN', 'summary' => 'unreachable: ' . $res['error'], 'errors' => [], 'warnings' => []];
    }

    // JSON response (the URL carried &format=json).
    if ($body !== '' && ($body[0] === '{')) {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $errors = [];
            $warnings = [];
            foreach ($json['checks'] ?? [] as $check) {
                $line = ($check['check'] ?? '?') . ': ' . ($check['message'] ?? '');
                if (($check['state'] ?? '') === 'ERROR') {
                    $errors[] = $line;
                } elseif (($check['state'] ?? '') === 'WARNING') {
                    $warnings[] = $line;
                }
            }
            $status = (string) ($json['status'] ?? '');
            if ($status === 'forbidden') {
                return ['state' => 'DOWN', 'summary' => 'rejected the token: ' . ($json['message'] ?? 'forbidden'), 'errors' => [], 'warnings' => []];
            }
            $state = $status === 'ok' ? 'OK' : ($status === 'warning' ? 'WARN' : 'FAIL');
            $counts = $json['summary'] ?? [];
            $nErr = (int) ($counts['errors'] ?? count($errors));
            $nWarn = (int) ($counts['warnings'] ?? count($warnings));
            return ['state' => $state, 'summary' => agg_summary($state, $nErr, $nWarn), 'errors' => $errors, 'warnings' => $warnings];
        }
    }

    // Plain-text response from health-check.php.
    if ($body !== '') {
        $lines = preg_split('/\R/', $body) ?: [];
        $errors = [];
        $warnings = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'ERROR')) {
                $errors[] = ltrim(substr($line, 5), ' :');
            } elseif (str_starts_with($line, 'WARNING')) {
                $warnings[] = ltrim(substr($line, 7), ' :');
            }
        }
        // Prefer the counts from the remote headline: the body may have been
        // truncated by MAX_RESPONSE_KB, the headline never is.
        $nErr = count($errors);
        $nWarn = count($warnings);
        if (preg_match('/(\d+)\s+error\(s\)/i', $body, $m)) {
            $nErr = (int) $m[1];
        }
        if (preg_match('/(\d+)\s+warning\(s\)/i', $body, $m)) {
            $nWarn = (int) $m[1];
        }

        if (str_contains($body, AGG_REMOTE_OK_KEYWORD)) {
            $state = $warnings === [] && $nWarn === 0 ? 'OK' : 'WARN';
            return ['state' => $state, 'summary' => agg_summary($state, 0, $nWarn), 'errors' => $errors, 'warnings' => $warnings];
        }
        if (stripos($body, 'Server health check FAILED') !== false) {
            return ['state' => 'FAIL', 'summary' => agg_summary('FAIL', $nErr, $nWarn), 'errors' => $errors, 'warnings' => $warnings];
        }
        if ($code === 403 || stripos($body, 'forbidden') !== false) {
            return ['state' => 'DOWN', 'summary' => "rejected the token (HTTP $code): " . substr($body, 0, 200), 'errors' => [], 'warnings' => []];
        }
        // Something answered, but it was not a health check: maintenance page,
        // WAF block, PHP fatal error, wrong URL.
        $excerpt = preg_replace('/\s+/', ' ', substr(strip_tags($body), 0, 200));
        return ['state' => 'DOWN', 'summary' => "unexpected response (HTTP $code): $excerpt", 'errors' => [], 'warnings' => []];
    }

    return ['state' => 'DOWN', 'summary' => "empty response (HTTP $code)" . ($res['error'] !== '' ? ' — ' . $res['error'] : ''), 'errors' => [], 'warnings' => []];
}

// =============================================================================
// Bootstrap
// =============================================================================

$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
$started = microtime(true);
$opt = ['verbose' => false, 'quiet' => false, 'json' => false, 'color' => true, 'env' => null, 'only' => []];

if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if ($arg === '-v' || $arg === '--verbose') {
            $opt['verbose'] = true;
        } elseif ($arg === '-q' || $arg === '--quiet') {
            $opt['quiet'] = true;
        } elseif ($arg === '--json') {
            $opt['json'] = true;
        } elseif ($arg === '--no-color') {
            $opt['color'] = false;
        } elseif (str_starts_with($arg, '--env=')) {
            $opt['env'] = substr($arg, 6);
        } elseif (str_starts_with($arg, '--only=')) {
            $opt['only'] = array_filter(array_map('trim', explode(',', substr($arg, 7))));
        } elseif ($arg === '-h' || $arg === '--help') {
            fwrite(STDOUT, "HumHub health check aggregator " . AGG_VERSION . "\n\n"
                . "Usage: php health-aggregator.php [options]\n\n"
                . "  -v, --verbose      list warnings of healthy instances too\n"
                . "  -q, --quiet        no output, exit code only\n"
                . "      --json         JSON output\n"
                . "      --no-color     disable ANSI colours\n"
                . "      --env=PATH     path to the .env (default: .env next to this script)\n"
                . "      --only=LABELS  only check these target labels\n\n"
                . "Exit codes: 0 = all passed, 1 = warnings only, 2 = at least one failure\n");
            exit(0);
        }
    }
    $opt['color'] = $opt['color'] && stream_isatty(STDOUT);
} else {
    $opt['json'] = (($_GET['format'] ?? '') === 'json');
    $opt['verbose'] = isset($_GET['verbose']);
    $opt['color'] = false;
}

$env = new Env();
$envFile = $opt['env'] ?? (__DIR__ . '/.env');
$envExists = is_file($envFile);
$envLoaded = $env->load($envFile);

// -----------------------------------------------------------------------------
// HTTP gate — fail closed, exactly like health-check.php
// -----------------------------------------------------------------------------
if (!$isCli) {
    header('Content-Type: ' . ($opt['json'] ? 'application/json' : 'text/plain') . '; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Health-Check: aggregator');

    $token = $env->str('AGGREGATOR_TOKEN');
    $allowed = $env->list('ALLOW_IPS');
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ipOk = $allowed === [] || in_array($remote, $allowed, true);

    $provided = '';
    foreach ([$_GET['token'] ?? null, agg_request_header('X-Health-Token')] as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            $provided = $candidate;
            break;
        }
    }
    if ($provided === '' && preg_match('/^Bearer\s+(.+)$/i', agg_request_header('Authorization'), $m)) {
        $provided = trim($m[1]);
    }

    if ($token === '') {
        $why = ($envExists && !$envLoaded)
            ? sprintf('%s exists but is not readable by the PHP user', $envFile)
            : 'AGGREGATOR_TOKEN is not configured in ' . $envFile;
        http_response_code(403);
        echo $opt['json'] ? json_encode(['status' => 'forbidden', 'message' => $why]) . "\n" : "Forbidden: $why\n";
        exit(1);
    }
    // Distinguish the misconfiguration cases: a 403 with no explanation is
    // painful to debug from a monitoring tool that only shows the status code.
    if (!$ipOk) {
        $why = "client IP $remote is not in ALLOW_IPS";
    } elseif ($provided === '') {
        $why = 'no token supplied — send ?token=..., an X-Health-Token header, or Authorization: Bearer';
    } elseif (!hash_equals(hash('sha256', $token), hash('sha256', $provided))) {
        $why = 'token mismatch';
    } else {
        $why = null;
    }
    if ($why !== null) {
        http_response_code(403);
        header('X-Health-Check: aggregator');
        echo $opt['json'] ? json_encode(['status' => 'forbidden', 'message' => $why]) . "\n" : "Forbidden: $why\n";
        exit(1);
    }
}

// =============================================================================
// Run
// =============================================================================

if (!function_exists('curl_multi_init')) {
    $msg = 'The cURL extension is required by the aggregator.';
    if (!$isCli) {
        http_response_code(503);
    }
    echo $opt['json'] ? json_encode(['status' => 'error', 'message' => $msg]) . "\n" : "ERROR: $msg\n";
    exit(2);
}

$targets = agg_targets($env);
if ($opt['only'] !== []) {
    $wanted = array_map('strtolower', $opt['only']);
    $targets = array_values(array_filter($targets, static fn($t) => in_array(strtolower($t['label']), $wanted, true)));
}

if ($targets === []) {
    $msg = $envLoaded
        ? "No targets configured in $envFile (set TARGET_1_URL, TARGET_2_URL, …)."
        : "No .env found at $envFile — copy .env.example and configure the targets.";
    if (!$isCli) {
        http_response_code(503);
    }
    echo $opt['json'] ? json_encode(['status' => 'error', 'message' => $msg]) . "\n" : "ERROR: $msg\n";
    exit(2);
}

$responses = agg_fetch_all($targets, $env);

$results = [];
foreach ($targets as $i => $target) {
    $verdict = agg_classify($responses[$i]);
    $results[] = [
        'label' => $target['label'],
        'url' => agg_redact($target['url']),
        'state' => $verdict['state'],
        'summary' => $verdict['summary'],
        'errors' => $verdict['errors'],
        'warnings' => $verdict['warnings'],
        'http_code' => $responses[$i]['code'],
        'time_ms' => (int) round($responses[$i]['time'] * 1000),
    ];
}

$failOnWarning = $env->bool('FAIL_ON_WARNING', false);
$failed = array_values(array_filter($results, static fn($r) => in_array($r['state'], ['FAIL', 'DOWN'], true)));
$warned = array_values(array_filter($results, static fn($r) => $r['state'] === 'WARN'));
$passed = array_values(array_filter($results, static fn($r) => in_array($r['state'], ['OK', 'WARN'], true)));
$isFailure = $failed !== [] || ($failOnWarning && $warned !== []);
$duration = microtime(true) - $started;

// =============================================================================
// Output
// =============================================================================

// The keyword must appear ONLY when every instance is healthy, so Uptime Kuma
// can key on it. On failure the phrase is absent from the whole response.
$headline = $isFailure
    ? sprintf('%d of %d server health check(s) FAILED', count($failed) + ($failOnWarning ? count($warned) : 0), count($results))
    : ($warned === []
        ? AGG_OK_KEYWORD
        : AGG_OK_KEYWORD . sprintf(' (%d instance(s) with warnings)', count($warned)));

if (!$isCli) {
    http_response_code($isFailure ? 503 : 200);
}

if ($opt['json']) {
    echo json_encode([
        'status' => $isFailure ? 'error' : ($warned !== [] ? 'warning' : 'ok'),
        'headline' => $headline,
        'summary' => [
            'total' => count($results),
            'passed' => count($passed),
            'warnings' => count($warned),
            'failed' => count($failed),
        ],
        'duration_ms' => (int) round($duration * 1000),
        'aggregator_version' => AGG_VERSION,
        'instances' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($isFailure ? 2 : ($warned !== [] ? 1 : 0));
}

if ($opt['quiet']) {
    exit($isFailure ? 2 : ($warned !== [] ? 1 : 0));
}

$colors = ['OK' => "\033[32m", 'WARN' => "\033[33m", 'FAIL' => "\033[31m", 'DOWN' => "\033[31m", 'reset' => "\033[0m"];
$paint = static function (string $text, string $state) use ($opt, $colors): string {
    return $opt['color'] && isset($colors[$state]) ? $colors[$state] . $text . $colors['reset'] : $text;
};

echo $paint($headline, $isFailure ? 'FAIL' : ($warned !== [] ? 'WARN' : 'OK')) . "\n";
printf(
    "%d instance(s): %d ok, %d with warnings, %d failed | %.2fs\n\n",
    count($results),
    count($passed) - count($warned),
    count($warned),
    count($failed),
    $duration
);

$maxDetail = $env->int('MAX_DETAIL_LINES', 10);
$showWarnings = $env->bool('SHOW_WARNINGS', true);

// Failures first — that is what the person reading the alert needs.
usort($results, static function (array $a, array $b): int {
    $rank = ['DOWN' => 0, 'FAIL' => 1, 'WARN' => 2, 'OK' => 3];
    return [$rank[$a['state']] ?? 9, $a['label']] <=> [$rank[$b['state']] ?? 9, $b['label']];
});

foreach ($results as $r) {
    printf(
        "%s %-24s %s (%dms)\n",
        $paint(str_pad($r['state'], 4), $r['state']),
        $r['label'],
        $r['summary'] !== '' ? $r['summary'] : $r['url'],
        $r['time_ms']
    );

    if (in_array($r['state'], ['FAIL', 'DOWN'], true) || $opt['verbose']) {
        printf("     %s\n", $r['url']);
    }

    $detail = [];
    foreach ($r['errors'] as $line) {
        $detail[] = 'ERROR: ' . $line;
    }
    if ($showWarnings && (in_array($r['state'], ['FAIL', 'DOWN'], true) || $opt['verbose'])) {
        foreach ($r['warnings'] as $line) {
            $detail[] = 'WARNING: ' . $line;
        }
    }
    $shown = array_slice($detail, 0, $maxDetail);
    foreach ($shown as $line) {
        printf("     - %s\n", $line);
    }
    if (count($detail) > count($shown)) {
        printf("     - … and %d more (open %s for the full report)\n", count($detail) - count($shown), $r['url']);
    }
}

exit($isFailure ? 2 : ($warned !== [] ? 1 : 0));
