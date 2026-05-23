#!/usr/bin/env bash
set -uo pipefail

WORK_DIR="/home/claude/wp-extensions-work/universal-wordpress-importer/.adversarial-loop/20260522-215023-running-and-decision"
TASK_FILE="$WORK_DIR/task.md"
FEEDBACK_FILE="$WORK_DIR/feedback.md"
LOG_FILE="$WORK_DIR/log.md"
MAX_ITER=10

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
    echo "Make real edits to src/Admin/ImportAdminPage.php and related tools."
    echo "Address every prior-feedback issue."
    echo "Capture the screenshots described in the iteration ritual."
    echo "Print a short bullet-list summary."
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
    echo "Default to FAIL. Find what is still wrong, missing, faked, or"
    echo "hand-waved. Re-derive the acceptance criteria from the task —"
    echo "do not anchor on the implementer summary."
    echo
    echo "## What to do"
    echo "1. Read every running-state screenshot the implementer produced"
    echo "   under .tmp/v6-shots/loop3-<n>/ with the Read tool — PNGs"
    echo "   render visually. Compare to baseline-pre-loop3/f-running-1280.png."
    echo "2. Read the actual diff in src/Admin/ImportAdminPage.php."
    echo "3. Run composer test yourself. Run verify-option-30-flow.js yourself."
    echo "4. Run the grep audits for font-size / padding / margin counts."
    echo "5. Explicitly verify the dedup rendering by reading the JS that builds the activity log; describe how it counts identical events."
    echo "6. Explicitly verify the URL-treatment decision is per-host"
    echo "   selectable by reading the markup + JS; assert the live-count label updates."
    echo "7. For each acceptance criterion write [✓/✗] with concrete evidence (file:line or screenshot)."
    echo
    echo "## Auto-FAIL patterns"
    echo "- Implementer claims feature works but you cannot produce a"
    echo "  screenshot showing it."
    echo "- 'Tests pass' without rerunning."
    echo "- 'Looks better' without evidence."
    echo
    echo "## Output format"
    echo "### Per-criterion checklist"
    echo "[✓/✗] line each."
    echo "### Findings"
    echo "Severity + Title + Evidence + Reproduction + Suggested action."
    echo "### Verdict"
    echo "Last line: 'VERDICT: PASS' or 'VERDICT: FAIL'."
    echo
    echo "PASS only when every criterion is [✓], no CRITICAL or HIGH"
    echo "findings, screenshots are visibly cleaner than the baseline,"
    echo "dedup is real, per-host selection is real, the rewritten-URLs"
    echo "indication is visible after the decision, and the design"
    echo "genuinely reads as crisp / clear / useful."
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
