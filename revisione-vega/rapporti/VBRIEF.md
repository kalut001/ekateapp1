# Revisione di "Vega" (copia del 12/11/2026) — istruzioni comuni

## Cos'è
Assistente personale vocale per Windows di un consulente per la sicurezza sul lavoro (D.Lgs. 81/2008), in Python.
Usa più "cervelli": Groq (API OpenAI-compatibili), Ollama locale, Claude (Anthropic, via claude_ponte.py che traduce dal formato OpenAI),
Gemini (gemini_ponte.py). Voce (vosk, edge-tts, pyttsx3, Gemini TTS), posta, agenda Google, telefono (via Tailscale),
controllo del PC, redazione e revisione di DVR, corsi, ATECO, biblioteca di conoscenze.
Punti d'ingresso: vega.py (voce), vega_testo.py (testo). Chiavi nel Gestore credenziali di Windows (keyring).
LEGGIMI.txt dice cosa è escluso dalla copia (impostazioni.env, dati/, conoscenze/, modelli/...).
Requisiti dichiarati: installa.bat consiglia Python 3.13; Vega.pyproj indica un .venv Python 3.15; verifica.py accetta >= 3.11.

Sorgente (sola lettura): /tmp/claude-0/-home-user-ekateapp1/e57d2537-192f-5d2e-ad4d-021f9810516e/scratchpad/vega/Vega/
NON modificarlo. Per eseguire codice usa una TUA copia, es. cp -r <sorgente> <scratchpad>/vrun_<gruppo>.

## Strumenti già pronti
- Docker image `vega313`: Python 3.13 con TUTTE le dipendenze di requirements.txt installate (pip check OK).
  Esempio: docker run --rm --network none -v <tua_copia>:/w -w /w vega313 python -c "import dvr; print(dvr.xxx(...))"
  Usa SEMPRE --network none (nessuna chiamata a servizi reali, nessuna chiave). Python 3.11 e 3.15: immagini python:3.11-slim, python:3.15-slim (senza dipendenze).
- Già verificato dal coordinatore: sintassi OK su 3.11/3.13/3.15; 48 moduli si importano su 3.13; pacchetti di requirements esistono su PyPI
  con wheel Windows per cp313/cp315. Report ruff: <scratchpad>/vqa/ruff.txt (426 righe, molte di stile; da valutare in particolare
  B023 in vega.py:399-502, PLW2901, B905, DTZ) ; bandit: <scratchpad>/vqa/bandit.txt.
- Riferimento API Claude aggiornato (per chi rivede codice che chiama Anthropic): modelli validi correnti includono
  claude-haiku-5-5, claude-sonnet-5-5, claude-opus-5-5. Su Haiku 5.5 e Sonnet 5.5 il ragionamento (thinking) è ATTIVO di default
  e consuma max_tokens (risposta che può finire con stop_reason "max_tokens" dopo un blocco thinking e senza testo);
  i blocchi thinking vanno letti per tipo e ripassati invariati nei cicli con strumenti; stop_reason "refusal" va gestito;
  Haiku 5.5 rifiuta temperature/top_p/top_k non di default (400); Sonnet 5.5 rifiuta thinking disabled e tool_choice any/tool (400).
  Documentazione: /tmp/claude-0/bundled-skills/2.1.322/0d3b544131257bf8af7beac944219443/claude-api/ (shared/model-migration.md, python/claude-api/README.md).
  Per Groq/Gemini/OpenAI NON inventare: se non puoi verificare un nome di modello o un parametro, scrivi NON VERIFICATO.

## Regole
1. Leggi OGNI riga dei file assegnati (Read a blocchi). Alla fine dichiara per ogni file "righe lette 1-N di N".
2. Ogni difetto: CONFERMATO-prova (eseguito nel contenitore, con comando) / CONFERMATO-codice (dimostrato dal codice) / SOSPETTO / MIGLIORAMENTO.
   Sempre file:riga. Non inventare. Non segnalare come difetto ciò che è corretto. Evita falsi positivi: segui il flusso reale
   (chi chiama la funzione, con quali valori) prima di affermare un difetto.
3. Priorità: (a) sicurezza: esecuzione di comandi, controllo del PC, accesso remoto dal telefono, iniezione di istruzioni (prompt injection)
   da email/web/documenti che porti a usare strumenti pericolosi, segreti (chiavi, password) in log/file/memoria, percorsi di file;
   (b) errori che fanno fallire o rispondere in modo sbagliato (eccezioni non gestite, thread, race, fusi orari, date, encoding Windows);
   (c) correttezza dei contenuti tecnici di sicurezza sul lavoro prodotti (DVR, corsi, scadenze) — elenca i riferimenti normativi con file:riga
   (li verifica un altro revisore); (d) compatibilità Windows / Python 3.11-3.15; (e) prestazioni e robustezza.
4. Non correggere nulla.

## Consegna (scrivi su <scratchpad>/vfind/<gruppo>.md e restituiscilo come risposta finale)
### File letti (righe)
### Difetti: ID (<gruppo>-NN) | gravità (critico/alto/medio/basso) | stato | file:riga | descrizione | come riprodurlo | conseguenza concreta | correzione proposta
### Riferimenti normativi trovati (se presenti): file:riga | testo esatto | cosa afferma
### Non verificato e perché
(<scratchpad> = /tmp/claude-0/-home-user-ekateapp1/e57d2537-192f-5d2e-ad4d-021f9810516e/scratchpad)
