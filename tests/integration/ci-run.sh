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
		if [ "$status" -eq 0 ]; then
			echo "Passed: $(grep -c '^ok - ' "$log") scenario groups."
		else
			echo "Failed. Last 120 lines:"
			echo
			echo '```'
			tail -n 120 "$log"
			echo '```'
		fi
	} >>"$summary"
fi

if [ "$status" -ne 0 ]; then
	# Annotations accept one line each, and only ten error annotations are kept
	# per step, so the first failures are the ones that matter.
	reported=0

	for pattern in '^FAIL:' 'Fatal error' 'PHP Fatal' '^PHP Warning' '^not ok'; do
		while IFS= read -r line; do
			[ -n "$line" ] || continue
			[ "$reported" -lt 10 ] || break

			echo "::error title=Integration suite ($label)::$line"
			reported=$((reported + 1))
		done < <(grep -E "$pattern" "$log" | head -n 10)

		[ "$reported" -lt 10 ] || break
	done

	if [ "$reported" -eq 0 ]; then
		echo "::error title=Integration suite ($label)::The suite failed without a FAIL line; see the job summary."
	fi

	echo "---- tail of the suite output ----"
	tail -n 60 "$log"

	rm -f "$log"

	exit 1
fi

rm -f "$log"

echo "Integration suite ($label) passed."
