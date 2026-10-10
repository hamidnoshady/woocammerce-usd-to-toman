#!/usr/bin/env bash
#
# Run the integration suite and make a failure readable.
#
# The suite prints "FAIL: <message>" to stderr and stops at the first failed
# assertion, which is the useful line, but a job log is not always available (for
# example when the runner's log storage is unreachable). The failing lines are
# therefore repeated as workflow annotations, which the API serves separately,
# and the full output is written to the job summary.
#
# Usage: bash tests/integration/ci-run.sh <wordpress-path> [label]

set -uo pipefail

wp_path="${1:?the WordPress path is required}"
label="${2:-suite}"
log="$(mktemp)"

echo "Running the integration suite ($label) against $wp_path"

php "$(dirname "$0")/run.php" "$wp_path" 2>&1 | tee "$log"
status="${PIPESTATUS[0]}"

summary="${GITHUB_STEP_SUMMARY:-}"

if [ -n "$summary" ]; then
	{
		echo "### Integration suite ($label)"
		echo
		# Count accurately: ok, not ok, and explicit skips.
		passed="$(grep -c '^ok - ' "$log" || true)"
		failed="$(grep -c '^not ok' "$log" || true)"
		skipped="$(grep -c '^skip - ' "$log" || true)"
		autonomous_skipped="$(grep -c 'autonomous.*skipped' "$log" || true)"
		# Escape backticks in dynamic values for markdown.
		echo "Passed: ${passed}, Failed: ${failed}, Skipped: ${skipped} (autonomous skipped: ${autonomous_skipped})"
		if [ "$status" -eq 0 ]; then
			echo "Result: ✅ passed"
		else
			echo "Result: ❌ failed"
			echo
			echo "Last 120 lines:"
			echo
			echo '```'
			tail -n 120 "$log" | sed 's/`/\\`/g'
			echo '```'
		fi
	} >>"$summary"
fi

if [ "$status" -ne 0 ]; then
	# On failure, also capture the server health so an empty reply (curl 52)
	# is not an opaque "FAIL: … Empty reply from server" without context.
	if [ -x "tests/integration/server.sh" ] && [ -f "/tmp/usdtf-server.log" ]; then
		echo "--- server log (last 50 lines) ---"
		tail -n 50 /tmp/usdtf-server.log || true
		echo "--- end server log ---"
		if [ "${USDTF_REQUIRE_HTTP_TESTS:-}" = "1" ]; then
			bash tests/integration/server.sh status 8888 2>&1 | head -n 100 || true
		fi
	fi

	# Annotations accept one line each, and only ten error annotations are kept
	# per step, so the first failures are the ones that matter.
	reported=0

	for pattern in 'FAIL:' 'Fatal error' 'PHP Fatal' 'Uncaught' '^PHP Warning' '^not ok' '^usdtf wait job' '^usdtf passive wait' '^usdtf hang diagnostics' 'exited with status' 'cURL error'; do
		while IFS= read -r line; do
			[ -n "$line" ] || continue
			[ "$reported" -lt 10 ] || break

			echo "::error title=Integration suite ($label)::$line"
			reported=$((reported + 1))
		done < <(grep -E "$pattern" "$log" | head -n 10)

		[ "$reported" -lt 10 ] || break
	done

	if [ "$reported" -eq 0 ]; then
		# Nothing recognizable: the process died without printing an assertion.
		# WordPress logs fatals to the debug log (display is off), so that log
		# and the output tail are the only evidence. Annotate them; the cap of
		# ten annotations per step still leaves room for both.
		echo "::error title=Integration suite ($label)::the suite exited with code $status without a FAIL line"
		reported=1

		debug_log="${wp_path%/}/../debug.log"

		if [ -f "$debug_log" ]; then
			while IFS= read -r line; do
				[ -n "$line" ] || continue
				[ "$reported" -lt 7 ] || break

				echo "::error title=Integration suite ($label) debug.log::$line"
				reported=$((reported + 1))
			done < <(grep -E 'Fatal|Uncaught|Stack trace|thrown in' "$debug_log" | tail -n 6)
		fi

		while IFS= read -r line; do
			[ -n "$line" ] || continue
			[ "$reported" -lt 10 ] || break

			echo "::error title=Integration suite ($label) tail::$line"
			reported=$((reported + 1))
		done < <(tail -n 10 "$log")
	fi

	echo "---- tail of the suite output ----"
	tail -n 60 "$log"

	rm -f "$log"

	exit 1
fi

# The autonomous (passive) scenarios are environment conditional by design:
# they need a concurrent server (USDTF_CONCURRENT=1) and the ordinary job
# deliberately runs without it. Their skip line stays visible in the log, the
# concurrent job enforces that they actually ran, and only skips that are NOT
# environment conditional fail the required-HTTP check.
unexplained_skips="$(grep '^skip - ' "$log" | grep -v 'requires USDTF_CONCURRENT' || true)"
if [ -n "$unexplained_skips" ]; then
	if [ "${USDTF_REQUIRE_HTTP_TESTS:-}" = "1" ]; then
		echo "::error::The suite skipped scenarios with USDTF_REQUIRE_HTTP_TESTS=1, so HTTP is not verified:" >&2
		echo "$unexplained_skips"
		echo "---- full log ----"
		cat "$log"
		rm -f "$log"
		exit 1
	fi
	echo "::warning::The suite skipped: $(echo "$unexplained_skips" | grep -c .) scenario(s)" >&2
	echo "$unexplained_skips"
fi

# Autonomous must not be skipped when concurrent is required.
if [ "${USDTF_CONCURRENT:-}" = "1" ] && grep -q "autonomous.*skipped.*requires USDTF_CONCURRENT" "$log"; then
	echo "::error::Autonomous HTTP tests were skipped with USDTF_CONCURRENT=1 — concurrent server failed" >&2
	grep "autonomous.*skipped" "$log" || true
	rm -f "$log"
	exit 1
fi
# Also check that autonomous actually passed when concurrent.
if [ "${USDTF_CONCURRENT:-}" = "1" ] && [ "$status" -eq 0 ]; then
	if ! grep -q "autonomous (passive) preview/update complete without wake" "$log"; then
		echo "::error::Autonomous suite did not report success with USDTF_CONCURRENT=1" >&2
		cat "$log"
		rm -f "$log"
		exit 1
	fi
fi

rm -f "$log"

echo "Integration suite ($label) passed."
