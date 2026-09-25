#!/usr/bin/env bash
# CornerArea build run: one RUN-QUEUE item per fresh Claude Code session.
# Keeps going until the queue is done, a run reports BLOCKED, a run fails
# (for example, the plan's usage limit), or you stop it.
# Stop: Ctrl-C, or `touch STOP` in the repo root (checked between runs).
# Resume: run this script again. The queue's state lives on the stack's top branch.
set -u
cd "$(git rev-parse --show-toplevel)" || exit 1

mkdir -p .run-logs
grep -qxF '.run-logs/' .git/info/exclude 2>/dev/null \
  || printf '.run-logs/\n.run-top-branch\nSTOP\n' >> .git/info/exclude

MAX_RUNS="${MAX_RUNS:-40}"

for i in $(seq 1 "$MAX_RUNS"); do
  if [ -f STOP ]; then echo "STOP file found, stopping."; exit 0; fi
  if [ -f .run-top-branch ]; then git checkout -q "$(cat .run-top-branch)" || exit 1; fi

  log=".run-logs/$(date +%Y%m%d-%H%M%S).log"
  echo "== run $i ($(date '+%H:%M')) -> $log"

  claude -p "$(cat prompts/run-step.md)" \
    --permission-mode auto \
    --permission-prompts none > "$log" 2>&1
  status=$?
  tail -n 4 "$log"

  if [ "$status" -ne 0 ]; then
    echo "Claude exited with $status (usage limit or error). Stopping; run again later to resume."
    exit "$status"
  fi

  state=$(grep -o 'RUN-STATE: [A-Z]*' "$log" | tail -n 1)
  case "$state" in
    "RUN-STATE: CONTINUE") ;;
    "RUN-STATE: DONE")     echo "Queue finished."; exit 0 ;;
    "RUN-STATE: BLOCKED")  echo "Blocked. See $log and prompts/RUN-QUEUE.md."; exit 3 ;;
    *)                     echo "No RUN-STATE line. Stopping so you can look at $log."; exit 4 ;;
  esac
done
echo "Reached MAX_RUNS=$MAX_RUNS."
