#!/usr/bin/env bash
set -uo pipefail

WORK_DIR="/home/claude/wp-extensions-work/universal-wordpress-importer/.adversarial-loop/20260523-010950-strict-dedup"
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
    echo "Make real edits to src/Admin/ImportAdminPage.php and tools."
    echo "Run the grep audit yourself before declaring done — every count"
    echo "in the acceptance checklist must pass when you grep."
    echo "Print a short bullet-list summary AND a copy of your final grep"
    echo "output for the three scenarios."
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
    echo "# Your role: HOSTILE verifier with FORENSIC grep discipline"
    echo
    echo "Default to FAIL. You MUST grep the rendered HTML yourself and"
    echo "count occurrences of each fact listed in the acceptance"
    echo "checklist. If any count exceeds the allowed maximum, FAIL."
    echo
    echo "## What you MUST do"
    echo "1. Run each scenario yourself:"
    echo "   php tools/render-admin-snapshot.php --running --scenario=stage-1-early > /dev/null && cp snapshot.html /tmp/early.html"
    echo "   php tools/render-admin-snapshot.php --running > /dev/null && cp snapshot.html /tmp/mid.html"
    echo "   php tools/render-admin-snapshot.php --running --scenario=stage-3-decision > /dev/null && cp snapshot.html /tmp/decision.html"
    echo
    echo "2. Strip the Technical-details disclosure from each before counting:"
    echo "   for f in /tmp/early.html /tmp/mid.html /tmp/decision.html; do"
    echo "     python3 -c \"import re,sys;h=open('\$f').read();h=re.sub(r'<details class=\\\"universal-importer-pipeline.*?</details>','',h,flags=re.S);open('\$f.user.html','w').write(h)\""
    echo "   done"
    echo
    echo "3. Run the grep audit:"
    echo "   for f in /tmp/early.html.user.html /tmp/mid.html.user.html /tmp/decision.html.user.html; do"
    echo "     echo \"=== \$f ===\""
    echo "     grep -oE 'Queued to fetch GitHub|GitHub repository fetch queued|file count will appear|Stage [0-9] of 6|Read source|Prepare content|URL treatment|Needs attention|Answer the prompt|Choose URL treatment to continue|Preparing [0-9]+ item ' \"\$f\" | sort | uniq -c | sort -rn"
    echo "   done"
    echo
    echo "4. Map every count to the acceptance checklist. Any failure = FAIL."
    echo "5. Read the screenshots under .tmp/v6-shots/loop5-<n>/ with the Read tool."
    echo "6. Run composer test + verify-option-30-flow.js yourself."
    echo
    echo "## Auto-FAIL"
    echo "- Trusting the implementer's claim without your own grep."
    echo "- 'No fact appears more than twice' without showing the grep output."
    echo "- 'PASS' when any fact in the user-facing markup duplicates."
    echo
    echo "## Output format"
    echo "### Per-criterion checklist"
    echo "Each criterion: [✓/✗] + grep command + actual count."
    echo "### Findings"
    echo "Severity + Title + Evidence (grep output) + Reproduction + Suggested action."
    echo "### Verdict"
    echo "Final line MUST be 'VERDICT: PASS' or 'VERDICT: FAIL'."
    echo "PASS only when every grep count meets the maximum."
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
