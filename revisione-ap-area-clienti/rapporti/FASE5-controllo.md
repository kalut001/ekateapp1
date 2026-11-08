# FASE 5 – Controllo indipendente dell'elenco DIFETTI-bozza.md (AP Area Clienti 1.28.0)

Revisore indipendente (non autore della bozza). Righe riferite al sorgente originale in scratchpad/src/ap-area-clienti.
Script miei in scratchpad/f5/: `nonces.sh` (nonce `apac_<op>` legati alla sessione del cookie, più op in una chiamata),
`post.sh` (invio a admin-post.php con nonce valido e codice monouso casuale), `csv.php` (prova fputcsv).
Dati di prova creati e rimossi: scadenza «TEST-F5-dativer» (Beta, eliminata), DPI id 8 «TEST-F5» (Beta, eliminato). Resta
nel registro di protocollo il numero 2026-013CDP, ora «annullato» (il protocollo non si cancella per progetto).
Plugin non modificato. Aziende 1-2 e utenti non toccati.

## 1. Esiti D01-D13

| ID | Esito | Gravità proposta | Motivazione (file:riga) |
|---|---|---|---|
| D01 | CONFERMATO (riprodotto da me) | alta, accettabile | dpi.php:333-340: il ramo modifica di `apac_op_dpi_save` aggiorna dpi, quantità, data, categoria. La firma (assegnata solo nel ramo nuovo, 350-362) resta. Prova: cliB `dpi_save` nuovo con firma (id 8, «TEST-F5 guanti», q.1, 01/11/2026), poi `dpi_save id=8` con «TEST-F5 imbracatura modificata», q.5, 01/10/2026, cat. 3 → `saved`. DB: firma, firma_nome e firma_at (08/11/2026 20:04) invariati. `/?apac_print=dpi&id=5` mostra il contenuto nuovo con «Anna Bianchi-BETA · 08/11/2026». Unica traccia: il protocollo CDP resta con l'oggetto vecchio («guanti»), e nel registro attività c'è solo «DPI salvato». Nota: G5 aveva proposto «medio». L'«alto» è giustificato perché a modificare è il datore di lavoro, cioè la parte interessata, su una ricevuta firmata dal lavoratore (art. 77) e senza traccia del contenuto precedente. |
| D02 | CONFERMATO (codice; riprodotto da G5, non da me) | alta, accettabile (come D01) | incarichi.php:485 (`$firma = $row ? '' : …`) e 498-499 (update di ruolo, ruolo_altro, data_inizio, data_fine con la firma conservata). Stessa logica di D01. G5 aveva proposto «medio». |
| D03 | CONFERMATO (codice; riprodotto da G5) | **medio** (da rivedere: alto è eccessivo) | sopralluoghi.php:787 (riapri: solo stato e chiuso_at, firme conservate), 680-689 (alla chiusura le firme si sovrascrivono solo se ne arrivano di nuove). Da aggiungere alla descrizione: 676-678 riscrive **sempre** `firma_tecnico_nome` e `firma_presente_nome`, quindi si può cambiare anche il nome sotto un'immagine di firma già raccolta. Per la gravità: le operazioni sono riservate allo studio (operazioni.php: `sopralluogo_riapri`/`salva` con ruolo studio, prova 3a). La riapertura annulla il protocollo («Verbale riaperto per modifica», sopralluoghi.php:789) e la nuova chiusura ne assegna uno nuovo, quindi una traccia esiste. G5 lo aveva valutato medio. |
| D04 | CONFERMATO (codice + prova `wp eval`) | alta accettabile, al limite (anche medio è difendibile) | sgsl-moduli.php:1239 e 1252 inseriscono `avviso_inviato => 0`. Lo schema usa DEFAULT -1 (db.php:93 ecc.). notifiche.php:73-76: con 0 la condizione `(<0 || soglia<0)` non è mai vera. `wp eval`: `apac_da_notificare(oggi+5,0)=-1`, `(oggi+5,-1)=7`, `(oggi,0)=-1`, `(oggi,-1)=0`. Nel DB di prova 2 attestati con 0. Attenuanti: la scadenza compare comunque nello scadenziario e negli indicatori, e il difetto riguarda solo gli attestati creati da questi due verbali. G4 aveva proposto «medio». |
| D05 | CONFERMATO (codice; riprodotto da G8/G9) | medio | sgsl-plus.php:898: chiave `apac_bozza_`+pathname+op+tipo+id, senza utente né azienda. 902-903: il ripristino scade dopo 7 giorni. Nessuna pulizia all'uscita: `apac_bozza` non compare altrove nel sorgente. Scenario realistico da aggiungere: un collaboratore dello studio che compila sullo stesso dispositivo per più aziende si vede proporre in un nuovo verbale di Beta la bozza di Alfa (stessa chiave con id vuoto), con rischio di mescolare i dati delle due aziende. |
| D06 | CONFERMATO (riprodotto da me con PHP 8.3 e 7.4) | medio | importazione.php:784: `fputcsv(..., ';', '"', '\\')` e `apac_csv_safe` (754-757) controlla solo il primo carattere. Con l'input `x\";=1+2;"` l'output è `"x\";=1+2;"""`: in Excel e LibreOffice (che non usano «\» come escape) `=1+2` diventa una cella a sé, non neutralizzata. Usato da protocollo.php:803, sgsl-plus.php:798, sgsl-studio.php:282, importazione.php:724/837. |
| D07 | CONFERMATO (codice + `wp eval`) | medio | offline.php:115 usa `apac_file_url()` = `wp_nonce_url` (files.php:333-342), che restituisce `&amp;`. `wp eval`: `…?apac_file=1&amp;mode=view&amp;_wpnonce=…` anche dentro il JSON. offline.js:356 fa `fetch(x.url)`: senza `_wpnonce` il server risponde 403 (files.php:368) e l'errore viene ignorato (`function () {}`). Funzione riservata allo studio (offline.php:96). |
| D08 | DA RIVEDERE → **basso** | basso | Il codice è corretto: compressione.php:47 → `apac_foto_riduci`, che esce senza riscrivere (moduli-comuni.php:148-149) se l'immagine è ≤1600 px e ≤700 KB. Con WebP che riduce meno del 5% resta l'originale (webp.php:155). Ma la gravità media è esagerata: il caso richiede un JPEG piccolo con EXIF caricato senza la riduzione del browser (che toglie l'EXIF), e il file è visibile solo all'azienda e allo studio. G5 lo aveva valutato basso (G5-05). |
| D09 | CONFERMATO (codice) | medio, accettabile | offline.js:22 (ITER 600000, MAX_TENT 5), 99-103 (PBKDF2→AES-GCM), 129 (`/^\d{6,12}$/`), 143-147 (contatore dei tentativi salvato nella stessa IndexedDB, quindi aggirabile con una copia). Per un PIN di 6 cifre lo spazio è 10^6 prove: la stima di minuti o ore con una GPU è plausibile, ma non l'ho misurata. Riguarda solo i dispositivi dello studio. |
| D10 | CONFERMATO (codice; dati di prova G5 rimossi, non ho riprodotto) | medio | sgsl-miglioramento.php:46: la query sulle azioni filtra solo `azienda_id`, mentre sopralluoghi.php:279-281 per i non-studio aggiunge `(z.sopralluogo_id = 0 OR s.stato = 'chiuso')`. Stati: la riga 46 usa 'aperta','fatta' e la 48 mappa 'fatta', ma gli stati reali sono aperta/completata/chiusa (sopralluoghi.php:239-245). |
| D11 | CONFERMATO (codice) | medio | consensi.php:290-293: senza un'immagine valida si ottiene `bad_signature`. La firma serve anche per la sola presa visione della privacy (260-262, 276-278, 286). interfaccia.php:404-405: solo eventi pointer. moduli-comuni.php:201: canvas `role="img"` senza tabindex né alternativa. Unica uscita senza firma: accordo «no_poteri» senza privacy in sospeso (283-288). |
| D12 | DA RIVEDERE → **basso** | basso | Il fatto è confermato: nessun uninstall.php né `register_uninstall_hook` (c'è solo `register_deactivation_hook`, ap-area-clienti.php:101), nessun filtro privacy, 53 `CREATE TABLE` in db.php. Però conservare i dati alla disinstallazione è difendibile e anzi prudente (registri della sicurezza da conservare). Gli strumenti privacy di WordPress non sono obbligatori: l'accesso e la cancellazione si possono gestire a mano. Sopra basso resterebbe solo la permanenza di chiave VAPID e password SMTP cifrata. G1 lo aveva valutato basso. |
| D13 | DA RIVEDERE (descrizione) | medio | Il nucleo è confermato: registro infortuni nominativo (sgsl-moduli.php:136 campo `lavoratore`, `dinamica` libera) con giornate perse e `inail_caso` «secondo il certificato medico» (137: fino a 3 giorni / oltre 3 / mortale), cioè una prognosi in classi: sono dati relativi alla salute. LEGGIMI.txt:44, 374, 488, 740, 1038 e l'accordo (consensi.php:29) dicono il contrario. Errori della bozza: (1) **sgsl-stress.php:124 non è una prova**: gli «eventi sentinella» (assenze per malattia) sono numeri aggregati dell'azienda (sgsl-stress.php:117-121, 276-277), non dati personali; (2) «prognosi» va precisato: il modulo dichiara di non raccoglierla (sgsl-moduli.php:134), ma `inail_caso` ne riporta la classe. |

## 2. Bassi D14-D62 errati, imprecisi o esagerati

Controllati nel codice file e righe di tutti i bassi con un riferimento. I CSS (D55-D60) sono controllati solo nelle righe citate, non a schermo.

| ID | Problema | Correzione dell'elenco |
|---|---|---|
| **D54** | **FALSO POSITIVO (riprodotto)**. actions.php:38 sovrascrive `$_REQUEST['azienda']` con l'azienda validata prima di chiamare qualsiasi operazione. perf.php:113-114 quindi non vede mai il valore inviato dal cliente. Prova: cliB `scadenza_save` con `azienda=1&azienda_id=1` POST → `saved`; `dati_ver` di Alfa resta 43, quello di Beta passa da 23 a 24 (dopo l'eliminazione 25). | Togliere D54 (al massimo un MIGL di pulizia del codice). |
| D15 | Stato «CONF-prova», ma G7-03 è CONFERMATO-codice. Mancano le righe. | CONF-codice; protocollo.php:569-571, 697; sgsl-moduli.php:1070-1079, 1406-1414. |
| D17 | Vero, ma va spiegata la causa. Con «Cessazione» (lavoratori.php:223-225) l'accesso viene revocato e le sessioni distrutte (lavoratore-area.php:239-246), e il difetto non si verifica. G2 l'ha riprodotto solo con un UPDATE diretto nel DB. Il percorso reale è il modulo anagrafica con la casella «In forza» tolta: `apac_op_lavoratore_save` (actions.php:247, 273-287) mette attivo=0 **senza** `apac_lavoratore_revoca_accesso`. | Sostituire il riferimento con actions.php:247/273-294 (manca la revoca) + files.php:373. Basso. |
| D23 | Le righe 316-329 sono solo l'inizio della funzione. L'update senza azzerare l'avviso è a sgsl-lavoratori.php:332. | sgsl-lavoratori.php:332. |
| D44 | Stato «CONF-prova», ma G7-04 è CONFERMATO-codice. Mancano le righe. | CONF-codice; sgsl-moduli.php:909 e 916. |
| D45 | **File sbagliato** e stato gonfiato: il controllo sta in sgsl-moduli.php:936 (solo per 'valutazione'), non in sgsl-stress.php. G7-05 è CONF-codice. | CONF-codice; sgsl-moduli.php:936 (+204, 1058-1059). |
| D46 | Mancano le righe. G7 stesso dice «garanzia apparente, non sfruttabile dall'interfaccia». | informazione.php:414, sgsl-moduli.php:1023 (stampa: informazione.php:554, sgsl-moduli.php:1365). |
| D47 | Manca il file. Effetto: solo il contatore «minori» del Check DVR, un giorno di anticipo, e solo il 29 febbraio. | check-dvr.php:317. Al limite del MIGL. |
| D49 / D50 / D51 | Riferimenti generici. | D49 sopralluoghi.php:38-46, 1041-1046, 1060; D50 attrezzature.php:537-546, 596-613; D51 lavoratori.php:214-228 (+ incarichi.php:472). |
| D30 | Esatto, ma `log_svuota`/`log_voce_delete` hanno ruolo «studio» (operazioni.php) e il collaboratore ha la cap studio per scelta di progetto 1.28.0 (helpers.php:22-27). È una scelta di permessi, non un difetto di controllo. | Basso/MIGL, con la motivazione «registro non a prova di cancellazione da parte dello studio». |
| D21 | Esatto (push.php:274), ma serve conoscere l'endpoint push altrui (URL segreto del servizio push). L'effetto è che la vittima smette di ricevere le notifiche: un DoS, nessuna lettura. | Precisare la conseguenza. |
| D12/D13 (raggruppati in bozza) | Vedi sopra. | – |

Controllati e corretti nel testo (file:riga esistenti e coerenti): D14, D16, D18-D20, D22, D24-D29, D31-D43, D48, D52, D53, D55-D58, D61, D62.
Nota D35: la query viene eseguita davvero (WP segnala soltanto `_doing_it_wrong`). Notice presente in /opt/wpt/debug.log righe 57-59.

## 3. Punti aperti

### 3a. Operazioni riservate allo studio con nonce VALIDO del cliente → CONFERMATO RIFIUTATE
- Registro letto con `wp eval` su `apac_operazioni()`: 157 operazioni, di cui 62 con ruolo ≠ cliente (tra queste tutte le consegna_*, nomina_*, informazione_*, sopralluogo_*, azione_* tranne `azione_completa`, utente_*, richiesta_*, log_*, demo_*, visita_*, novita_save/delete, azienda_create/delete/permessi…).
- Per cliB (uid 3), roA (4) e lavB (7) ho generato con `f5/nonces.sh` un nonce `apac_<op>` legato alla sessione del cookie per ciascuna delle 62. Ho inviato `op`, `_apac_nonce` valido, `azienda_id=1`, `id=1`, **senza** codice monouso: se il ruolo passasse, l'invio si fermerebbe comunque prima di eseguire con `reload_needed`.
- Controllo che il nonce sia valido: `dpi_save` di cliB con lo stesso metodo → `reload_needed` (quindi supera i controlli di nonce e ruolo).
- Esito: **62/62 `apac_msg=denied` per cliB, roA e lavB**. Il rifiuto avviene in actions.php:25-27 (`'cliente' !== $o['ruolo'] && ! $admin`), prima del monouso e della funzione. `apac_is_admin()` vale solo per manage_options/apac_studio e non durante l'anteprima (helpers.php:9-27).

### 3b. N13 e N14
Normattiva: l'URN `…decreto.legislativo:2008-04-09;81~art50` / `~art193` caricato con TinyFish mostra solo l'indice: il testo sta in un frame dinamico. L'indice però marca gli artt. 50 e 193 come «orig.» (nessuna modifica; ultimo aggiornamento dell'atto 23/03/2026). Testo letto su https://brocardi.it/testo-unico-sicurezza-sul-lavoro/titolo-i/capo-iii/sezione-vii/art50.html, https://brocardi.it/testo-unico-sicurezza-sul-lavoro/titolo-viii/capo-ii/art193.html (aggiornati al 07/04/2026) e https://tussl.it/…/art-193 (Certifico), concordanti tra loro. Affidabilità medio-alta: fonti secondarie concordanti, articoli mai modificati secondo Normattiva.
- **N13 → ERRATO, confermato.** Art. 50 c.1: «c) è consultato sulla designazione del responsabile e degli addetti al servizio di prevenzione, alla attività di prevenzione incendi, al primo soccorso, alla evacuazione … e del medico competente; d) è consultato in merito all'organizzazione della formazione di cui all'articolo 37». sgsl-moduli.php:67 «Organizzazione della formazione (lett. c)» deve dire **lett. d)**. La voce «Designazione di RSPP, ASPP, addetti alle emergenze, medico competente (lett. c)» è corretta.
- **N14 → CORRETTO (non è un difetto).** Art. 193 c.2: «Il datore di lavoro tiene conto dell'attenuazione prodotta dai dispositivi di protezione individuale dell'udito indossati dal lavoratore solo ai fini di valutare l'efficienza dei DPI uditivi e il rispetto del valore limite di esposizione». sgsl-plus.php:872 e sgsl-strumenti.php:57 («art. 193, c. 2») sono esatti. Coincide con G10 riga 164. Togliere N14 dai «DA CONFERMARE». L'art. 190 non serve a questi due punti e non l'ho letto.

## 4. Nuovi difetti
Nessun nuovo difetto di gravità media o alta. Precisazioni che valgono come nuove righe:

**F5-01 | basso | CONFERMATO-codice | actions.php:247, 273-294 (apac_op_lavoratore_save) contro lavoratori.php:223-225**
Descrizione: togliere «In forza» dalla scheda del lavoratore (portal.php:1110) mette attivo=0 ma, a differenza di «Cessazione», non revoca l'accesso personale del lavoratore e non chiude le sue sessioni. È la causa reale di D17: con una sessione aperta il lavoratore può ancora scaricare i documenti «per lavoratori» dai link ottenuti prima. Il portale e le operazioni lo bloccano già: helpers.php:62-63, actions.php:17.
Riproduzione: non eseguita con il modulo. È lo stesso effetto provato da G2-03 con attivo=0 nel DB.
Conseguenza: accesso residuo ai documenti dopo la disattivazione. Se si rimette «In forza», l'account torna attivo senza riattivazione esplicita.
Correzione: chiamare `apac_lavoratore_revoca_accesso` quando attivo passa da 1 a 0 in lavoratore_save (o unificare con lavoratore_cessa). In più `apac_user_can_use_portal()` in apac_serve_file / apac_serve_extra_files.

**Contenuti modificabili dopo la firma (ricerca richiesta, simili a D01-D03): nessun altro caso.**
- nomine.php:521 (`'bozza' !== $n->stato` → bloccato), consegne.php:621, consegne-out.php:307, informazione.php:317 (in più `apac_prot_blocca_modifica`, 320-322), sgsl-moduli.php:955 (dopo la firma solo rettifica, 962-967). Le firme dell'informazione si raccolgono e chiudono nella stessa operazione (informazione.php:431-437).
- Firma a distanza dei verbali SGSL legata all'impronta della bozza (sgsl-plus.php:77-89, 145-147): corretta.
- Presa visione dei documenti: impronta sha256 del file (documenti.php:231 e 146). La sostituzione del file archivia la versione (admin-tools.php:486-499): corretta.

**PHP 7.4:** nessuna funzione PHP 8 (str_contains/str_starts_with/str_ends_with/array_is_list/get_debug_type/fdiv/match/nullsafe/attributi/catch senza variabile). C'è `array_key_first` (interfaccia.php:345, sgsl-miglioramento.php:256), che esiste da PHP 7.3. `php -l` con php:7.4-cli su tutti i file: nessun errore.

## 5. Punti di ingresso e copertura

Grep degli `add_action` su init/template_redirect/admin_post*/wp_ajax*/admin_init/plugins_loaded/shutdown/delete_user e dei filtri login_redirect/show_admin_bar/wp_nav_menu_items, closure comprese (misura.php:28-52). Già nelle tabelle G1-G9 (per nome della funzione o parametro): apac_cons (G4), apac_serve_extra_files apac_ver/img/foto (G2), apac_lavoratore_guard (G1/G2), wp_ajax_apac_cerca_aziende (G3), apac_save_prefs/portal css/headers/gzip (G3), apac_js/apac_offline (G2), apac_report (G4), apac_richiesta (G4), apac_qr (G5), apac_template/export (G2), apac_file (G2), save/test mail (G4), apac_vc_serve_blank (G6), apac_attivita_csv e apac_sgsl_csv (G8), apac_push (G2), apac_save_checklist (G5), apac_prot_export (G7), apac_serve_print (G5-G8), apac_sw (G2), save_consensi (G4), save_contatti/save_gsc (G3), apac_mis_stampa (G3), apac_firma GET (G7).

Non presenti in nessuna tabella e verificati da me:

| Punto di ingresso | Controllo (file:riga) | Esito |
|---|---|---|
| admin_post apac_save_button / sync_style / protezione_manuale / clear_mail_error / run_cron / recreate_dir | `current_user_can('manage_options') && check_admin_referer(...)` (admin.php:65, 81, 91, 101, 111, 121) | OK (solo codice): cliente, lavoratore e collaboratore (senza manage_options) ricevono `wp_die`. Utenti non loggati: admin-post nopriv non registrato. |
| admin_init apac_block_wp_admin_for_clients | admin.php:6-20 (esclude ajax, cron, admin-post) | OK. Già provato da G1 per il collaboratore. |
| login_redirect / show_admin_bar | admin.php:22-37 | OK, nessun ingresso di dati. |
| init apac_ruolo_studio, apac_maybe_sync_site_css, apac_ensure_cron, plugins_loaded apac_maybe_upgrade | admin.php:41-54; site-style.php:83-88; nessun input utente | OK. |
| init apac_portal_router (pwa.php:24-67): POST `action=apac_action` da qualsiasi URL, POST `apac_login`, GET `apac_file`, GET `apac_logout` | apac_action → `apac_handle_action` (nonce + registro); anonimo → `apac_serve_file_denied` (pwa.php:35-37). Login: nonce `apac_login` + `wp_signon` (protezioni del filtro authenticate), redirect solo verso la pagina del portale con `apac_qr` ripulito a 16 caratteri alfanumerici (pwa.php:97-98): nessun open redirect. Logout: nonce `apac_logout` (pwa.php:59-61). | OK. Il percorso alternativo all'admin-post è coperto dalla prova 3a (stesso gestore). Il nonce di login per l'utente anonimo è uguale per tutti gli anonimi (login CSRF teorico, come wp-login.php): non lo segnalo. |
| init apac_firma_distanza_endpoint, ramo **POST** (sgsl-plus.php:149-185) | Token di 160 bit salvato come hash, scadenza, verbale in bozza, impronta invariata (120-142). UPDATE con `firma IS NULL` (167). | OK (solo codice). Collegato a D31 (la revoca cancella anche le firme raccolte, sgsl-plus.php:119). |
| shutdown apac_mis_stampa | manage_options + apac_tempi (misura.php:57-59) | OK. |
| delete_user apac_remember_deleted_user | admin-tools.php:300-311 | Coerente con D61 (le iscrizioni push restano). |

`$_COOKIE` e intestazioni X-Forwarded non vengono mai letti. `$_FILES` si legge solo nelle funzioni `apac_op_*` (dietro lo smistatore) e in forms.php.

## 6. Non verificato
- D02, D03, D10: non riprodotti di persona (riproduzioni di G5, dati poi rimossi). Esito dato sul codice, che non lascia dubbi.
- D09: tempo di forzatura del PIN non misurato.
- D11: nessuna prova con un lettore di schermo reale (solo codice).
- D14 (concorrenza), D18-D20, D24, D27, D31-D34, D36-D39, D41: controllati solo nel codice citato, non rieseguiti.
- D55-D60 (CSS/contrasto/320 px): solo righe CSS controllate, nessuna prova visiva.
- F5-01: modulo «In forza» non eseguito dal browser.
- N13/N14: testo letto su fonti secondarie (Brocardi, TuSSL/Certifico), non sul testo di Normattiva (frame non caricabile). L'indice Normattiva conferma che gli artt. 50 e 193 non sono mai stati modificati.
