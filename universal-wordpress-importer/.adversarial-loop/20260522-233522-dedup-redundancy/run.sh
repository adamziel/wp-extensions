#!/usr/bin/env bash
set -uo pipefail

WORK_DIR="/home/claude/wp-extensions-work/universal-wordpress-importer/.adversarial-loop/20260522-233522-dedup-redundancy"
TASK_FILE="$WORK_DIR/task.md"
FEEDBACK_FILE="$WORK_DIR/feedback.md"
LOG_FILE="$WORK_DIR/log.md"
MAX_ITER=8

: > "$FEEDBACK_FILE"
: > "$LOG_FILE"

cd /home/claude/wp-extensions-work/universal-wordpress-importer

for i in $(seq 1 "$MAX_ITER"); do
  echo "=== Iteration $i ===" | tee -a "$LOG_FILE"

  IMPL_PROMPT="$WORK_DIR/iter-$i-impl-prompt.md"
  IMPL_OUT="$WORK_DIR/iter-$i-impl-output.md"
  VERIFY_PROMPT="$WORK_DIR/iter-$i-verify-prompt.md"
  VERIFY_OUT="$WORK_DIR/iter-$i-verify-output.md"

  {
    echo "# Task"
    cat "$TASK_FILE"
    echo
    echo "# Prior verifier feedback"
    if [ -s "$FEEDBACK_FILE" ]; then
      cat "$FEEDBACK_FILE"
    else
      echo "(none — first iteration)"
    fi
    echo
    echo "# Instructions"
    echo "Make real edits to src/Admin/ImportAdminPage.php and tools/render-admin-snapshot.php."
    echo "Address every prior-feedback issue."
    echo "Take screenshots of EVERY scenario (early / mid / decision)."
    echo "Print a short bullet summary."
  } > "$IMPL_PROMPT"

  claude -p --dangerously-skip-permissions < "$IMPL_PROMPT" > "$IMPL_OUT" 2>&1 || {
    echo "Implementer call failed on iteration $i" | tee -a "$LOG_FILE"
    exit 2
  }

  {
    echo "# Task"
    cat "$TASK_FILE"
    echo
    echo "# Implementer summary"
    cat "$IMPL_OUT"
    echo
    echo "# Your role: HOSTILE verifier"
    echo
    echo "Default to FAIL. Re-derive acceptance criteria from the task."
    echo
    echo "## You MUST"
    echo "1. Read screenshots under .tmp/v6-shots/loop4-<n>/ with the Read tool."
    echo "2. Read the user's verbatim examples in the task and check whether each"
    echo "   restatement of the same fact is now eliminated."
    echo "3. Grep snapshot.html (regenerate via php tools/render-admin-snapshot.php --running and"
    echo "   --running --scenario=stage-1-early and --running --scenario=stage-3-decision)"
    echo "   for 'Invalid Git ref' — must NOT appear in user log container."
    echo "4. Run composer test + verify-option-30-flow.js yourself."
    echo "5. For 'no fact twice', identify the candidate fact, list every place it appears"
    echo "   in the screenshot HTML, and assert ≤ 2 occurrences."
    echo "6. For dedup: confirm the implementer added a group-key map (grep for the new map)"
    echo "   and that 3 semantically-equivalent events collapse to 1 row."
    echo
    echo "## Auto-FAIL"
    echo "- 'Looks better' without explicit citation."
    echo "- Implementer claim without screenshot evidence."
    echo "- Same fact still appearing 3+ times."
    echo
    echo "## Output format"
    echo "### Per-criterion checklist"
    echo "[✓/✗] line each + evidence."
    echo "### Findings"
    echo "Severity + Title + Evidence + Reproduction + Suggested action."
    echo "### Verdict"
    echo "Last line: 'VERDICT: PASS' or 'VERDICT: FAIL'."
  } > "$VERIFY_PROMPT"

  claude -p --dangerously-skip-permissions < "$VERIFY_PROMPT" > "$VERIFY_OUT" 2>&1 || {
    echo "Verifier call failed on iteration $i" | tee -a "$LOG_FILE"
    exit 3
  }

  if tail -n 10 "$VERIFY_OUT" | grep -qx "VERDICT: PASS"; then
    echo "PASS on iteration $i" | tee -a "$LOG_FILE"
    echo "$i" > "$WORK_DIR/passed-on-iteration.txt"
    exit 0
  fi

  echo "FAIL on iteration $i — feeding feedback to next implementer" | tee -a "$LOG_FILE"
  {
    echo
    echo "## Iteration $i verifier feedback"
    cat "$VERIFY_OUT"
  } >> "$FEEDBACK_FILE"
done

echo "Did not converge after $MAX_ITER iterations" | tee -a "$LOG_FILE"
exit 1
