# G10 – Verifica dei riferimenti normativi di «AP Area Clienti» 1.28.0 (testo vigente all'8/11/2026)

## Metodo e limiti

- Ho estratto i riferimenti con grep su tutti i `.php` e su `LEGGIMI.txt` (597 righe trovate; sono escluse le occorrenze non normative come «Legge» = verbo e «art.» nel codice PDF).
- **Fonte primaria usata: Normattiva**, testo «vigente al 08/11/2026». L'ho letto articolo per articolo con il browser TinyFish (WebFetch e curl verso normattiva.it, lavoro.gov.it e certifico.com sono bloccati dalla rete della sessione). Formato degli URL:
  `https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2008-04-09;81~artNN!vig=2026-11-08` (abbreviato più avanti in **NA-81/NN**).
  Normattiva indica: «Ultimo aggiornamento all'atto pubblicato il 23/03/2026» (cioè la L. 34/2026).
- Articoli del D.Lgs. 81/2008 letti integralmente sul testo vigente: 2, 15, 16, 17, 18, 19, 26, 27, 28, 29, 31, 34, 35, 36, 37, 38, 41, 43, 45, 47, 50, 71, 73, 77, 90, 96, 99, 107, 113, 173, 189, 193, 201. Fuori dal D.Lgs. 81/2008 ho letto su Normattiva: L. 81/2017 art. 22, D.Lgs. 81/2015 art. 35, D.P.R. 1124/1965 art. 53, D.P.R. 177/2011 art. 2, CAD (D.Lgs. 82/2005) art. 20.
- **Allegati del D.Lgs. 81/2008**: Normattiva non li restituisce tramite URN (le varianti ~allegatoVII, ~all7 e :7 aprono la pagina dell'atto). Ho verificato l'Allegato VII su tussl.it, aggiornato al 27/10/2026, che riporta la nota INL 780/2026 e la circolare MLPS 11/2026. È una fonte secondaria, ma coerente con le notizie di settore. Gli Allegati XIV e XVII **non li ho letti**.
- **Accordo Stato-Regioni 17/04/2025** (Rep. 59/CSR): non ho raggiunto la GU né il sito della Conferenza. Ho usato trascrizioni e commenti (stefanofarina.it, che riporta testualmente la Parte III, puntosicuro.it, certifico, BUR Emilia-Romagna): fonti secondarie, affidabilità media.
- **D.M. 2/9/2021**: testo del decreto (PDF dell'Ordine degli Ingegneri di Macerata), letto per art. 2, art. 5 e all. I p. 1.3. **D.L. 159/2025**: testo coordinato AIFOS con le modifiche al D.Lgs. 81/2008, più gli articoli vigenti su Normattiva. **Metodologia INAIL stress 2017**: PDF del manuale, ed. 2017 (ISBN 978-88-7484-570-5), copia ospitata da unifi.it.
- Le norme tecniche UNI/EN/ISO sono a pagamento e non le ho lette: dove servono sono indicate come NON VERIFICATO.
- Livelli di affidabilità: **A** = testo vigente letto sulla fonte primaria; **M** = fonte secondaria attendibile o testo ufficiale ospitato da terzi; **B** = solo conoscenza pregressa e risultati di ricerca, senza lettura del testo.

## Sintesi

| Esito | N. riferimenti (circa) |
|---|---|
| CORRETTO | la grande maggioranza (≈ 175 occorrenze distinte) |
| ERRATO / IMPRECISO | 9 |
| SUPERATO (riferimento da aggiornare) | 2 |
| Omissione rispetto a norme nuove | 2 |
| NON VERIFICATO | 8 (norme tecniche, alcuni DM, Allegati XIV/XVII) |

### Problemi trovati (in ordine di importanza)

1. **LEGGIMI.txt:1055-1056 – attribuzione errata.** Il plugin attribuisce al D.L. 159/2025 (conv. L. 198/2025) anche la «formazione dei preposti». Il c. 7-ter dell'art. 37 (aggiornamento almeno biennale e in presenza) viene dalla L. 215/2021 e nel testo vigente non ha segni di modifiche recenti. Il D.L. 159/2025 ha modificato altri commi dell'art. 37 (secondo il testo coordinato AIFOS) e altri articoli: 2, 3, 6, 11, 15 (lett. z-bis), 20, 25, 27, 30, 39, 41, 51, 77 (c. 4, lett. a, indumenti come DPI), 113, 115 e l'All. XII. Violenza e molestie e indumenti come DPI sono invece attribuiti **correttamente**. → ERRATO (solo la parte sui preposti), affidabilità M.
2. **Omissione – art. 37, c. 4, lett. b-bis.** La L. 11/3/2026 n. 34 (in vigore dal 7/4/2026; nota INL 780 del 15/4/2026) ha aggiunto tra i momenti in cui la formazione è obbligatoria anche «i periodi di cassa integrazione guadagni, sia in caso di sospensione che in caso di riduzione dell'orario». Il plugin elenca solo assunzione, cambio di mansione e nuove attrezzature (LEGGIMI.txt:442, 779; sgsl-lavoratori.php:6-8). Testo vigente: NA-81/37. Affidabilità A.
3. **Coordinatori per la sicurezza (formazione-regole.php:9, 64; LEGGIMI.txt:436).** Il riferimento «D.Lgs. 81/2008, Allegato XIV» è **superato**: l'Accordo 17/4/2025, in base all'art. 98, c. 3, «aggiorna e sostituisce i requisiti della formazione per i coordinatori» (Parte III, punto 4). Il valore indicato resta giusto: aggiornamento quinquennale, 40 ore. → SUPERATO (solo il riferimento), affidabilità M.
4. **Piano di emergenza (obblighi.php:6-7, 39-41, 88; LEGGIMI.txt:460).** Il D.M. 2/9/2021, art. 2, c. 2, prevede tre casi: almeno 10 lavoratori; luoghi aperti al pubblico con più di **50 persone presenti contemporaneamente**; attività dell'All. I al D.P.R. 151/2011. Il testo del plugin dice «aperto al pubblico» senza la soglia dei 50. Il codice, poi, attiva l'esercitazione annuale solo con almeno 10 lavoratori o con un documento «emergenze» in archivio, quindi gli altri due casi non vengono rilevati da soli. → IMPRECISO / incompleto, affidabilità M.
5. **Addestramento (LEGGIMI.txt:738; sgsl-moduli.php:114, modulo «Addestramento» con norma «art. 37, c. 4»).** Il c. 4 dice *quando* fare formazione e addestramento. *Cosa* sia l'addestramento, chi lo svolge (persona esperta, sul luogo di lavoro) e l'obbligo di **tracciarlo in un apposito registro, anche informatizzato** stanno nel **c. 5** vigente, oltre che nell'art. 77, c. 5 per i DPI. Conviene citare «art. 37, c. 4 e 5». → IMPRECISO, affidabilità A.
6. **check-dvr.php:70.** «Scale portatili, trabattelli, ponteggi» → «art. 113». L'art. 113 riguarda solo le scale. Per i trabattelli (ponti su ruote) vale l'art. 140, per i ponteggi gli artt. 131-138 e gli All. XVIII-XXII. → IMPRECISO (parziale), affidabilità A per l'art. 113.
7. **check-dvr.php:88.** «Movimenti ripetitivi e posture» → «Tit. VI». Il Titolo VI riguarda la movimentazione manuale dei carichi. I movimenti ripetitivi rientrano nella valutazione generale dell'art. 28; l'All. XXXIII richiama la ISO 11228-3. → IMPRECISO (parziale), affidabilità M.
8. **sgsl-plus.php:309.** La denuncia INAIL è indicata «entro due giorni dalla ricezione dei riferimenti del certificato medico». L'art. 53 del D.P.R. 1124/1965 vigente dice «entro due giorni da quello in cui il datore di lavoro ne ha avuto notizia», con la denuncia «corredata dei riferimenti al certificato medico». La decorrenza indicata dal plugin corrisponde alla prassi INAIL, che però non ho verificato sul testo della circolare. → IMPRECISO rispetto alla lettera della norma, affidabilità A per il testo di legge.
9. **DURC (cantieri.php:14, 552; LEGGIMI.txt:476).** La durata di 120 giorni è corretta. La decorrenza è indicata «dalla data di richiesta», mentre secondo le fonti (art. 7 D.M. 30/1/2015, circ. MLPS 19/2015) decorre dalla data in cui è effettuata la verifica. → IMPRECISO sulla decorrenza, affidabilità M-B.
10. **allegato7.php:8, 70; LEGGIMI.txt:452-453.** La prima verifica è affidata all'INAIL «entro 45 giorni», e questo è corretto. Manca però che, trascorsi inutilmente i 45 giorni, il datore di lavoro può rivolgersi a soggetti pubblici o privati abilitati (art. 71, c. 11). → incompleto, affidabilità A.
11. **cantieri.php:10, 28.** L'Allegato XVII cita ancora la «autocertificazione art. 29 c. 5», ma quel regime è cessato il 30/06/2013 (nota «AGGIORNAMENTO (15)» in calce all'art. 29). Oggi le imprese fino a 10 lavoratori usano le procedure standardizzate. → nota: citazione letterale dell'All. XVII (non letto), ma l'istituto è superato. Affidabilità A per l'art. 29.
12. Formale: check-dvr.php:102 scrive «art. 15, lett. z-bis»; il riferimento preciso è «art. 15, **c. 1**, lett. z-bis)». Il contenuto è corretto.

---

## Tabella completa dei riferimenti

Legenda colonne: file:riga | testo nel plugin (abbreviato) | fonte consultata | esito | nota e affidabilità.

### A. Formazione e periodicità (formazione-regole.php, incarichi.php, LEGGIMI)

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| formazione-regole.php:6, 58; LEGGIMI.txt:434-435 | Formazione specifica lavoratori: aggiornamento quinquennale (Accordo S-R 17/04/2025, Rep. 59/CSR, GU n. 119 del 24/05/2025) | stefanofarina.it/accordo-formazione-sicurezza-2025 (Parte III, 1.1); puntosicuro.it AR-25327 | CORRETTO | quinquennale, almeno 6 ore. GU n. 119 del 24/5/2025 confermata da più fonti. M |
| formazione-regole.php:57; LEGGIMI.txt:439 | Formazione generale: credito formativo permanente, nessun aggiornamento proprio | conoscenza (Accordo 2011 e 2025) | NON VERIFICATO sul testo 2025 | molto probabilmente corretto. B |
| formazione-regole.php:7, 59; LEGGIMI.txt:434 | Preposto: almeno biennale (art. 37, c. 7-ter; Accordo 2025) | NA-81/37; Accordo Parte III 1.2 (secondaria) | CORRETTO | c. 7-ter: «cadenza almeno biennale», interamente in presenza; Accordo: 6 ore ogni 2 anni. A |
| formazione-regole.php:8, 60 | Dirigente quinquennale | Accordo Parte III 1.3 (secondaria) | CORRETTO | 6 ore ogni 5 anni. M |
| formazione-regole.php:8, 61 | Datore di lavoro quinquennale | puntosicuro AR-25327 | CORRETTO | 6 ore ogni 5 anni. M |
| formazione-regole.php:8, 62 | Datore di lavoro RSPP quinquennale | puntosicuro AR-25327 e commenti | CORRETTO | sulle ore minime le fonti divergono (6/10/14 oppure 8); il plugin indica solo la cadenza. M |
| formazione-regole.php:8, 63 | RSPP/ASPP quinquennale | Accordo Parte III 3 | CORRETTO | 40 ore RSPP, 20 ore ASPP. M |
| formazione-regole.php:9, 64; LEGGIMI.txt:436 | Coordinatore CSP/CSE quinquennale (Allegato XIV) | Accordo Parte II 6 e Parte III 4 | **SUPERATO** (riferimento) | il valore (5 anni, 40 ore) è giusto, la fonte oggi è l'Accordo 2025. M |
| formazione-regole.php:10, 65; LEGGIMI.txt:435; nomine.php:150 | Addetto antincendio quinquennale (D.M. 2/9/2021) | D.M. 2/9/2021 art. 5, c. 5 (PDF ord. ing. Macerata) | CORRETTO | «aggiornamento con cadenza almeno quinquennale, secondo quanto previsto nell'allegato III». M |
| formazione-regole.php:11, 66; LEGGIMI.txt:436 | Primo soccorso triennale (D.M. 388/2003, art. 3, c. 5) | D.M. 388/2003 (copie su iuav.it e csao.it, tramite ricerca) | CORRETTO | «ripetuta con cadenza triennale almeno per quanto attiene alla capacità di intervento pratico». M |
| formazione-regole.php:12, 74; LEGGIMI.txt:437-438 | RLS annuale da 15 lavoratori (4 ore da 15 a 50, 8 ore oltre 50); sotto 15 decide il CCNL (art. 37, c. 11) | NA-81/37 | CORRETTO | testo vigente identico, compresa la proporzionalità sotto i 15. A |
| formazione-regole.php:13, 67, 70; LEGGIMI.txt:437 | Abilitazioni attrezzature (art. 73, c. 5): quinquennale (Accordo 2025) | NA-81/73; Accordo Parte III 6 | CORRETTO | 4 ore di pratica ogni 5 anni. A/M |
| formazione-regole.php:19-33; LEGGIMI.txt:446 | 11 abilitazioni: PLE, carrelli, gru per autocarro, gru a torre, gru mobile, carroponte, trattori, macchine movimento terra, pompe cls, caricatori, raccoglifrutta | Accordo Parte II 8.3.9-8.3.11 (secondaria) | CORRETTO | 8 attrezzature storiche più 3 nuove (raccoglifrutta, caricatori, carriponte); per le nuove i corsi vanno completati entro 12 mesi dal 24/5/2025. M |
| formazione-regole.php:187, 265 | Abilitazione specifica (art. 73, c. 5) | NA-81/73 | CORRETTO | A |
| formazione-regole.php:212, 228 | Lavoratori senza formazione generale o specifica (art. 37, c. 1 e c. 4) | NA-81/37 | CORRETTO | A |
| formazione-regole.php:247; criticita.php:142, 163, 183 | art. 37 / art. 18, c. 1, lett. l) | NA-81/18, NA-81/37 | CORRETTO | lett. l): obblighi degli artt. 36 e 37. A |
| formazione-regole.php:112, 122; dpi.php:225, 313; LEGGIMI.txt:447-449 | Categorie DPI (Reg. UE 2016/425, All. I); cat. III per anticaduta, otoprotettori, facciali filtranti, maschere | conoscenza del Reg. 2016/425 | CORRETTO | B-M. L'art. 77, c. 5 rinvia ancora al D.Lgs. 475/1992, sostituito dal Reg. 2016/425 (D.Lgs. 17/2019): il richiamo del plugin è quello attuale |
| formazione-regole.php:132, 283; sgsl-lavoratori.php:157; LEGGIMI.txt:449 | Addestramento indispensabile per DPI di III categoria e dell'udito (art. 77, c. 5) | NA-81/77 | CORRETTO | A |
| incarichi.php:5-9, 17-21; LEGGIMI.txt:398-400 | preposto «individuato» (art. 18, c. 1, lett. b-bis); addetti «designati» (lett. b); RLS «eletto o designato» (art. 47, c. 2); formazione art. 37, c. 7, 9, 10 | NA-81/18, 47, 37 | CORRETTO | A |
| LEGGIMI.txt:442-443 | Formazione all'assunzione, al cambio di mansione, con nuove attrezzature (art. 37, c. 4) | NA-81/37 | CORRETTO ma **incompleto** | manca la lett. b-bis (periodi di CIG, L. 34/2026). A |
| LEGGIMI.txt:829-830 | Frequenza di almeno il 90% (Accordo S-R 17/4/2025) | certifico; BUR Emilia-Romagna, All. 1 (modello di attestato); puntosicuro | CORRETTO | M |
| sgsl-plus.php:361 | Attestato interno: per i corsi soggetti all'Accordo vanno rispettati requisiti, durate e contenuti | — | CORRETTO | avvertenza generica |
| sgsl-moduli.php:103-104; protocollo.php:29; LEGGIMI.txt:736 | Formazione interna (art. 37), verifica della lingua | NA-81/37 c. 13 | CORRETTO | A |
| sgsl-moduli.php:114; LEGGIMI.txt:738 | Addestramento (art. 37, c. 4) | NA-81/37 | **IMPRECISO** | citare anche il c. 5 (definizione, persona esperta, registro). A |

### B. Valutazione dei rischi, DVR, riunione periodica, informazione

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| consegne-out.php:58; consegne.php:401, 973, 876, 889, 989; helpers.php:424; LEGGIMI.txt:503, 536, 590 | DVR custodito presso l'unità produttiva (art. 29, c. 4) | NA-81/29 | CORRETTO | il c. 4 riguarda anche il DUVRI. A |
| consegne-out.php:60, 161-164; criticita.php:34; LEGGIMI.txt:538 | Valutazione dei rischi ed elaborazione del DVR non delegabili (art. 17, c. 1, lett. a) | NA-81/17 | CORRETTO | A |
| consegne-out.php:61; sgsl-plus.php:10, 429; LEGGIMI.txt:538, 801; documenti.php:316 | Data certa o attestata dalle sottoscrizioni di DL, RSPP, RLS/RLST, MC ove nominato (art. 28, c. 2) | NA-81/28 | CORRETTO | testo coincidente. A |
| sgsl-rischi.php:6-9, 170; obblighi.php:8-9, 107, 357-365; fascicolo.php:160; interfaccia.php:725; LEGGIMI.txt:461-463, 741 | Rielaborazione immediata, comunque entro 30 giorni (modifiche significative, evoluzione della tecnica, infortuni significativi, sorveglianza sanitaria), misure aggiornate, comunicazione al RLS (art. 29, c. 3) | NA-81/29 | CORRETTO | A |
| check-dvr.php:125 | DVR precedente (art. 29, c. 3) | NA-81/29 | CORRETTO | A |
| check-dvr.php:126 | Valutazioni specifiche (art. 28, c. 3) | NA-81/28 | CORRETTO | A |
| check-dvr.php:111 | Scavi con possibili ordigni bellici (art. 28, c. 1) | NA-81/28 | CORRETTO | A |
| check-dvr.php:166; criticita.php:34 | D.Lgs. 81/2008, artt. 17, 28 e 29 | NA-81 | CORRETTO | A |
| sgsl-miglioramento.php:7, 194, 208; LEGGIMI.txt:767 | Programma delle misure, procedure e ruoli (art. 28, c. 2, lett. c e d) | NA-81/28 | CORRETTO | A |
| criticita.php:133 | Mansioni a rischio specifico (art. 28, c. 2, lett. f); art. 37, c. 1, lett. b) | NA-81/28, 37 | CORRETTO | A |
| sgsl-moduli.php:199-200; LEGGIMI.txt:832 | Stress lavoro-correlato (art. 28, c. 1) | NA-81/28 | CORRETTO | A |
| sgsl-stress.php:3-19, 327, 448; LEGGIMI.txt:915-925 | Metodologia INAIL ed. 2017: sentinella 0/6/16; Contenuto 0-23/24-43/44-100; Contesto 0-37/38-53/54-100; finale 0-58/59-90/91-216; casa-lavoro −4; arrotondamenti come Tab. 7 e 10 | Manuale INAIL 2017 (ateneosicuro.unifi.it, PDF ed. 2017), Tab. 9, 11, 12 | CORRETTO | tutte le fasce coincidono. Attenzione: il modulo INAIL 2022 per il settore sanitario ha fasce diverse (finale 0-70/71-110/111-216), quindi va applicato solo l'ed. 2017 generale. M |
| sgsl-stress.php:18 | Base: art. 28, c. 1 e 1-bis; Commissione consultiva del 17/11/2010 | NA-81/28; manuale INAIL 2017 | CORRETTO | A |
| sgsl-stress.php:73 | All. IV del D.Lgs. 17/2010 (macchine) | conoscenza | CORRETTO | B. Il Reg. UE 2023/1230 si applica dal 20/1/2027 |
| sgsl-stress.php:130; LEGGIMI.txt:920 | Visite su richiesta del lavoratore (art. 41, c. 2, lett. c) | NA-81/41 | CORRETTO | A |
| obblighi.php:4-5, 80, 329; sgsl-plus.php:11, 476; fascicolo.php:155; LEGGIMI.txt:459, 803 | Riunione almeno annuale sopra 15 lavoratori (art. 35, c. 1); fino a 15 la chiede il RLS (c. 4) | NA-81/35 | CORRETTO | A |
| sgsl-moduli.php:44, 49; protocollo.php:25; LEGGIMI.txt:731 | Argomenti a)-d) (art. 35, c. 2) | NA-81/35 | CORRETTO | A |
| check-dvr.php:133 | Verbale della riunione (art. 35) | NA-81/35 c. 5 | CORRETTO | A |
| informazione.php:3-24, 495, 529; protocollo.php:24, 352; LEGGIMI.txt:618-624 | 7 voci dell'art. 36 (c. 1 lett. a-d; c. 2 lett. a-c); incaricati artt. 45 e 46 | NA-81/36 | CORRETTO | testo coincidente. A |
| informazione.php:260; helpers.php:458; check-dvr.php:107; LEGGIMI.txt:693 | Lavoratori immigrati: verifica della comprensione della lingua (art. 36, c. 4) | NA-81/36 | CORRETTO | A |
| sgsl-lavoratori.php:49, 415; LEGGIMI.txt:778 | art. 37, c. 13 (lingua) | NA-81/37 | CORRETTO | A |
| informazione.php:554; LEGGIMI.txt:644 | L'informazione non sostituisce formazione e addestramento (art. 37) | NA-81/36-37 | CORRETTO | A |
| sgsl-rischi.php:229; LEGGIMI.txt:753 | Rischi per mansione → informazione (art. 36, c. 2, lett. a) | NA-81/36 | CORRETTO | A |
| sgsl-lavoratori.php:6-9, 418, 422; LEGGIMI.txt:779-780 | art. 37, c. 4 lett. a e b; somministrazione: D.Lgs. 81/2015, art. 35, c. 4 | NA-81/37; Normattiva D.Lgs. 81/2015 art. 35 | CORRETTO (manca la lett. b-bis) | c. 4 del D.Lgs. 81/2015: a carico del somministratore, salvo che il contratto lo affidi all'utilizzatore. A |
| sgsl-plus.php:8; LEGGIMI.txt:808-809 | Copia del DVR al RLS su richiesta, consultazione in azienda (art. 18, c. 1, lett. o) | NA-81/18 | CORRETTO | A |

### C. Figure, nomine, deleghe

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| nomine.php:10-12 | art. 2 c. 1 lett. d-i; 17 c. 1 b; 18 c. 1 a, b, b-bis, aa; 31 c. 1, 2, 6; 32; 34 c. 1, 2, 2-bis, 3; 38 c. 1 e 4; 43 c. 1 b e c. 3; 47 c. 3 e 4; 50 c. 1 c | NA-81/2, 17, 18, 31, 34, 38, 43, 47, 50 | CORRETTO | tutti esistenti e pertinenti. L'art. 32 non l'ho riletto (B) |
| nomine.php:144-146; LEGGIMI.txt:553 | Preposto: art. 18 c. 1 b-bis, art. 19, art. 2 c. 1 e; «non può subire pregiudizio» | NA-81/2, 18, 19 | CORRETTO | A |
| nomine.php:149-151, 154-156; LEGGIMI.txt:554-556 | Addetti emergenze: artt. 18 c. 1 b, 43 c. 1 b, 45; art. 37 c. 9; D.M. 2/9/2021; D.M. 388/2003; rifiuto solo per giustificato motivo (art. 43 c. 3) | NA-81/18, 37, 43, 45 | CORRETTO | l'art. 37 c. 9 rinvia ancora al D.M. 10/3/1998, sostituito dal D.M. 2/9/2021 adottato ex art. 46 c. 3. A |
| nomine.php:46-51, 160-162; LEGGIMI.txt:557-558 | RLS: art. 47 c. 3 (fino a 15, eletto direttamente) e c. 4 (oltre 15, nell'ambito delle RSA o, in loro assenza, eletto); attribuzioni art. 50; formazione art. 37 c. 10 e 11; comunicazione INAIL art. 18 c. 1 aa | NA-81/47, 50, 18 | CORRETTO | A |
| criticita.php:56 | art. 47 (RLS) e art. 48 (RLST) | NA-81/47 | CORRETTO | l'art. 48 non l'ho riletto. A/B |
| nomine.php:165-166; LEGGIMI.txt:559 | Dirigente: art. 2 c. 1 d, art. 18, art. 37 c. 7 | NA-81/2, 37 | CORRETTO | definizione riportata alla lettera. A |
| nomine.php:169-172, 347, 921, 930; criticita.php:45; LEGGIMI.txt:549 | RSPP: art. 17 c. 1 b (non delegabile); art. 32; art. 2 c. 1 f; art. 31 c. 2 (mezzi, tempo, nessun pregiudizio) | NA-81/2, 17, 31 | CORRETTO | A |
| nomine.php:175-176; LEGGIMI.txt:550 | ASPP: artt. 31 e 32, art. 2 c. 1 g; «firma il datore di lavoro» | NA-81/2, 31 | CORRETTO | la firma riservata al DL è una scelta prudenziale del plugin, la legge non la impone. A |
| nomine.php:36-41, 180-182, 399-400; criticita.php:67, 75; LEGGIMI.txt:551 | Medico competente: art. 18 c. 1 a; titoli art. 38 c. 1 lett. a-d; art. 55 D.Lgs. 277/1991; elenco art. 38 c. 4; art. 2 c. 1 h; artt. 25 e 41 | NA-81/2, 18, 38, 41 | CORRETTO | omessa la lett. d-bis (riservata ai sanitari militari), che non serve per le aziende. A |
| nomine.php:186-188, 406-408, 589, 911; helpers.php:446; informazione.php:51; protocollo.php:37; LEGGIMI.txt:552, 578 | DL-SPP: art. 34 c. 1 (All. II, esclusi i casi art. 31 c. 6), informazione preventiva al RLS, corsi c. 2, 2-bis e 3 | NA-81/31, 34 | CORRETTO | A |
| nomine.php:20-33, 193, 411, 440; helpers.php:450; sgsl-plus.php:12, 468; LEGGIMI.txt:560, 733, 802 | Consultazione del RLS sulle designazioni (art. 50 c. 1 lett. c) solo per RSPP, ASPP, MC e addetti alle emergenze | NA-81/50 | CORRETTO | l'insieme delle figure coincide con la lett. c. A |
| sgsl-moduli.php:63; protocollo.php:26; LEGGIMI.txt:733 | Consultazione del RLS (art. 50 c. 1 lett. b e c) | NA-81/50 | CORRETTO | A |
| sgsl-moduli.php:124-127; protocollo.php:44; sgsl-rischi.php:503-559; LEGGIMI.txt:739, 810 | Delega di funzioni (art. 16, lett. a-e; subdelega c. 3-bis) | NA-81/16 | CORRETTO | A |
| rifiniture.php:155; LEGGIMI.txt:685 | RSPP e addetti alle emergenze (artt. 17 e 18) | NA-81/17, 18 | CORRETTO | A |
| criticita.php:94 | art. 18 c. 1 b | NA-81/18 | CORRETTO | A |
| sgsl-preposto.php:7, 99, 183; LEGGIMI.txt:769 | Non conformità dei preposti (art. 19 c. 1 lett. a, f, f-bis) | NA-81/19 | CORRETTO | A |

### D. Emergenze, antincendio, primo soccorso

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| sgsl-emergenze.php:7-9, 88, 194; LEGGIMI.txt:761 | Designazione preventiva (art. 43 c. 1 b); addetti formati, in numero sufficiente, con attrezzature adeguate (c. 3) | NA-81/43 | CORRETTO | A |
| obblighi.php:6, 88, 339; sgsl-moduli.php:13, 90; fascicolo.php:155; LEGGIMI.txt:460, 734 | Esercitazione almeno annuale (D.M. 2/9/2021, all. I, p. 1.3) | D.M. 2/9/2021 all. I p. 1.3 | CORRETTO | «cadenza almeno annuale» dove il piano di emergenza è obbligatorio. M |
| obblighi.php:6-7, 39-41, 88 | Piano obbligatorio «almeno 10 lavoratori» o «aperto al pubblico o soggetto ai VVF» | D.M. 2/9/2021 art. 2 c. 2 | **IMPRECISO** | manca la soglia «più di 50 persone presenti contemporaneamente»; il codice controlla solo ≥10 lavoratori. M |
| check-dvr.php:52, 127 | Planimetrie e piano di emergenza (D.M. 2/9/2021) | D.M. 2/9/2021 | CORRETTO | M |
| check-dvr.php:47, 48, 128 | Estintori, idranti, registro dei controlli (D.M. 1/9/2021) | ricerca e conoscenza | CORRETTO (riferimento pertinente) | testo del D.M. 1/9/2021 non letto. B |
| check-dvr.php:85 | Incendio (D.M. 3/9/2021) | ricerca e conoscenza | CORRETTO | B |
| sgsl-plus.php:842-847, 477; LEGGIMI.txt:845 | Estintori: controllo periodico semestrale (UNI 9994-1) | — | NON VERIFICATO | norma UNI a pagamento; per quanto noto il controllo periodico è semestrale |
| check-dvr.php:53; sgsl-plus.php:575-619; LEGGIMI.txt:843-844 | Contenuto minimo: cassetta (D.M. 388/2003 all. 1) e pacchetto di medicazione (all. 2) | D.M. 388/2003 (copie, tramite ricerca) | CORRETTO | M |
| check-dvr.php:56 | Attività soggetta ai VVF (D.P.R. 151/2011) | conoscenza | CORRETTO | B |

### E. Attrezzature, DPI, Allegato VII

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| allegato7.php:3, 8, 70, 197, 206; LEGGIMI.txt:451-453 | Verifiche dell'All. VII (art. 71 c. 11): prima verifica INAIL entro 45 giorni; successive ASL/ARPA o soggetti abilitati; verbali conservati | NA-81/71 | CORRETTO (incompleto) | non dice che, scaduti i 45 giorni, si può ricorrere a soggetti abilitati. A |
| allegato7.php:5-7 | Modifiche della L. 11/3/2026 n. 34 e circ. MLPS 11/2026: triennale solo per le piattaforme per operazioni in frutteto, PLE ordinarie annuali | tussl.it/allegati/allegato-vii (27/10/2026); biblus.acca.it (2/11/2026); quotidianosicurezza.it (30/10/2026) | CORRETTO | L. 34/2026 art. 12, GU n. 68 del 23/3/2026, in vigore dal 7/4/2026; circolare MLPS n. 11 del 26/10/2026; nota INL 780 del 15/4/2026. M |
| allegato7.php:16-36 | 21 voci con periodicità (scale aeree 12; PLE 12; frutteto 36; ponti a mano 24; ponti sospesi 24; idroestrattori discontinui 24, continui 36, con solventi 12; telescopici 12; autosollevanti 24; ascensori da cantiere 12; sollevamento mobile settori 12, uso regolare >10 anni 12, ≤10 anni 24; fisso settori >10 anni 12, ≤10 anni 24; fisso uso regolare >10 anni 24, ≤10 anni 36; generatori di calore >116 kW 60; pressione: secondo il libretto) | tussl.it Allegato VII aggiornato | CORRETTO | tutte le periodicità coincidono. Le attrezzature a pressione hanno periodicità diverse per gruppo e categoria (verifica di funzionamento e di integrità), quindi è corretto lasciarle «da indicare». M |
| check-dvr.php:72, 73, 130 | Gru e PLE (All. VII); verbali delle verifiche (All. VII) | tussl.it | CORRETTO | M |
| check-dvr.php:129 | Libretti e manuali delle macchine (art. 71) | NA-81/71 c. 4 | CORRETTO | A |
| attrezzature.php:225-235; check-dvr.php:71, 74 | Abilitazione dell'operatore (art. 73 c. 5): carrelli, macchine movimento terra | NA-81/73 | CORRETTO | A |
| check-dvr.php:68 | Macchine di produzione (Tit. III) | NA-81 indice | CORRETTO | A |
| check-dvr.php:122 | Indumenti ad alta visibilità o protettivi (art. 77) | NA-81/77 c. 4 lett. a | CORRETTO | indumenti che diventano DPI in base alla valutazione dei rischi (D.L. 159/2025). A |
| check-dvr.php:120 | Imbracatura anticaduta: DPI di III cat. | Reg. 2016/425 | CORRETTO | B-M |
| sgsl-lavoratori.php:143, 146 | DPI della mansione (art. 18 c. 1 lett. d) | NA-81/18 | CORRETTO | A |
| dpi.php:232 | Sostituzione del DPI secondo il fabbricante e la valutazione dei rischi | NA-81/77 c. 4 a | CORRETTO | A |
| sgsl-strumenti.php:10; LEGGIMI.txt:850 | DPI anticaduta: esame almeno ogni 12 mesi (UNI EN 365) | — | NON VERIFICATO | norma a pagamento; coerente con la prassi |
| check-dvr.php:59-65 | D.M. 37/2008; D.P.R. 462/2001; D.P.R. 162/1999; D.M. 329/2004 | conoscenza | CORRETTO (pertinenti) | B |

### F. Agenti fisici, rischi specifici (calcolatori e Check DVR)

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| sgsl-strumenti.php:7-8, 50, 63-68; sgsl-plus.php:873-875; LEGGIMI.txt:811 | Rumore: inferiori 80 dB(A)/135 dB(C), superiori 85/137, limite 87/140 (art. 189 c. 1) | NA-81/189 | CORRETTO | A |
| sgsl-strumenti.php:57; sgsl-plus.php:872; LEGGIMI.txt:812 | Il valore limite si verifica con l'attenuazione dei DPI (art. 193 c. 2) | NA-81/193 | CORRETTO | A |
| sgsl-strumenti.php:8-9, 50, 71-73; sgsl-plus.php:880-882; LEGGIMI.txt:812-813 | Vibrazioni: mano-braccio azione 2,5 / limite 5 m/s²; corpo intero 0,5 / 1,0 m/s² (art. 201 c. 1); All. XXXV | NA-81/201 | CORRETTO | All. XXXV non letto. A |
| sgsl-strumenti.php:93; LEGGIMI.txt:814-815 | Movimentazione manuale dei carichi: All. XXXIII, serie ISO 11228 | conoscenza | CORRETTO | B |
| check-dvr.php:81 | Cadute dall'alto oltre 2 m (art. 107) | NA-81/107 | CORRETTO | A |
| check-dvr.php:70 | Scale portatili, trabattelli, ponteggi (art. 113) | NA-81/113 | **IMPRECISO** | l'art. 113 riguarda solo le scale (c. 2 modificato dal D.L. 159/2025). A |
| check-dvr.php:84 | Elettrico (artt. 80-83) | NA-81 indice (Capo III, artt. 80-87) | CORRETTO | A |
| check-dvr.php:89 | Videoterminali per almeno 20 ore a settimana (art. 173) | NA-81/173 c. 1 lett. c | CORRETTO | A |
| check-dvr.php:92 | Caldo, freddo, aperto (art. 180) | NA-81 indice | CORRETTO | il microclima è tra gli agenti fisici dell'art. 180. M |
| check-dvr.php:86, 87, 90, 91, 93, 94, 96-99, 41, 77, 110 | Tit. XI; Tit. VI; Tit. VIII Capi II, III, IV, V; Tit. IX Capi I, II, III; Tit. X; Tit. VII; Tit. IV | NA-81 indice | CORRETTO | numerazione di titoli e capi conforme all'indice vigente. A |
| check-dvr.php:88 | Movimenti ripetitivi e posture (Tit. VI) | NA-81 indice | **IMPRECISO** | il Tit. VI riguarda la movimentazione dei carichi. M |
| check-dvr.php:37, 42, 51 | All. IV; All. IV, 1.5 (uscite di emergenza) | conoscenza | CORRETTO | All. IV non letto. B |
| check-dvr.php:39, 95; LEGGIMI.txt:1056 | D.Lgs. 101/2020 (radon, radiazioni ionizzanti) | conoscenza | CORRETTO | B |
| check-dvr.php:40, 100 | Ambienti confinati (D.P.R. 177/2011) | Normattiva D.P.R. 177/2011 art. 2 | CORRETTO | A |
| sgsl-moduli.php:163; LEGGIMI.txt:835 | Spazi confinati: almeno il 30% con esperienza triennale; preposto con esperienza; contratti certificati (art. 2 c. 1 lett. c) | Normattiva D.P.R. 177/2011 art. 2 | CORRETTO | A |
| check-dvr.php:102 | Aggressioni, violenze, molestie (art. 15, lett. z-bis) | NA-81/15 | CORRETTO (forma) | va scritto «art. 15, c. 1, lett. z-bis)»; lettera introdotta dal D.L. 159/2025, art. 5 c. 3 lett. c. A |
| check-dvr.php:103 | Notturno o turni (D.Lgs. 66/2003) | conoscenza | CORRETTO | B |
| check-dvr.php:105; sgsl-moduli.php:187-190; sgsl-plus.php:14, 496; sgsl-lavoratori.php:47, 171; LEGGIMI.txt:804, 832 | Lavoro agile: informativa scritta almeno annuale al lavoratore e al RLS (L. 81/2017, art. 22) | Normattiva L. 81/2017 art. 22 | CORRETTO | A |
| check-dvr.php:106; sgsl-lavoratori.php:167; sgsl-plus.php:14, 493; helpers.php:491; LEGGIMI.txt:804 | Minori (L. 977/1967) | conoscenza | CORRETTO | contenuto (valutazione specifica preventiva, lavori vietati, informazione ai genitori) coerente con gli artt. 7-8 della L. 977/1967; testo non riletto. B |
| check-dvr.php:108 | Gravidanza (D.Lgs. 151/2001) | NA-81/28 c. 1 (rinvio) | CORRETTO | A |
| check-dvr.php:112 | Divieto di alcol e sostanze (art. 41 c. 4) | NA-81/41 | CORRETTO | anche c. 2 lett. e-quater; il c. 4-bis prevede un nuovo accordo entro il 31/12/2026. A |
| check-dvr.php:131 | Schede di dati di sicurezza (Reg. CE 1907/2006) | conoscenza | CORRETTO | B |
| check-dvr.php:134 | Protocollo sanitario del MC (art. 25) | conoscenza | CORRETTO | art. 25 non riletto. B |
| LEGGIMI.txt:1056 | D.Lgs. 135/2024 | Normattiva / GU n. 226 del 26/9/2024 (tramite ricerca) | CORRETTO | recepisce la dir. (UE) 2022/431 (cancerogeni, mutageni, reprotossici). M |
| LEGGIMI.txt:1055-1056 | D.L. 159/2025 conv. L. 198/2025 per violenza e molestie, indumenti come DPI, **formazione dei preposti** | NA-81/15, 37, 77; testo coordinato AIFOS; ricerche | violenza e indumenti CORRETTI; **preposti ERRATO** | L. 29/12/2025 n. 198, GU n. 301 del 30/12/2025. Il c. 7-ter dell'art. 37 è della L. 215/2021. M |

### G. Appalti, cantieri, patente a crediti

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| cantieri.php:6-8, 191-197; LEGGIMI.txt:473 | Notifica preliminare prima dell'inizio dei lavori: più imprese anche non contemporanee (art. 90 c. 3) o un'unica impresa da almeno 200 uomini-giorno (art. 99 c. 1) | NA-81/99, 90 | CORRETTO | A |
| cantieri.php:8-9, 200-205; LEGGIMI.txt:473-474 | CSP/CSE e PSC con più imprese esecutrici (art. 90 c. 3 e 4); i lavoratori autonomi non contano | NA-81/90 | CORRETTO | il c. 5 (seconda impresa arrivata dopo) è riflesso nel testo «se ne arriva un'altra». A |
| cantieri.php:9 | POS di ogni impresa esecutrice (artt. 89 e 96) | NA-81/96 c. 1 g | CORRETTO | esclusione delle mere forniture (c. 1-bis). A |
| cantieri.php:10-13, 41, 520 | Idoneità tecnico-professionale secondo l'All. XVII (CCIAA, DVR o autocertificazione art. 29 c. 5, DURC, nessun provvedimento art. 14, nominativo art. 97; per gli autonomi: macchine, DPI, attestati, idoneità sanitaria, DURC) | NA-81/90 c. 9 a; All. XVII non letto | NON VERIFICATO (All. XVII) | contenuto coerente con quanto noto dell'All. XVII; l'autocertificazione art. 29 c. 5 è cessata il 30/6/2013. B |
| cantieri.php:127, 1018 | art. 90 c. 9 lett. a; art. 26 c. 1 | NA-81/90, 26 | CORRETTO | per cantieri sotto 200 uomini-giorno senza rischi All. XI bastano CCIAA, DURC e autocertificazione. A |
| cantieri.php:14, 552; LEGGIMI.txt:476 | DURC valido 120 giorni dalla richiesta (D.M. 30/1/2015) | ricerca (ipsoa, edotto, ance) | CORRETTO (durata) / IMPRECISO (decorrenza) | decorre dalla data della verifica. M-B |
| cantieri.php:15-17, 137, 348-352, 1027; LEGGIMI.txt:477-478 | Patente a crediti (art. 27): dal 1/10/2024, cantieri; esclusi mere forniture e prestazioni intellettuali e imprese con SOA dalla III classifica; almeno 15 crediti; verifica sul portale INL | NA-81/27 | CORRETTO | c. 1, 5, 10 e 15; il punteggio iniziale è 30 crediti; c. 7-bis, 8 e 9 modificati dal D.L. 159/2025. A |
| cantieri.php:18-19, 35; sgsl-moduli.php:160 | Appalti interni: CCIAA e autocertificazione art. 47 D.P.R. 445/2000 | NA-81/26 c. 1 a | CORRETTO | A |
| cantieri.php:19, 213-217, 421; sgsl-moduli.php:146-147; protocollo.php:49; LEGGIMI.txt:475, 742 | DUVRI (art. 26 c. 3), esclusioni del c. 3-bis, allegato al contratto e aggiornato con l'evoluzione dei lavori | NA-81/26 | CORRETTO | esclusioni: servizi intellettuali, mere forniture, lavori fino a 5 uomini-giorno salvo rischi particolari. A |
| cantieri.php:19, 220; LEGGIMI.txt:475 | Costi della sicurezza nel contratto, a pena di nullità (art. 26 c. 5) | NA-81/26 | CORRETTO | A |
| sgsl-moduli.php:159-163; protocollo.php:50; LEGGIMI.txt:743 | Idoneità (art. 26 c. 1 a), tessera di riconoscimento (c. 8), indicazione dei preposti (c. 8-bis) | NA-81/26 | CORRETTO | A |
| sgsl-moduli.php:169; protocollo.php:32; LEGGIMI.txt:743 | Coordinamento con le imprese (art. 26 c. 2) | NA-81/26 | CORRETTO | A |
| check-dvr.php:109, 132 | Ditte esterne, DUVRI (art. 26) | NA-81/26 | CORRETTO | A |
| cantieri.php:325, 381; LEGGIMI.txt:472 | Cantiere (Titolo IV) / appalto in azienda (art. 26) | NA-81 | CORRETTO | A |
| cantieri.php:991 | artt. 26, 90, 96, 99 | NA-81 | CORRETTO | A |

### H. Infortuni e comunicazioni INAIL

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| sgsl-plus.php:305-307, 453; LEGGIMI.txt:826-827 | Comunicazione telematica entro 48 ore dal certificato, a fini statistici, per assenze di almeno un giorno oltre quello dell'evento (art. 18 c. 1 lett. r) | NA-81/18 | CORRETTO | A |
| sgsl-plus.php:309 | Denuncia entro 2 giorni «dalla ricezione dei riferimenti del certificato medico» (D.P.R. 1124/1965 art. 53); vale anche come comunicazione art. 18 | Normattiva D.P.R. 1124/1965 art. 53 | **IMPRECISO** | la legge dice «da quello in cui il datore di lavoro ne ha avuto notizia», con la denuncia corredata dei riferimenti. L'assorbimento della comunicazione nella denuncia è corretto (art. 18 lett. r). A |
| sgsl-plus.php:311 | Infortunio mortale o con pericolo di morte: entro 24 ore (art. 53) | Normattiva D.P.R. 1124/1965 art. 53 | CORRETTO | A |

### I. Altro (privacy, firma, organizzazione)

| file:riga | testo nel plugin | fonte | esito | nota |
|---|---|---|---|---|
| documenti.php:316; sgsl-plus.php:14; LEGGIMI.txt:521, 840 | Firma elettronica semplice «liberamente valutabile in giudizio» (CAD art. 20 c. 1-bis) | Normattiva D.Lgs. 82/2005 art. 20 | CORRETTO | A |
| consensi.php:7, 22, 34-42, 85, 110, 281-287, 440, 493; richieste.php:9, 819; LEGGIMI.txt:80, 237, 277, 368 | GDPR artt. 28, 32, 13-14, 15-22, 32-36 | conoscenza del Reg. UE 2016/679 | CORRETTO (pertinenti) | fuori dall'ambito D.Lgs. 81/2008. B |
| LEGGIMI.txt:16; criticita.php:7; consegne*.php; nomine.php:336, 970; sgsl-moduli.php:1295 | Richiami generici «D.Lgs. 9 aprile 2008, n. 81 e s.m.i.» | NA-81 | CORRETTO | A |
| sgsl-miglioramento.php:399 | Relazione del RSPP sullo stato della sicurezza (documento volontario) | — | n/a | nessun obbligo citato |
| portal.php:1631; riepiloghi.php:268 | Avvertenza: controllo automatico, non certifica la conformità | — | n/a | corretto come limite di responsabilità |

## Ulteriori novità fino a novembre 2026 da considerare (non citate o citate in parte dal plugin)

- **L. 11/3/2026 n. 34** (Legge annuale PMI, in vigore dal 7/4/2026): art. 37, c. 4, lett. b-bis (formazione nei periodi di CIG); nuova voce dell'All. VII per le piattaforme in frutteto (questa è già recepita). Indicazioni operative: nota INL 780 del 15/4/2026. Altre modifiche di questa legge non le ho analizzate.
- **D.L. 159/2025, conv. L. 198/2025**: art. 15 c. 1 lett. z-bis; art. 77 c. 4 lett. a; art. 113 c. 2 (scale verticali fisse oltre 5 m, efficace dal 1/2/2026 per quelle installate entro il 31/10/2025); patente a crediti (art. 27 c. 7-bis, 8, 9; art. 90 c. 9 lett. b-bis «documento equivalente»); badge di cantiere; tracciamento dei mancati infortuni; art. 6, nuovo accordo sui soggetti formatori.
- **Art. 41, c. 4-bis**: nuovo accordo su alcol e tossicodipendenza entro il 31/12/2026.
- **Accordo 17/4/2025, transitori**: corso obbligatorio per il datore di lavoro entro 24 mesi (24/5/2027, dato da fonti secondarie); preposti formati da più di 2 anni da aggiornare entro il 24/5/2026; corsi per le 3 nuove attrezzature entro 12 mesi. Il plugin non gestisce il termine per il corso del datore di lavoro. FAQ del Ministero sull'Accordo pubblicate il 10/4/2026 (non lette).
- **Circolare MLPS n. 11 del 26/10/2026** (piattaforme in frutteto): già recepita in allegato7.php.

## Elenco dei riferimenti NON VERIFICATI e motivo

1. UNI 9994-1 (controllo semestrale degli estintori): norma a pagamento.
2. UNI EN 365 (esame annuale dei DPI anticaduta): norma a pagamento.
3. Serie ISO 11228 / All. XXXIII: allegato non letto.
4. Allegato XVII (documenti di idoneità nei cantieri): allegato non raggiungibile su Normattiva via URN.
5. Allegato XIV (coordinatori): non letto; comunque superato dall'Accordo 2025.
6. Allegati IV e XXXV del D.Lgs. 81/2008: non letti.
7. D.M. 1/9/2021 e D.M. 3/9/2021: testi non letti (riferimenti pertinenti per quanto noto).
8. Accordo 2025, «formazione generale = credito permanente»: non riletto sul testo 2025.

## Fonti principali (URL)

- Normattiva, D.Lgs. 81/2008 vigente all'8/11/2026: https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2008-04-09;81~art37!vig=2026-11-08 (stesso schema per ogni articolo citato)
- Normattiva, L. 81/2017 art. 22: https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:legge:2017-05-22;81~art22!vig=2026-11-08
- Normattiva, D.Lgs. 81/2015 art. 35: https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2015-06-15;81~art35!vig=2026-11-08
- Normattiva, D.P.R. 1124/1965 art. 53: https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.del.presidente.della.repubblica:1965-06-30;1124~art53!vig=2026-11-08
- Normattiva, D.P.R. 177/2011 art. 2: https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.del.presidente.della.repubblica:2011-09-14;177~art2!vig=2026-11-08
- Normattiva, CAD art. 20: https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2005-03-07;82~art20!vig=2026-11-08
- Allegato VII aggiornato: https://tussl.it/allegati/allegato-vii
- Circ. MLPS 11/2026: https://biblus.acca.it/notizie/piattaforme-per-lavori-in-frutteto-quando-la-verifica-e-triennale/ ; https://www.quotidianosicurezza.it/normativa/leggi/circolare-ministero-lavoro-11-26-ottobre-2026.htm
- D.L. 159/2025, testo coordinato: https://aifos.org/inst/aifos/public/data/general/files/bacheca/documenti/DECRETO-LEGGE_31ottobre2025-n159-Modifiche_al_DLgs81_2008.pdf ; conversione: https://www.ancecatania.it/2026/01/08/legge-di-conversione-n-198-2025-del-dl-159-2025-misure-su-salute-sicurezza-sul-lavoro-e-protezione-civile/
- L. 34/2026 e CIG: https://www.dottrinalavoro.it/notizie-c/inl-legge-annuale-pmi-prime-indicazioni-operative ; https://www.ecnews.it/lavoro/rapporto-di-lavoro/salute-e-sicurezza/legge-pmi-indicazioni-inl-novita-salute-sicurezza/
- Accordo S-R 17/4/2025: https://stefanofarina.it/accordo-formazione-sicurezza-2025/ ; https://www.puntosicuro.it/informazione-formazione-addestramento-C-56/analisi-del-nuovo-accordo-stato-regioni-sulla-formazione-AR-25327/ ; BUR Emilia-Romagna, All. 1 (modello di attestato con il 90%)
- D.M. 2/9/2021: https://macerata.ordingegneri.it/wp-content/uploads/sites/39/2023/04/DM-2-9-21.pdf
- D.M. 388/2003: https://www.iuav.it/Servizi-IU/servizi-ge1/prevenzion/Leggi-e-no/archivio/DMin_388_2003.pdf
- INAIL, metodologia stress 2017: https://www.ateneosicuro.unifi.it/upload/sub/pdf/linee%20guida%20inail%20stress%20lavoro%20correlato%202017.pdf
- D.Lgs. 135/2024: https://www.gazzettaufficiale.it/eli/id/2024/09/26/24G00153/sg
