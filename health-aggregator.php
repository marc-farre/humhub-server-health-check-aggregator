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
 *                                    [--log[=LINES]] [--log-path] [--clear-log]
 *          Exit codes: 0 = all OK, 1 = warnings only, 2 = at least one failure
 *
 *   HTTP:  https://<host>/health-aggregator/health-aggregator.php?token=SECRET
 *          200 = all passed, 503 = at least one instance failed.
 *          Uptime Kuma: keyword monitor on "All server health checks passed".
 *          Add &format=json for machine-readable output.
 *          Add &log=200 to read the last 200 log lines instead of running.
 *
 * LOG
 *   Run from a monitor, nobody ever sees the terminal — so every run that is not
 *   healthy, every state change and every internal error is also appended to a
 *   log file (LOG_FILE, rotated at LOG_MAX_KB). It is readable over HTTP with
 *   the same token via ?log=, which is the only way to get at it when the
 *   aggregator is driven by Uptime Kuma.
 *
 * Configuration lives in a `.env` next to this script; protect it with the
 * bundled .htaccess. Never expose this endpoint without a token: it reveals the
 * health of every instance at once.
 * =============================================================================
 */

declare(strict_types=1);

const AGG_VERSION = '1.1.1';

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
    /** @var array<string,string[]> values from repeated `KEY[]=` lines, in file order */
    private array $lists = [];
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
            // `KEY[]=` repeated on several lines is a list, so that the targets
            // can be written one per line instead of being numbered by hand.
            // Plain `KEY=` stays last-one-wins, as every .env does.
            if (str_ends_with($key, '[]')) {
                if ($val !== '') {
                    $this->lists[substr($key, 0, -2)][] = $val;
                }
                continue;
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

    /**
     * An empty value means "not set", not "set to nothing".
     *
     * `LOG_FILE=` with nothing after it is how a commented-out setting usually
     * ends up looking once someone has edited a `.env`, and reading that as an
     * empty path rather than as the default is how it turns into a crash.
     */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->raw($key);
        return ($v === null || trim($v) === '') ? $default : $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->raw($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return self::truthy($v);
    }

    public static function truthy(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on', 'enabled'], true);
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

    /**
     * The repeated `KEY[]=` lines, followed by a comma-separated plain `KEY`.
     *
     * A real environment variable cannot be given twice, so a container that has
     * no .env to write `[]` lines into can pass the same list as one
     * comma-separated `KEY`. Both forms are read, and add up.
     *
     * Only commas and newlines separate entries here, never spaces: an entry
     * carries inline options after a `|`, and splitting on spaces would tear
     * `url | token=x` into three unusable pieces instead of one entry.
     *
     * @return string[]
     */
    public function items(string $key): array
    {
        $items = $this->lists[$key] ?? [];
        $flat = $this->raw($key);
        if ($flat !== null && trim($flat) !== '') {
            foreach (preg_split('/[,\r\n]+/', $flat) ?: [] as $part) {
                $items[] = $part;
            }
        }
        return array_values(array_filter(array_map('trim', $items), static fn($p) => $p !== ''));
    }
}

// =============================================================================
// Log
// =============================================================================

/**
 * Where to put a file when the script's own directory is not writable.
 *
 * The name is tied to the installation directory and the user, so two
 * aggregators on one host — or a leftover file from an install that has moved —
 * never read each other's log or state.
 *
 * Everything goes inside a 0700 directory we own rather than straight into the
 * shared temp directory: a predictable name there is a file any local user can
 * pre-empt with a symlink, and the PHP user would then happily append the log
 * into whatever it points at. Returns '' when no such directory can be had,
 * which callers report rather than work around.
 */
function agg_fallback_path(string $name): string
{
    static $dir = null;
    if ($dir === null) {
        $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : get_current_user();
        $dir = rtrim(sys_get_temp_dir(), '/') . '/health-aggregator-' . substr(sha1(__DIR__ . '|' . $uid), 0, 12);
        if (!is_dir($dir) || is_link($dir)) {
            $dir = @mkdir($dir, 0700, true) ? $dir : '';
        }
        if ($dir !== '') {
            // Someone else's directory at our path is not one we can trust.
            $owner = @fileowner($dir);
            if (function_exists('posix_geteuid') && $owner !== false && $owner !== posix_geteuid()) {
                $dir = '';
            } else {
                @chmod($dir, 0700);
            }
        }
    }
    return $dir === '' ? '' : $dir . '/' . $name;
}

/**
 * An append-only log of what the aggregator saw.
 *
 * The aggregator normally runs from a monitor, where the response body is the
 * only thing anyone sees and only for as long as the monitor keeps it. Anything
 * worth explaining after the fact — a failing instance, an instance that came
 * back, a slow one, a PHP error, a broken configuration — is therefore also
 * appended here, and can be read back over HTTP with `?log=`.
 *
 * The file is written next to the script by default, where the bundled
 * .htaccess already denies `.log`. If that directory is not writable by the PHP
 * user (a very common split between the CLI and web users), the temp directory
 * is used instead and the fallback is reported rather than silently swallowed.
 */
final class AggLog
{
    public const OFF = 'off';

    private string $file;
    private string $fallback = '';
    private string $format;
    private string $events;
    private int $maxBytes;
    private int $keep;
    private bool $detail;
    private bool $enabled;
    private ?string $problem = null;
    private bool $opened = false;

    public function __construct(Env $env, string $dir)
    {
        $this->enabled = $env->bool('LOG_ENABLED', true);
        $this->file = $env->str('LOG_FILE', rtrim($dir, '/') . '/health-aggregator.log');
        $this->format = strtolower($env->str('LOG_FORMAT', 'text')) === 'json' ? 'json' : 'text';
        $this->events = strtolower($env->str('LOG_EVENTS', 'changes'));
        if (!in_array($this->events, ['off', 'problems', 'changes', 'all'], true)) {
            $this->events = 'changes';
        }
        $this->maxBytes = max(0, $env->int('LOG_MAX_KB', 1024)) * 1024;
        $this->keep = max(0, $env->int('LOG_KEEP', 1));
        $this->detail = $env->bool('LOG_DETAIL', true);
        if ($this->events === self::OFF) {
            $this->enabled = false;
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function events(): string
    {
        return $this->enabled ? $this->events : self::OFF;
    }

    public function detail(): bool
    {
        return $this->detail;
    }

    public function file(): string
    {
        return $this->file;
    }

    /** Why logging is not working, or null when it is. */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /** @param array<string,scalar|null> $context */
    public function line(string $level, string $target, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if ($this->format === 'json') {
            $entry = json_encode(['ts' => date('c'), 'level' => $level, 'target' => $target, 'message' => $message] + $context, JSON_UNESCAPED_SLASHES);
        } else {
            $entry = sprintf('[%s] %-5s %-20s %s', date('Y-m-d H:i:sP'), strtoupper($level), $target, $message);
        }
        $this->append((string) $entry . "\n");
    }

    /** @return string[] the last $lines lines, oldest first */
    public function tail(int $lines): array
    {
        $path = $this->resolve();
        if ($path === null || !is_readable($path)) {
            return [];
        }
        // Read from the end in chunks: the log may be megabytes and the caller
        // usually wants the last screenful.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $size = (int) @filesize($path);
        $chunk = 8192;
        $offset = $size;
        $buffer = '';
        while ($offset > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = (int) min($chunk, $offset);
            $offset -= $read;
            fseek($handle, $offset);
            $buffer = (string) fread($handle, $read) . $buffer;
        }
        fclose($handle);
        $buffer = trim($buffer);
        if ($buffer === '') {
            return [];
        }
        return array_slice(preg_split('/\R/', $buffer) ?: [], -$lines);
    }

    public function clear(): bool
    {
        $path = $this->resolve();
        return $path !== null && @file_put_contents($path, '') !== false;
    }

    /** The file actually being written to, or null when logging is off/broken. */
    public function resolve(): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        if ($this->opened) {
            return $this->file;
        }
        $this->opened = true;
        if ($this->open($this->file)) {
            return $this->file;
        }
        // Only now is the fallback worth having: obtaining it creates a directory,
        // and a working local log should leave no trace anywhere else.
        $this->fallback = agg_fallback_path('health-aggregator.log');
        if ($this->fallback !== '' && $this->open($this->fallback)) {
            $this->problem = sprintf('%s is not writable by the PHP user — logging to %s instead', $this->file, $this->fallback);
            $this->file = $this->fallback;
            return $this->file;
        }
        $this->problem = sprintf(
            '%s is not writable by the PHP user%s — nothing is being logged',
            $this->file,
            $this->fallback !== '' ? ', nor is ' . $this->fallback : ' and no usable fallback directory could be created'
        );
        $this->enabled = false;
        return null;
    }

    /** Can this path be appended to? Creates it, with the log's own mode. */
    private function open(string $path): bool
    {
        // fopen('') is a ValueError, not a false return.
        if (trim($path) === '') {
            return false;
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        @chmod($path, 0640);
        return true;
    }

    /**
     * Never throws, whatever the filesystem does.
     *
     * The error and exception handlers log through here, so an exception raised
     * while logging would be raised from inside the handler for the previous
     * one — turning a single problem into a cascade of fatals and burying the
     * original cause. Failing to write a log line is not worth an exception.
     */
    private function append(string $entry): void
    {
        try {
            $path = $this->resolve();
            if ($path === null || $path === '') {
                return;
            }
            $this->rotate($path);
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                return;
            }
            @flock($handle, LOCK_EX);
            @fwrite($handle, $entry);
            @flock($handle, LOCK_UN);
            fclose($handle);
        } catch (Throwable $e) {
            $this->problem = 'writing to the log failed: ' . $e->getMessage();
            $this->enabled = false;
        }
    }

    /** Keep the log from growing without bound: <file> -> <file>.1 -> … */
    private function rotate(string $path): void
    {
        if ($this->maxBytes <= 0) {
            return;
        }
        clearstatcache(true, $path);
        if ((int) @filesize($path) < $this->maxBytes) {
            return;
        }
        if ($this->keep === 0) {
            @file_put_contents($path, '');
            return;
        }
        for ($i = $this->keep; $i >= 1; $i--) {
            $from = $i === 1 ? $path : $path . '.' . ($i - 1);
            if (is_file($from)) {
                @rename($from, $path . '.' . $i);
            }
        }
        // The rotated-in file is created by the next fwrite() and would take the
        // umask default; the log names instance URLs, so keep it to 0640.
        @touch($path);
        @chmod($path, 0640);
    }
}

/**
 * What the previous run saw, so that "instance-two failed" can be logged once
 * instead of on every poll, and so the log can say how long it has been failing.
 */
final class AggState
{
    private string $file;
    private array $data;

    public function __construct(Env $env, string $dir)
    {
        $this->file = $env->str('STATE_FILE', rtrim($dir, '/') . '/.health-aggregator-state.json');
        if (trim($this->file) === '') {
            $this->file = rtrim($dir, '/') . '/.health-aggregator-state.json';
        }
        $data = null;
        if (is_readable($this->file)) {
            $data = json_decode((string) @file_get_contents($this->file), true);
        } elseif (file_exists($this->file)) {
            // There, but written by the other user — a cron run as root and a web
            // run as www-data is the common split. This process cannot read it,
            // so it keeps its own copy: reading the primary and writing somewhere
            // else would reset the change detection on every single poll.
            $fallback = agg_fallback_path('health-aggregator-state.json');
            if ($fallback !== '') {
                $this->file = $fallback;
                $data = is_readable($fallback) ? json_decode((string) @file_get_contents($fallback), true) : null;
            }
        }
        // Simply not there yet is the first run: keep the configured path and let
        // save() create it, which falls back on its own if the write fails.
        $this->data = is_array($data) ? $data : [];
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function save(): bool
    {
        if (trim($this->file) === '') {
            return false;
        }
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $this->file . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $json) === false) {
            $fallback = agg_fallback_path('health-aggregator-state.json');
            if ($fallback === '' || $this->file === $fallback) {
                return false;
            }
            $this->file = $fallback;
            $tmp = $this->file . '.tmp' . getmypid();
            if (@file_put_contents($tmp, $json) === false) {
                return false;
            }
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }
        return true;
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

/** The per-target settings an entry may carry, whether inline or numbered. */
const AGG_TARGET_OPTIONS = ['token', 'label', 'insecure', 'host_header', 'timeout'];

/**
 * Read the targets: the `TARGET_URL[]` list first, then the numbered
 * `TARGET_n_*` blocks, both of which may be used in the same .env.
 *
 * @return array<int,array{label:string,url:string,token:string,insecure:bool,host_header:string,timeout:int}>
 */
function agg_targets(Env $env): array
{
    $timeout = $env->int('TIMEOUT', 15);
    // TARGET_* is the name every other per-target setting uses; DEFAULT_* is the
    // older name for these two and keeps working.
    $defaults = [
        'token' => $env->str('TARGET_TOKEN', $env->str('DEFAULT_TOKEN')),
        'insecure' => $env->bool('TARGET_INSECURE', $env->bool('DEFAULT_INSECURE', false)) ? 'true' : 'false',
        'label' => '',
        'host_header' => '',
        'timeout' => (string) $timeout,
    ];

    /** @var array<int,array<string,string>> url plus whichever options are set, in configured order */
    $entries = [];
    foreach ($env->items('TARGET_URL') as $entry) {
        $parsed = agg_parse_target($entry);
        if ($parsed !== null) {
            $entries[] = $parsed;
        }
    }
    $max = $env->int('MAX_TARGETS', 100);
    for ($i = 1; $i <= $max; $i++) {
        $url = trim($env->str("TARGET_{$i}_URL"));
        if ($url === '') {
            continue;
        }
        $entry = ['url' => $url];
        foreach (AGG_TARGET_OPTIONS as $name) {
            $v = $env->raw("TARGET_{$i}_" . strtoupper($name));
            if ($v !== null && trim($v) !== '') {
                $entry[$name] = trim($v);
            }
        }
        $entries[] = $entry;
    }

    $targets = [];
    $used = [];
    foreach ($entries as $index => $entry) {
        $entry += $defaults;
        // A URL is all a target needs: the host in it names the instance.
        $label = $entry['label'] !== '' ? $entry['label'] : agg_derive_label($entry['url'], $used, $index + 1);
        $used[strtolower($label)] = true;
        $targets[] = [
            'label' => $label,
            'url' => $entry['url'],
            'token' => $entry['token'],
            'insecure' => Env::truthy($entry['insecure']),
            'host_header' => $entry['host_header'],
            // One habitually slow instance should not force a longer timeout on
            // the whole fleet. 0 keeps cURL's meaning of "no timeout".
            'timeout' => max(0, is_numeric($entry['timeout']) ? (int) $entry['timeout'] : $timeout),
        ];
    }

    return $targets;
}

/**
 * One `TARGET_URL[]` entry: the URL, then any per-target settings after a `|`.
 *
 *   https://one.example.org/health-check.php|token=abc123|label=intranet (staging)
 *
 * A setting with no value is on — `|insecure` means `|insecure=true`. Anything
 * that is not one of AGG_TARGET_OPTIONS is ignored rather than fatal: a typo in
 * one instance's options must not take the whole fleet's monitoring down.
 *
 * @return array<string,string>|null the url and its options, or null for a blank entry
 */
function agg_parse_target(string $entry): ?array
{
    $parts = explode('|', $entry);
    $url = trim((string) array_shift($parts));
    if ($url === '') {
        return null;
    }
    $parsed = ['url' => $url];
    foreach ($parts as $part) {
        $pos = strpos($part, '=');
        // The value may itself contain '=' (a token never needs quoting).
        $name = strtolower(trim($pos === false ? $part : substr($part, 0, $pos)));
        $value = $pos === false ? 'true' : trim(substr($part, $pos + 1));
        $name = str_replace('-', '_', $name);
        if ($value !== '' && in_array($name, AGG_TARGET_OPTIONS, true)) {
            $parsed[$name] = $value;
        }
    }
    return $parsed;
}

/**
 * Name an instance after the host in its URL.
 *
 * The label is the instance's identity everywhere it matters — the output, the
 * log, the change-detection state, `--only` — so two instances must never end up
 * sharing one. Several HumHub sites behind a single host is an ordinary setup, so
 * when the host is already taken the path they live under is what tells them
 * apart: `example.org/site-a`, `example.org/site-b`.
 *
 * @param array<string,bool> $used labels already assigned, lower-cased
 */
function agg_derive_label(string $url, array $used, int $index): string
{
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    if ($host === '') {
        return "target$index";
    }
    if (!isset($used[strtolower($host)])) {
        return $host;
    }
    // Same host on a different port is a whole different instance, and the port
    // says so far more usefully than any suffix we could invent.
    $port = parse_url($url, PHP_URL_PORT);
    if (is_int($port) && !isset($used[strtolower($host . ':' . $port)])) {
        return $host . ':' . $port;
    }
    $segments = array_values(array_filter(explode('/', trim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/'))));
    array_pop($segments); // health-check.php itself says nothing about which site it is
    // Outermost segment first: the health check normally sits in a subdirectory
    // of the site it belongs to, so "/site-a/health/" is named by "site-a", not
    // by the "health" directory every one of them has.
    foreach ($segments as $segment) {
        $candidate = $host . '/' . $segment;
        if (!isset($used[strtolower($candidate)])) {
            return $candidate;
        }
    }
    // Same host, same path, twice over: fall back to something merely unique.
    return $host . '-' . $index;
}

/**
 * A stable key for a cURL handle. PHP 8 hands out CurlHandle objects where PHP 7
 * used resources, and casting an object to int yields 1 for every handle — which
 * would map every error onto the same target.
 *
 * @param resource|object $handle
 */
function agg_curl_id($handle): int
{
    return is_object($handle) ? spl_object_id($handle) : (int) $handle;
}

/**
 * Fetch every target, retrying the ones that did not answer at all.
 *
 * A single dropped connection or a request that arrived while the instance was
 * busy would otherwise report a healthy server as DOWN. Only transport failures
 * are retried — an instance that answered with errors answered, and repeating
 * the question would not change it.
 *
 * @param array<int,array{label:string,url:string,token:string,insecure:bool,host_header:string,timeout:int}> $targets
 * @return array<int,array{body:string,code:int,error:string,time:float,attempts:int}>
 */
function agg_fetch_all(array $targets, Env $env, ?AggLog $log = null): array
{
    $results = agg_fetch_batch($targets, $env, array_keys($targets));
    $retries = max(0, $env->int('RETRIES', 1));

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $again = [];
        foreach ($results as $i => $res) {
            if ($res['error'] !== '' && trim($res['body']) === '') {
                $again[] = $i;
            }
        }
        if ($again === []) {
            break;
        }
        $log?->line('info', 'aggregator', sprintf(
            'retry %d/%d for %d unreachable instance(s): %s',
            $attempt,
            $retries,
            count($again),
            implode(', ', array_map(static fn(int $i) => $targets[$i]['label'], $again))
        ));
        foreach (agg_fetch_batch($targets, $env, $again) as $i => $res) {
            $res['attempts'] = ($results[$i]['attempts'] ?? 1) + 1;
            $results[$i] = $res;
        }
    }

    return $results;
}

/**
 * Fetch the given targets in parallel, so the total time is that of the slowest
 * instance rather than the sum of all of them.
 *
 * @param array<int,array{label:string,url:string,token:string,insecure:bool,host_header:string,timeout:int}> $targets
 * @param int[] $indices which of them to fetch
 * @return array<int,array{body:string,code:int,error:string,time:float,attempts:int}>
 */
function agg_fetch_batch(array $targets, Env $env, array $indices): array
{
    $connectTimeout = $env->int('CONNECT_TIMEOUT', 5);
    $maxBody = $env->int('MAX_RESPONSE_KB', 256) * 1024;

    $multi = curl_multi_init();
    $handles = [];

    foreach ($indices as $i) {
        $target = $targets[$i];
        $timeout = $target['timeout'];
        $url = $target['url'];
        $headers = ['Accept: text/plain, application/json'];

        // If the URL already carries the token, leave it alone; otherwise send it
        // as a header so it does not end up in the target's access log. A
        // `?token=` with nothing after it is where someone means to paste one and
        // has not: the fallback token still goes in the header, and the target
        // ignores the empty query parameter.
        if ($target['token'] !== '' && !preg_match('/[?&]token=[^&]/i', $url)) {
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
        // -1 means there was nothing to wait on (routine while the threaded
        // resolver works); without the pause the loop spins at 100% CPU.
        if ($running && curl_multi_select($multi, 1.0) === -1) {
            usleep(1000);
        }
    } while ($running > 0 && $status === CURLM_OK);

    // Per-handle results only become available once the message queue is drained;
    // without this, curl_error() can come back empty for a refused connection.
    $curlErrors = [];
    while (($msg = curl_multi_info_read($multi)) !== false) {
        if (($msg['result'] ?? CURLE_OK) !== CURLE_OK) {
            $curlErrors[agg_curl_id($msg['handle'])] = function_exists('curl_strerror')
                ? (string) curl_strerror((int) $msg['result'])
                : 'cURL error ' . (int) $msg['result'];
        }
    }

    $results = [];
    foreach ($handles as $i => $ch) {
        $body = (string) curl_multi_getcontent($ch);
        $error = (string) curl_error($ch);
        if ($error === '' && isset($curlErrors[agg_curl_id($ch)])) {
            $error = $curlErrors[agg_curl_id($ch)];
        }
        $results[$i] = [
            'body' => strlen($body) > $maxBody ? substr($body, 0, $maxBody) : $body,
            'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'error' => $error,
            'time' => (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
            'attempts' => 1,
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
                $entry = [
                    'message' => '[' . ($check['check'] ?? '?') . '] ' . ($check['message'] ?? ''),
                    'hint' => isset($check['hint']) && $check['hint'] !== null ? (string) $check['hint'] : null,
                ];
                if (($check['state'] ?? '') === 'ERROR') {
                    $errors[] = $entry;
                } elseif (($check['state'] ?? '') === 'WARNING') {
                    $warnings[] = $entry;
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
        $lastList = null;   // 'e' or 'w': which list the previous entry went into
        $lastIndex = -1;
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // health-check.php prints its advice on an indented continuation line
            // ("        -> do this"); attach it to the entry above it.
            if ($lastList !== null && preg_match('/^->\s*(.+)$/', $trimmed, $hm)) {
                if ($lastList === 'e') {
                    $errors[$lastIndex]['hint'] = trim($hm[1]);
                } else {
                    $warnings[$lastIndex]['hint'] = trim($hm[1]);
                }
                continue;
            }
            if (str_starts_with($trimmed, 'ERROR')) {
                $errors[] = ['message' => ltrim(substr($trimmed, 5), ' :'), 'hint' => null];
                $lastList = 'e';
                $lastIndex = count($errors) - 1;
            } elseif (str_starts_with($trimmed, 'WARNING')) {
                $warnings[] = ['message' => ltrim(substr($trimmed, 7), ' :'), 'hint' => null];
                $lastList = 'w';
                $lastIndex = count($warnings) - 1;
            } elseif ($trimmed === '') {
                $lastList = null;
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
$opt = ['verbose' => false, 'quiet' => false, 'json' => false, 'color' => true, 'env' => null, 'only' => [], 'log' => 0, 'logPath' => false, 'clearLog' => false];

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
        } elseif ($arg === '--log') {
            $opt['log'] = 200;
        } elseif (str_starts_with($arg, '--log=')) {
            $opt['log'] = max(1, (int) substr($arg, 6));
        } elseif ($arg === '--log-path') {
            $opt['logPath'] = true;
        } elseif ($arg === '--clear-log') {
            $opt['clearLog'] = true;
        } elseif ($arg === '-h' || $arg === '--help') {
            fwrite(STDOUT, "HumHub health check aggregator " . AGG_VERSION . "\n\n"
                . "Usage: php health-aggregator.php [options]\n\n"
                . "  -v, --verbose      list warnings of healthy instances too\n"
                . "  -q, --quiet        no output, exit code only\n"
                . "      --json         JSON output\n"
                . "      --no-color     disable ANSI colours\n"
                . "      --env=PATH     path to the .env (default: .env next to this script)\n"
                . "      --only=LABELS  only check these target labels\n"
                . "      --log[=N]      print the last N log lines (default 200) and exit\n"
                . "      --log-path     print the path of the log file and exit\n"
                . "      --clear-log    empty the log file and exit\n\n"
                . "Over HTTP the same log is available as ?log=N with the usual token.\n\n"
                . "Exit codes: 0 = all passed, 1 = warnings only, 2 = at least one failure\n");
            exit(0);
        }
    }
    $opt['color'] = $opt['color'] && stream_isatty(STDOUT);
} else {
    $opt['json'] = (($_GET['format'] ?? '') === 'json');
    $opt['verbose'] = isset($_GET['verbose']);
    $opt['color'] = false;
    // A stray or malformed `log` parameter must never quietly turn the monitor
    // endpoint into a log view: that would answer 200 with a body which cannot
    // contain the keyword, and the monitor would report the fleet as broken.
    if (isset($_GET['log'])) {
        $raw = strtolower(trim((string) $_GET['log']));
        if ($raw === '' || in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            $opt['log'] = 200;
        } elseif (ctype_digit($raw) && (int) $raw > 0) {
            $opt['log'] = min((int) $raw, 5000);
        }
    }
}

$env = new Env();
$envFile = $opt['env'] ?? (__DIR__ . '/.env');
$envExists = is_file($envFile);
$envLoaded = $env->load($envFile);

$log = new AggLog($env, __DIR__);

// The response body must stay a clean report: PHP's own error output would end
// up inside it, in front of the headline a keyword monitor reads. Errors are not
// lost — the handlers below put them in the log instead.
if (!$isCli) {
    @ini_set('display_errors', '0');
}

// -----------------------------------------------------------------------------
// Everything PHP itself complains about goes to the log as well
// -----------------------------------------------------------------------------
// Run from a monitor there is no terminal to print to and no PHP error log worth
// finding, so a notice about a malformed .env or a fatal in the middle of a run
// would simply vanish. On the CLI the message is still shown as before.
set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0) use ($log, $isCli): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    try {
        $log->line('error', 'php', sprintf('%s in %s:%d', $message, basename($file), $line));
    } catch (Throwable $ignored) {
    }
    return !$isCli; // over HTTP, swallow it: the body must stay a clean report
});

set_exception_handler(static function (Throwable $e) use ($log, $isCli): void {
    // The report must survive a broken log: whatever went wrong, the operator
    // still needs to be told, and on the CLI the message on stderr may be the
    // only copy they get.
    try {
        $log->line('error', 'php', sprintf('uncaught %s: %s in %s:%d', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()));
    } catch (Throwable $ignored) {
    }
    if (!$isCli) {
        http_response_code(503);
        echo "ERROR: the aggregator crashed — see the log (?log=50).\n";
    } else {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    }
    exit(2);
});

// A real fatal — out of memory, max_execution_time, a call into something that
// is not there — never reaches the exception handler, so the clean failure
// response has to be produced here as well.
register_shutdown_function(static function () use ($log, $isCli): void {
    $fatal = error_get_last();
    if ($fatal === null || !($fatal['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        return;
    }
    try {
        $log->line('error', 'php', sprintf('fatal: %s in %s:%d', $fatal['message'], basename((string) $fatal['file']), (int) $fatal['line']));
    } catch (Throwable $ignored) {
    }
    if ($isCli) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(503);
    }
    echo "\nERROR: the aggregator crashed — see the log (?log=50).\n";
});

// -----------------------------------------------------------------------------
// Log inspection (CLI) — the HTTP equivalent lives behind the token gate below
// -----------------------------------------------------------------------------
if ($isCli && ($opt['logPath'] || $opt['clearLog'] || $opt['log'] > 0)) {
    $path = $log->resolve();
    if ($path === null) {
        fwrite(STDERR, 'No log available: ' . ($log->problem() ?? 'logging is disabled (LOG_ENABLED=false or LOG_EVENTS=off)') . "\n");
        exit(2);
    }
    if ($opt['logPath']) {
        fwrite(STDOUT, $path . "\n");
        exit(0);
    }
    if ($opt['clearLog']) {
        $ok = $log->clear();
        fwrite($ok ? STDOUT : STDERR, ($ok ? 'Cleared ' : 'Could not clear ') . $path . "\n");
        exit($ok ? 0 : 2);
    }
    $lines = $log->tail($opt['log']);
    fwrite(STDOUT, $lines === [] ? "(log is empty: $path)\n" : implode("\n", $lines) . "\n");
    exit(0);
}

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

// -----------------------------------------------------------------------------
// Log inspection over HTTP — same token, no separate secret to keep
// -----------------------------------------------------------------------------
// This is the whole point of the log for anyone driving the aggregator from
// Uptime Kuma: the run itself is invisible, so the record of it has to be
// reachable with a browser.
if (!$isCli && $opt['log'] > 0) {
    if (!$env->bool('LOG_VIEW', true)) {
        http_response_code(403);
        echo "Forbidden: reading the log over HTTP is disabled (LOG_VIEW=false).\n";
        exit(1);
    }
    $path = $log->resolve();
    $lines = $path === null ? [] : $log->tail($opt['log']);
    if ($opt['json']) {
        echo json_encode([
            'status' => $path === null ? 'error' : 'ok',
            'log_file' => $path,
            'message' => $path === null ? ($log->problem() ?? 'logging is disabled') : null,
            'lines' => $lines,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    if ($path === null) {
        echo 'No log available: ' . ($log->problem() ?? 'logging is disabled (LOG_ENABLED=false or LOG_EVENTS=off)') . "\n";
        exit(0);
    }
    printf("%s — last %d line(s)\n\n", $path, count($lines));
    echo $lines === [] ? "(empty — nothing worth logging has happened yet)\n" : implode("\n", $lines) . "\n";
    exit(0);
}

// =============================================================================
// Run
// =============================================================================

if (!function_exists('curl_multi_init')) {
    $msg = 'The cURL extension is required by the aggregator.';
    $log->line('error', 'aggregator', $msg);
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
        ? "No targets configured in $envFile (add a TARGET_URL[]= line per instance)."
        : "No .env found at $envFile — copy .env.example and configure the targets.";
    $log->line('error', 'aggregator', $msg);
    if (!$isCli) {
        http_response_code(503);
    }
    echo $opt['json'] ? json_encode(['status' => 'error', 'message' => $msg]) . "\n" : "ERROR: $msg\n";
    exit(2);
}

$responses = agg_fetch_all($targets, $env, $log);

$slowMs = $env->int('SLOW_MS', 5000);
$results = [];
foreach ($targets as $i => $target) {
    $verdict = agg_classify($responses[$i]);
    $timeMs = (int) round($responses[$i]['time'] * 1000);
    $results[] = [
        'label' => $target['label'],
        'url' => agg_redact($target['url']),
        'state' => $verdict['state'],
        'summary' => $verdict['summary'],
        'errors' => $verdict['errors'],
        'warnings' => $verdict['warnings'],
        'http_code' => $responses[$i]['code'],
        'time_ms' => $timeMs,
        'attempts' => (int) ($responses[$i]['attempts'] ?? 1),
        // An instance creeping towards the timeout is worth knowing about before
        // it crosses it and starts being reported as down.
        'slow' => $slowMs > 0 && $timeMs >= $slowMs,
    ];
}

$failOnWarning = $env->bool('FAIL_ON_WARNING', false);
$failed = array_values(array_filter($results, static fn($r) => in_array($r['state'], ['FAIL', 'DOWN'], true)));
$warned = array_values(array_filter($results, static fn($r) => $r['state'] === 'WARN'));
$passed = array_values(array_filter($results, static fn($r) => in_array($r['state'], ['OK', 'WARN'], true)));
$isFailure = $failed !== [] || ($failOnWarning && $warned !== []);
$duration = microtime(true) - $started;
$status = $isFailure ? 'error' : ($warned !== [] ? 'warning' : 'ok');

// The keyword must appear ONLY when every instance is healthy, so Uptime Kuma
// can key on it. On failure the phrase is absent from the whole response.
$headline = $isFailure
    ? sprintf('%d of %d server health check(s) FAILED', count($failed) + ($failOnWarning ? count($warned) : 0), count($results))
    : ($warned === []
        ? AGG_OK_KEYWORD
        : AGG_OK_KEYWORD . sprintf(' (%d instance(s) with warnings)', count($warned)));

// =============================================================================
// Log the run
// =============================================================================
// What gets written is governed by LOG_EVENTS:
//   all       every run
//   changes   every run that is not healthy, plus the run that recovers, plus a
//             reminder every LOG_REPEAT_MINUTES while a problem persists
//   problems  the same, minus the recovery line
//   off       nothing
// The point is a log that is worth reading: a monitor polling every 60 seconds
// would otherwise bury a real incident under thousands of identical lines.

if ($log->enabled()) {
    // Resolved unconditionally, not just when something is written: otherwise a
    // log that cannot be written to stays undetected on exactly the healthy runs
    // that write nothing, and the NOTE in the output below never appears.
    $log->resolve();
    $state = new AggState($env, __DIR__);
    $previous = is_array($state->get('instances')) ? $state->get('instances') : [];
    $now = time();

    $current = [];
    $changes = [];
    foreach ($results as $r) {
        $was = is_array($previous[$r['label']] ?? null) ? $previous[$r['label']] : null;
        $changed = $was === null ? false : (string) $was['state'] !== $r['state'];
        $current[$r['label']] = [
            'state' => $r['state'],
            'since' => $changed || $was === null ? $now : (int) ($was['since'] ?? $now),
        ];
        if ($changed) {
            $changes[] = sprintf('%s %s -> %s', $r['label'], $was['state'], $r['state']);
        }
    }
    // An instance that disappeared from the configuration is a change too.
    foreach ($previous as $label => $was) {
        if (!isset($current[$label])) {
            $changes[] = sprintf('%s %s -> no longer configured', $label, is_array($was) ? (string) $was['state'] : '?');
        }
    }

    $lastStatus = (string) $state->get('status', '');
    $lastLogged = (int) $state->get('logged_at', 0);
    $repeatAfter = max(0, $env->int('LOG_REPEAT_MINUTES', 60)) * 60;
    $statusChanged = $lastStatus !== '' && $lastStatus !== $status;

    $shouldLog = $log->events() === 'all'
        || $changes !== []
        || $statusChanged
        || ($status !== 'ok' && ($repeatAfter === 0 || $now - $lastLogged >= $repeatAfter))
        || ($lastStatus === '' && $status !== 'ok');
    // `problems` differs from `changes` in exactly one way: it does not write the
    // line that says everything is fine again.
    if ($log->events() === 'problems' && $status === 'ok') {
        $shouldLog = false;
    }

    if ($shouldLog) {
        $level = $isFailure ? 'error' : ($warned !== [] ? 'warn' : 'info');
        $log->line($level, 'aggregator', sprintf(
            '%s | %d instance(s): %d ok, %d with warnings, %d failed | %.2fs%s',
            $headline ?? '',
            count($results),
            count($passed) - count($warned),
            count($warned),
            count($failed),
            $duration,
            $changes === [] ? '' : ' | changed: ' . implode(', ', $changes)
        ));

        foreach ($results as $r) {
            $noteworthy = in_array($r['state'], ['FAIL', 'DOWN'], true)
                || ($r['state'] === 'WARN' && $log->detail())
                || $r['slow']
                || $r['attempts'] > 1;
            if (!$noteworthy && $log->events() !== 'all') {
                continue;
            }
            $since = (int) $current[$r['label']]['since'];
            $log->line(
                in_array($r['state'], ['FAIL', 'DOWN'], true) ? 'error' : ($r['state'] === 'WARN' ? 'warn' : 'info'),
                $r['label'],
                sprintf(
                    '%s %s (%dms%s%s)%s %s',
                    $r['state'],
                    $r['summary'],
                    $r['time_ms'],
                    $r['attempts'] > 1 ? ', ' . $r['attempts'] . ' attempts' : '',
                    $r['slow'] ? ', slow' : '',
                    $r['state'] === 'OK' || $since >= $now ? '' : sprintf(' since %s', date('Y-m-d H:i', $since)),
                    $r['url']
                )
            );
            if (!$log->detail()) {
                continue;
            }
            foreach ($r['errors'] as $entry) {
                $log->line('error', $r['label'], '  ' . $entry['message']);
            }
            foreach ($r['warnings'] as $entry) {
                $log->line('warn', $r['label'], '  ' . $entry['message']);
            }
        }
        $state->set('logged_at', $now);
    }

    $state->set('status', $status);
    $state->set('instances', $current);
    $state->set('checked_at', $now);
    $state->save();
}

// =============================================================================
// Output
// =============================================================================

if (!$isCli) {
    http_response_code($isFailure ? 503 : 200);
}

if ($opt['json']) {
    echo json_encode([
        'status' => $status,
        'headline' => $headline,
        'summary' => [
            'total' => count($results),
            'passed' => count($passed),
            'warnings' => count($warned),
            'failed' => count($failed),
        ],
        'duration_ms' => (int) round($duration * 1000),
        'aggregator_version' => AGG_VERSION,
        'log_file' => $log->resolve(),
        'log_problem' => $log->problem(),
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

$maxDetail = $env->int('MAX_DETAIL_LINES', 20);
$showWarnings = $env->bool('SHOW_WARNINGS', true);
$showHints = $env->bool('SHOW_HINTS', true);

// Failures first — that is what the person reading the alert needs.
usort($results, static function (array $a, array $b): int {
    $rank = ['DOWN' => 0, 'FAIL' => 1, 'WARN' => 2, 'OK' => 3];
    return [$rank[$a['state']] ?? 9, $a['label']] <=> [$rank[$b['state']] ?? 9, $b['label']];
});

foreach ($results as $r) {
    printf(
        "%s %-24s %s (%dms%s%s)\n",
        $paint(str_pad($r['state'], 4), $r['state']),
        $r['label'],
        $r['summary'] !== '' ? $r['summary'] : $r['url'],
        $r['time_ms'],
        // Both are worth seeing in the alert itself: they are the early warning
        // that an instance is on its way to being reported as down.
        $r['slow'] ? ', slow' : '',
        $r['attempts'] > 1 ? ', ' . $r['attempts'] . ' attempts' : ''
    );

    if (in_array($r['state'], ['FAIL', 'DOWN'], true) || $opt['verbose']) {
        printf("     %s\n", $r['url']);
    }

    // Errors always; warnings whenever the instance reported any, so a WARN
    // instance is as actionable in the alert as a failing one.
    $detail = [];
    foreach ($r['errors'] as $entry) {
        $detail[] = ['level' => 'ERROR', 'message' => $entry['message'], 'hint' => $entry['hint']];
    }
    if ($showWarnings) {
        foreach ($r['warnings'] as $entry) {
            $detail[] = ['level' => 'WARNING', 'message' => $entry['message'], 'hint' => $entry['hint']];
        }
    }
    $shown = array_slice($detail, 0, $maxDetail);
    foreach ($shown as $entry) {
        printf("     %s %s\n", $paint(str_pad($entry['level'], 7), $entry['level'] === 'ERROR' ? 'FAIL' : 'WARN'), $entry['message']);
        if ($showHints && $entry['hint'] !== null && $entry['hint'] !== '') {
            printf("             -> %s\n", $entry['hint']);
        }
    }
    if (count($detail) > count($shown)) {
        printf("     … and %d more (open %s for the full report)\n", count($detail) - count($shown), $r['url']);
    }
}

// A log nobody can write to is worse than no log, because it is silent — so say
// so in the response itself, which is the one place that is always read.
if ($log->problem() !== null) {
    printf("\nNOTE %s\n", $log->problem());
} elseif ($opt['verbose'] && $log->resolve() !== null) {
    printf("\nlog: %s\n", $log->resolve());
}

exit($isFailure ? 2 : ($warned !== [] ? 1 : 0));
