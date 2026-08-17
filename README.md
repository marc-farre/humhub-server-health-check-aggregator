# HumHub Server health check aggregator

Calls the `health-check.php` endpoint (see https://github.com/marc-farre/humhub-server-health-check)
of several HumHub instances in parallel and condenses everything into **one** result, so a single
Uptime Kuma monitor covers the whole fleet instead of one entry per server.

```
All server health checks passed
2 instance(s): 2 ok, 0 with warnings, 0 failed | 0.31s

OK   instance-one             healthy (142ms)
OK   instance-two             healthy (168ms)
```

When something breaks, the phrase is **absent** and the failing instances come
first, with their errors inline:

```
1 of 2 server health check(s) FAILED
2 instance(s): 1 ok, 0 with warnings, 1 failed | 0.28s

FAIL instance-two             2 error(s), 4 warning(s) (151ms)
     https://two.example.org/humhub-server-health-check/health-check.php
     - ERROR: [humhub_permissions] HumHub directories are not writable: uploads/file …
     - ERROR: [cron] Cron uses PHP 8.1 (/opt/php8.1/bin/php, cron/run) but the web …
OK   instance-one             healthy (139ms)
```

## Install

Put it on any one server (a monitoring box, or one of the HumHub servers):

```bash
git clone git@github.com:marc-farre/humhub-server-health-check-aggregator.git
cd /path/to/webroot/humhub-server-health-check-aggregator
cp .env.example .env
chmod 600 .env                 # must stay readable by the PHP user
openssl rand -hex 32           # paste into AGGREGATOR_TOKEN
```

Then list the instances in `.env`:

```ini
AGGREGATOR_TOKEN=<the token you just generated>

# If every instance was deployed with the same HEALTH_TOKEN, set it once:
DEFAULT_TOKEN=<the per-instance HEALTH_TOKEN>

TARGET_1_LABEL=instance-one
TARGET_1_URL=https://one.example.org/humhub-server-health-check/health-check.php

TARGET_2_LABEL=instance-two
TARGET_2_URL=https://two.example.org/humhub-server-health-check/health-check.php
TARGET_2_TOKEN=<a different token, if this instance has one>
```

Numbering runs from 1 upwards without gaps — add `TARGET_3_*`, `TARGET_4_*` and
so on as the fleet grows. Test it:

```bash
php health-aggregator.php -v
curl -s "https://monitor.example.org/humhub-server-health-check-aggregator/health-aggregator.php?token=…"
```

## Uptime Kuma

One HTTP(s) monitor:

- **URL**: `https://monitor.example.org/humhub-server-health-check-aggregator/health-aggregator.php?token=…`
  (or send the token as `X-Health-Token` / `Authorization: Bearer`)
- **Keyword monitor** on `All server health checks passed` — recommended, since
  the alert body then contains the failing instances and their errors.
- Or a plain **status code monitor**: `200` when everything passed, `503` when at
  least one instance failed.
- Set the Kuma request timeout **above** the aggregator's `TIMEOUT` (default 15 s).

Instances that only have warnings still count as passed, so warnings do not page
you. Set `FAIL_ON_WARNING=true` if you would rather be told.

## What counts as a failure

| Situation | Reported as |
|---|---|
| Body contains `Server health check passed` | `OK`, or `WARN` if it also lists warnings |
| Body contains `Server health check FAILED` | `FAIL`, with the remote `ERROR:` lines |
| Connection refused, DNS failure, TLS error, timeout | `DOWN` with the cURL reason |
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
  keeping it out of the target's access log; if `TARGET_n_URL` already contains
  `?token=`, it is used as given.
- **The keyword is unambiguous.** Per-instance lines are written in the
  aggregator's own words (`healthy`, `2 error(s), 4 warning(s)`) and never repeat
  the remote `Server health check passed` phrase — otherwise a keyword monitor
  configured with the single-server phrase would match even when another instance
  had failed.
- **Fail closed**: without `AGGREGATOR_TOKEN` the endpoint refuses every HTTP
  request. It exposes the health of the entire fleet at once, so it needs at least
  as much protection as the individual endpoints.
- `MAX_DETAIL_LINES` (default 10) caps the lines printed per instance so one very
  broken server cannot bury the others in the alert; the full report stays one
  click away at the instance URL.

## CLI

```
php health-aggregator.php [-v] [--json] [--quiet] [--no-color]
                          [--env=/path/.env] [--only=label,label]
```

Exit codes: `0` = all passed, `1` = warnings only, `2` = at least one failure —
so it also works as a cron job that mails you only when something is wrong:

```cron
*/5 * * * * out=$(/path/to/php /path/to/humhub-server-health-check-aggregator/health-aggregator.php 2>&1) || echo "$out"
```
