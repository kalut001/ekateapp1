# Skill e MCP installati

Copiati dai repository ufficiali degli autori il 2026-11-02 (scope di progetto: `.claude/skills/`).

| Pacchetto | Sorgente | Commit | Licenza | Skill |
|---|---|---|---|---|
| Emil Kowalski | github.com/emilkowalski/skill (`skills/`) | f1090be | MIT | emil-design-eng, animate, animate-expo, animation-performance, animation-vocabulary, apple-design, ask-sonner, break-ui, find-animation-opportunities, improve-animations, mobile-native, pick-ui-library, prototype, remove-animations, review-animations, write-swift |
| Impeccable | github.com/pbakaus/impeccable (`.claude/skills/impeccable`) | c3c94b9 | Apache-2.0 | impeccable |
| Taste Skill | github.com/Leonxlnx/taste-skill (`skills/taste-skill`) | 12af471 | MIT | design-taste-frontend (cartella `taste-skill`) |
| Figma | github.com/figma/mcp-server-guide (`skills/`) | f3f264d | vedi repo | figma-* (16 skill) |
| Playwright | github.com/microsoft/playwright-cli (`skills/playwright-cli`) | c2d8d19 | Apache-2.0 | playwright-cli |

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
