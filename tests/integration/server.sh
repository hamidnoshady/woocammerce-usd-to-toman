#!/usr/bin/env bash
# Canonical server lifecycle for the integration suite.
#
# Both suites run the PHP built-in server with PHP_CLI_SERVER_WORKERS=4
# (available since PHP 7.4): a single-threaded server turns every
# non-blocking loopback into a backlog race (cURL 7/52) and cannot prove
# autonomous completion at all. The server has to be observable: PID and
# process group tracked, health probed, logs tailed on failure, and
# stopped cleanly between runs.
#
# Usage:
#   bash tests/integration/server.sh start  <wp-path> [router] [port]
#   bash tests/integration/server.sh stop   [port]
#   bash tests/integration/server.sh status [port]
#   bash tests/integration/server.sh restart <wp-path> [router] [port]
#
# Environment:
#   USDTF_SERVER_LOG  Log file (default /tmp/usdtf-server.log)
#   USDTF_SERVER_PID  PID file (default /tmp/usdtf-server.pid)
#
# All paths are resolved to absolute so the working directory does not
# matter. The server is started with setsid/nohup and disowned so the
# GitHub runner does not kill it when the step's shell exits.

set -euo pipefail

DEFAULT_PORT="8888"
LOG_FILE="${USDTF_SERVER_LOG:-/tmp/usdtf-server.log}"
PID_FILE="${USDTF_SERVER_PID:-/tmp/usdtf-server.pid}"
ATTEMPTS=60
DELAY=1

usdtf_log() {
    echo "[server] $*" >&2
}

usdtf_resolve_router() {
    local router="$1"
    local wp_path="$2"

    # If no router given, try the integration router, fallback to index.php.
    if [ -z "$router" ]; then
        if [ -f "tests/integration/router.php" ]; then
            router="tests/integration/router.php"
        elif [ -f "${wp_path}/index.php" ]; then
            router="${wp_path}/index.php"
        else
            router=""
        fi
    fi

    # Make absolute if relative.
    if [ -n "$router" ] && [[ "$router" != /* ]]; then
        router="$(pwd)/${router}"
    fi

    # Resolve via realpath if possible, but keep original if missing.
    if [ -n "$router" ] && command -v realpath >/dev/null 2>&1; then
        # realpath fails for missing file; ignore.
        router="$(realpath -m "$router" 2>/dev/null || echo "$router")"
    fi

    echo "$router"
}

usdtf_resolve_wp() {
    local wp_path="$1"
    if [[ "$wp_path" != /* ]]; then
        wp_path="$(pwd)/${wp_path}"
    fi
    if command -v realpath >/dev/null 2>&1; then
        wp_path="$(realpath -m "$wp_path" 2>/dev/null || echo "$wp_path")"
    fi
    echo "$wp_path"
}

usdtf_is_alive() {
    local pid="$1"
    if [ -z "$pid" ]; then
        return 1
    fi
    if kill -0 "$pid" 2>/dev/null; then
        return 0
    fi
    return 1
}

usdtf_port_listening() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -ltn "sport = :${port}" 2>/dev/null | grep -q ":${port}"
        return $?
    fi
    if command -v netstat >/dev/null 2>&1; then
        netstat -ltn 2>/dev/null | grep -q ":${port}"
        return $?
    fi
    if command -v lsof >/dev/null 2>&1; then
        lsof -iTCP:"${port}" -sTCP:LISTEN >/dev/null 2>&1
        return $?
    fi
    # Fallback: try curl with timeout so a hung single-threaded server does not block.
    curl -fsS --max-time 3 --connect-timeout 2 -o /dev/null "http://127.0.0.1:${port}/" 2>/dev/null
    return $?
}


usdtf_supervise_server() {
    # Runs as its own setsid'd process and never returns normally.
    #
    # Backend selection: nginx + PHP-FPM when both binaries exist, the PHP
    # built-in server otherwise. The built-in server tree has been observed
    # to die mid-suite without any request-level error (whole process tree
    # gone, port closed, supervisor killed with it), a fragility of its
    # worker mode that fastcgi does not share: nginx buffers responses, so
    # a vanished client never kills a worker. Either way the supervisor
    # records every death with its exit status and uptime and restores the
    # listener within a fraction of a second; five consecutive sub-second
    # exits stop the supervisor so a genuinely broken start cannot spin.
    local php_bin="$1" port="$2" wp_path="$3" router="$4"
    local restarts=0 started status lifetime

    if command -v nginx >/dev/null 2>&1 && usdtf_fpm_binary >/dev/null 2>&1; then
        usdtf_supervise_fpm_nginx "$php_bin" "$port" "$wp_path"
        exit $?
    fi

    echo "[server] nginx/php-fpm unavailable, falling back to the built-in server (worker-mode fragility applies)" >&2

    while true; do
        started=$(date +%s)
        PHP_CLI_SERVER_WORKERS=4 "$php_bin" -d memory_limit=512M -d max_execution_time=0 -S "127.0.0.1:${port}" -t "$wp_path" "$router"
        status=$?
        lifetime=$(( $(date +%s) - started ))
        echo "[server] php -S (port ${port}) exited with status ${status} after ${lifetime}s at $(date -u +%Y-%m-%dT%H:%M:%SZ); restarting" >&2

        if [ "$lifetime" -lt 1 ]; then
            restarts=$(( restarts + 1 ))
            if [ "$restarts" -ge 5 ]; then
                echo "[server] php -S exited too quickly 5 times in a row, supervisor giving up" >&2
                exit 1
            fi
        else
            restarts=0
        fi

        # A restart can hit "Address already in use" when orphaned workers
        # from the dead tree still hold the port: reap precisely owned
        # leftovers (php -S for this port and docroot) before retrying.
        bash "$0" __reap__ "$port" "$wp_path" >&2 || true
        sleep 0.2
    done
}

usdtf_fpm_binary() {
    # Print the php-fpm binary matching the CLI version, or nothing.
    local cand
    for cand in php-fpm php-fpm8.2 php-fpm8.1 php-fpm8.0 php-fpm7.4; do
        if command -v "$cand" >/dev/null 2>&1; then
            command -v "$cand"
            return 0
        fi
    done
    return 1
}

usdtf_supervise_fpm_nginx() {
    # nginx + PHP-FPM supervision: one pool on a private unix socket, nginx
    # on the test port, both restarted together when either dies. Semantics
    # mirror the router: existing files are served directly, everything else
    # goes through index.php.
    local php_bin="$1" port="$2" wp_path="$3"
    local fpm_bin sock conf_dir restarts=0 started status lifetime fpm_pid

    fpm_bin="$(usdtf_fpm_binary)"
    sock="/tmp/usdtf-fpm-${port}.sock"
    conf_dir="/tmp/usdtf-nginx-${port}"
    mkdir -p "$conf_dir"

    cat > "${conf_dir}/fpm.conf" <<FPMCONF
[global]
error_log = ${conf_dir}/fpm-error.log
daemonize = no
pid = ${conf_dir}/fpm.pid
[usdtf]
listen = ${sock}
listen.backlog = 1024
listen.owner = $(id -un)
listen.group = $(id -gn)
listen.mode = 0660
pm = static
pm.max_children = 12
; Recycle children periodically and terminate stuck ones: a child that
; hangs or crashes must not hold a pool slot forever (requests then fail
; with 502 while the pool looks alive).
pm.max_requests = 200
request_terminate_timeout = 60s
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 0
php_value[upload_max_filesize] = 32M
php_value[post_max_size] = 32M
FPMCONF

    cat > "${conf_dir}/nginx.conf" <<NGINXCONF
daemon off;
pid ${conf_dir}/nginx.pid;
error_log ${conf_dir}/nginx-error.log warn;
worker_processes 1;
events { worker_connections 256; }
http {
    # Access log into the shared server log so failure diagnostics show the
    # request flow under nginx exactly like the built-in server did.
    access_log ${LOG_FILE};
    client_max_body_size 32M;
    server {
        listen 127.0.0.1:${port};
        server_name _;
        root ${wp_path};
        index index.php;
        location / {
            try_files \$uri /index.php\$is_args\$args;
        }
        location ~ \.php\$ {
            include /etc/nginx/fastcgi_params;
            fastcgi_pass unix:${sock};
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_read_timeout 300s;
        }
    }
}
NGINXCONF

    while true; do
        started=$(date +%s)
        "$fpm_bin" --fpm-config "${conf_dir}/fpm.conf" -p "${conf_dir}" &
        fpm_pid=$!
        # Give the pool a moment to create its socket.
        for i in $(seq 1 20); do
            [ -S "$sock" ] && break
            sleep 0.1
        done
        if [ ! -S "$sock" ]; then
            echo "[server] php-fpm did not create ${sock}; see ${conf_dir}/fpm-error.log" >&2
            kill "$fpm_pid" 2>/dev/null || true
            exit 1
        fi

        # nginx in the foreground: the supervisor lives and dies with it.
        nginx -c "${conf_dir}/nginx.conf"
        status=$?
        lifetime=$(( $(date +%s) - started ))
        echo "[server] nginx (port ${port}) exited with status ${status} after ${lifetime}s at $(date -u +%Y-%m-%dT%H:%M:%SZ); restarting pair" >&2
        kill "$fpm_pid" 2>/dev/null || true
        wait "$fpm_pid" 2>/dev/null || true
        rm -f "$sock"

        if [ "$lifetime" -lt 1 ]; then
            restarts=$(( restarts + 1 ))
            if [ "$restarts" -ge 5 ]; then
                echo "[server] nginx exited too quickly 5 times in a row, supervisor giving up" >&2
                exit 1
            fi
        else
            restarts=0
        fi
        sleep 0.2
    done
}

usdtf_reap_orphans() {
    # Precisely stop php -S processes bound to this port that serve our
    # docroot. Anything else on the port is reported, never killed.
    local port="$1" docroot="$2"
    local listeners="" opid ocmd

    if command -v ss >/dev/null 2>&1; then
        listeners="$(ss -ltnp "sport = :${port}" 2>/dev/null | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u || true)"
    elif command -v lsof >/dev/null 2>&1; then
        listeners="$(lsof -t -iTCP:"${port}" -sTCP:LISTEN 2>/dev/null | sort -u || true)"
    fi

    for opid in $listeners; do
        [ -n "$opid" ] || continue
        ocmd="$(ps -o cmd= -p "$opid" 2>/dev/null || echo "")"
        if [[ "$ocmd" != *"php"*"-S 127.0.0.1:${port}"* ]]; then
            echo "[server] port ${port} listener PID ${opid} is not our php -S (cmd: ${ocmd}), not killing"
            continue
        fi
        if [ -n "$docroot" ] && [[ "$ocmd" != *"$docroot"* ]]; then
            echo "[server] port ${port} listener PID ${opid} serves another docroot, not killing"
            continue
        fi
        echo "[server] reaping orphaned server PID ${opid} on port ${port}"
        kill -TERM "$opid" 2>/dev/null || true
    done

    for i in $(seq 1 6); do
        usdtf_port_listening "$port" || break
        sleep 0.5
    done

    if usdtf_port_listening "$port"; then
        for opid in $listeners; do
            [ -n "$opid" ] || continue
            ocmd="$(ps -o cmd= -p "$opid" 2>/dev/null || echo "")"
            if [[ "$ocmd" == *"php"*"-S 127.0.0.1:${port}"* ]] && { [ -z "$docroot" ] || [[ "$ocmd" == *"$docroot"* ]]; }; then
                echo "[server] force killing orphaned server PID ${opid}"
                kill -KILL "$opid" 2>/dev/null || true
            fi
        done
    fi
}

usdtf_start_concurrent() {
    # Genuine concurrent PHP execution: PHP_CLI_SERVER_WORKERS=4 makes php -S multi-worker since 7.4.
    # Proves overlapping execution via deterministic barrier (two 1.5s sleeps in parallel must finish in ~1.5s not 3s).
    local wp_path="${1:?wp path required}"
    local router="${2:-}"
    local port="${3:-$DEFAULT_PORT}"

    wp_path="$(usdtf_resolve_wp "$wp_path")"
    router="$(usdtf_resolve_router "$router" "$wp_path")"

    if [ ! -d "$wp_path" ]; then
        echo "::error::WP_PATH does not exist: $wp_path" >&2
        return 1
    fi

    if [ -n "$router" ] && [ ! -f "$router" ]; then
        router="${wp_path}/index.php"
    fi

    # Precise stop: only owned PID, no pkill yet.
    usdtf_stop "$port" || true
    # Legacy socat files: remove only if stale (PID dead), never pkill unrelated.
    for stale in "/tmp/usdtf-socat.pid" "/tmp/usdtf-socat.log"; do
        if [ -f "$stale" ]; then
            spid="$(cat "$stale" 2>/dev/null || echo "")"
            if [ -n "$spid" ] && ! kill -0 "$spid" 2>/dev/null; then
                rm -f "$stale"
            elif [ -n "$spid" ] && kill -0 "$spid" 2>/dev/null; then
                cmdline="$(ps -o cmd= -p "$spid" 2>/dev/null || echo "")"
                if [[ "$cmdline" != *"socat"* ]]; then
                    rm -f "$stale"
                fi
            fi
        fi
    done

    if usdtf_port_listening "$port"; then
        echo "::error::Port $port still listening after stop — genuine conflict (unrelated occupant, not killed)" >&2
        if command -v ss >/dev/null 2>&1; then ss -ltn "sport = :${port}" 2>/dev/null | head -n 20 >&2 || true; fi
        return 1
    fi

    mkdir -p "$(dirname "$LOG_FILE")"
    : > "$LOG_FILE"

    local php_bin="php"
    if ! command -v "$php_bin" >/dev/null 2>&1; then
        for cand in php8.4 php8.3 php8.2 php8.1 php8.0 php7.4; do
            if command -v "$cand" >/dev/null 2>&1; then
                php_bin="$cand"
                break
            fi
        done
    fi

    # Track docroot for precise ownership.
    echo "$wp_path" > "${PID_FILE}.docroot"

    usdtf_log "Starting supervised concurrent server (nginx+php-fpm when available, else php -S workers=4; auto-restart on crash) on 127.0.0.1:${port} docroot ${wp_path}"
    if command -v setsid >/dev/null 2>&1; then
        setsid bash "$0" __supervise__ "$php_bin" "$port" "$wp_path" "$router" >"$LOG_FILE" 2>&1 < /dev/null &
    else
        nohup bash "$0" __supervise__ "$php_bin" "$port" "$wp_path" "$router" >"$LOG_FILE" 2>&1 < /dev/null &
    fi
    local pid=$!
    echo "$pid" > "$PID_FILE"
    # Also write per-port state with pgid/docroot for precise ownership.
    pgid=$(ps -o pgid= -p "$pid" 2>/dev/null | tr -d ' ' || echo "")
    if [ -z "$pgid" ]; then pgid="$pid"; fi
    cat > "/tmp/usdtf-server-${port}.json" <<JSON
{"pid": $pid, "pgid": "$pgid", "port": $port, "docroot": "$wp_path", "log": "$LOG_FILE", "router": "$router"}
JSON
    echo "$pgid" > "${PID_FILE}.pgid" 2>/dev/null || true
    disown 2>/dev/null || true
    usdtf_log "Concurrent supervisor PID $pid (pgid $pgid, workers=4, auto-restart) on $port docroot $wp_path state /tmp/usdtf-server-${port}.json"

    local attempt=1
    while [ "$attempt" -le "$ATTEMPTS" ]; do
        if usdtf_is_alive "$pid"; then
            code=$(curl -s --max-time 3 --connect-timeout 2 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}/" 2>/dev/null || echo "000")
            usdtf_log "Concurrent probe http://127.0.0.1:${port}/ -> $code (attempt $attempt/${ATTEMPTS}) workers=4"
            if [[ "$code" =~ ^[23][0-9][0-9]$ ]]; then
                sleep 0.5
                code2=$(curl -s --max-time 3 --connect-timeout 2 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}/" 2>/dev/null || echo "000")
                usdtf_log "Concurrent second probe -> $code2"
                if [[ "$code2" =~ ^[23][0-9][0-9]$ ]]; then
                    usdtf_log "Proving genuine concurrency with barrier (two 1.5s sleeps in parallel, must overlap)"
                    barrier_file="${wp_path}/usdtf-barrier.php"
                    cat > "$barrier_file" <<'BARRIERPHP'
<?php usleep(1500000); echo "barrier ".getmypid();
BARRIERPHP
                    # Use high-res timer if available.
                    if date +%s%N >/dev/null 2>&1; then
                        start_ns=$(date +%s%N)
                        curl -s --max-time 5 "http://127.0.0.1:${port}/usdtf-barrier.php" >/dev/null 2>&1 & p1=$!
                        curl -s --max-time 5 "http://127.0.0.1:${port}/usdtf-barrier.php" >/dev/null 2>&1 & p2=$!
                        wait $p1; c1=$?
                        wait $p2; c2=$?
                        end_ns=$(date +%s%N)
                        elapsed_ms=$(( (end_ns - start_ns) / 1000000 ))
                        usdtf_log "Barrier parallel: c1=$c1 c2=$c2 elapsed ${elapsed_ms}ms (must be <2500ms for genuine concurrent)"
                        rm -f "$barrier_file"
                        if [ "$c1" -eq 0 ] && [ "$c2" -eq 0 ] && [ "$elapsed_ms" -gt 2500 ]; then
                            usdtf_log "Barrier failed: elapsed ${elapsed_ms}ms >2500ms — single worker, not concurrent"
                            rm -f "$PID_FILE" "${PID_FILE}.docroot" "${PID_FILE}.pgid" "/tmp/usdtf-server-${port}.json"
                            if usdtf_is_alive "$pid"; then
                                cmdline="$(ps -o cmd= -p "$pid" 2>/dev/null || echo "")"
                                if [[ "$cmdline" == *"php"*"-S 127.0.0.1:${port}"* ]] || [[ "$cmdline" == *"__supervise__"* ]]; then
                                    kill "$pid" 2>/dev/null || true; sleep 0.5; kill -9 "$pid" 2>/dev/null || true
                                fi
                            fi
                            return 1
                        fi
                    else
                        # Fallback without high-res timer: just check both succeed.
                        curl -s --max-time 5 "http://127.0.0.1:${port}/usdtf-barrier.php" >/dev/null 2>&1 & p1=$!
                        curl -s --max-time 5 "http://127.0.0.1:${port}/usdtf-barrier.php" >/dev/null 2>&1 & p2=$!
                        wait $p1; c1=$?
                        wait $p2; c2=$?
                        usdtf_log "Barrier parallel: c1=$c1 c2=$c2 (no high-res timer)"
                        rm -f "$barrier_file"
                        if [ "$c1" -ne 0 ] || [ "$c2" -ne 0 ]; then
                            usdtf_log "Barrier probes failed"
                            return 1
                        fi
                    fi
                    return 0
                fi
            fi
            if [[ "$code" = "500" || "$code" = "000" ]]; then
                body=$(curl -s --max-time 3 --connect-timeout 2 "http://127.0.0.1:${port}/" 2>/dev/null | head -c 500 || true)
                if [ -n "$body" ]; then
                    usdtf_log "Concurrent body preview: $body"
                fi
            fi
        else
            usdtf_log "Concurrent supervisor $pid died (php -S gave up or crashed); see log"
            break
        fi
        usdtf_log "Waiting for concurrent server (attempt $attempt/${ATTEMPTS})..."
        sleep "$DELAY"
        attempt=$((attempt+1))
    done

    rm -f "$PID_FILE" "${PID_FILE}.docroot"
    if usdtf_is_alive "$pid"; then
        cmdline="$(ps -o cmd= -p "$pid" 2>/dev/null || echo "")"
        if [[ "$cmdline" == *"php"*"-S 127.0.0.1:${port}"* ]]; then
            usdtf_log "Cleaning up unresponsive concurrent $pid"
            kill "$pid" 2>/dev/null || true; sleep 0.5; kill -9 "$pid" 2>/dev/null || true
        fi
    fi
    echo "::error::Concurrent server did not answer on http://127.0.0.1:${port}/ after $((ATTEMPTS * DELAY))s." >&2
    usdtf_status "$port" || true
    echo "--- log ---" >&2; tail -n 100 "$LOG_FILE" 2>/dev/null || true
    for usdtf_err in "$(dirname "$LOG_FILE")"/usdtf-nginx-*/fpm-error.log "$(dirname "$LOG_FILE")"/usdtf-nginx-*/nginx-error.log; do
        if [ -f "$usdtf_err" ]; then
            usdtf_tail="$(tail -n 10 "$usdtf_err" 2>/dev/null | tr '\n' ' ' | cut -c1-600 || true)"
            [ -n "$usdtf_tail" ] && echo "::error::${usdtf_err}: ${usdtf_tail}" >&2
        fi
    done
    return 1
}

usdtf_start() {
    local wp_path="${1:?wp path required}"
    local router="${2:-}"
    local port="${3:-$DEFAULT_PORT}"

    wp_path="$(usdtf_resolve_wp "$wp_path")"
    router="$(usdtf_resolve_router "$router" "$wp_path")"

    if [ ! -d "$wp_path" ]; then
        echo "::error::WP_PATH does not exist: $wp_path" >&2
        return 1
    fi

    if [ -n "$router" ] && [ ! -f "$router" ]; then
        usdtf_log "Router $router not found, falling back to ${wp_path}/index.php"
        router="${wp_path}/index.php"
    fi

    # Stop any previous server on this port. The previous php -S may still
    # hold the socket in TIME_WAIT for a second; give the kernel a moment so
    # the next listen does not fail with Address already in use.
    usdtf_stop "$port" || true
    sleep 3
    # Controlled relaunch: if port still listening after our stop, it's a genuine
    # conflict (another process), not our stale server. Fail fast with diagnostics
    # instead of starting and hitting Address already in use.
    if usdtf_port_listening "$port"; then
        echo "::error::Port $port is still listening after stop — genuine conflict, not our server" >&2
        if command -v ss >/dev/null 2>&1; then ss -ltn "sport = :${port}" 2>/dev/null | head -n 20 >&2 || true; fi
        if command -v lsof >/dev/null 2>&1; then lsof -iTCP:"${port}" -sTCP:LISTEN 2>/dev/null | head -n 20 >&2 || true; fi
        return 1
    fi

    # Ensure log directory exists and truncate.
    mkdir -p "$(dirname "$LOG_FILE")"
    : > "$LOG_FILE"

    # Choose PHP binary: prefer the php from setup-php (usually `php`), fall
    # back to versioned binaries if the unversioned one is missing. The
    # previous check preferred php8.2 even when php pointed at a newer
    # version, which could pick the wrong minor and miss extensions.
    local php_bin="php"
    if ! command -v "$php_bin" >/dev/null 2>&1; then
        for cand in php8.4 php8.3 php8.2 php8.1 php8.0 php7.4; do
            if command -v "$cand" >/dev/null 2>&1; then
                php_bin="$cand"
                break
            fi
        done
    fi
    if ! command -v "$php_bin" >/dev/null 2>&1; then
        echo "::error::php not found" >&2
        return 1
    fi

    usdtf_log "Starting supervised server (nginx+php-fpm when available, else php -S workers=4; auto-restart on crash) on 127.0.0.1:${port} docroot ${wp_path}"
    usdtf_log "Log: $LOG_FILE, PID: $PID_FILE, router: $router, docroot: $wp_path"
    usdtf_log "PHP version: $($php_bin -v 2>&1 | head -n1 || echo unknown)"
    if [ -n "$router" ] && [ -f "$router" ]; then
        if ! "$php_bin" -l "$router" 2>&1 | head -n 20; then
            usdtf_log "Router syntax check failed for $router"
        fi
    fi

    # Start the supervised server detached (setsid when available): the
    # supervisor owns the php process tree, restarts it when the tree dies
    # mid-suite and records every death with its exit status.
    if command -v setsid >/dev/null 2>&1; then
        setsid bash "$0" __supervise__ "$php_bin" "$port" "$wp_path" "$router" >"$LOG_FILE" 2>&1 < /dev/null &
    else
        nohup bash "$0" __supervise__ "$php_bin" "$port" "$wp_path" "$router" >"$LOG_FILE" 2>&1 < /dev/null &
    fi
    local pid=$!
    echo "$pid" > "$PID_FILE"
    pgid=$(ps -o pgid= -p "$pid" 2>/dev/null | tr -d ' ' || echo "")
    if [ -z "$pgid" ]; then pgid="$pid"; fi
    cat > "/tmp/usdtf-server-${port}.json" <<JSON
{"pid": $pid, "pgid": "$pgid", "port": $port, "docroot": "$wp_path", "log": "$LOG_FILE", "router": "$router"}
JSON
    echo "$pgid" > "${PID_FILE}.pgid" 2>/dev/null || true
    # Disown so the runner does not kill it when the step shell exits.
    disown 2>/dev/null || true

    usdtf_log "Server supervisor PID $pid (pgid $pgid, workers=4, auto-restart) on $port docroot $wp_path state /tmp/usdtf-server-${port}.json"

    # Wait for server to answer. Use -w '%{http_code}' so a 500 is visible
    # instead of an opaque curl 22, and accept 2xx/3xx as healthy (WordPress
    # may redirect to /wp-admin/install.php when the DB is not yet ready).
    local attempt=1
    while [ "$attempt" -le "$ATTEMPTS" ]; do
        if usdtf_is_alive "$pid"; then
            local code
            code=$(curl -s --max-time 3 --connect-timeout 2 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}/" 2>/dev/null || echo "000")
            usdtf_log "Probe http://127.0.0.1:${port}/ -> $code (attempt $attempt/${ATTEMPTS})"
            if [[ "$code" =~ ^[23][0-9][0-9]$ ]]; then
                usdtf_log "Server answered on http://127.0.0.1:${port}/ (attempt $attempt/${ATTEMPTS}, code $code)"
                sleep 0.5
                local code2
                code2=$(curl -s --max-time 3 --connect-timeout 2 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}/" 2>/dev/null || echo "000")
                usdtf_log "Second probe -> $code2"
                if [[ "$code2" =~ ^[23][0-9][0-9]$ ]]; then
                    return 0
                fi
            fi
            # Also log a one-line body preview when the code is unexpected,
            # to surface a router 500 without needing the full log.
            if [[ "$code" = "500" || "$code" = "000" ]]; then
                local body
                body=$(curl -s --max-time 3 --connect-timeout 2 "http://127.0.0.1:${port}/" 2>/dev/null | head -c 500 || true)
                if [ -n "$body" ]; then
                    usdtf_log "Body preview (first 500 chars): $body"
                fi
            fi
        else
            usdtf_log "Server process $pid died"
            # If the log says Address already in use, the port was still held;
            # wait a second and retry — this happens after a quick stop/start
            # when switching from working copy to built ZIP.
            if grep -q "Address already in use" "$LOG_FILE" 2>/dev/null; then
                usdtf_log "Address already in use, waiting before retry"
                sleep 2
            fi
            break
        fi

        usdtf_log "Waiting for server (attempt $attempt/${ATTEMPTS})..."
        sleep "$DELAY"
        attempt=$((attempt+1))
    done

    # Failed: cleanup PID so next start does not think we own the port.
    rm -f "$PID_FILE"
    # If we left a supervised server running but unresponsive, stop it
    # (verified ownership: supervisor or php -S for this port).
    if usdtf_is_alive "$pid"; then
        cmdline="$(ps -o cmd= -p "$pid" 2>/dev/null || echo "")"
        if [[ "$cmdline" == *"php"*"-S 127.0.0.1:${port}"* ]] || [[ "$cmdline" == *"__supervise__"* ]]; then
            usdtf_log "Cleaning up unresponsive server PID $pid"
            kill "$pid" 2>/dev/null || true
            sleep 0.5
            kill -9 "$pid" 2>/dev/null || true
        fi
    fi
    echo "::error::The test web server did not answer on http://127.0.0.1:${port}/ after $((ATTEMPTS * DELAY))s." >&2
    usdtf_status "$port" || true
    # Dump logs.
    echo "--- server log (${LOG_FILE}, $(wc -l < "$LOG_FILE" 2>/dev/null || echo 0) lines) ---" >&2
    tail -n 200 "$LOG_FILE" 2>/dev/null || true
    echo "--- end server log ---" >&2
    # Annotate the backend's own logs so a failed nginx/php-fpm start is
    # attributable from the API without downloading job logs.
    for usdtf_err in "$(dirname "$LOG_FILE")"/usdtf-nginx-*/fpm-error.log "$(dirname "$LOG_FILE")"/usdtf-nginx-*/nginx-error.log; do
        if [ -f "$usdtf_err" ]; then
            usdtf_tail="$(tail -n 10 "$usdtf_err" 2>/dev/null | tr '\n' ' ' | cut -c1-600 || true)"
            [ -n "$usdtf_tail" ] && echo "::error::${usdtf_err}: ${usdtf_tail}" >&2
        fi
    done

    # Also dump WordPress debug log if exists.
    local wp_debug="${wp_path}/../debug.log"
    if [ -f "$wp_debug" ]; then
        echo "--- WordPress debug.log (${wp_debug}) ---" >&2
        tail -n 100 "$wp_debug" 2>/dev/null || true
        echo "--- end debug.log ---" >&2
    fi
    # Alternative location: wp-content/debug.log ?
    if [ -f "${wp_path}/wp-content/debug.log" ]; then
        echo "--- wp-content/debug.log ---" >&2
        tail -n 100 "${wp_path}/wp-content/debug.log" 2>/dev/null || true
    fi

    return 1
}

usdtf_stop() {
    local port="${1:-$DEFAULT_PORT}"
    local pid="" pgid=""
    if [ -f "$PID_FILE" ]; then
        pid="$(cat "$PID_FILE" 2>/dev/null || echo "")"
    fi
    if [ -f "${PID_FILE}.pgid" ]; then
        pgid="$(cat "${PID_FILE}.pgid" 2>/dev/null || echo "")"
    fi

    # With PHP_CLI_SERVER_WORKERS the master forks workers that share the
    # listen socket; killing only the master can orphan a worker that keeps
    # the port open. The server runs in its own session (setsid), so the
    # tracked process group contains exactly our master and its workers.
    if [ -n "$pgid" ] && kill -0 "-$pgid" 2>/dev/null; then
        usdtf_log "Stopping server process group $pgid (master $pid) on port $port"
        kill -TERM "-$pgid" 2>/dev/null || true
        for i in $(seq 1 10); do
            kill -0 "-$pgid" 2>/dev/null || break
            sleep 0.5
        done
        if kill -0 "-$pgid" 2>/dev/null; then
            usdtf_log "Force killing process group $pgid"
            kill -KILL "-$pgid" 2>/dev/null || true
            sleep 0.5
        fi
    elif [ -n "$pid" ] && usdtf_is_alive "$pid"; then
        # Legacy state (PID recorded, no process group): verify ownership
        # before signalling anything, then stop the PID's whole group. The
        # recorded PID is either the supervisor (bash ... __supervise__)
        # or, from older revisions, the php -S master itself.
        cmdline="$(ps -o cmd= -p "$pid" 2>/dev/null || echo "")"
        if [[ "$cmdline" != *"php"*"-S 127.0.0.1:${port}"* ]] && [[ "$cmdline" != *"__supervise__"* ]]; then
            usdtf_log "PID $pid does not look like our server (cmd: $cmdline), not killing"
        else
            pgid="$(ps -o pgid= -p "$pid" 2>/dev/null | tr -d ' ' || echo "")"
            if [ -n "$pgid" ] && [ "$pgid" != "$$" ]; then
                usdtf_log "Stopping server PID $pid (group $pgid) on port $port"
                kill -TERM "-$pgid" 2>/dev/null || true
                for i in $(seq 1 10); do
                    kill -0 "-$pgid" 2>/dev/null || break
                    sleep 0.5
                done
                kill -KILL "-$pgid" 2>/dev/null || true
            else
                kill "$pid" 2>/dev/null || true
            fi
        fi
    else
        if [ -n "$pid" ]; then
            usdtf_log "PID file $pid not alive, removing stale file"
        fi
    fi

    # Orphaned worker reaping: if the port is still listening and the
    # listener is a php -S bound to this port (and our docroot when the
    # state file exists), it is an orphan from a killed master. Stop it
    # precisely by PID after the ownership check; never signal anything
    # that is not a php -S for this port + docroot.
    if usdtf_port_listening "$port"; then
        local docroot=""
        if [ -f "${PID_FILE}.docroot" ]; then
            docroot="$(cat "${PID_FILE}.docroot" 2>/dev/null || echo "")"
        fi
        local listeners=""
        if command -v ss >/dev/null 2>&1; then
            listeners="$(ss -ltnp "sport = :${port}" 2>/dev/null | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u || true)"
        elif command -v lsof >/dev/null 2>&1; then
            listeners="$(lsof -t -iTCP:"${port}" -sTCP:LISTEN 2>/dev/null | sort -u || true)"
        fi
        for opid in $listeners; do
            [ -n "$opid" ] || continue
            ocmd="$(ps -o cmd= -p "$opid" 2>/dev/null || echo "")"
            if [[ "$ocmd" != *"php"*"-S 127.0.0.1:${port}"* ]]; then
                usdtf_log "Port $port listener PID $opid is not our php -S (cmd: $ocmd), not killing"
                continue
            fi
            if [ -n "$docroot" ] && [[ "$ocmd" != *"$docroot"* ]]; then
                usdtf_log "Port $port listener PID $opid serves another docroot, not killing"
                continue
            fi
            usdtf_log "Reaping orphaned server worker PID $opid on port $port"
            kill -TERM "$opid" 2>/dev/null || true
        done
        # Give the orphans a moment to exit; anything left gets KILLed only
        # if it still matches the ownership check.
        for i in $(seq 1 6); do
            usdtf_port_listening "$port" || break
            sleep 0.5
        done
        if usdtf_port_listening "$port"; then
            for opid in $listeners; do
                [ -n "$opid" ] || continue
                ocmd="$(ps -o cmd= -p "$opid" 2>/dev/null || echo "")"
                if [[ "$ocmd" == *"php"*"-S 127.0.0.1:${port}"* ]] && { [ -z "$docroot" ] || [[ "$ocmd" == *"$docroot"* ]]; }; then
                    usdtf_log "Force killing orphaned server worker PID $opid"
                    kill -KILL "$opid" 2>/dev/null || true
                fi
            done
        fi
    fi

    if usdtf_port_listening "$port"; then
        usdtf_log "Port $port is still listening after stop — genuine conflict (unrelated occupant, not killed)"
        if command -v ss >/dev/null 2>&1; then ss -ltnp "sport = :${port}" 2>/dev/null | head -n 20 >&2 || true; fi
    fi

    rm -f "$PID_FILE" "${PID_FILE}.docroot" "${PID_FILE}.pgid" "/tmp/usdtf-server-${port}.json"
    # Legacy 18888/socat state from earlier revisions: remove only precisely
    # owned leftovers, never pkill/fuser anything.
    for stale in "/tmp/usdtf-socat.pid"; do
        if [ -f "$stale" ]; then
            spid="$(cat "$stale" 2>/dev/null || echo "")"
            if [ -n "$spid" ] && kill -0 "$spid" 2>/dev/null; then
                scmd="$(ps -o cmd= -p "$spid" 2>/dev/null || echo "")"
                if [[ "$scmd" == *"socat"* ]]; then
                    usdtf_log "Stopping legacy socat $spid"
                    kill "$spid" 2>/dev/null || true
                    sleep 0.5
                    kill -9 "$spid" 2>/dev/null || true
                fi
            fi
            rm -f "$stale"
        fi
    done
    # Do not remove log file; diagnostics need it after stop.
    return 0
}

usdtf_status() {
    local port="${1:-$DEFAULT_PORT}"
    local pid=""
    if [ -f "$PID_FILE" ]; then
        pid="$(cat "$PID_FILE" 2>/dev/null || echo "")"
    fi
    echo "--- server status ---" >&2
    if [ -n "$pid" ]; then
        echo "PID file $PID_FILE: $pid" >&2
        if usdtf_is_alive "$pid"; then
            echo "Process $pid is alive: $(ps -o pid,ppid,stat,cmd -p "$pid" 2>/dev/null || echo "ps unavailable")" >&2
        else
            echo "Process $pid is not alive" >&2
        fi
    else
        echo "No PID file" >&2
    fi

    echo "Port $port listening: $(usdtf_port_listening "$port" && echo yes || echo no)" >&2

    if command -v ss >/dev/null 2>&1; then
        echo "ss -ltn:" >&2
        ss -ltn 2>/dev/null | grep -E ":${port}|LISTEN" | head -n 20 >&2 || true
    fi
    if [ -f "$LOG_FILE" ]; then
        echo "Log $LOG_FILE: $(wc -l < "$LOG_FILE") lines, $(stat -c %s "$LOG_FILE" 2>/dev/null || wc -c < "$LOG_FILE") bytes" >&2
        echo "Log head:" >&2
        head -n 20 "$LOG_FILE" 2>/dev/null || true
        echo "Log tail:" >&2
        tail -n 50 "$LOG_FILE" 2>/dev/null || true
    else
        echo "No log file $LOG_FILE" >&2
    fi

    # Check router file.
    local router_guess="tests/integration/router.php"
    if [ -f "$router_guess" ]; then
        echo "Router $router_guess exists" >&2
    else
        echo "Router $router_guess missing" >&2
    fi

    # Check wp path.
    local wp_guess="${WP_PATH:-wp}"
    if [ -d "$wp_guess" ]; then
        echo "WP_PATH $wp_guess exists" >&2
        ls -la "$wp_guess" | head -n 20 >&2 || true
    fi
    if [ -f "/tmp/usdtf-socat.pid" ]; then
        spid="$(cat /tmp/usdtf-socat.pid 2>/dev/null || echo "")"
        echo "Socat PID file /tmp/usdtf-socat.pid: $spid" >&2
        if [ -n "$spid" ] && kill -0 "$spid" 2>/dev/null; then
            echo "Socat $spid alive: $(ps -o pid,ppid,stat,cmd -p "$spid" 2>/dev/null || echo "ps unavailable")" >&2
        else
            echo "Socat $spid not alive" >&2
        fi
        if [ -f "/tmp/usdtf-socat.log" ]; then
            echo "Socat log tail:" >&2; tail -n 20 /tmp/usdtf-socat.log 2>/dev/null || true
        fi
    fi
    echo "--- end status ---" >&2
    return 0
}

cmd="${1:-}"
case "$cmd" in
    __supervise__)
        shift
        usdtf_supervise_server "$@"
        exit $?
        ;;
    __reap__)
        shift
        usdtf_reap_orphans "$@"
        exit $?
        ;;
    start)
        shift
        # If --concurrent flag given, use concurrent backend.
        if [ "${1:-}" = "--concurrent" ]; then
            shift
            usdtf_start_concurrent "$@"
        else
            usdtf_start "$@"
        fi
        ;;
    start-concurrent)
        shift
        usdtf_start_concurrent "$@"
        ;;
    stop)
        shift
        usdtf_stop "$@"
        ;;
    restart)
        shift
        usdtf_stop "${3:-$DEFAULT_PORT}" || true
        sleep 1
        usdtf_start "$@"
        ;;
    status)
        shift
        usdtf_status "$@"
        ;;
    *)
        echo "Usage: $0 {start <wp-path> [router] [port]|stop [port]|restart <wp-path> [router] [port]|status [port]}" >&2
        exit 1
        ;;
esac