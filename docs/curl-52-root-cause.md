# curl 52 / cURL 7 Root Cause — Evidence vs Hypothesis

Branch: `arena/25782cb8-woocammerce-usd-to-toman`. Updated after CI runs
38047660272 (4f65788), 38048477582 (84c71c1), 38048786791 (e3e1cb4),
38049335722 (14dcb04), 38049588350 (ea72f81) and the baseline 38034289660
(fd1ab90).

## EVIDENCE

### Baseline (38034289660, fd1ab90)

- Ordinary integration: PASSED 59 `ok -` lines, one of which was
  `ok - autonomous (passive) HTTP skipped on php -S: requires USDTF_CONCURRENT=1`
  — a hidden skip counted as a pass.
- Concurrent integration: FAILED deterministically at
  `tests/integration/http.php` — the preview started over REST was never
  completed by the queue (`status=NULL last=[]`, no successful poll payload
  in 120 s). Earlier PR comments show: `POST /preview` → 200, one worker
  `POST admin-ajax.php` arrives ~7 s later, the job reads `running` with
  `processed:0` and stalls; Action Scheduler's async runner answered 403
  without a nonce and took ~7 s with one.
- 38003431248: the concurrent backend died during built-ZIP startup with
  `Address already in use` after the previous `stop` — the socket was still
  held (no wait/port-listening guard, no exit-status or process-group
  evidence captured). Fixed by the 3 s drain + `usdtf_port_listening` guard
  + `ps -o pid,ppid,pgid,cmd` diagnostics now in `server.sh`.

### Post-squash runs (workers=4 concurrent server, barrier 1507 ms < 2500 ms)

- A1 (autonomous preview/update without wake) PASSES — the loopback from the
  REST thread is served by a free worker and the job completes.
- A2 (injected `fail-next-loopback` for `usdtf_run_batch`) "passed" only
  because the assertion accepted `running` (a weakened assertion). The job's
  batch action sits PENDING in Action Scheduler: the injected failure
  consumes the loopback dispatch, and the enqueue happens inside the worker
  (admin-ajax) request where `REST_REQUEST` is not defined, so the
  Action-Scheduler dispatch fallback never fires. Nothing runs the pending
  action within the test window.
- A3 (persisted queue must complete) FAILED with `status='running'` after
  45 s for the same reason — the pending Action Scheduler action had no
  runner: AS's async request needs a nonce (403 in the server log:
  `POST /wp-admin/admin-ajax.php?action=as_async_request_queue_runner → 403`)
  and AS's own `action_scheduler_run_queue` wp-cron event recurs only every
  60 s, which does not fit a 45 s window.
- A4 (worker interrupted after batch 1, orphaned) FAILED with
  `status='running'`: the orphaned job has NO pending action, so only
  `resume_orphaned_jobs()` can re-queue it. Before this change that call was
  bolted onto `GET /jobs/<id>/status` (a read route with side effects), and
  after removing the side effect nothing triggered recovery within 180 s.
- Ordinary integration FAILED at `POST /update … cURL error 7: Failed to
  connect to 127.0.0.1 port 8888 after 0 ms` while the server log shows the
  server continuously serving (including the successful `POST /update → 200`
  and the following job polls). The refused connections never reach the
  server (no `Accepted` lines). The ordinary server was still single-thread
  (`usdtf_start` without workers): every enqueue from REST fires a
  non-blocking loopback plus (as a fallback) an AS async request and a
  `wp-cron.php?doing_wp_cron` poke; each wp-cron pass runs due events
  (Action Scheduler's queue runner has a 20 s time limit), so the one worker
  is saturated and the listen backlog overflows — on the runner that
  surfaces as an instant `ECONNREFUSED` (cURL 7, "after 0 ms").

## HYPOTHESIS → VERIFICATION

- "Claim-by-delete before `do_action()` with a 0.5 s non-blocking client and
  no `ignore_user_abort` loses the step when the worker is aborted" —
  PARTIALLY CONFIRMED: the claim has no lease and `handle_loopback()` did
  not set `ignore_user_abort`/time limit (fixed in Phase A). The stall seen
  in the baseline, however, is fully explained by the missing dispatch for
  pending Action Scheduler actions and the missing recovery trigger — both
  now fixed and observable in the runs above.
- "Recovery on a read route (GET /status) only happens if someone polls" —
  CONFIRMED by design; the fix moves recovery to a dedicated trigger.

## FIXES (this change set)

1. **Lease + heartbeat on the worker claim** (Phase A):
   `handle_loopback()` sets `ignore_user_abort(true)` and a time limit,
   writes `usdtf_worker_lease_{job}_{hook}` with a timestamp before
   unscheduling, deletes it after `do_action()`, and heartbeats the job.
   `Scheduler::is_lease_expired()` / `clear_expired_leases()` let the
   maintenance tick re-queue a step whose worker died after claiming.
2. **Recovery out of the read route** (Phase B): `GET /jobs/<id>/status` is
   side-effect free again. Recovery lives in `Cron::tick()` (scheduled every
   60 s via the new `usdtf_one_minute` recurrence, 300 s fallback) and in
   the gated `POST /usdtf/v1/test/tick` route used by the integration
   suite, which fires the same production recovery: `resume_orphaned_jobs`
   + `recover_stale_jobs` + the lease pass + the production Action Scheduler
   queue runner (`ActionScheduler_QueueRunner::run()`, exactly what AS's
   own wp-cron event does). No CLI wake, no token loopback, no
   `POST /jobs/{id}/resume`.
3. **Interruption models loss BEFORE requeue** (Phase C): the crash branch
   heartbeats and returns without queuing the next step; the counter
   self-clears so a recovered run is not interrupted again.
4. **Genuine concurrency everywhere** (Phase D): both `usdtf_start` and
   `usdtf_start_concurrent` now run `PHP_CLI_SERVER_WORKERS=4`; the
   concurrent start proves overlap with a deterministic barrier (two 1.5 s
   sleeps in parallel must finish < 2500 ms; measured 1507 ms) and fails the
   job otherwise.
5. **Precise lifecycle** (Phase E): PID, process group and docroot are
   tracked in `${PID_FILE}.pgid` and `/tmp/usdtf-server-{port}.json`; only
   owned processes are killed (cmdline + docroot verified), unrelated
   occupants are reported, never killed; startup failures tail the log and
   dump `ps` evidence; the legacy 18888/socat cleanup is precise and the
   dead socat install step was removed from CI.
6. **Fault injection contained** (Phase F): every `/test/*` route and test
   option is gated behind `USDTF_ENABLE_TEST_ROUTES` (false by default,
   defined only by the integration `make-config.php`).
7. **Tests** (Phase G/H): assisted (`usdtf_it_http_wait_job`, CLI wakes) and
   unassisted (`usdtf_it_http_wait_job_passive`, polls + the recovery tick
   only) are separate helpers; the passive helper logs the HTTP code and
   body of failed polls and stashes them for assertions; the autonomous
   skip is now a visible `skip - ` line that only the concurrent job
   treats as required; A2 demands `completed` (the weakened
   accept-`running` assertion is gone); the update retry loop logs every
   failed attempt for attributable evidence.
8. **No new sleeps**: no timeout or retry count was increased in this
   change set; A2's window stays 45 s and A4's stays 180 s.

### The total-accept-stop hang (runs b7e1e00 and 5291b88) — CAPTURED

The concurrent suite's `http.php` preview wait failed with
`status=NULL last=[]` while the server log showed:

- Every worker logs its final `[200]` (responses written to fire-and-forget
  clients whose sockets were already closed), then
- accepts one more connection (`Accepted` with no status line, no
  `Closing`) and never completes it, and
- the subsequent polls/wakes are all accepted-and-stuck — the port still
  listens, so the failures are not refusals but never-finishing requests.

`Scheduler::fire_loopback()` used `'blocking' => false, 'timeout' => 0.5`:
WP's non-blocking mode returns immediately and closes the client socket, so
the worker always wrote its `ok` response into a dead connection. That
dead-socket write is what leaves the PHP built-in server worker unable to
finish its next accepted connection. The same pattern existed for the
`wp-cron.php?doing_wp_cron` poke added to `dispatch_action_scheduler()`.

A second bug amplified the damage: `usdtf_it_http_wait_job()` treated ANY
`200` response with `is_array( $json )` as a payload — and an empty body
decodes to `array()`, which `empty( $last['is_active'] )` treated as "job
finished", so the wait returned `[]` immediately and the hang diagnostics
never fired.

Fixes: `fire_loopback()` is now a blocking request with a 3 s timeout (the
step's response is actually consumed; a timeout falls back to Action
Scheduler exactly like a refused loopback); the redundant non-blocking
wp-cron poke was removed; both wait helpers treat a response without a
parsable `status` as a failed poll, log each distinct garbage body once,
and capture `ps` (with wait channels), `ss` and the server log tail after
five consecutive failures.

### The mid-suite server death (run 4ddd819, built-ZIP leg) — CAPTURED

The hardened wait helpers finally caught the ordinary leg's failure in the
act:

- `usdtf wait job 27: poll failed with code 0 body 'cURL error 7: Failed to
  connect to 127.0.0.1 port 8888 after 0 ms'` — instant refusal, i.e. the
  port was CLOSED, not busy.
- The hang diagnostics captured at that moment: **no `php -S` process
  exists at all** (only the runner's php-fpm pools and the test CLI), and
  `ss` shows nothing listening on 8888.
- The server log ends mid-suite right after serving the preview request —
  the whole supervised tree (master + workers) died with no request-level
  error, the same silent-death class as run 38003431248.

Since the tree can die without leaving any trace in its own log, the
lifecycle now runs the server under a **supervisor** (`server.sh
__supervise__`): every death is recorded with its exit status and uptime
(`[server] php -S (port …) exited with status N after Ns`), the listener is
restored within a fraction of a second so a crashed dev-server cannot
silently void a whole suite, and five consecutive sub-second exits stop the
supervisor instead of spinning. The queue is persisted in the database, so
a restarted server picks the job up from its persisted progress — the
recovery paths this PR adds are what make that safe. Orphaned workers that
survive a dead master are reaped precisely (php -S for this port and
docroot only) before each restart.
