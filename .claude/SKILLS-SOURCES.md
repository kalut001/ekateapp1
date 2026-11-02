# Skill e MCP installati

Copiati dai repository ufficiali degli autori il 2026-11-02 (scope di progetto: `.claude/skills/`).

| Pacchetto | Sorgente | Commit | Licenza | Skill |
|---|---|---|---|---|
| Emil Kowalski | github.com/emilkowalski/skill (`skills/`) | f1090be | MIT | emil-design-eng, animate, animate-expo, animation-performance, animation-vocabulary, apple-design, ask-sonner, break-ui, find-animation-opportunities, improve-animations, mobile-native, pick-ui-library, prototype, remove-animations, review-animations, write-swift |
| Impeccable | github.com/pbakaus/impeccable (`.claude/skills/impeccable`) | c3c94b9 | Apache-2.0 | impeccable |
| Taste Skill | github.com/Leonxlnx/taste-skill (`skills/taste-skill`) | 12af471 | MIT | design-taste-frontend (cartella `taste-skill`) |
| Figma | github.com/figma/mcp-server-guide (`skills/`) | f3f264d | vedi repo | figma-* (16 skill) |
| Playwright | github.com/microsoft/playwright-cli (`skills/playwright-cli`) | c2d8d19 | Apache-2.0 | playwright-cli |
| Agent Skills (Addy Osmani) | github.com/addyosmani/agent-skills (`skills/`) | 301b02b | MIT | 25 skill (spec-driven-development, test-driven-development, code-review-and-quality, security-and-hardening, ecc.) |
| Strix | github.com/usestrix/strix (`skills/`) | 6b7ae2e | Apache-2.0 | 9 skill di security testing (penetration-testing-with-strix, owasp-top-10-testing, ecc.) |
| anydoc | github.com/firecrawl/anydoc (`skills/`) | e7178e0 | MIT | convert-documents-to-markdown |
| Claudex Loop | github.com/chaseai-yt/claudex-loop (`skills/`) | 53cddc4 | MIT | claudex-loop, claudex-route, codex-build, codex-review |
| Claude Code Setup | github.com/anthropics/claude-plugins-official (`plugins/claude-code-setup/skills/`) | a7ba5b9 | vedi repo | claude-automation-recommender |
| Agent Reach | github.com/Panniantong/Agent-Reach (`agent_reach/skill`) | 7a38047 | MIT | agent-reach |

## Server MCP (`.mcp.json`)

- `figma`: server remoto ufficiale `https://mcp.figma.com/mcp` (richiede login Figma via OAuth con `/mcp`).
- `playwright`: `npx -y @playwright/mcp@latest` (server ufficiale Microsoft).

## Note

- La skill `playwright-cli` richiede il comando `playwright-cli`: `npm install -g @playwright/cli@latest`
  (installazione globale: va ripetuta in ogni nuovo container/macchina).
- Copia manuale di Impeccable: non include l'hook di design automatico. `npx impeccable install` scarica da
  impeccable.style (bloccato nell'ambiente cloud). In alternativa si può copiare a mano
  `.claude/settings.json` (hook) e `.claude/agents/` dal repository pbakaus/impeccable, commit c3c94b9.
- Il motore del detector (`.claude/skills/impeccable/scripts/impeccable detect <file>`) si scarica da
  GitHub Releases con verifica SHA-256.
- Aggiornamento: ripetere la copia dai repository sopra.

## CLI richieste da alcune skill (installazione globale, da ripetere su ogni macchina/container)

- anydoc: `npm install -g @firecrawl/anydoc`
- Strix: `uv tool install strix-agent` (serve Docker e una chiave LLM: `STRIX_LLM`, `LLM_API_KEY`)
- Agent Reach: `uv tool install https://github.com/Panniantong/Agent-Reach/releases/latest/download/agent-reach.zip`
  (NON installare il pacchetto PyPI `agent-reach`: non è questo progetto)
- Claudex Loop: richiede le CLI `codex` e `claude` installate e autenticate.

## Installazione automatica nelle sessioni cloud

`.claude/hooks/session-start.sh` (registrato come hook SessionStart in `.claude/settings.json`) installa
le CLI sopra all'avvio di ogni sessione Claude Code cloud, saltando quelle già presenti.
Sul PC locale non fa nulla: lì vanno installate a mano con i comandi sopra.
