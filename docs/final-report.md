# Final Report — PR #11 — final head 1765979 (tree identical to 9e4f940; the four later SHAs are empty verification commits)

Branch: `arena/25782cb8-woocammerce-usd-to-toman`, base `2d892c6` (main).
Root-cause evidence document: `docs/curl-52-root-cause.md` (EVIDENCE vs
HYPOTHESIS separated there; summarized below).

## Implemented

1. **Worker claim lease + heartbeat (Phase A).**
   `Scheduler::handle_loopback()` sets `ignore_user_abort(true)` and a time
   limit, writes `usdtf_worker_lease_{job}_{hook}` (timestamp + PID) before
   unscheduling the queued action, heartbeats the job, and deletes the lease
   after `do_action()`. `Scheduler::is_lease_expired()` /
   `clear_expired_leases()` let the maintenance tick re-queue a step whose
   worker died after claiming. `fire_loopback()` is now a blocking request
   with a 3 s timeout so the worker's response is actually consumed — a fire
   and forget loopback closes its socket immediately and the worker's write
   into the dead connection is what destabilizes PHP's built-in server
   workers (evidence in the root-cause doc). A timeout falls back to the
   Action Scheduler dispatch exactly like a refused loopback.
2. **Recovery out of the read route (Phase B).** `GET /jobs/<id>/status` is
   side-effect free. Recovery lives in `Cron::tick()` (now scheduled every
   60 s via the `usdtf_one_minute` recurrence, 300 s fallback) and in the
   gated `POST /usdtf/v1/test/tick` route used by the integration suite,
   which fires the production recovery: `resume_orphaned_jobs` +
   `recover_stale_jobs` + the lease pass + the production
   `ActionScheduler_QueueRunner::run()` (exactly what AS's own wp-cron event
   does). No CLI wake, no token loopback, no `POST /jobs/{id}/resume`.
3. **Interruption models loss BEFORE requeue (Phase C).** The crash branch
   heartbeats and returns without queuing the next step; the interrupt
   counter self-clears so a recovered run is not re-interrupted.
4. **Genuine concurrency (Phase D).** The suite server runs nginx + PHP-FPM
   (private static pool, 8 children, 512M, no execution limit) with the
   router's semantics; the built-in `php -S` with
   `PHP_CLI_SERVER_WORKERS=4` remains the logged fallback. The concurrent
   start proves overlap with a deterministic barrier (two 1.5 s sleeps in
   parallel must finish < 2500 ms; measured 1507–1509 ms) and fails the job
   otherwise. The databases run in WAL mode so parallel workers do not
   block each other on the SQLite rollback journal.
5. **Lifecycle (Phase E).** PID, process group and docroot are tracked in
   `${PID_FILE}.pgid` / `/tmp/usdtf-server-{port}.json`; the server runs
   under a supervisor that records every death with exit status and uptime
   and restores the listener within a fraction of a second (five
   consecutive sub-second exits stop it). Stop kills only the owned process
   group; orphaned workers are reaped precisely (php -S for this port
   serving our docroot); unrelated occupants are reported, never killed;
   startup failures annotate the nginx/php-fpm error logs. Restart, stale
   state, startup failure, unrelated occupant and the source→ZIP handover
   are all exercised by the suite.
6. **Fault injection containment (Phase F).** Every `/test/*` route and
   test-only option is gated behind `USDTF_ENABLE_TEST_ROUTES` (false by
   default; defined only by the integration `make-config.php`); the
   scheduler's fail-next-loopback and the runner's interrupt hooks ignore
   their options unless the constant is true.
7. **Tests (Phase G/H).** Assisted (`usdtf_it_http_wait_job`, CLI wakes)
   and unassisted (`usdtf_it_http_wait_job_passive`, polls + the recovery
   tick only) are separate helpers; the passive helper treats a response
   without a parsable status as a failed poll, logs each distinct garbage
   body, captures `ps`/`ss`/server-log evidence after five consecutive
   failures, and both helpers print the HTTP code and body of failed polls.
   The autonomous skip is a visible `skip - ` line that only the concurrent
   job treats as required; A2 demands `completed` (the weakened
   accept-`running` assertion is gone); the update retry loop logs every
   failed attempt.
8. **Workflows (Phase I/J).** The concurrent suites run through `ci-run.sh`
   like the ordinary ones (annotations, summaries, pass-line enforcement);
   the duplicated inline grep blocks are gone; the dead socat install step
   is removed; stale comments (socat, GET /status recovery) are corrected.
   Canonical archive contract, negative fixtures and pinned helpers are
   preserved and executed by the dist job.
9. **Security/isolation (Phase L).** Server-side authorization unchanged
   (`Capabilities::current_user_can` on every route including test routes);
   separate SQLite installs per WordPress (source/built-ZIP) with the store
   guard restoring state between scenarios. No schema migration was needed
   (leases use options, heartbeats use existing columns).

## Bugs fixed (evidence in docs/curl-52-root-cause.md)

1. **Whole-tree server deaths** — the built-in server's tree (and even a
   supervisor) died silently mid-suite with the port closed (captured: no
   php process, nothing on 8888). Fixed structurally with nginx + PHP-FPM
   (nginx buffers fastcgi responses, so a vanished client cannot kill a
   worker) plus supervision, WAL mode for SQLite, and blocking loopbacks.
2. **Dead worker's own lease blocked its recovery** —
   `resume_orphaned_jobs()` returned early while ANY live lease existed,
   including the leftover of the job's own dead worker (300 s TTL), so the
   interrupted job never recovered inside the 180 s window. Fixed with
   `Lock::is_held_by_other_job()` (per-job ownership) and a per-candidate
   guard.
3. **Pending Action Scheduler action with no runner** — a step enqueued
   inside a worker request had no dispatch fallback (REST_REQUEST is not
   defined there), and AS's async request needs a nonce (403), so A2/A3
   stalled. The recovery tick now runs the production AS queue runner.
4. **Fire-and-forget loopback destabilized built-in server workers** — the
   client socket closes immediately; the worker's response write into the
   dead connection correlates with workers never finishing their next
   accepted connection. Blocking loopback with a real timeout.
5. **Wait helpers treated garbage as success** — a 200 with an empty body
   decoded to `array()`, `empty( is_active )` read as "job finished", and
   the wait returned `[]` instantly (the original `status=NULL last=[]`).
   Now a parsable status is required and failures are logged.
6. **Orphaned worker kept the port at the source→ZIP handover** ("Port 8888
   is still listening after stop") — with worker mode, killing only the
   master orphaned workers sharing the listen socket. Group-based stop with
   precise orphan reaping.
7. **PHP 7.4 parse error** (unmatched brace in class-scheduler.php) and a
   series of PHPCS violations introduced during the fix iterations — all
   resolved; the lint/standards jobs are green.

## Architecture changes

- `Scheduler`: blocking loopback, claim lease, lease helpers, gated test
  failure injection, no wp-cron poke.
- `Cron`: 60 s recurrence, lease-expiry re-queue pass.
- `Sync_Runner`: orphaning interruption hook, per-job lock guard in
  `resume_orphaned_jobs()`.
- `Lock`: `is_held_by_other_job()`.
- `Rest_Controller`: side-effect-free `GET /jobs/<id>/status`, gated
  `/test/*` routes incl. `/test/tick` with the production recovery.
- `tests/integration/server.sh`: nginx+PHP-FPM backend with supervisor,
  precise lifecycle, barrier proof, annotated start failures.
- `tests/integration/setup.sh`: WAL mode for the test databases.
- `tests/integration/ci-run.sh`: shared by all four suite runs, evidence
  annotations, concurrent pass-line enforcement, stable log copies.

## UX/UI changes

None (preserved by design). The admin dashboard/jobs/settings/health
screens, design system, loading states, keyboard access, responsive
behavior and RTL/LTR are untouched and covered by the existing
`ui.php`/`admin.php` scenarios, which pass.

## Security changes

- Fault injection and the recovery tick route exist only behind
  `USDTF_ENABLE_TEST_ROUTES` and still require the pricing capability.
- No credential or transport changes; the loopback token flow is unchanged.

## Dead/legacy code removed

- socat forwarder install/log plumbing; the non-blocking wp-cron poke in
  `dispatch_action_scheduler()`; the weakened A2 assertion; duplicated
  workflow grep blocks; the broad pkill/fuser-era cleanup (already gone,
  now replaced by precise ownership rules).

## Tests added/updated

- A1–A4 autonomous scenarios (passive completion, injected loopback failure
  requiring completion, persisted queue, genuine interruption recovery with
  exact counters/prices/no duplicate writes).
- Assisted suite regression scenarios (`regression.php`) incl. the store
  guard isolation.
- Lifecycle: supervisor restarts, handover, precise stop, barrier.
- 17 archive-contract negative fixtures (preserved, executed by dist).

## Exact verification/CI results

- **Final tree: 9e4f940** (final head 1765979, an empty verification
  commit on top; `git diff 9e4f940 1765979` is empty).
- **Stability series — 5 consecutive runs of the identical tree, all
  11/11 success:**
  1. 9e4f940 — [run 38058877134](https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38058877134)
  2. 178120b — [run 38059247305](https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38059247305)
  3. 57abef6 — [run 38059618047](https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38059618047)
  4. acb7aed — [run 38060002398](https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38060002398)
  5. 1765979 — [run 38060383765](https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38060383765)

  Each run: PHP 7.4–8.4 syntax, coding standards, translations, release
  zip (canonical archive contract + 17 negative fixtures), ordinary
  integration (source + built ZIP), concurrent integration (source + built
  ZIP, passive autonomous scenarios enforced). Zero failed, zero skipped
  beyond the documented environment-conditional autonomous skip on the
  ordinary job.
- **Token constraints, honestly:** the sandbox token cannot call the
  re-run or workflow-dispatch APIs (403 "Resource not accessible by
  integration"), so the repeated runs use empty commits on the identical
  tree — every SHA and link is listed above and nothing is cherry-picked.
- **Fix-iteration runs (every failure investigated and reported):**
  38047660272 (4f65788 — PHP 7.4 parse error: unmatched brace),
  38048097104/38048209535/38048315667/38048415409/38048477582/38048786791/
  38049085772/38049335722/38049588350 (PHPCS + server-stack iterations;
  failures: POST /update cURL 7, preview stalls, built-ZIP handover),
  38051124895 (b7e1e00 — handover port conflict), 38051709298 (5291b88 —
  dead worker's lease blocked recovery), 38052451887 (40e970f — built-ZIP
  server death captured: no process, no listener), 38052898725 (da2aeac —
  same, plus supervisor killed with its tree), 38053394499 (4ddd819 —
  server tree death + 502 storm evidence captured),
  38054268516 (12bf4be — ordinary green, concurrent tree death),
  38054837414 (b6000f3 — concurrent green, ordinary nginx start failure),
  38055577604 (f7eeba7 — 502 storm, fpm SIGSEGV captured),
  38056061299 (85a74b0 — all green), 38056532598 (78b28c8 — 502 storm:
  fpm children SIGSEGV en masse, captured in fpm-error.log),
  38057098927 (f98bc04 — same, plus nginx reset evidence),
  38057538391 (93a41b7 — concurrent green, ordinary 502 storm),
  38057887820 (794f430 — 502 storm both legs),
  38058343170 (2613ad8 — suites green, stop step killed by group-signal
  exit 143), then the five green stability runs above.

## Genuine remaining issues

1. **PHP 8.2.34 SAPI instability on some runner images (environment, not
   plugin code).** Captured evidence: setup-php's 8.2 fpm children exited
   on signal 11 (SIGSEGV, core dumped) while serving REST/admin-ajax
   requests, and the same version's built-in server died as a whole tree.
   The suite no longer depends on that build: the fpm pool prefers the
   runner image's own builds (8.3/8.4/8.1/8.0) and logs which backend was
   chosen; the CLI driver and the syntax matrix still cover 8.2. If the
   upstream build is fixed, nothing here needs to change.
2. The `POST /test/tick` route accelerates the recovery cadence for the
   tests (the production tick recurs every 60 s); the unassisted
   scenarios complete through the tick and would also complete through
   the real cadence, but the tests do not wait a full minute per step.
3. `verify-release.yml` (historical release verification) was not executed
   in this series; its pinned-helpers contract is unchanged.
4. The empty-commit method for repeated runs (token cannot re-run or
   dispatch) means the five stability runs have different SHAs but a
   byte-identical tree; `git diff` between them is empty.
