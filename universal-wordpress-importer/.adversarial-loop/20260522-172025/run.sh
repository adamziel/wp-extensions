#!/usr/bin/env bash
set -uo pipefail

WORK_DIR="/home/claude/wp-extensions-work/universal-wordpress-importer/.adversarial-loop/20260522-172025"
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
    echo "Implement the task above in the current repo. Make real edits."
    echo "If there is prior feedback, address every issue listed."
    echo "When done, print a short bullet-list summary of what you changed."
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
    echo "You are NOT here to confirm the implementer's work. You are here"
    echo "to find what is wrong, missing, faked, or hand-waved. Default to"
    echo "FAIL until every doubt is reproducibly cleared."
    echo
    echo "## What to actually do (not just claim to do)"
    echo
    echo "1. Re-derive the acceptance criteria from the task above. Do not anchor on the implementer's summary."
    echo "2. Read the screenshots under .tmp/v6-shots/loop-N/ with the Read tool — they render visually. If the open-popover state is missing, add a state to tools/screenshot-admin-flow.js and re-run."
    echo "3. Run composer test yourself and confirm the assertion count."
    echo "4. Run node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium yourself."
    echo "5. Grep for forbidden tokens (\"Server path\", wp-admin blue, external resources)."
    echo "6. Read the actual changed regions of src/Admin/ImportAdminPage.php to verify each acceptance bullet."
    echo
    echo "## Forbidden verifier patterns (auto-FAIL)"
    echo "- \"Tests pass\" without re-running."
    echo "- \"Looks correct\" without screenshot evidence or file:line citation."
    echo "- Treating the implementer's claims as fact."
    echo
    echo "## Output format"
    echo
    echo "### Per-acceptance-criterion checklist"
    echo "One line per criterion: \`[✓/✗] <criterion>: <evidence>\`"
    echo
    echo "### Findings"
    echo "Each numbered: Severity (CRITICAL/HIGH/MEDIUM/LOW), Title, Evidence (file:line or output), Reproduction (commands), Suggested action."
    echo
    echo "### Verdict"
    echo "End with EXACTLY one of these as the final line:"
    echo "VERDICT: PASS"
    echo "VERDICT: FAIL"
    echo
    echo "PASS only if every acceptance bullet is [✓] with concrete evidence, zero CRITICAL or HIGH findings, and the design genuinely reads as a polished GitHub-style picker + a discoverable directory selector. Default to FAIL."
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
