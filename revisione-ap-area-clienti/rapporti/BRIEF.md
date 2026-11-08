# Revisione plugin WordPress "AP Area Clienti" 1.28.0 — istruzioni comuni per i revisori

## Contesto
Plugin per l'area riservata delle aziende clienti di uno studio di consulenza sicurezza sul lavoro (D.Lgs. 81/2008).
In uso su un sito reale: NON contattare mai apsicurezzalavoro.it. Requisiti dichiarati: WordPress 6.0+, PHP 7.4+.
Ruoli: amministratore WP (studio, manage_options), apac_collaboratore (cap apac_studio: tutta l'area riservata,
nessuna bacheca WP), apac_cliente (deve vedere SOLO la propria azienda: user meta apac_azienda_id),
sola consultazione (user meta apac_accesso=lettura), lavoratore (apac_lavoratore_id + apac_accesso=lettura).
Funzioni chiave: includes/helpers.php (apac_is_admin, apac_user_studio, apac_current_azienda_id,
apac_can_access_azienda, apac_get_row_for_azienda), includes/actions.php (apac_handle_action: smistatore unico
dei moduli POST admin-post.php?action=apac_action con op, _apac_nonce, registro in includes/operazioni.php),
includes/admin-tools.php (apac_client_can permessi), includes/anteprima.php ("Vedi come il cliente").
Molti endpoint GET sono agganciati a 'init' (download, stampe, export CSV, push, offline, sw, ecc.).

Sorgente originale (sola lettura, da leggere): /tmp/claude-0/-home-user-ekateapp1/e57d2537-192f-5d2e-ad4d-021f9810516e/scratchpad/src/ap-area-clienti/
LEGGIMI.txt nello stesso percorso = funzioni e correzioni dichiarate di tutte le versioni.

## Sito di test locale (già attivo)
- URL: http://127.0.0.1:8080  (pagina portale: /area-riservata/ ; azioni: /wp-admin/admin-post.php)
- WordPress 7.1.4 in /opt/wpt/wp, PHP 8.3, MariaDB (mysql -uroot wpt), WP-CLI: `wp --allow-root --path=/opt/wpt/wp ...`
- WP_DEBUG attivo, log: /opt/wpt/debug.log. Email NON inviate: scritte in /opt/wpt/mails.log.
- PHP 7.4 disponibile via docker: `docker run --rm -v <dir>:/src php:7.4-cli php ...`
- Aziende: id 1 "Alfa Srl", id 2 "Beta Spa" (con lavoratori, attestati, DPI, attrezzature, misure, verbali di esempio;
  lavoratori di Beta hanno cognome con suffisso -BETA). Lavoratori Alfa id 1-4, Beta id 5-8.
- Utenti (password Pass-<login>-123, admin: Admin-Pass-123):
  admin (id1, amministratore) · cliA (id2, cliente Alfa) · cliB (id3, cliente Beta) · roA (id4, Alfa sola consultazione)
  lavA (id5, lavoratore Alfa, lavoratore_id 1) · collab (id6, apac_collaboratore) · lavB (id7, lavoratore Beta, lav 5) · editor1 (id8, editor WP, nessuna azienda)
- Cookie già pronti: /opt/wpt/jars/<login>.txt  (rigenera con /opt/wpt/login.sh <login>). Esempio:
  curl -s -b /opt/wpt/jars/cliB.txt 'http://127.0.0.1:8080/area-riservata/?sez=documenti'
- Per inviare un modulo serve il nonce (campo _apac_nonce, azione 'apac_'.op) e il codice monouso del modulo:
  il modo più affidabile è scaricare la pagina con il modulo come utente e riusare TUTTI i campi hidden del form,
  cambiando solo l'ID da attaccare. Puoi anche creare un nonce con wp eval:
  `wp --allow-root --path=/opt/wpt/wp eval 'wp_set_current_user(3); echo wp_create_nonce("apac_OP");'` (nota: i nonce
  dipendono dalla sessione: per curl preferisci quelli presi dalla pagina servita a quel cookie).
- Playwright/Chromium preinstallato (require('playwright') funziona) se servono prove nel browser.

## Regole
1. NON modificare i file del plugin (né in /opt/wpt/wp/wp-content/plugins/ap-area-clienti né nel sorgente).
   NON cancellare aziende 1 e 2 né gli utenti esistenti. Puoi creare dati nuovi (prefisso "TEST-<tuo gruppo>").
   Altri revisori usano lo stesso sito in parallelo: niente reset del database, niente disattivazione del plugin.
2. Leggi OGNI riga dei file assegnati (non a campione). Usa Read a blocchi se il file è grande.
   Alla fine dichiara per ogni file assegnato: righe totali lette (es. "1-1015 di 1015").
3. Ogni difetto: CONFERMATO (riprodotto con prova reale sul sito di test, oppure dimostrato in modo inequivocabile dal
   codice — indica quale dei due) / SOSPETTO (da verificare) / MIGLIORAMENTO (non è un difetto).
   Sempre file:riga. Non inventare. Se una cosa è verificata e corretta, NON segnalarla come difetto.
   Evita falsi positivi: prima di segnalare un IDOR controlla davvero se l'ID viene filtrato per azienda_id
   (es. apac_get_row_for_azienda, WHERE azienda_id = %d) o se il chiamante passa dal controllo in apac_handle_action.
   Prima di segnalare XSS controlla l'escaping effettivo nel punto di stampa. Segnala le SQL injection solo se un dato
   controllabile dall'utente arriva nella query senza prepare/cast/whitelist (i nomi tabella da apac_table() sono sicuri).
4. Priorità: (a) isolamento tra aziende / IDOR, (b) permessi per ruolo lato server, (c) SQLi/XSS/CSRF/open redirect,
   (d) upload/download file, (e) fughe di dati (log, email, push, cache, offline), (f) errori funzionali e PHP
   (notice/warning/fatal su PHP 7.4 e 8.x, es. funzioni PHP 8 come str_contains in codice 7.4, null passati a funzioni
   stringa in 8.1+), (g) casi limite (date, fusi, ora legale, doppio invio, testi lunghi/emoji).
5. Per ogni endpoint/operazione/download dei tuoi file compila la riga della tabella di isolamento:
   endpoint/op | parametro ID | controllo azienda presente? (file:riga) | prova cliB su dato di Alfa (esito) | ruoli ammessi lato server.
6. Non correggere nulla.

## Formato di consegna (scrivi anche su file: <scratchpad>/findings/<gruppo>.md e restituiscilo come risposta finale)
### File letti
- percorso — righe lette X-Y di N
### Difetti
Per ciascuno: ID provvisorio (<gruppo>-NN) | gravità proposta (critico/alto/medio/basso) | stato (CONFERMATO-prova / CONFERMATO-codice / SOSPETTO / MIGLIORAMENTO)
| file:riga | descrizione | come riprodurlo (comandi esatti usati) | conseguenza concreta | correzione proposta
### Tabella isolamento/permessi (righe dei tuoi endpoint)
### Non verificato e perché
