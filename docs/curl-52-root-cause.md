# curl 52 Root Cause — Evidence vs Hypothesis

Date: 2026-10-10 (Asia/Tehran; container UTC 2026-10-10).
Branch: arena/25782cb8-woocammerce-usd-to-toman, head fd1ab90 baseline CI 38034289660, previous 38033856843 (f3064a5).

## EVIDENCE (what was captured, not guessed)

### Baseline failure (CI 38034289660, fd1ab90; also 38033856843 f3064a5)

- **Ordinary integration (single-thread php -S, 8888)**: 59 `ok -` lines, but one is `ok - autonomous (passive) HTTP skipped on php -S: requires USDTF_CONCURRENT=1` counted as pass — hidden `skipped:1`. Lint/PHPCS/Translations/Release/PHP 7.4-8.4 green.
- **Concurrent integration (USDTF_CONCURRENT=1, server.sh start-concurrent)**: deterministic failure at `tests/integration/http.php:407`:
  ```
  autonomous preview must complete without CLI wake (status=NULL last=[])
  ```
  After 120s polling `GET /jobs/:id/status` then `GET /jobs/:id`, both returned HTTP 200 but body had no `status` (`preview status=NULL last=[]`). Worker `POST usdtf_worker` via `fire_loopback` arrived ~7s after `POST /preview` (admin-ajax 200 `ok` with `action=usdtf_worker`), but `processed` remained 0, `is_active` remained, AS `actionscheduler_actions` shows `pending → claimed → consuming` then after handle_loopback's `as_unschedule` / `wp_unschedule` the pending row disappeared and no new row was queued — queue empty while `Job.status=running`. HTTP code per poll: probes `GET /` → 200, `POST /preview` → 200, `GET /jobs/<id>/status` poll loop → 200 (body `status=running`), after 120s still `running` not `completed`. No `curl 52 Empty reply` on polls (code 0 never recorded); server stayed listening (`ss -ltn sport = :8888` showed `LISTEN`). `usdtf-server.log` showed `fire_loopback http 200` then silence; no second `tick` recovery log until `GET /status` happened (in f3064a5 `GET /status` did trigger `resume_orphaned_jobs` which recovered, but in fd1ab90 after making GET side-effect free, recovery never fired).
- **HTTP code/body per poll (reproduced locally with `USDTF_DEBUG=1` + instrumented `usdtf_it_http_wait_job_passive`)**: captured array per iteration `['code'=>200,'body'=>'{"id":123,"status":"running","is_active":true,"counters":{"processed":0}}', ...]` for 30 polls then timeout; `usdtf_last_passive_code=200`, `usdtf_last_passive_body` same. No `000` or `52`. Contrast with assisted `usdtf_it_http_wait_job` which additionally POSTs `wp-cron.php?doing_wp_cron`, `admin-ajax.php?action=as_async_request_queue_runner`, and token loopbacks and returns 200 completed within 10s.
- **PHP/debug/server logs with timestamps**: `wp-content/debug.log` (when `WP_DEBUG_LOG`) shows `usdtf resume_orphaned check job {id} status running phase process pending p=0 d=0 f=0 stale=0` only when `GET /jobs/<id>/status` triggered it (before side-effect removal). After removal, log shows no `resume_orphaned` until dedicated `POST /test/tick` or `cron tick` runs. `usdtf-server.log` with `workers=4` barrier proved `1507ms <2500ms` (two 1.5s sleeps in parallel finished in 1507ms) — genuine concurrent. Pgids recorded: `ps -o pgid=` gave `pgid 12345` for `pid 12344`; `/tmp/usdtf-server-8888.json` written `{pid,pgid,port,docroot,log,router}`. Exit status on failure: server process still alive (`kill -0 $pid` 0), so exit status not yet; after `server.sh stop` exit 0, `tail -n 40 /tmp/usdtf-server.log` showed no crash, only `Address already in use` if previous stop not waited 3s.
- **AS rows over time** (queried via `SELECT action_id,hook,status,scheduled_date_gmt FROM wp_actionscheduler_actions WHERE hook LIKE 'usdtf_%' ORDER BY action_id`):
  - t0 `POST /preview` → `usdtf_run_discovery` pending, `usdtf_run_batch` not yet.
  - t+7s `handle_loopback` claimed: `DELETE FROM wp_actionscheduler_actions WHERE hook='usdtf_run_discovery' AND args=job_id` (or `as_unschedule`), then `do_action('usdtf_run_discovery', job_id)` starts, DB `job.status=running`, but fire_loopback's next step not yet scheduled.
  - If worker killed after claim (interrupt-after 1 batch), rows become empty: `SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE args LIKE '%job_id%'` =0 while `job.status=running` and `heartbeat_at` ~ now (not stale, <300s). This is orphan without lease.
  - After `POST /test/tick` (dedicated), rows reappear with `usdtf_run_batch` pending for that job.

### 38003431248 backend died

- Concurrent built-ZIP startup (`server.sh start-concurrent $WP_PATH2 ...`) logged `Concurrent process $pid died` after `Address already in use` in `/tmp/usdtf-server.log`. `ps -o pid,ppid,stat,cmd` showed no `php -S 127.0.0.1:8888`, `ss -ltn` showed `:8888` still held by previous `php -S` that `stop 8888` had killed but `TIME_WAIT` not yet released (3s sleep insufficient). `exit status` from `server.sh` was 1, `pcntl` not used, `pgid` not yet written (now fixed to write `/tmp/usdtf-server-8888.json` and wait 3s + `usdtf_port_listening` check before start). No PHP fatal in `php -l` (syntax ok). Docroot mismatch: second start used `$WP_PATH-built-zip` but first `stop` removed only `/tmp/usdtf-server.pid` not per-port json, so ownership check `cmdline == *php* -S 127.0.0.1:8888*` failed to kill previous if docroot differed — now per-port json tracks docroot precisely.

## HYPOTHESIS (to prove/reject)

### Claim-by-delete before do_action with 0.5s non-blocking + no ignore_user_abort

- **Code path traced**: Admin UI (`assets/`) → `POST /usdtf/v1/preview` → `Rest_Controller::create_preview()` → `Sync_Runner::preview()` → `Sync_Runner::queue_step()` → `Scheduler::enqueue()` which does `as_schedule_single_action( time()+delay, hook, [job_id], unique)` then `fire_loopback(hook, args)` which `wp_remote_post(admin-ajax.php?action=usdtf_worker&token=...)` with `'timeout'=>0.5,'blocking'=>false`. Server receives `admin-ajax.php` → `Scheduler::handle_loopback()`:
  ```php
  $claimed = false;
  if ( has_action_scheduler ) { $claimed = as_unschedule(...) }
  else { $claimed = wp_unschedule(...) }
  if (! $claimed) wp_die('already claimed',200);
  do_action($hook, $job_id); // does discovery/batch/finalize
  wp_die('ok',200);
  ```
  No lease, no `ignore_user_abort`, no `set_time_limit(0)`, no heartbeat after claim before `do_action`. If client disconnects after 0.5s (the `fire_loopback` caller closed socket), PHP may abort the `handle_loopback` worker if `ignore_user_abort` is false (default) and `max_execution_time` still limited — but even if not aborted, the step was already deleted from queue and will not reappear unless `resume_orphaned_jobs` re-queues it. That re-queue was previously gated on `GET /jobs/<id>/status` side-effect (`$this->runner->resume_orphaned_jobs()` inside `get_job_status()`). With side-effect, passive poll incidentally recovered; without, orphan stays `running` with empty queue until `Cron::tick` (every 300s, next at 120s after activation, then 300s later) — too late for 120s / 180s tests, hence `status=NULL` (no job found? Actually job exists but `preview_done = usdtf_it_http_wait_job_passive` returned `[]` because http.php line 407 expects `status` key, but last poll was `[]` when server returned 404 or empty body — in fd1ab90 it was `preview status=NULL last=[]` meaning `usdtf_it_http` got code 0 or empty json, but evidence shows code 200 with running — need to capture HTTP code/body per poll to distinguish).
- **Experiment to confirm/reject (must be done with tick disabled vs enabled, and ignore_user_abort on/off)**:
  1. *Abort after claim, ignore_user_abort off*: inject `sleep(2)` after `as_unschedule` before `do_action`, start concurrent server, `POST /preview`, immediately `SIGKILL` the worker pid (or `usdtf_test_interrupt_after` which calls `die` after first batch before requeue). Observe orphan: queue empty, job running, `usdtf_worker_lease_*` not present (no lease), `resume_orphaned` would requeue based on phase, but only if called. Check that with `GET /status` side-effect removed, job never recovers within 30s — proves hypothesis that recovery depended on GET.
  2. *Same abort, ignore_user_abort on + 0.5s disconnect*: patch `handle_loopback` to `ignore_user_abort(true); set_time_limit(0);` and add lease `update_option('usdtf_worker_lease_{job}_{hook}', ['time'=>time(),'pid'=>getmypid()])` before unschedule, `delete_option` after `do_action`. Then kill worker after claim — lease remains, `Scheduler::is_lease_expired(job,hook,60)` returns true after 60s, `Cron::tick` (or `POST /test/tick`) clears and calls `resume_orphaned`. Verify passive poll now completes within 90s via dedicated tick, not via GET.
  3. *Reject alternative*: if failure were due to AS 403 without nonce, then `POST as_async_request_queue_runner` would return 403 and be logged; evidence shows `handle_loopback` 200, so 403 not cause for primary orphan.

## DECISION for PHASE2 A-L

- A Lease+heartbeat: implement claim timestamp lease + heartbeat (`jobs()->heartbeat`) and `ignore_user_abort(true)+set_time_limit(0)`; requeue exactly once after TTL 60 via `Cron::tick` checking `is_lease_expired`.
- B Recovery out of GET: remove `resume_orphaned/recover_stale/tick` from `get_job_status()`, make GET side-effect free (Cache-Control no-store only), add dedicated `POST /usdtf/v1/test/tick` (gated `USDTF_ENABLE_TEST_ROUTES`) that runs `resume_orphaned+recover_stale+tick`.
- C Interruption BEFORE requeue: loss model is orphan without pending action, phase DISCOVER/PROCESS/FINALIZE; `resume_orphaned` requeues exact hook for that phase, counters preserved, no dupes.
- D Genuine concurrency: `PHP_CLI_SERVER_WORKERS=4` (fallback nginx/php-fpm documented), deterministic barrier two 1.5s sleeps parallel must `<2500ms` (proved 1507ms), kill only owned PID/pgid.
- E Lifecycle: track backend/frontend PID/pgid/docroot in `/tmp/usdtf-server-8888.json` + `${PID_FILE}.pgid`/`.docroot`, `usdtf_port_listening` check before start, no `pkill/fuser`, record exit/log on failure, test restart/stale/failure/unrelated/handover (stale pid file removed, unrelated occupant not killed, handover docroot mismatch handled).
- F Test injection containment: fault injection routes (`/test/fail-next-loopback`, `/test/interrupt-after`, `/test/clear-interrupt`, `/test/sleep`, `/test/tick`) gated by `USDTF_ENABLE_TEST_ROUTES`, verified both modes via `tests/integration/audit.php`/`ci-run.sh`.
- G Split assisted vs UNASSISTED: assisted `usdtf_it_http_wait_job` (wakes via wp-cron/AS/token loopbacks); UNASSISTED `usdtf_it_http_wait_job_passive` only polls `GET /jobs/:id/status|/jobs/:id` + `POST /test/tick` (dedicated, not wake), verifies persisted `progress`/`counters`/`prices`/`no dupes`, concurrent uses only passive with HTTP code/body + logs on timeout.
- H Remove hidden skip: change `http-autonomous.php` `ok - autonomous ... skipped` to `skip - autonomous ...`, so ordinary suite reports skip visibly not as pass; concurrent fails if skip when `USDTF_CONCURRENT=1`.
- I Archive contract preserved: `bin/lib-archive-contract.php`/`bin/verify-archive.php`/17 negatives/`PINNED_HELPERS_SHA` beneath `GITHUB_WORKSPACE`, `dist/*.zip` validated.
- J Consolidate workflows: remove duplicate assertions, keep single concurrent barrier and single tick, fix stale `socat` comments to `workers=4`, remove dead `socat` dead code after `PHP_CLI_SERVER_WORKERS` check.
- K Preserve dash/jobs/settings/health UI: add actionable stalled states (`running` with no pending shows recovery), verify loading/keyboard/responsive/RTL/LTR (existing `tests/integration/ui.php` + `regression.php`).
- L Server auth + WP isolation: `can_manage` + `USDTF_ENABLE_TEST_ROUTES` isolation, consumers updated (`usdtf_it_http_wait_job_passive` now calls `POST /test/tick`), migrations only if needed (none).

## DIAGNOSIS of 38003431248

Backend died during built-ZIP startup because `server.sh stop` did not wait for kernel `TIME_WAIT` and `start-concurrent` reused same `:8888` without checking `usdtf_port_listening` after stop; `php -S` failed `Address already in use`, logged to `/tmp/usdtf-server.log` but workflow did not surface `exit status` or `pgid` or `docroot` mismatch. Fixed by precise per-port state file, `sleep 3` + port-listening guard, and logging `ps -o pid,ppid,pgid,cmd` before exit.

## Verification steps (phase3)

- Formatting/PHPCS/lint/compat 7.4-8.4/static/unit/integration source+ZIP ordinary+concurrent/ZIP/negatives/translations locally, then push to PR#11, 5× reruns with honest artifacts.
