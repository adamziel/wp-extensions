#!/usr/bin/env bash
set -uo pipefail

WORK_DIR="/home/claude/wp-extensions-work/universal-wordpress-importer/.adversarial-loop/20260522-180808-polish"
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
    echo "Implement the task above. Make real edits to src/Admin/ImportAdminPage.php."
    echo "If there is prior feedback, address every issue listed."
    echo "Run the iteration ritual described in the task."
    echo "Print a short bullet-list summary of what changed."
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
    echo "Default to FAIL. Find what is still wrong. The implementer is"
    echo "not your friend. Re-derive the acceptance criteria from the"
    echo "task — do not anchor on the implementer summary."
    echo
    echo "## What to actually do"
    echo "1. Read every screenshot under .tmp/v6-shots/polish-<n>-1280/ and .tmp/v6-shots/polish-<n>-768/ with the Read tool. PNGs render visually."
    echo "2. Compare to the baseline-before-loop2 screenshots in the same parent directory."
    echo "3. Run composer test yourself and confirm assertions ≥ 5765."
    echo "4. Run node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium yourself."
    echo "5. Run the font-size / padding / margin grep audits described in the task."
    echo "6. For each acceptance criterion, write one line: [✓/✗] <criterion>: <evidence>"
    echo
    echo "## Auto-FAIL patterns"
    echo "- 'Tests pass' without re-running."
    echo "- 'Looks good' without screenshot evidence."
    echo "- Trusting the implementer's claims as fact."
    echo
    echo "## Output format"
    echo "### Per-criterion checklist"
    echo "[✓/✗] line each."
    echo "### Findings"
    echo "Severity + Title + Evidence + Reproduction + Suggested action."
    echo "### Verdict"
    echo "Final line must be EXACTLY 'VERDICT: PASS' or 'VERDICT: FAIL'."
    echo
    echo "PASS only if every criterion is [✓], no CRITICAL or HIGH findings, screenshots are visibly more polished than the baseline, and the type/space scale is now tight."
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

  echo "FAIL on iteration $i — feeding verifier output back to next implementer" | tee -a "$LOG_FILE"
  {
    echo
    echo "## Iteration $i verifier feedback"
    cat "$VERIFY_OUT"
  } >> "$FEEDBACK_FILE"
done

echo "Did not converge after $MAX_ITER iterations" | tee -a "$LOG_FILE"
exit 1
