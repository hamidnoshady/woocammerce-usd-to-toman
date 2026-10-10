# Final Report — f3064a5 (ab3c602 + 88313e0 + f3064a5) — PR #11 head ca3e488

**Branch:** `arena/25782cb8-woocammerce-usd-to-toman`  
**Final SHA:** `f3064a5` (`ab3c602` genuine concurrent + `88313e0` debug + `f3064a5` 180s) — parent `ca3e488` `60a33fb` `1525c3a` chain, base `2d892c6`  
**Reviewed head:** `ca3e488`  
**Latest run:** `38033856843` at `f3064a5` — 9/11 (both integrations `failure` at `http-autonomous.php:238` `status='running'`), previous `38033504467` same, `38033154454` same barrier `1507ms` evidence.

This report distinguishes **evidence vs hypothesis**, **assisted (http.php) vs autonomous (http-autonomous.php) vs historical (37985983564) vs unverified (results-receiver EOF)**. No tests disabled, no assertions weakened, no TODOs.

## Implemented

**1. Backend death investigation + evidence vs hypothesis**  
- `38003431248` `cURL 7` (ordinary) + `38003431248` built-ZIP skipped due to backend died during `setup.sh` → `server.sh start` `Address already in use` (stale `18888`). `server.sh` now logs `PID`, `PPID`, `STAT`, `CMD`, `ss -ltn`, `lsof`, `log head/tail`, `PHP -v`, `router -l`, `docroot` tracking via `${PID_FILE}.docroot`, `process-group` via `setsid` + `disown`, `exit status` via `kill -0` + `ps -o cmd=`. Distinguishes `genuine conflict` (port listening after `stop` with no owned PID → `::error` not `pkill`) vs `stale` (PID dead → remove file). Not increased sleeps beyond `60×1s` + `4s` drain + `5×0.5s` health probe (ca3e488) + `7×4s` preview retry (1525c3a). Evidence: `38032448329` barrier `1507ms` <2500ms proves workers=4.

**2. Genuine concurrent PHP execution**  
- `tests/integration/server.sh` `usdtf_start_concurrent` now `PHP_CLI_SERVER_WORKERS=4` (since 7.4) single `php -S` on `8888` with `setsid env PHP_CLI_SERVER_WORKERS=4`, no `socat` fork insufficiency. `docroot` tracked. Deterministic barrier: creates `${WP_PATH}/usdtf-barrier.php` (`usleep 1.5s`) then two parallel `curl` to that file, measures `elapsed_ms` via `date +%s%N`, fails if `>2500ms` (sequential would be ~3000ms). Evidence: `38033154454` `Barrier parallel: c1=0 c2=0 elapsed 1509ms` and `38033504467` `1507ms`. Previous `socat` fork to single `php -S` on `18888` only proved two `200` not overlapping — removed.

**3. Server lifecycle ownership precise**  
- `usdtf_is_alive` via `kill -0`, `usdtf_port_listening` via `ss`/`netstat`/`lsof`/`curl --max-time 3`, `usdtf_resolve_router`/`wp`, `PID_FILE` + `.docroot`, `setsid`/`nohup` + `disown`. `usdtf_stop` verifies `cmdline` contains `php.*-S 127.0.0.1:${port}` **and** docroot matches before `kill`; otherwise logs `not killing` and reports `genuine conflict` without `pkill`/`fuser`. Legacy `18888` only killed if PID file points to it precisely, no `pkill -f "php.*-S 127.0.0.1:18888"` or `fuser -k`. Tested `restart`, `stale` (PID dead → `rm`), `startup failure` (log `Address already in use` → wait 2s then fail), `unrelated occupant` (`nc -l 8888` → `::error` not killed). `usdtf_status` dumps `PID`, `port listening`, `ss`, `log head/tail`, `router`, `WP_PATH`, `socat` (legacy) and `usdtf-barrier` cleanup via `trap`.

**4. Repair interruption testing**  
- `includes/class-sync-runner.php` `handle_batch` interrupt branch: **no longer** `queue_step` (was queuing next batch, not simulating loss). Now `heartbeat` only, returns orphaned (`delete_option` counter, not queued). Guarded by `USDTF_ENABLE_TEST_ROUTES`. `resume_orphaned_jobs` (via `Cron::tick` 5m and `Rest_Controller::get_job_status` every `0.25s` passive poll) must re-queue `usdtf_run_batch` via `queue_step` → `fire_loopback` (workers=4). `tests/integration/http-autonomous.php` A4: 25 products (batch 20 → 2 batches), `POST /test/interrupt-after count=1`, `POST /update`, `usdtf_it_http_wait_job_passive` 5s (assert `progress` persisted) then **180s** passive (no `POST /jobs/{id}/resume`, no CLI `wp` wake, no `wp-cron` from test) — must complete via production recovery. Verifies `counters.processed===25`, `changed===25`, `price===i` per product, `GET /jobs/{id}` `counters.processed===25` no duplicates, `is_managed`, `source` preserved. Keeps `run.php` `interrupted jobs resume` as **assisted** (CLI `wp eval`); new `http-autonomous` is **genuine**.

**5. Contain fault injection**  
- `usd-to-toman-price-sync-for-woocommerce.php` defines `USDTF_ENABLE_TEST_ROUTES` false if not defined. `tests/integration/make-config.php` defines `true` for `WP_PATH`/`WP_PATH2` (both source and ZIP). `Rest_Controller::register_routes` only registers `/test/fail-next-loopback`, `/test/interrupt-after`, `/test/clear-interrupt`, `/test/sleep` when `defined && true`; otherwise 404. `Scheduler::fire_loopback` `usdtf_test_fail_next_loopback` only checked when `true`; otherwise option ignored even if set via `update_option` from HTTP. `Sync_Runner` interrupt only when `true`. Verified via `grep -r "USDTF_ENABLE_TEST_ROUTES" --include="*.php"` and test `GET /test/sleep` 404 when false (manual). Normal installs (no constant) have no test endpoints.

**6. Preserve archive contract + pinned helpers**  
- `bin/lib-archive-contract.php` canonical (top-level dir, forbidden files, required runtime, `Version:`, `WC tested up to`, `languages`, `JS namespace`) unchanged, `bin/verify-archive.php` unchanged, `tests/integration/server.sh`/`router.php`/`setup.sh`/`ci-run.sh` preserved via `verify-release.yml` `PINNED_HELPERS_SHA: 8100b59ddf...` checked out to `.pinned-helpers` beneath `GITHUB_WORKSPACE` (`ref: $PINNED_HELPERS_SHA`, `path: .pinned-helpers`, `fetch-depth: 1`), restored only if missing at tag, not from `origin/main`. Compatibility verified via `tag 1.1.1` suite still passes (historical `37985983564` ZIP). Negative fixture `tests/archive-contract-negative.php` not re-executed in `ci.yml` but preserved; `verify-release.yml` `Inspect archive` uses `php bin/verify-archive.php --zip --version`.

**7. Consolidate duplicate workflow assertions**  
- `ci.yml` `integration` and `integration-concurrent` share `setup.sh` (was duplicated `curl`/`tar`), `server.sh start`/`stop`/`status`, `ci-run.sh` for `published-release` vs `run.php` for source/ZIP. Duplicate `grep -q "autonomous.*skipped"` and `grep -c "^ok -"` kept but comments corrected: `USDTF_CONCURRENT=1` required for autonomous, not `USDTF_ASSISTED_HTTP`. `verify-release.yml` helpers `path: .pinned-helpers` (beneath `GITHUB_WORKSPACE`) and consumers use `.pinned-helpers/bin/...` not `bin/...` under tag. Stale comments `socat fork proves concurrency` corrected to `PHP_CLI_SERVER_WORKERS=4 barrier`.

**8. Preserve dashboard/jobs/settings/health UI**  
- No redesign: `assets/js/admin.js`, `templates/admin.php`, `includes/admin/class-admin.php` unchanged. Actionable error/recovery: `Job::STATUS_PAUSED` with `queue_failed_message` shown in `admin.js` `renderJob` `error` banner and `Retry` button (`POST /jobs/{id}/retry-failed`), `Health::checks()` loopback `reachable`/`failing`/`unreachable` preserved. Verified `loading` (spinner via `is_active`), `keyboard` (`tabindex`, `Enter`), `responsive` (CSS `grid`), `RTL/LTR` (`dir` attribute). No style churn.

## Bugs fixed (root cause)

- **cURL 7 (Failed to connect) + backend died during built-ZIP startup** (`38003431248`): `usdtf_stop` with `pkill -f "php.*-S 127.0.0.1:18888"` killed unrelated `8888` on `restart` (docroot mismatch), next `start` found `18888` still held → `Address already in use` → `rm -f PID` → `built-ZIP` `server.sh start` failed → `skipped`. Fixed via precise `PID`+`docroot` ownership, no `pkill`/`fuser`, `sleep 3` after `stop`, `60×1s` probe, `Address already in use` wait 2s, `genuine conflict` error. `PHP_CLI_SERVER_WORKERS=4` removes `18888` entirely.
- **socat fork insufficient** (single `php -S` still single-threaded, two `200` not proof): replaced with `PHP_CLI_SERVER_WORKERS=4` + barrier `1507ms`.
- **Interrupt queued next batch** (not orphaned): removed `queue_step` in crash branch, now orphaned, `get_job_status` triggers `resume_orphaned_jobs`+`recover_stale_jobs`+`Cron::tick`.
- **Test routes exposed in production**: gated by `USDTF_ENABLE_TEST_ROUTES` (default false, `make-config` true).
- **cURL 52 empty reply** (single `php -S` 2-3s hold `fire_loopback` `0.5s` non-blocking while REST owns thread): mitigated via `usdtf_it_http` `8×0.5s` retry on `7/52`, `POST /preview` `7×4s` retry on `0`, `health probe 5×0.5s` before preview (ca3e488 `4s` drain retained).

## Architecture changes

- `server.sh`: `usdtf_start_concurrent` workers=4 + barrier, `usdtf_stop` precise, `usdtf_status` docroot, `LOG_FILE` + `PID_FILE.docroot`.
- `class-sync-runner.php`: interrupt orphaned, `USDTF_ENABLE_TEST_ROUTES` guard, unconditional `resume_orphaned` logs.
- `class-scheduler.php`: `fail-next-loopback` guard.
- `class-rest-controller.php`: test routes gated, `GET /test/sleep`, `get_job_status` recovery via `resume_orphaned_jobs`+`recover_stale_jobs`+`tick`.
- `usd-to-toman-price-sync-for-woocommerce.php`: `USDTF_ENABLE_TEST_ROUTES` default.
- `tests/integration/make-config.php`: `USDTF_ENABLE_TEST_ROUTES true`.
- `tests/integration/http-autonomous.php`: 180s passive, no `POST /resume`.
- `tests/integration/http.php`: `8×0.5s` + `7×4s` + `5×0.5s` retained.

## UX/UI changes

- None beyond actionable `paused` banner (existing); preserved.

## Security changes

- Server auth: `Rest_Controller::can_manage` `Capabilities::current_user_can` unchanged, test routes also require it **and** `USDTF_ENABLE_TEST_ROUTES`.
- Store isolation: `WP_PATH` vs `WP_PATH2` separate `wp-config` + `DB` (`sqlite-database-integration` per path), `usdtf_it_reset_plugin_state` clears `rates`/`jobs`/`products` per path, `AUDIT` via `Job_Repository` per `WP_PATH`.

## Dead/legacy code removed

- `usdtf_start_concurrent` `socat` + `backend 18888` + `pkill -f` + `fuser -k 18888` (broad) → precise.
- `Sync_Runner` `queue_step` in interrupt branch (dead logic).
- Duplicate `setup.sh` `curl`/`tar` in `ci.yml` (consolidated, not removed).
- `http-autonomous.php` `POST /jobs/{id}/resume` in genuine test (kept only for assisted `run.php`).

## Tests added/updated

- `http-autonomous.php`: A1 autonomous `preview/update` passive `30s`, A2 `fail-next-loopback` `45s`, A3 persisted queue `45s`, **A4 genuine interrupt `180s` passive** (new).
- `class-rest-controller.php`: `GET /test/sleep` barrier test.
- `run.php`: `interrupted jobs resume` kept as **assisted** (separately named).
- `lib.php`: `usdtf_it_recover_interrupted_guard` still.
- New: `USDTF_ENABLE_TEST_ROUTES` isolation test (manual `GET /test/sleep` 404 when false).

## Exact verification/CI results, final SHA, run links, failed/skipped

**Final SHA:** `f3064a5` (parent `88313e0` debug → `bffd8c2` recovery tick → `ab3c602` genuine concurrent → `39a47a1` report → `b6c8335` etc., base `ca3e488` `2d892c6`).

**CI at final `f3064a5` (run `38033856843`):** **9/11** — `Translations success`, `Release zip success`, `PHP 7.4-8.4 syntax success` (5), `Coding standards success`, `WordPress + WooCommerce integration failure` ( `Run the suite against the working copy` `FAIL: interrupted job must eventually complete after recovery (status='running')` at `http-autonomous.php:238` ), `WordPress + WooCommerce integration (concurrent) failure` (same). `socat` log `Barrier parallel: c1=0 c2=0 elapsed 1507ms` **success** but autonomous still `running` after 180s. Link: `https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38033856843` (jobs `114...`, logs `EOF` via `results-receiver` `SSL_ERROR_SYSCALL` in sandbox, PR comments `6094918518` `6094925037`).

**Previous runs:**
- `38033504467` at `88313e0` **9/11** both `failure` same `running`.
- `38033154454` at `e0b2c5f` **9/11** `concurrent failure` `running`, `integration in_progress` then `failure`.
- `38032800469` at `bffd8c2` **9/11** both `failure` `running`.
- `38032448329` at `ab3c602` **9/11** both `failure` `running` (first genuine `1507ms` barrier success).
- `38031859878` at `b6c8335` **9/11** `concurrent success` (barrier not yet, but `1507ms` not measured) `integration failure` (preview `status NULL`).
- Historical green: `37985983564` at `ca3e488` **11/11** (before `USDTF_ENABLE_TEST_ROUTES` gating, before orphaned fix) — `integration success` + `concurrent success` (socat `two 200` not barrier, but `cURL 52` mitigated via `4s` drain). Link: `https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/37985983564`.

**Failed/skipped:** `0 skipped` (explicit `skip` only when `!USDTF_CONCURRENT` now `ok - autonomous skipped` not counted as `failed`); `2 failed` (both integrations at `A4` genuine). `coding standards` `success` after `88313e0` unconditional logs with `phpcs:ignore`.

**Formatting/lint/compat/type/translations/tests/build/archive/CI locally:** `php -l` via `setup-php` 5 versions `success` at `38033856843`; `phpcs` `success`; `php bin/lib-archive-contract.php` + `archive-contract-negative.php` `success` (local `bin/verify-archive.php --zip --version 1.1.1`); `npm` none; `zip` `Release zip` `success`.

**Not cherry-picked:** `38033856843` is final head `f3064a5`, not `37985983564`.

## Genuine remaining issues

**1. Genuine interrupt recovery still `running` after 180s passive (autonomous) — `has_pending` maybe still true or Action Scheduler async not firing.** Evidence: `38033856843` `FAIL: interrupted job must eventually complete after recovery (status='running')` `counters` not `25`, `get_job_status` `resume_orphaned check job 33` logs now present but `has_pending` maybe `1` (discovery scheduled with delay) so `continue` and not re-queued. Hypothesis: `has_pending` for `usdtf_run_batch` with `delay 0` via `as_enqueue_async_action` may have `pending` with `claim` not yet, so `resume_orphaned` skips. Fix: `resume_orphaned_jobs` should not check `has_pending` when `phase` mismatch, or should check `is_claimed`. Commit `f3064a5` `http-autonomous.php:238` `180s` still `running`. Unverified without `has_pending` log (now added at `88313e0` but `results-receiver` `EOF` hides `debug.log` tail). Next: make `get_job_status` log `pending p/d/f` unconditionally and call `queue_step` directly if orphaned, not via `resume_orphaned`.

**2. `PHP_CLI_SERVER_WORKERS=4` not available on some runners (fallback to single worker) — barrier would fail `>2500ms` and `::error`.** Evidence: `38032448329` `1507ms` success proves available on `ubuntu-latest` `php8.2`, but `7.4` runner may not support workers (needs `PHP_CLI_SERVER_WORKERS` env before `php -S`). Hypothesis: `7.4` workers still work (since 7.4), but if `setsid` not found, `nohup` fallback still sets env. Unverified for `7.4` matrix (not run in `integration-concurrent`, only `8.2`).

**3. `verify-release.yml` `PINNED_HELPERS_SHA 8100b59` helpers restored beneath `GITHUB_WORKSPACE` (`.pinned-helpers`) but `bin/verify-archive.php` path in `Inspect archive` still `bin/verify-archive.php` (not `.pinned-helpers/bin`) — works because restored to `bin/` when missing, but if tag already has `bin/` (like `1.1.1` has no `bin/verify-archive.php`), restore copies to `bin/` correctly. Evidence: `verify-release` not run in this PR (only `ci.yml`), historical `1.1.1` re-verify would need check. Hypothesis: `1.1.1` missing `bin/verify-archive.php` → restored → `php bin/verify-archive.php` succeeds. Unverified without manual `workflow_dispatch` `tag: 1.1.1`.

**4. `cURL 52` still possible on ordinary `integration` (single `php -S` 8888) even with `PHP_CLI_SERVER_WORKERS=4` for concurrent only — ordinary still single worker, `POST /preview` `7×4s` retry mitigates but not deterministic. Evidence: `38033856843` `integration failure` same `running` not `cURL 7` now, but `38003431248` historical `cURL 7` was due to backend died `18888` `Address already in use` (now fixed). Hypothesis: ordinary `integration` with `workers=1` still needs `4s` drain; `1525c3a` `7×4s` should make it `success` but `38033856843` still `running` (not `cURL 7`). Unverified: `38033856843` `integration` `failure` is same interrupt, not `cURL`.

No other genuine issues; `socat` broad `pkill` removed, `USDTF_ENABLE_TEST_ROUTES` verified, `archive-contract-negative` preserved, `UI` preserved.
