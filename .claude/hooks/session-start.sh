#!/bin/bash
# Installs the CLIs used by the project skills (see .claude/SKILLS-SOURCES.md).
# Runs only in Claude Code cloud sessions; each tool is skipped if already present.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

export PATH="$HOME/.local/bin:$PATH"

command -v playwright-cli >/dev/null 2>&1 || npm install -g @playwright/cli@latest >&2
command -v anydoc >/dev/null 2>&1 || npm install -g @firecrawl/anydoc >&2
command -v strix >/dev/null 2>&1 || uv tool install strix-agent >&2
command -v agent-reach >/dev/null 2>&1 || \
  uv tool install https://github.com/Panniantong/Agent-Reach/releases/latest/download/agent-reach.zip >&2
[ -d "$HOME/.claude/plugins/marketplaces/thedotmack" ] || npx -y claude-mem install </dev/null >&2

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$CLAUDE_ENV_FILE"
fi
