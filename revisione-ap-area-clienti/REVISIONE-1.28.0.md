# Revisione completa — AP Area Clienti 1.28.0

Data: 8 novembre 2026. Prove eseguite solo su installazioni WordPress di test locali con dati finti; il sito reale non è stato contattato. Nessun file del plugin è stato modificato (controllo con sha256 di tutti i file).

Questa revisione **non** dichiara il plugin "senza bug" o "sicuro al 100%": elenca ciò che è stato verificato, come, e cosa resta non verificato (sezione 6).

---

## 1. Sintesi

| Gravità | Difetti software | di cui confermati con prova reale | solo dal codice | sospetti |
|---|---|---|---|---|
| Critico | **0** | – | – | – |
| Alto | **3** | 1 | 2 | 0 |
| Medio | **8** | 4 | 4 | 0 |
| Basso | **50** | 28 | 20 | 2 |
| **Totale** | **61** | | | |

Riferimenti normativi: **13** rilievi (1 errato, 1 omissione, 1 superato, 7 imprecisi/incompleti, 1 nota, 1 formale, 1 errato confermato in fase 5). La grande maggioranza dei ~190 riferimenti controllati è risultata corretta.

**Isolamento tra aziende: nessuna falla trovata.** In tutte le prove (pagine, operazioni, download, stampe, esportazioni, ricerca, PDF, AJAX, copia offline, push) un cliente di Beta non ha potuto leggere né modificare dati di Alfa cambiando ID nell'indirizzo, nei moduli o nelle richieste. L'unica fuga tra aziende trovata è **lato browser** su dispositivo condiviso (D05: bozze in localStorage).

**Collaboratore (novità 1.28.0): non può diventare amministratore** né entrare nella bacheca WordPress (verificato: profile.php, user-new.php, REST /wp/v2/users, impostazioni del plugin, operazioni sugli utenti dello studio).

I tre difetti alti riguardano l'**integrità dei documenti firmati** (D01, D02) e un **avviso di scadenza della formazione che non parte mai** (D04).

---

## 2. Metodo e prove automatiche

| Prova | Esito |
|---|---|
| `php -l` su PHP 8.3.6 (71 file PHP) | nessun errore |
| `php -l` su PHP 7.4.33 reale (Docker php:7.4-cli) | nessun errore |
| PHPCompatibility 9.3.5, testVersion 7.4- | 0 segnalazioni |
| Ricerca funzioni solo PHP 8 (str_contains, match, nullsafe…) | nessuna |
| PHPCS WordPress + WordPress-Security | 500 segnalazioni di sicurezza, esaminate: 442 PreparedSQL dovute a nomi tabella da `apac_table()` o segnaposto generati dinamicamente (falsi positivi); nonce/sanitize segnalati sono coperti dallo smistatore o da controlli a valle; 2 EscapeOutput su numeri interi. Nessuna SQL injection trovata. |
| PHPStan 2.1 livello 5 con stub WordPress | 40 messaggi, tutti esaminati: falsi positivi (stub) o codice ridondante innocuo. Livelli 6-8: oltre 2.600 messaggi di tipizzazione mancante, non indicativi di difetti. |
| Attivazione su WordPress 7.1.4 + PHP 8.3 con WP_DEBUG | nessun avviso; 53 tabelle InnoDB utf8mb4 |
| Attivazione su WordPress 6.0.18 + PHP 7.4.33 con WP_DEBUG | nessun avviso |
| Aggiornamento database da versione 20 → 28 | riuscito, nessun errore |
| Visita di tutte le 23 sezioni come admin, cliente, lavoratore, su entrambe le combinazioni | tutte HTTP 200; su PHP 8.3 avvisi solo in D27, D32, D35 |
| Carico 200 aziende / 5.000 lavoratori / 3.000 attestati | pannello studio 58 query; dashboard 140-195 query; N+1 in D52, D53 |
| Visualizzazione (Playwright) 375/768/1366 px, 108 combinazioni | nessun errore JS, nessuna pagina più larga dello schermo; a 320 px vedi D59 |
| Accessibilità (axe-core, tastiera, etichette) | 0 campi senza etichetta, 0 immagini senza alt; problemi in D11, D55-D58 |

Organizzazione: 9 revisori hanno letto integralmente i 79 file divisi per area, con prove reali; 1 revisore ha verificato i riferimenti normativi sulle fonti; 1 revisore indipendente (fase 5) ha ricontrollato l'elenco: ha riprodotto di nuovo D01 e D06, scartato 1 falso positivo (ex D54), corretto 4 gravità e diverse righe. Un rilievo di un revisore ("art. 15 lett. z-bis inesistente") è stato scartato perché falso: la lettera è vigente.

---

## 3. Tabella dei difetti

Stato: **CONF-prova** = riprodotto sul sito di test · **CONF-codice** = dimostrato dal codice · **SOSP** = da verificare.

### Alto

| ID | Stato | file:riga | Descrizione | Come riprodurlo | Conseguenza | Correzione proposta |
|---|---|---|---|---|---|---|
| D01 | CONF-prova (2 volte) | includes/dpi.php:333-340 | Una consegna DPI già firmata dal lavoratore resta modificabile (DPI, quantità, data, categoria); la firma resta. | Firmare una consegna; poi `dpi_save` sullo stesso id cambiando DPI e quantità → salvato; la stampa mostra il contenuto nuovo con la firma vecchia. | La ricevuta firmata (prova della consegna DPI, art. 77) può non corrispondere a ciò che il lavoratore ha firmato. | Bloccare la modifica se firmata (o invalidare la firma e richiederla), come già fanno nomine e consegne. |
| D02 | CONF-codice | includes/incarichi.php:485, 498-499 | Incarico firmato per accettazione: ruolo e date modificabili dopo la firma; la firma resta. | Firmare un incarico; `incarico_save` con altro ruolo → salvato. | Accettazione firmata di un incarico diverso da quello reale. | Come D01. |
| D04 | CONF-codice + prova `wp eval` | includes/sgsl-moduli.php:1239, 1252; notifiche.php:73-76 | Gli attestati creati da «Formazione interna» e da «Verbale di addestramento DPI» sono inseriti con `avviso_inviato = 0`; `apac_da_notificare()` richiede −1 o una soglia maggiore, quindi non inviano mai l'avviso a 30/7/0 giorni. | `apac_da_notificare(<data +10 gg>, 0)` → −1; con −1 → 30. | Formazione in scadenza senza email di avviso (la scadenza compare comunque nello scadenziario). | Inserire `avviso_inviato => -1`; correggere con una migrazione le righe esistenti con 0 e nessun avviso registrato. |

### Medio

| ID | Stato | file:riga | Descrizione | Come riprodurlo | Conseguenza | Correzione proposta |
|---|---|---|---|---|---|---|
| D03 | CONF-codice | includes/sopralluoghi.php:676-678, 684-689, 780-791 | «Riapri per correggere» (solo studio) conserva le firme; richiudendo senza firmare il verbale modificato porta le firme vecchie; i nomi dei firmatari si riscrivono a ogni chiusura. | Chiudere un sopralluogo firmato, riaprire, modificare, richiudere senza firmare. | Verbale di sopralluogo con firme che non si riferiscono al testo finale (il protocollo viene annullato e rifatto). | Alla riapertura azzerare le firme; non riscrivere i nomi sotto firme già raccolte. |
| D05 | CONF-prova | includes/sgsl-plus.php:893-915 | Bozze dei verbali in localStorage con chiave senza utente né azienda, non cancellate all'uscita (durata 7 giorni). | Playwright: l'admin scrive la dinamica di un infortunio di Alfa, esce; cliB nello stesso browser apre un infortunio di Beta → «Ripristina» mostra il testo di Alfa. | Dati personali, anche sanitari, passano tra utenti e aziende su PC/tablet condivisi (anche il collaboratore che lavora per più aziende). | Chiave con id utente e azienda; cancellare `apac_bozza_*` all'uscita e al cambio utente; valutare sessionStorage. |
| D06 | CONF-prova (2 volte, PHP 7.4 e 8.3) | includes/importazione.php:784 (`apac_send_csv`, usata da protocollo, SGSL, esportazioni) | `fputcsv(…, '\\')`: una virgoletta dopo «\» non viene raddoppiata; l'oggetto `x\";=1+2;"` produce nel CSV una cella `=1+2` non neutralizzata da `apac_csv_safe`. | Creare come cliente un protocollo con quell'oggetto; esportare; aprire con un lettore CSV standard. | Formula eseguita quando lo studio apre l'esportazione in Excel/LibreOffice. | Usare escape vuoto `''` in fputcsv (ammesso da PHP 7.4). |
| D07 | CONF-prova | includes/offline.php:115; assets/offline.js:356-358 | La copia offline non scarica mai i documenti: `apac_file_url()` usa `wp_nonce_url()`, che restituisce `&amp;`; nel JSON il nonce si perde → 403. L'interfaccia dice comunque «aggiornata». | Scaricare la copia offline come studio; tutte le richieste `apac_file` → 403. | DVR, piano di emergenza ecc. non disponibili senza linea, anche se l'app dice il contrario. | Nel JSON usare l'URL non codificato (`html_entity_decode` o nonce aggiunto con add_query_arg); segnalare all'utente i download falliti. |
| D09 | CONF-codice | assets/offline.js:22, 99-103, 129, 143-147 | Crittografia corretta (AES-GCM 256, RSA-OAEP 3072, PBKDF2 600.000 iterazioni) ma PIN di 6-12 sole cifre e limite di 5 tentativi solo nello script. | Analisi del codice. | Chi copia l'IndexedDB può provare i PIN fuori dal browser (tempo non misurato; per 6 cifre stimato in minuti). | PIN più lungo o alfanumerico, oppure chiave legata a WebAuthn/al server; documentare il rischio. |
| D10 | CONF-prova (G5) | includes/sgsl-miglioramento.php:46 | Il programma di miglioramento mostra al cliente le azioni di sopralluoghi ancora in bozza (anche in `apac_print=programma`) e cerca lo stato «fatta» mentre i sopralluoghi usano «completata» (sopralluoghi.php:242). | Creare un sopralluogo in bozza con un'azione; aprire Miglioramento come cliente. | Il cliente vede misure non ancora decise; le azioni «da verificare» spariscono dall'elenco. | Filtrare sui sopralluoghi chiusi; usare lo stato «completata». |
| D11 | CONF-codice + prova | includes/consensi.php:290-292; includes/interfaccia.php:404 | La firma obbligatoria al primo accesso (anche per la sola presa visione privacy) si fa solo disegnando sul canvas, che non è raggiungibile da tastiera. | Playwright: la firma riesce solo simulando il mouse. | Chi usa solo tastiera o lettore di schermo non può entrare nell'area (WCAG 2.1.1). | Alternativa: nome digitato più dichiarazione. |
| D13 | CONF-codice | includes/sgsl-moduli.php:132-140 (campo `inail_caso` a :137); sgsl-plus.php:448; LEGGIMI 1.22/1.26; accordo art. 28 punto 3 | Il registro infortuni lega un lavoratore identificato a infortunio, giorni di assenza e classe del certificato medico (`inail_caso`), più il testo libero «dinamica»; LEGGIMI e accordo art. 28 dichiarano che non si trattano dati sanitari. | Analisi del codice e dei testi. | Informativa e accordo non corrispondono al trattamento reale di dati art. 9 GDPR. | Aggiornare accordo e informativa; ridurre i campi; escludere questi dati da bozze e copia offline. |

### Basso

| ID | Stato | file:riga | Descrizione e conseguenza | Correzione proposta |
|---|---|---|---|---|
| D08 | CONF-prova | includes/compressione.php:47; moduli-comuni.php:147-153; webp.php:155 | EXIF con posizione GPS conservato in documenti immagine e foto quando la conversione WebP non avviene o non riduce del 5% (prova: lavA scarica un JPEG con GPS intatto). | Rimuovere sempre i metadati (ricodifica GD) al caricamento. |
| D12 | CONF-codice | (assenti) uninstall.php, register_uninstall_hook, esportatori/eraser privacy WP | Disinstallando restano tabelle, opzioni (chiave VAPID, password SMTP cifrata), user meta, ruoli, cartella privata; richieste degli interessati da fare a mano. | Esportatore ed eraser per lavoratori/utenti; disinstallazione con scelta esplicita di cancellare i dati. |
| D14 | CONF-prova | includes/protocollo.php:120-147 | Due invii simultanei: nessun doppione (chiave unica) ma il nuovo tentativo rilegge lo stesso MAX → errore in 2 casi su 3; la firma va rifatta. | `SELECT … FOR UPDATE` o GET_LOCK. |
| D15 | CONF-codice | includes/protocollo.php:569-571, 697; sgsl-moduli.php:1070-1079, 1406-1414 | Registro di protocollo e CSV mostrano data e luogo degli infortuni a utenti senza permesso «infortuni». | Applicare `apac_mod_vede`. |
| D16 | CONF-prova | includes/sgsl-plus.php:676-689 | Lo stesso nel fascicolo. | Come D15. |
| D17 | CONF-codice (+ prova G2 via database) | includes/actions.php:247, 273-294; helpers.php:88-97; files.php:373 | Togliendo «In forza» dall'anagrafica il lavoratore viene disattivato **senza** revocare l'accesso né chiudere le sessioni (la «Cessazione» invece lo fa, lavoratori.php:223-225): con la sessione aperta scarica ancora i documenti «per lavoratori». | Revocare accesso e sessioni anche da quel percorso. |
| D18 | CONF-prova | assets/offline.js:248, 261-268 | La copia offline di un'azienda salva anche pagine (pannello, studio, utenti) con dati di altre aziende; togliere l'azienda non li rimuove. | Escludere le pagine multi-azienda. |
| D19 | CONF-prova | assets/offline.js:394-401 | Uscita da wp-login.php?action=logout: la copia offline resta (cancellata solo dal link dell'area). | Cancellare anche al logout di WordPress (hook + controllo all'avvio). |
| D20 | CONF-prova | includes/push.php:266 | `endpoint[]` come array → TypeError fatale (HTTP 500). | Controllare `is_string`. |
| D21 | CONF-prova | includes/push.php:274-275 | Chi conosce l'endpoint push di un altro utente lo sostituisce (effetto: la vittima non riceve più notifiche). | Filtrare per utente o rifiutare. |
| D22 | CONF-codice | includes/importazione.php:839-853 | File temporaneo dell'export XLSX (con codici fiscali) lasciato sul server se la scrittura fallisce o il client interrompe. | `register_shutdown_function` per cancellarlo. |
| D23 | CONF-codice | includes/sgsl-lavoratori.php:332 | Cambiando la data del piano formazione l'avviso non viene azzerato. | Reimpostare `avviso_inviato = -1`. |
| D24 | CONF-prova | includes/scadenze.php:24-33, 74 | Ricorrenze mensili perdono il giorno (31/01→28/02→28/03…), annuali dal 29/02 idem. | Calcolare dalla data originale. |
| D25 | CONF-codice | includes/consensi.php:51, 114-116 | L'impronta dell'accordo art. 28 include la ragione sociale: correggere un refuso blocca di nuovo tutti gli utenti sulla firma. | Escludere la ragione sociale dall'impronta. |
| D26 | CONF-codice | includes/operazioni.php (azienda_logo, azienda_logo_delete); rifiniture.php:66-120 | Logo modificabile da un cliente senza permesso «azienda». | Aggiungere il permesso «azienda». |
| D27 | CONF-prova | includes/richieste.php:204, 273 | Campi array inviati da anonimo → «Array to string conversion». | Controllare `is_string`. |
| D28 | SOSP | includes/richieste.php:316-319 | Richieste confermate senza scadenza: 50 richieste sospendono il modulo pubblico. | Scadenza delle richieste. |
| D29 | CONF-codice | includes/richieste.php:706 | «richiesta_doppia» rivela se un indirizzo ha una richiesta in esame. | Messaggio neutro. |
| D30 | CONF-codice | includes/actions.php (log_voce_delete, log_svuota) | Il collaboratore può cancellare o svuotare il registro attività, anche delle proprie azioni (scelta di permessi). | Riservare a manage_options. |
| D31 | CONF-prova | includes/sgsl-plus.php:116-121 | La revoca del link di firma cancella anche una firma già raccolta. | `AND firma IS NULL`. |
| D32 | CONF-prova | sgsl-strumenti.php:515; actions.php:230; admin-tools.php:252 | Eliminando l'azienda di prova: avvisi «Undefined array key» e voce vuota nel registro. | Controllare le chiavi del contesto. |
| D33 | CONF-prova | includes/sgsl-strumenti.php:379 | Import registro infortuni: «Lavoratore» finisce in «Persona esterna». | Collegare al lavoratore. |
| D34 | CONF-prova | includes/operazioni.php:195; sgsl-miglioramento.php:355 | Classificazione dei mancati infortuni aperta lato server al cliente (interfaccia: solo studio). | ruolo studio nel registro. |
| D35 | CONF-prova | includes/sgsl-studio.php:103 | `prepare()` senza segnaposto: notice a ogni apertura della pagina studio. | Togliere `prepare`. |
| D36 | CONF-prova | includes/sgsl-studio.php:423 | Nota della visita pianificata mostrata al cliente senza avviso nel campo. | Avviso o campo separato. |
| D37 | CONF-prova | includes/sgsl-studio.php:169-207 | `mese=2026-13` → link a gennaio 1970. | Validare il mese 01-12. |
| D38 | CONF-prova (ambiente) | includes/sgsl-studio.php:345 | Link «Fonte» validato con risoluzione DNS: rifiutato se il DNS non risponde. | `esc_url_raw` + controllo schema. |
| D39 | CONF-prova | includes/sgsl-miglioramento.php:320 | Ore lavorate senza massimo (salvato 9223372036854775807). | Limite ragionevole. |
| D40 | CONF-codice | includes/sgsl-rischi.php:144 | `COALESCE(documento_id, %d)` con null scrive 0 invece di NULL. | Gestire il null. |
| D41 | CONF-prova | includes/sgsl-miglioramento.php:158-177 | Il cliente riporta «in corso» una misura verificata dallo studio; la data di verifica si perde. | Controllare i passaggi di stato. |
| D42 | SOSP (metodo) | includes/sgsl-miglioramento.php:228-247 | IF conta anche infortuni con 0 giorni; IG senza giornate convenzionali (UNI 7249); mancati per data di inserimento. Formule IF = n×10⁶/ore e IG = gg×10³/ore verificate corrette. | Allineare alla UNI 7249 o dichiarare il metodo. |
| D43 | CONF-codice | includes/sgsl-plus.php:366-368 | DPI anticaduta riconosciuti per parola («quota» fa scattare l'ispezione anche per un casco). | Categoria esplicita. |
| D44 | CONF-codice | includes/sgsl-moduli.php:909, 916 | Orari confrontati come stringhe: prova di evacuazione o permesso a cavallo della mezzanotte non registrabili. | Gestire il passaggio di giorno. |
| D45 | CONF-codice | includes/sgsl-moduli.php:936 | Nel verbale stress «prossima verifica» precedente alla data della valutazione accettata. | Validare l'ordine delle date. |
| D46 | CONF-codice | includes/informazione.php:414, 554; sgsl-moduli.php:1023, 1365 | L'impronta SHA-256 in stampa non copre firmatari né firme (garanzia apparente). | Includere firme e firmatari. |
| D47 | CONF-codice | includes/check-dvr.php:317 | Caso limite del 29 febbraio nel calcolo della minore età. | Confronto per data completa. |
| D48 | CONF-codice | includes/allegato7.php:207 | Testo «Verifica superata» usato per «scaduta». | Correggere il testo. |
| D49 | CONF-codice | includes/sopralluoghi.php:38-46, 1041-1046, 1060 | Liste di controllo predefinite non eliminabili, il testo dice il contrario. | Elenco delle nascoste o testo corretto. |
| D50 | CONF-codice | includes/attrezzature.php:537-546, 596-613 | Un controllo con data precedente all'ultima fa tornare indietro la prossima scadenza. | Non toccare ultima/prossima se data < ultima. |
| D51 | CONF-codice | includes/lavoratori.php:214-228; incarichi.php:472 | Cessazione con data futura: lavoratore disattivato subito, incarichi con fine precedente all'inizio. | Rifiutare date future o programmare. |
| D52 | CONF-prova | includes/portal.php:963-970 | N+1 nella lista lavoratori (~2 query per lavoratore). | Query aggregata. |
| D53 | CONF-prova | includes/portal.php:1667-1672 | N+1 nell'elenco aziende dello studio. | Query aggregata. |
| D55 | CONF-prova | assets/app.css:1487 | Focus invisibile nelle 160 opzioni della lista stress (`outline` con una variabile non colore). | `box-shadow: var(--s-focus)`. |
| D56 | CONF-prova | assets/portal.css:475, 903 | Focus giallo 2 px su bianco, contrasto 1,68:1. | Focus a due toni. |
| D57 | CONF-prova | app.css:319, 791, 994; portal.css:132, 617; dark.css:232 | Testi con contrasto 2,55-4,43:1. | Niente opacity sui testi. |
| D58 | CONF-prova | includes/moduli-comuni.php:491 | Scheda di emergenza tradotta con `<html lang="it">`. | lang della lingua scelta. |
| D59 | CONF-prova | portal.css:377-379 vs app.css:149; portal.css:92 | A 320 px scorrimento orizzontale (LEGGIMI 1.9.0: «anche a 320 px»). | Correggere specificità e a capo. |
| D60 | CONF-prova | portal.css:28-31; menu-button.php:40 | Tutta larghezza e pulsante nel menu solo con Blocksy/menu classici (LEGGIMI: «in automatico»). | Documentare o supportare i menu a blocchi. |
| D61 | CONF-codice | admin-tools.php:300-311; push.php | Eliminando un utente restano le sue iscrizioni push. | Cancellarle in delete_user. |
| D62 | CONF-prova | assets/qr-area-riservata.png | QR fisso con l'indirizzo del sito reale (sbagliato su staging o cambio slug). | Generarlo da qr.php con l'URL reale. |

(L'ID D54 è stato eliminato: falso positivo dimostrato in fase 5, actions.php:38 riscrive `$_REQUEST['azienda']` con l'azienda validata.)

### Riferimenti normativi (verifica su Normattiva e fonti ufficiali; affidabilità indicata nel rapporto G10)

| ID | Esito | file:riga | Problema |
|---|---|---|---|
| N01 | ERRATO | LEGGIMI.txt:1055-1056 | Attribuisce al D.L. 159/2025 la formazione dei preposti: l'art. 37 c. 7-ter viene dalla L. 215/2021. |
| N02 | OMISSIONE | LEGGIMI.txt:442, 779; sgsl-lavoratori.php:6-8 | Manca l'art. 37 c. 4 lett. b-bis (formazione nei periodi di cassa integrazione, L. 34/2026, in vigore dal 7/4/2026). |
| N03 | SUPERATO | formazione-regole.php:9, 64; LEGGIMI.txt:436 | Coordinatori: Allegato XIV sostituito dall'Accordo Stato-Regioni 17/4/2025 (5 anni / 40 ore resta corretto). |
| N04 | IMPRECISO | obblighi.php:6-7, 39-41, 88 | Piano di emergenza: manca la soglia «più di 50 persone presenti» (D.M. 2/9/2021 art. 2 c. 2). |
| N05 | IMPRECISO | sgsl-moduli.php:114; LEGGIMI.txt:738 | Addestramento citato come art. 37 c. 4: è il c. 5. |
| N06 | IMPRECISO | check-dvr.php:70 | L'art. 113 riguarda le scale; trabattelli art. 140, ponteggi artt. 131 e ss. |
| N07 | IMPRECISO | check-dvr.php:88 | «Tit. VI» per movimenti ripetitivi e posture: il Titolo VI è la movimentazione manuale dei carichi. |
| N08 | IMPRECISO | sgsl-plus.php:309 | Denuncia INAIL: termine dall'avuta notizia (art. 53 D.P.R. 1124/1965); la prassi INAIL citata non è stata verificata. |
| N09 | IMPRECISO (affidabilità media-bassa) | cantieri.php:14, 552 | DURC: 120 giorni dalla data della verifica, non della richiesta. |
| N10 | INCOMPLETO | allegato7.php:8, 70 | Non indica che, trascorsi i 45 giorni, la prima verifica può essere fatta da soggetti abilitati (art. 71 c. 11). |
| N11 | NOTA | cantieri.php:10, 28 | L'Allegato XVII cita l'autocertificazione dell'art. 29 c. 5, regime cessato il 30/6/2013. |
| N12 | FORMALE | check-dvr.php:102 | Scrivere «art. 15, c. 1, lett. z-bis)». |
| N13 | ERRATO (affidabilità medio-alta: Brocardi agg. 07/04/2026 e TuSSL concordi; Normattiva mostra solo l'indice, articolo mai modificato) | sgsl-moduli.php:67 | «Organizzazione della formazione (lett. c)»: è l'art. 50 c. 1 **lett. d)**. |

Verificati e corretti (estratto): preposto biennale (art. 37 c. 7-ter); RLS 4/8 ore; primo soccorso triennale (D.M. 388/2003); antincendio quinquennale ed esercitazione annuale (D.M. 2/9/2021); Accordo 2025; patente a crediti (art. 27); art. 99; art. 26; artt. 28-29; art. 35; artt. 189, 193 (compreso c. 2 sull'attenuazione dei DPI dell'udito), 201; periodicità dell'Allegato VII inclusa la L. 34/2026; fasce INAIL stress 2017. Elenco completo con URL: `findings/G10-normativa.md`.

---

## 4. Isolamento tra aziende (prova: utente cliB di Beta contro dati di Alfa)

| Area / endpoint | Parametro attaccato | Controllo nel codice | Esito della prova |
|---|---|---|---|
| Tutte le operazioni POST (`admin-post.php?action=apac_action`, oltre 150 op) | `azienda_id`, ID delle righe | actions.php:34 azienda forzata dal profilo; righe con `apac_get_row_for_azienda` / `WHERE azienda_id` | negato (es. `lavoratore_save` su lav. 1 → denied, dato invariato) |
| ID secondari (lavoratore in DPI e incarichi, attrezzatura in controlli, controllo in Allegato VII, documento in sostanze/DVR) | ID di Alfa | verifica di appartenenza | negato / bad_value |
| Pagine del portale (23 sezioni, `?azienda=1`, `lav`, `mod`, `lavoro`, `impresa`…) | azienda e ID | helpers.php:77-79 ignora `?azienda` per i clienti | solo dati di Beta / «non trovato» |
| Download documenti `apac_file`, versioni `apac_ver` | id | files.php:372-379; documenti.php:64-66 | 403 |
| Foto `apac_img` / `apac_foto` | id | documenti.php:64-66 (stesso codice di apac_ver) | 403 sulle foto di Alfa (G5) |
| Stampe `apac_print` (dpi, sopralluogo, etichette, consegna, nomina, moduli SGSL, programma, emergenza) | id | filtro per azienda | 403 |
| `apac_print=fascicolo`, `apac_report` (riepilogo), `apac_export` xlsx/csv, `apac_prot_export`, `apac_sgsl_csv_export` | azienda | azienda dal profilo | solo Beta |
| Consensi `apac_serve_consenso` | id | consensi.php | 403 |
| Ricerca nei dati e `wp_ajax_apac_cerca_aziende` | q | admin-only + nonce; ricerca dati per azienda | 403 / nessun dato di Beta per cliA |
| QR attrezzature | codice | ~80 bit casuali | qr_non_valido |
| Firma a distanza | token | 160 bit, hash, scadenza 7 gg, legato alla bozza | nessun accesso ad altre aziende |
| Copia offline `apac_offline` | azienda | solo studio | 403 per clienti e lavoratori |
| Push `apac_push` | endpoint | per utente su unsubscribe/test | vedi D21 (solo sostituzione dell'iscrizione) |
| Protocollo annulla/sostituisci | riga | filtro per azienda | denied |
| Richieste di accesso (`richiesta_collega`) | azienda | solo studio | negato |
| **Bozze in localStorage del browser** | – | **chiave senza utente/azienda** | **fuga su dispositivo condiviso (D05)** |

Dettaglio riga per riga nei rapporti `findings/G1.md` … `G9.md`.

## 5. Permessi per ruolo (controllo lato server)

| Operazione | Cliente (modifica) | Sola consultazione | Lavoratore | Collaboratore | Amministratore |
|---|---|---|---|---|---|
| Vedere dati della propria azienda | sì | sì | solo i propri (verificato) | tutte le aziende | tutte |
| Modificare dati della propria azienda | secondo i permessi dell'azienda | negato (verificato) | solo op ammesse: segnalazioni, mancati, firme proprie (verificato) | sì | sì |
| 62 operazioni riservate allo studio (consegne, nomine, visite, demo…) | negato 62/62 con nonce valido | negato 62/62 | negato 62/62 | sì | sì |
| Utenti: crea/revoca/reset | solo utenti cliente della propria azienda | negato | negato | solo utenti cliente; negato su admin/studio (verificato) | sì |
| Impostazioni del plugin, SMTP, mail di prova | negato | negato | negato | negato 403 (verificato) | sì |
| Bacheca WordPress (profile.php, user-new.php, REST users) | redirect al portale | redirect | redirect | redirect / 401 (verificato) | sì |
| Vista «studio», export attività | negato | negato | negato | sì | sì |
| Registro attività: cancellare voci | negato | negato | negato | **sì (D30)** | sì |
| Revoca dell'accesso | – | – | – | chiude tutte le sessioni (verificato) | idem |

---

## 6. Cosa NON è stato possibile verificare, e perché

- **Server web reale (Apache/Nginx, .htaccess)**: il sito di test usa il server integrato di PHP. La cartella privata è risultata fuori dalla webroot e il rilevamento «esposta» funziona, ma la protezione via .htaccess non è stata provata su Apache.
- **Invio SMTP reale**: nessun server di posta nel test; le email sono state intercettate e lette su file.
- **Notifiche push reali, WebAuthn/PRF, navigation preload**: richiedono servizi e autenticatori reali del browser.
- **Tempo reale di forzatura del PIN (D09)**: stimato, non misurato.
- **Safari/iOS**: disponibile solo Chromium.
- **Tema Blocksy e plugin WP Statistics/WPForms**: non installati; tutta larghezza simulata.
- **Lettore di schermo reale**: accessibilità verificata con axe-core e tastiera, non con NVDA/VoiceOver.
- **D02, D03, D10**: confermati dal codice; D10 riprodotto da G5, D02-D03 non rieseguiti in fase 5.
- **Norme tecniche a pagamento** (UNI 9994-1, UNI EN 365, ISO 11228, UNI 7249): non verificabili sul testo.
- **Allegati IV, XIV, XVII, XXXV del D.Lgs. 81/2008, D.M. 1/9/2021 e 3/9/2021**: non letti sul testo ufficiale. N13 letto su fonti secondarie concordanti, non sul testo Normattiva.
- **Novità normative non gestite dal plugin** (segnalate, non difetti): termine per il nuovo corso del datore di lavoro (Accordo 2025), nuovo accordo alcol e sostanze previsto entro il 31/12/2026.
- **Prestazioni su hosting reale**: misurate solo in locale.

## 7. Privacy (GDPR) in breve

- **Dati trattati**: anagrafiche lavoratori (codice fiscale, data di nascita, email, lingua), attestati, DPI, incarichi, firme (immagini PNG) con IP e user agent, registro infortuni nominativo (dati relativi alla salute, D13), segnalazioni e mancati infortuni (con autore, non anonimi), richieste di accesso, iscrizioni push, registro attività, transient con dati dei moduli.
- **Dove**: 53 tabelle `wp_apac_*`, user meta e opzioni, cartella privata fuori webroot (documenti, foto WebP, firme, PDF dei consensi), browser (localStorage bozze e lista stress, IndexedDB/Cache della copia offline cifrata).
- **Chi vede**: lo studio (admin e collaboratore) tutte le aziende; il cliente solo la propria (verificato); il lavoratore solo i propri dati.
- **Esportazione e cancellazione**: nessuna integrazione con gli strumenti privacy di WordPress (D12).
- **Dopo la disattivazione**: resta tutto; si ferma solo il controllo giornaliero. **Dopo la disinstallazione**: resta tutto (nessun uninstall.php).

## 8. Copertura dei file

Tutti i **79 file** sono stati letti integralmente; ogni rapporto dichiara le righe lette «1-N di N».

| Revisore | File |
|---|---|
| G1 nucleo e accessi | ap-area-clienti.php, includes/helpers.php, operazioni.php, actions.php, admin.php, admin-tools.php, utenti.php, anteprima.php, lavoratore-area.php, forms.php, misura.php, db.php (12) |
| G2 file, offline, push | includes/files.php, documenti.php, importazione.php, compressione.php, webp.php, spazio.php, offline.php, webapp.php, pwa.php, push.php, assets/offline.js (11) |
| G3 portale e interfaccia | includes/portal.php, interfaccia.php, ui.php, search.php, menu-button.php, site-style.php, seo.php, perf.php, contatti.php (9) |
| G4 email e notifiche | includes/mail.php, notifiche.php, invii.php, consensi.php, richieste.php, riepiloghi.php, criticita.php, scadenze.php, segnalazioni.php, avvisi-azienda.php, rifiniture.php, obblighi.php (12) |
| G5 moduli di lavoro | includes/lavoratori.php, dpi.php, attrezzature.php, sopralluoghi.php, mancati.php, qr.php, moduli-comuni.php, incarichi.php, formazione-regole.php, allegato7.php (10) |
| G6 cantieri e consegne | includes/cantieri.php, fascicolo.php, consegne.php, consegne-out.php, nomine.php (5) |
| G7 protocollo e SGSL | includes/protocollo.php, informazione.php, sgsl-moduli.php, check-dvr.php, sgsl-stress.php (5) |
| G8 SGSL parte 2 | includes/sgsl-miglioramento.php, sgsl-rischi.php, sgsl-emergenze.php, sgsl-lavoratori.php, sgsl-preposto.php, sgsl-studio.php, sgsl-plus.php, sgsl-strumenti.php (8) |
| G9 stili, LEGGIMI, immagini | LEGGIMI.txt, assets/app.css, portal.css, dark.css, button.css, logo-pdf.jpg, qr-area-riservata.png (7) |
| **Totale** | **79** |

## 9. Miglioramenti (non difetti)

Header X-Frame-Options/CSP assenti sulle pagine riservate (no-store e noindex presenti); impronta dei documenti ricalcolata a ogni pagina; ricompressione dei PDF durante la richiesta; notifiche push con nomi sulla schermata di blocco e iscrizione non rimossa all'uscita; SMTP senza cifratura ammesso; nel ripiego openssl la stessa chiave per cifratura e HMAC; `wp_die` senza 403 restituisce 500; lo studio riceve un'email per azienda; mancati infortuni non anonimi; numerazione dei verbali unica per lo studio; il lavoratore vede firme ed esiti dei colleghi nei verbali di informazione; risposte della lista stress nel localStorage senza scadenza; funzioni mai chiamate (apac_slc_calcola, apac_slc_trend, apac_cdvr_script); molte regole `!important`; LEGGIMI 1.9.18 non aggiornato per il collaboratore; l'accordo art. 28 promette copie di sicurezza mentre il LEGGIMI 1.13 le esclude. Dettagli nei rapporti.

---

## 10. Prossimo passo

Nessuna correzione è stata fatta. Dopo il tuo OK su questo elenco (anche parziale: dimmi quali ID correggere), preparo la 1.28.1 con: correzioni, prove ripetibili sul sito di test, LEGGIMI aggiornato, zip pronto e istruzioni di aggiornamento e di ritorno alla 1.28.0.
