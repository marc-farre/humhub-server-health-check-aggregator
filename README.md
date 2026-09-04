# HumHub health check aggregator

Calls the `health-check.php` endpoint of several HumHub instances in parallel and
condenses everything into **one** result, so a single Uptime Kuma monitor covers
the whole fleet instead of one entry per server.

```
All server health checks passed
2 instance(s): 2 ok, 0 with warnings, 0 failed | 0.31s

OK   one.example.org          healthy (142ms)
OK   two.example.org          healthy (168ms)
```

When something breaks, the phrase is **absent** and the failing instances come
first, with their errors inline:

```
1 of 2 server health check(s) FAILED
2 instance(s): 1 ok, 0 with warnings, 1 failed | 0.28s

FAIL two.example.org          2 error(s), 4 warning(s) (151ms)
     https://two.example.org/humhub-server-health-check/health-check.php
     - ERROR: [app_permissions] HumHub directories are not writable: uploads/file …
     - ERROR: [cron] Cron uses PHP 8.1 (/opt/php8.1/bin/php, cron/run) but the web …
OK   one.example.org          healthy (139ms)
```

## Install

Put it on any one server (a monitoring box, or one of the HumHub servers):

```bash
mkdir -p /path/to/webroot/health-aggregator
cp health-aggregator.php .env.example .htaccess /path/to/webroot/health-aggregator/
cd /path/to/webroot/health-aggregator
cp .env.example .env
chmod 600 .env                 # must stay readable by the PHP user
openssl rand -hex 32           # paste into AGGREGATOR_TOKEN
```

Then list the instances in `.env`:

```ini
AGGREGATOR_TOKEN=<the token you just generated>

# If every instance was deployed with the same TOKEN, set it once:
TARGET_TOKEN=<the per-instance TOKEN>

TARGET_URL[]=https://one.example.org/humhub-server-health-check/health-check.php
TARGET_URL[]=https://two.example.org/humhub-server-health-check/health-check.php?token=<a different token, if this instance has one>
```

One `TARGET_URL[]` line per instance, in any order — adding a server to the
fleet is one line, with nothing to renumber. A URL is all an instance needs: it
is named after the host in it, so those two appear everywhere as
`one.example.org` and `two.example.org`. (Derived names are always made unique:
a repeated host is qualified by its port, then by the path the instance lives
under.)

Anything else one instance needs follows its URL after a `|`:

| Setting | Overrides |
| --- | --- |
| `token` | that instance's own token, when it differs from `TARGET_TOKEN`. Sent as an `X-Health-Token` header, so it stays out of the target's access log; a `?token=…` in the URL is used as given instead, which keeps the URL one you can paste into a browser |
| `label` | the derived name, for when several instances share a host and the host name alone would not tell them apart: `\|label=intranet (staging)` |
| `insecure` | TLS verification, skipping it (self-signed staging certificates only) |
| `host_header` | the `Host` header, for checking an instance by IP |
| `timeout` | `TIMEOUT`, for one habitually slow instance |

A setting with no value is on, so `|insecure` means `|insecure=true`.

Where a `.env` is not an option — a container configured through the
environment, which cannot give the same variable twice — pass the whole list as
one comma-separated `TARGET_URL`, `|` settings included. The numbered
`TARGET_n_URL` / `TARGET_n_TOKEN` / `TARGET_n_LABEL` … form also still works and
may be mixed in, numbered from 1 without gaps up to `MAX_TARGETS` (100).

Test it:

```bash
php health-aggregator.php -v
curl -s "https://monitor.example.org/health-aggregator/health-aggregator.php?token=…"
```

## Uptime Kuma

One monitor covers the fleet:

| Field | Value |
|---|---|
| Monitor Type | `HTTP(s) - Keyword` |
| URL | `https://monitor.example.org/health-aggregator/health-aggregator.php?token=<AGGREGATOR_TOKEN>` |
| Keyword | `All server health checks passed` |
| Invert Keyword | off |
| Method | `GET` |
| Body / Body Encoding | leave empty (the encoding dropdown is irrelevant for GET) |
| Accepted Status Codes | `200-299` |
| Request Timeout | above `TIMEOUT × (RETRIES + 1)` — 30 s with the defaults |

Putting the token in the URL is the simplest option. To keep it out of the URL
field, leave it off and add a header under **HTTP Options → Headers**:

```json
{ "X-Health-Token": "<AGGREGATOR_TOKEN>" }
```

`Authorization: Bearer <token>` works too, though some servers strip that header
before PHP sees it — if a Bearer token gives 403, use `X-Health-Token` instead.

Instances that only have warnings still count as passed, so warnings do not page
you. Set `FAIL_ON_WARNING=true` if you would rather be told.

## Logs

Driven by a monitor, the response body is the only thing anyone ever sees — and
only for as long as Uptime Kuma keeps it. So every run that is not healthy,
every state change, and every PHP error the aggregator itself hits is appended
to a log file, and that log is readable **over HTTP with the same token**:

```bash
curl -s "https://monitor.example.org/health-aggregator/health-aggregator.php?token=…&log=100"
```

```
/var/www/health-aggregator/health-aggregator.log — last 5 line(s)

[2026-05-03 09:19:39+02:00] ERROR aggregator  1 of 2 server health check(s) FAILED | 2 instance(s): 1 ok, 0 with warnings, 1 failed | 0.28s | changed: two OK -> FAIL
[2026-05-03 09:19:39+02:00] ERROR two         FAIL 2 error(s), 1 warning(s) (151ms) https://two.example.org/…
[2026-05-03 09:19:39+02:00] ERROR two           [app_permissions] HumHub directories are not writable: uploads/file …
[2026-05-03 09:20:41+02:00] ERROR two         DOWN unreachable: Operation timed out (3002ms, 2 attempts) since 2026-05-03 09:19 https://two.example.org/…
[2026-05-03 09:24:11+02:00] INFO  aggregator  All server health checks passed | 2 instance(s): 2 ok, 0 with warnings, 0 failed | 0.31s | changed: two DOWN -> OK
```

`&format=json` returns the same lines as a JSON array. On the command line:

```bash
php health-aggregator.php --log=100     # last 100 lines
php health-aggregator.php --log-path    # where the log actually is
php health-aggregator.php --clear-log
```

**A monitor polls constantly, so the log records events rather than runs.** With
the default `LOG_EVENTS=changes` a line is written when an instance changes
state (in either direction), when the overall verdict changes, and once every
`LOG_REPEAT_MINUTES` (default 60) while a problem persists — not once a minute
for the whole outage. Each failing line carries a `since` timestamp, so how long
it has been broken is in the log itself.

| Setting | Default | Meaning |
|---|---|---|
| `LOG_ENABLED` | `true` | Master switch. |
| `LOG_FILE` | `health-aggregator.log` next to the script | Falls back to the temp directory if that path is not writable — and says so in the response body, because a silent log is worse than none. |
| `LOG_EVENTS` | `changes` | `all` (every run), `changes`, `problems` (as `changes` but no recovery line), `off`. |
| `LOG_FORMAT` | `text` | `json` writes one JSON object per line. |
| `LOG_DETAIL` | `true` | Include each instance's individual error and warning lines. |
| `LOG_REPEAT_MINUTES` | `60` | How often an unchanged problem is repeated. `0` = every run. |
| `LOG_MAX_KB` | `1024` | Rotates to `<file>.1` above this size. |
| `LOG_KEEP` | `1` | How many rotated files to keep. |
| `LOG_VIEW` | `true` | Set `false` to disable reading the log over HTTP. |
| `STATE_FILE` | `.health-aggregator-state.json` next to the script | Remembers the previous run, for change detection and `since`. |

The bundled `.htaccess` already denies `.log` and dotfiles. On nginx, add the log
to the deny rule yourself:

```nginx
location ~ ^/health-aggregator/(?!health-aggregator\.php$) { deny all; }
```

A PHP error or a crash is logged too, and over HTTP it no longer leaks into the
response: `display_errors` is forced off for the web SAPI, so the body stays a
clean `ERROR: the aggregator crashed — see the log (?log=50).` with a 5xx status
(503, or PHP's own 500 on a true fatal such as an out-of-memory). The keyword
monitor fails as it should, and the reason is one URL away instead of buried in
a PHP error log you would have to find first.

## Slow instances

An instance that answers late is reported as `DOWN` once it crosses `TIMEOUT`,
which makes the run before that — the one where it was merely slow — the useful
warning. Instances slower than `SLOW_MS` (default 5000) are marked in both the
output and the log:

```
OK   two.example.org          healthy (4001ms, slow)
```

Transport failures are retried `RETRIES` times (default `1`) before an instance
is called down, so a single dropped connection does not page you. Only failures
to *answer* are retried — an instance that reported errors reported them. Worst
case the run takes `TIMEOUT × (RETRIES + 1)`, so keep the Uptime Kuma request
timeout above that. A `|timeout=25` on one instance overrides `TIMEOUT` just
for it, which beats raising it for the whole fleet because of one slow server.

### Getting a 403?

The response tells you which side refused, and why:

```bash
curl -i "https://monitor.example.org/health-aggregator/health-aggregator.php?token=…"
```

- **`Content-Type: text/plain` with an `X-Health-Check: aggregator` header** — the
  script refused. The body names the reason: `no token supplied`, `token mismatch`,
  `client IP … is not in ALLOW_IPS`, or `.env exists but is not readable by the
  PHP user`.
- **`Content-Type: text/html` and no `X-Health-Check` header** — the *web server*
  refused, before PHP ran. Usual causes:
  - the aggregator was dropped into the per-instance `health/` directory, whose
    rules only permit `health-check.php` (the nginx snippet
    `location ~ ^/health/(?!health-check\.php$) { deny all; }` returns exactly
    403). Give the aggregator its own directory, or add its filename to the rule.
  - an `.htaccess` `RewriteRule` that Apache refuses because
    `Options FollowSymLinks` is not granted for the directory — the error log
    shows `AH00670`. The shipped `.htaccess` avoids rewrites entirely for this
    reason; if you enabled the optional block, comment it out again.
  - an `.htaccess`/nginx rule denying the whole directory, or a WAF.

If `ALLOW_IPS` is set and Uptime Kuma runs in Docker or behind a reverse proxy,
the address PHP sees is the container gateway or proxy — not the Kuma host. Either
add that address, or drop `ALLOW_IPS` and rely on the token. The body reports the
IP it actually saw, so you can copy it from there.

## What counts as a failure

| Situation | Reported as |
|---|---|
| Body contains `Server health check passed` | `OK`, or `WARN` if it also lists warnings |
| Body contains `Server health check FAILED` | `FAIL`, with the remote `ERROR:` lines |
| Connection refused, DNS failure, TLS error, timeout | `DOWN` with the cURL reason, after `RETRIES` further attempts |
| HTTP 403 (wrong or missing token) | `DOWN` — misconfiguration, not silence |
| Anything else answering (maintenance page, WAF, PHP fatal, wrong URL) | `DOWN` with a 200-character excerpt |

An unreachable or misconfigured instance is treated as a failure rather than
being skipped — a monitor that goes quiet when a server disappears is worse than
no monitor.

## Design notes

- **Parallel fetch** via `curl_multi`, so total runtime is roughly the slowest
  instance rather than the sum. Twenty instances at 15 s each still finish in
  about 15 s.
- **Tokens are never printed.** Instance URLs are redacted (`token=***`) in every
  output mode, including JSON, so a public Kuma status page cannot leak them. By
  default the token is sent as an `X-Health-Token` header rather than in the URL,
  keeping it out of the target's access log; if a configured URL already carries a
  non-empty `?token=`, that is used as given instead.
- **The keyword is unambiguous.** Per-instance lines are written in the
  aggregator's own words (`healthy`, `2 error(s), 4 warning(s)`) and never repeat
  the remote `Server health check passed` phrase — otherwise a keyword monitor
  configured with the single-server phrase would match even when another instance
  had failed.
- **Fail closed**: without `AGGREGATOR_TOKEN` the endpoint refuses every HTTP
  request. It exposes the health of the entire fleet at once, so it needs at least
  as much protection as the individual endpoints.
- **Warnings come with their detail.** Every instance that reported warnings gets
  them listed, along with the remote's advice line, in the same format the
  per-instance script uses:

  ```
  WARN demo.example.org        healthy, 2 warning(s) (356ms)
       WARNING [php_settings] display_errors is On for the web SAPI.
               -> Leaks paths and stack traces to visitors — turn it off in production.
       WARNING [opcache] OPcache pressure: cache_full=yes, … 100.0% used of 256.00 MB.
               -> Increase opcache.memory_consumption / opcache.max_accelerated_files.
  ```

  `SHOW_WARNINGS=false` reduces this to errors only, `SHOW_HINTS=false` drops the
  `->` lines. Works whether the instance answers in plain text or JSON.
- `MAX_DETAIL_LINES` (default 20) caps the entries printed per instance so one
  very broken server cannot bury the others in the alert; the remainder is
  summarised as "… and N more" and the full report stays one click away at the
  instance URL.

## CLI

```
php health-aggregator.php [-v] [--json] [--quiet] [--no-color]
                          [--env=/path/.env] [--only=label,label]
                          [--log[=LINES]] [--log-path] [--clear-log]
```

Exit codes: `0` = all passed, `1` = warnings only, `2` = at least one failure —
so it also works as a cron job that mails you only when something is wrong:

```cron
*/5 * * * * out=$(/path/to/php /path/to/health-aggregator/health-aggregator.php 2>&1) || echo "$out"
```
