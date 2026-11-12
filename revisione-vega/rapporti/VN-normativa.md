# VN – Verifica dei riferimenti normativi nel codice di «Vega» (stato al 12/11/2026)

## Errati, imprecisi o superati (solo questi)

| # | file:riga | Problema | Esito |
|---|---|---|---|
| E1 | ateco.py:85-122 (`gruppo_primo_soccorso`), con effetti su dvr.py:601-603, corsi.py:496-502, competenze.py | Il D.M. 388/2003, art. 1, Gruppo A punto I, mette nel gruppo A **qualunque sia il numero di lavoratori**: aziende a rischio di incidente rilevante, **centrali termoelettriche, impianti e laboratori nucleari, lavori in sotterraneo**, aziende estrattive, fabbriche di esplosivi. Il codice riconosce solo le estrattive (ATECO 05-09) e gli esplosivi; tra i dubbi segnala solo «Seveso». Con 5 lavoratori o meno restituisce quindi **B o C** anche per una centrale termoelettrica, un impianto nucleare o un lavoro in sotterraneo, e il gruppo così ottenuto finisce nel DVR («Classificazione: gruppo …») e nella durata del corso (12 ore invece di 16). | **ERRATO** nei casi indicati (A: testo GU dell'art. 1) |
| E2 | dvr.py:584-589 | La parafrasi dell'art. 29, c. 3, scritta nel DVR («la valutazione è rielaborata … nel termine di trenta giorni») unisce due obblighi diversi. Il testo vigente distingue: la **valutazione** va rielaborata **immediatamente**, il **documento** entro 30 giorni. Mancano inoltre l'aggiornamento delle misure, l'obbligo di darne **immediata evidenza** e la **comunicazione immediata al RLS**. | **IMPRECISO** (A: G10 su Normattiva art. 29) |
| E3 | dvr.py:604-608 | Il DVR riporta sempre sia la cassetta (All. 1) sia il pacchetto di medicazione (All. 2), senza dire quale si applica. La regola è: cassetta per i gruppi A e B (art. 2, c. 1), pacchetto per il gruppo C (c. 2) e per i lavoratori in luoghi isolati (c. 5). | **IMPRECISO** (A) |
| E4 | redattore.py:212 | Nella sezione del PEE «Planimetrie, informazione del personale ed esercitazioni» la fonte indicata è «D.M. 2/09/2021 All. II p. 2.2». Le planimetrie sono davvero al p. 2.2, c. 3; le **esercitazioni** (almeno annuali) sono invece all'**All. I, p. 1.3**. | **IMPRECISO** (M: testo del D.M.) |
| E5 | cervello.py:53 | L'esempio «Preposto: 12 ore, aggiornamento ogni due anni da 6 ore. Accordo Stato-Regioni 2025, Parte II punto 2.2» ha i valori giusti, ma attribuisce tutto alla Parte II, p. 2.2. L'aggiornamento sta nella **Parte III, p. 1.2**. Il modello rischia di imitare la citazione sbagliata. | **IMPRECISO** (riferimento) (A) |
| E6 | corsi.py:60 | La ricerca «articolo 47 rappresentante dei lavoratori territoriale» punta all'art. 47, ma l'RLST è disciplinato dall'**art. 48** (64 ore + 8 ore annue, c. 7). Il punto fisso di corsi.py:156 usa invece correttamente l'art. 48. È solo una stringa di ricerca. | IMPRECISO, minore |
| E7 | ateco.py:108 | Il codice tratta come «comparto dell'agricoltura» (Gruppo A, punto III) le divisioni ATECO 01, 02 **e 03 (pesca)**. Il D.M. non definisce il comparto e non ho trovato fonti che includano la pesca. | NON VERIFICATO / dubbio |

Nessun riferimento risulta **SUPERATO** in senso stretto. Il codice cita come storici, e lo dice, l'Accordo 25/7/2012 (ateco.py:237) e il D.Lgs. 334/1999 (ateco.py:105). Tutti e due sono abrogati e il codice ne indica correttamente il superamento.

Altre osservazioni (non sono errori di norma, ma incidono sui dati):
- **O1 – ATECO 2007 e ATECO 2025.** L'Allegato IV dell'Accordo 2025 usa le divisioni **ATECO 2007** (confermato sul testo). Le visure, invece, dal 1/4/2025 riportano codici ATECO 2025: redattore.py:41 propone ad esempio «96.30.01», che è un codice 2025, mentre corsi.py:196-197 chiede all'LLM un codice «ATECO 2007». Il codice avverte del problema (ateco.py:146-148). Per le divisioni di esempi_dvr.json (01, 41, 86, 93) la corrispondenza regge.
- **O2 – Testo dell'Allegato IV sul sito del Ministero.** La versione HTML/PDF ha refusi: «33 - FABBRICAZIONE DI ALTRI MEZZI DI TRASPORTO» al posto di 30, la divisione 30 manca, e l'estrazione si interrompe a «Q - SANITÀ E ASSISTENZA SOCIALE» senza 86 e 87. Con quel testo `classe_rischio` attribuisce la divisione 33 a una voce sbagliata e non trova 30, 86 e 87. Per 30, 86 e 87 il codice risponde comunque «da verificare»; per la 33 no.
- **O3 – esperto.py:124-126.** La tabella `PERIODICITA` mescola anni, mesi e giorni («trimestral»→3, «semestral»→6, «mensil»→1, «quindicinal»→15). Così una fonte che dice «trimestrale» può giustificare «3 anni» nella risposta, e il controllo dei numeri non se ne accorge. È un difetto di logica, non di norma.
- **O4 – Data di entrata in vigore dell'Accordo 59/CSR.** Il codice indica «GU n. 119 del 24/05/2025», ed è corretto. La FAQ ministeriale (quesito 11) fissa però l'entrata in vigore al **19/05/2025**, data di pubblicazione sul sito del Ministero. Il codice non calcola scadenze transitorie, ma se un giorno lo farà conviene usare il 19/5/2025.

---

## Metodo e fonti

- **Estrazione.** Ho fatto un grep sui pattern richiesti in tutti i .py, in esempi_dvr.json e in LEGGIMI.txt: 415 righe. Ho poi letto per intero ateco.py, le parti normative di corsi.py, dvr.py, redattore.py e biblioteca.py (fonti) e il contesto delle altre occorrenze. Restano fuori, perché non normative, le occorrenze di «DPR» come devicePixelRatio, «ISO» come formato data, «WNDPROC» e simili. LEGGIMI.txt non contiene riferimenti normativi. esempi_dvr.json contiene solo 4 codici ATECO.
- **Il codice non riporta importi di sanzioni.** biblioteca.py:281 collega soltanto le parole «sanzion/ammend/arresto» agli artt. 55-59. In esperto.py:107 e 178 compaiono «3.559,60» e «9.112» solo come esempi nei commenti. Sono gli importi vigenti dell'ammenda dell'art. 55, c. 1 (da 3.559,60 a 9.112,57 €), dopo la rivalutazione del 15,9% disposta dal D.D. MLPS n. 111 del 20/9/2023 (avviso in GU n. 242 del 16/10/2023; nota INL 724/2023). Secondo la cadenza quinquennale dell'art. 306, c. 4-bis, la prossima rivalutazione sarebbe nel 2028: è una deduzione, NON VERIFICATA.
- **Fonti primarie lette in questa sessione:**
  - **Accordo S-R 17/4/2025, testo integrale** sul sito del Ministero del Lavoro (https://www.lavoro.gov.it/temi-e-priorita-salute-e-sicurezza/focus/accordo-stato-regioni-17042025-allegato), = **ASR**.
  - **FAQ ministeriali sull'Accordo** (https://www.lavoro.gov.it/temi-e-priorita-salute-e-sicurezza/focus/faq-accordo-stato-regioni-27032026).
  - **D.M. 2/9/2021**, testo del decreto (PDF dell'Ordine degli Ingegneri di Macerata, https://macerata.ordingegneri.it/wp-content/uploads/sites/39/2023/04/DM-2-9-21.pdf), = **DM21**.
  - **D.M. 388/2003**, testo da GU riportato da Olympus-Uniurb (https://olympus.uniurb.it/index.php?option=com_content&view=article&id=31), più la scheda GU (GU n. 27 del 3/2/2004), = **DM388**.
  - **Art. 37 e 48 del D.Lgs. 81/2008** nel testo aggiornato con L. 34/2026 e D.L. 159/2025 (brocardi.it), = **TU37 / TU48**.
  - **L. 34/2026**, testo da GU (copia https://www.dottrinalavoro.it/wp-content/uploads/2026/03/34-2026-Legge-PMI.pdf).
  - **Struttura ATECO 2025** ISTAT (https://www.istat.it/wp-content/uploads/2025/03/Struttura-ATECO-2025-italiano.pdf).
  - **Allegati XV e XXI** del D.Lgs. 81/2008 (tussl.it).
- **Riuso.** Riprendo G10-normativa.md (Normattiva, vigente all'8/11/2026) per gli artt. 17, 18, 28, 29, 34, 45, 47 e 50 e per il D.L. 159/2025 / L. 198/2025. Riprendo anche FASE5-controllo.md, sezione 3b, per l'art. 50 c. 1 (lett. c e d) e l'art. 193.
- **Siti non raggiungibili.** www.ispettorato.gov.it (testo coordinato INL di ottobre 2026) è stato rifiutato dal proxy della sessione (403 / ENOTFOUND). Il PDF dell'INL è stato letto solo come risultato di ricerca: esiste all'URL indicato in biblioteca.py:39.
- **Affidabilità.** **A** = testo ufficiale letto (GU, Ministero o testo del decreto). **M** = fonte secondaria attendibile o testo ufficiale ospitato da terzi. **B** = conoscenza pregressa e risultati di ricerca, senza lettura del testo.

## Tabella completa

### 1. Formazione: Accordo Stato-Regioni 17/04/2025 e art. 37

| file:riga | testo nel codice | fonte (URL) | esito | nota / affid. |
|---|---|---|---|---|
| biblioteca.py:41; revisore.py:53; esame_revisione.py:34-35 | Accordo 17/4/2025, Rep. Atti n. 59/CSR, GU n. 119 del 24/05/2025; «è quello vigente» | ASR; FAQ q. 11; statoregioni.it Rep. 59/CSR | CORRETTO | Vigente. Secondo la FAQ è in vigore dal 19/5/2025 (sito MLPS), vedi O4. A |
| dvr.py:93 | «Accordi Stato-Regioni del 2011-2016 superati dall'Accordo del 17/04/2025» | ASR, Parte VII, Disposizioni finali | CORRETTO | Sono abrogati gli Accordi 221/CSR e 223/CSR del 21/12/2011, 53/CSR del 22/2/2012, 153/CSR del 25/7/2012 e 128/CSR del 7/7/2016. Resta in vigore l'All. XXI (ponteggi e funi, Accordo 2006). A |
| ateco.py:4-5, 15, 49-71; competenze.py:6, 52, 101 | Allegato IV: «macrocategorie di rischio e corrispondenze ATECO 2007» | ASR, Allegato IV | CORRETTO | Titolo testuale identico. Vedi O1 e O2. A |
| ateco.py:6, 74-82; corsi.py:154-155, 488-489; competenze.py:93 | Lavoratori: 4 h generale + 4/8/12 h specifica per rischio basso/medio/alto = 8/12/16 h (Parte II, p. 2.1) | ASR, Parte II, 2.1 | CORRETTO | Il testo coincide con la regex del codice. A |
| ateco.py:192-193, 207-225; corsi.py:493-494; competenze.py:111-114 | Chi non è presente, neanche saltuariamente, nei reparti produttivi può fare il corso del rischio basso; resta la formazione specifica secondo il DVR (Parte II, p. 2.1.1) | ASR, Parte II, 2.1.1 «Condizioni particolari» | CORRETTO | Testo e numerazione esatti. A |
| ateco.py:154-155, 212-213, 234-237 | Contenuti e durata «subordinati all'esito della valutazione dei rischi» (p. 2.1); il principio era già nelle linee applicative del 25/07/2012 | ASR, Parte II, 2.1; Parte VII | CORRETTO | Il 153/CSR del 2012 è abrogato, ma il codice lo cita come precedente storico. A |
| ateco.py:196-200; corsi.py:55, 464 | Aggiornamento lavoratori: «periodicità quinquennale, durata minima 6 ore» (Parte III, 1.1) | ASR, Parte III, 1.1 | CORRETTO | A |
| cervello.py:53 | Preposto: 12 ore, aggiornamento biennale da 6 ore, «Parte II punto 2.2» | ASR, Parte II, 2.2 («Durata minima 12 ore») e Parte III, 1.2 | CORRETTO nei valori, **IMPRECISO** nel riferimento | Vedi E5. A |
| corsi.py:47-51, 152-153; esame_revisione.py:32-33 | Preposto: art. 37 c. 7-ter, presenza, aggiornamento biennale; va individuato (art. 18 c. 1 lett. b-bis) | TU37 c. 7-ter; G10 (art. 18); FAQ q. 38 | CORRETTO | La videoconferenza sincrona è equiparata alla presenza per la parte teorica (FAQ q. 38). A |
| corsi.py:30-34, 145-146 | Datore di lavoro: art. 37 c. 7 (modificato dalla L. 215/2021), Accordo Parte II p. 3 e Parte III 1.4 | TU37 c. 7; ASR (16 ore; aggiornamento 6 h ogni 5 anni; corso entro 24 mesi dall'entrata in vigore) | CORRETTO | A |
| corsi.py:35-39, 147-148 | DL-SPP: art. 34, Allegato II; modulo comune e moduli integrativi (Parte II p. 4); aggiornamento quinquennale (Parte III p. 2) | ASR, Parte II 4 (modulo comune 8 h; integrativi 16/12/16/16 h per ATECO 2007); Parte III 2 (5 anni, 8 h) | CORRETTO | Si accede dopo il corso del datore di lavoro (p. 3). A |
| corsi.py:40-43, 149-150 | RSPP/ASPP: Moduli A, B, C (p. 5.2-5.4), aggiornamento quinquennale (Parte III p. 3) | ASR: A 28 h, C 24 h; aggiornamento RSPP 40 h, ASPP 20 h ogni 5 anni | CORRETTO | A |
| corsi.py:44-46, 151 | Dirigenti (art. 2 c. 1 lett. d); p. 2.3; Parte III 1.3 | ASR: 12 h, aggiornamento 6 h ogni 5 anni | CORRETTO | A |
| corsi.py:58-61, 156 | RLS: 32 ore iniziali, aggiornamento annuale secondo i lavoratori (art. 37); RLST: art. 48, 64 ore | TU37 c. 11; TU48 c. 7 | CORRETTO | RLS: 4 h/anno da 15 a 50 lavoratori, 8 h/anno oltre 50; sotto 15 decide il CCNL (c. 11 modificato dal D.L. 159/2025). RLST: 64 h entro 3 mesi + 8 h annue. Stringa corsi.py:60: vedi E6. A/M |
| corsi.py:77-83 | Abilitazioni attrezzature (art. 73 c. 5): elencare durata teorica e pratica e aggiornamento | ASR Parte II 8, Parte III 6 (5 anni, 4 h di pratica); FAQ q. 19 | CORRETTO | Solo in presenza, senza VCS né e-learning (FAQ q. 19, 33). A |
| corsi.py:84-86 | Ponteggi (PiMUS): «aggiornamento ogni quattro anni» | D.Lgs. 81/2008 All. XXI, punto 6 (tussl.it/allegati/allegato-xxi) | CORRETTO | 28 h; aggiornamento ogni 4 anni, minimo 4 h. L'All. XXI non è abrogato dall'Accordo 2025, che non tratta i ponteggi. M |
| corsi.py:87-89 | Lavori su funi | All. XXI (art. 116 c. 4) | CORRETTO (nessun numero nel codice) | Base 12 h + modulo A o B 20 h; aggiornamento ogni 5 anni, 8 h. M |
| corsi.py:90-95 | DPI di III categoria: addestramento obbligatorio, art. 77 c. 5 | G10 (art. 77, Normattiva) | CORRETTO | A |
| corsi.py:100-104; intrecci.py:4 | D.P.R. 177/2011; art. 66 (ambienti sospetti); il D.P.R. richiama gli artt. 66 e 121 | ASR Parte II 7 e Parte III 5 (12 h; aggiornamento 4 h di pratica ogni 5 anni); D.P.R. 177/2011 art. 1 (non riletto) | CORRETTO | Il corso della Parte II p. 7 andava concluso entro 12 mesi (FAQ q. 25). B/A |
| corsi.py:108-112 | Lavori elettrici: art. 82; figure PES/PAV/PEI nelle norme CEI 11-27 e CEI EN 50110 | conoscenza | CORRETTO | Norme CEI a pagamento, non lette: NON VERIFICATO nei contenuti. B |
| corsi.py:113-119; biblioteca.py:100-106 | Alimentaristi: normativa regionale siciliana (D.A. 698/2022, All. A), non D.Lgs. 81 | regione.sicilia.it (D.A. n. 698 del 3/8/2022, pubblicato sul sito il 29/8/2022) | CORRETTO (atto) / NON VERIFICATO (GURS n. 40 del 26/8/2022 e revoca del D.A. 630/2019) | M/B |
| corsi.py:120-125; biblioteca.py:89-95 | DAE: L. 4/8/2021 n. 116 (GU n. 193 del 13/8/2021); D.M. Salute 18/3/2011 (GU n. 129 del 6/6/2011) | certifico / testo-unico-sicurezza (L. 116/2021); def.finanze.it, senato.it (D.M. 18/3/2011) | CORRETTO | Gli obblighi delle società sportive dilettantistiche derivano anche dal D.M. 24/4/2013 (decreto Balduzzi), che non è in biblioteca: non verificato. M |
| corsi.py:96-99; biblioteca.py:85-88 | Segnaletica stradale: D.I. 22/1/2019 (GU n. 37 del 13/2/2019) | più fonti concordanti (quotidianosicurezza, ANCE) | CORRETTO | M |
| corsi.py:126-131; studioso.py:59 | Patente a crediti: formazione come requisito; D.M. 132/2024 | G10 (art. 27, Normattiva) | CORRETTO | A |
| corsi.py:132-134 | Stranieri: verifica della lingua | TU37 c. 13; FAQ q. 39-40 | CORRETTO | A |
| corsi.py:33 | «art. 37 c. 7 come modificato» | TU37 c. 7, nota (1): D.L. 146/2021 conv. L. 215/2021 | CORRETTO | A |
| corsi.py:196-197 | Ipotesi «codice ATECO 2007» | ASR, Allegato IV | CORRETTO | Coerente con l'Allegato IV; vedi O1. A |
| biblioteca.py:45 | FAQ del Ministero sull'Accordo (URL …27032026) | lavoro.gov.it (pagina letta) | CORRETTO | FAQ pubblicate tra fine marzo e inizio aprile 2026. A |
| biblioteca.py:109-113; studioso.py:39 | D.A. Regione Siciliana 20/3/2026 n. 368, recepimento dell'Accordo 59/CSR e linee guida, «GURS n. 15 del 27/03/2026» | regione.sicilia.it (pagina del D.A. 368 del 20/3/2026) | CORRETTO (atto e oggetto) / NON VERIFICATO (n. e data GURS) | M |
| biblioteca.py:120-123 | D.I. 6/3/2013, criteri di qualificazione del formatore (GU n. 65 del 18/03/2013, solo comunicato) | FAQ q. 16 (cita il D.I. 6/3/2013) | CORRETTO (atto) / NON VERIFICATO (GU) | B |

### 2. Primo soccorso: D.M. 388/2003

| file:riga | testo nel codice | fonte | esito | nota / affid. |
|---|---|---|---|---|
| biblioteca.py:131-134; corsi.py:143 | D.M. 15/7/2003 n. 388, testo vigente su Normattiva (GU del 3/2/2004) | GU (scheda atto: GU n. 27 del 3/2/2004, in vigore dal 3/8/2004); Normattiva | CORRETTO | Normattiva: atto mai modificato nel merito (ultimo aggiornamento 28/5/2004). A |
| corsi.py:71-76; ateco.py:7-8, 85-104, 112-122 | Gruppo in base ad attività, indice INAIL e numero di lavoratori; A punto II: oltre 5 lavoratori e indice di inabilità permanente > 4 (statistiche INAIL pubblicate in GU); B: 3 o più; C: meno di 3 | DM388, art. 1 | CORRETTO | Testo coincidente. A |
| ateco.py:95-100 | ATECO 05-09 (estrattive) ed esplosivi → gruppo A, punto I | DM388, art. 1, A-I | CORRETTO ma **incompleto** | Mancano centrali termoelettriche, impianti nucleari e lavori in sotterraneo: vedi E1. A |
| ateco.py:105-106 | L'art. 1 richiama il D.Lgs. 334/1999, oggi sostituito dal D.Lgs. 105/2015 | DM388, art. 1; DM21, all. III 3.2.2 a) (cita il D.Lgs. 105/2015) | CORRETTO | A/M |
| ateco.py:107-111 | Oltre 5 lavoratori a tempo indeterminato del comparto agricoltura → A punto III (divisioni 01-03) | DM388, art. 1, A-III | CORRETTO nel testo; divisione 03 dubbia | Vedi E7. A/B |
| corsi.py:74-76, 236-256; competenze.py:8, 125-133 | Durata = somma delle tre giornate: Allegato 3 per il gruppo A, Allegato 4 per i gruppi B e C | DM388, art. 3 c. 3-4; All. 3 (6+4+6 = 16 h); All. 4 (4+4+4 = 12 h) | CORRETTO | Nel testo pubblicato l'All. 4 a volte non riporta le etichette «Seconda/Terza giornata». La regex di corsi.py:247 può allora non trovarle; in quel caso il codice non dichiara nessuna durata. A |
| corsi.py:73, 160; competenze.py:132-133 | Ripetizione con cadenza triennale, almeno per la capacità di intervento pratico (art. 3) | DM388, art. 3 c. 5 | CORRETTO | A |
| dvr.py:8, 178-180, 600-608 | Contenuto della cassetta (All. 1) e del pacchetto (All. 2) | DM388, artt. 2 e All. 1-2 | CORRETTO nei testi / **IMPRECISO** nell'uso | Vedi E3. A |
| intrecci.py:4 | Il D.M. 388/2003 «si aggancia all'art. 45» | G10 (art. 45, Normattiva) | CORRETTO | A |
| redattore.py:211 | PEE, primo soccorso: art. 45 e D.M. 388/2003 | idem | CORRETTO | A |

### 3. Antincendio ed emergenze: D.M. 1, 2 e 3 settembre 2021

| file:riga | testo nel codice | fonte | esito | nota / affid. |
|---|---|---|---|---|
| corsi.py:62-70, 198-199; competenze.py:9, 148 | Livello secondo l'All. III: p. 3.2.2 (livello 3, elenco), 3.2.3 (livello 2: All. I del D.P.R. 151/2011, oppure cantieri con infiammabili e fiamme libere), 3.2.4 (livello 1: le altre attività) | DM21, All. III 3.2.2-3.2.4 | CORRETTO | Numerazione e contenuti esatti. Il livello 2 esclude i cantieri interamente all'aperto; il livello 3 è un elenco «almeno». M |
| corsi.py:157-159; competenze.py:64, 147-148 | Corsi 1/2/3-FOR e 1/2/3-AGG ai p. 3.2.5 e 3.2.6 | DM21, All. III: FOR 4/8/16 h; AGG 2/5/8 h | CORRETTO | Il codice legge le ore dal testo. M |
| corsi.py:64; competenze.py (aggiornamento) | Aggiornamento antincendio con cadenza quinquennale | DM21, art. 5 c. 5 | CORRETTO | M |
| redattore.py:181-185, 197 | Piano di emergenza (art. 46; D.M. 2/9/2021, art. 2): almeno 10 lavoratori; luogo aperto al pubblico con più di 50 persone presenti contemporaneamente; attività dell'All. I al D.P.R. 151/2011 | DM21, art. 2 c. 2 | CORRETTO | Comprende la soglia dei 50 persone, che mancava nell'altro prodotto (G10 n. 4). M |
| redattore.py:199-209 | PEE: All. II p. 2.1 (azioni, evacuazione, chiamata VVF, esigenze speciali), p. 2.2 (fattori e compiti), p. 3 (esigenze speciali) | DM21, All. II 2.1, 2.2, 3 | CORRETTO | M |
| redattore.py:212 | Planimetrie ed esercitazioni → All. II p. 2.2 | DM21, All. II 2.2 c. 3; All. I 1.3 | **IMPRECISO** | Vedi E4. M |
| dvr.py:73, 93 | D.M. 10/3/1998 «sostituito dai D.M. 1, 2 e 3 settembre 2021» | DM21, art. 7 (abroga gli artt. 3 c. 1 f, 5, 6 e 7 del D.M. 10/3/1998); gli altri due decreti abrogano il resto | CORRETTO | L'art. 37 c. 9 del D.Lgs. 81/2008 cita ancora il D.M. 10/3/1998 (TU37); la sostituzione è avvenuta per effetto dell'art. 46 c. 3. A/M |
| biblioteca.py:60, 126-129 | D.M. 2/9/2021 in GU n. 237 del 4/10/2021; pubblicazione VVF dei tre decreti | DM21, art. 8 (in vigore un anno dopo la pubblicazione) | CORRETTO (atto) / GU n. 237 non riletta | B/M |
| biblioteca.py:115-117; studioso.py:48 | D.M. Interno 22/9/2026: modifica del D.M. 1/9/2021, nuovo art. 6-bis (disposizioni transitorie per i tecnici manutentori), GU n. 222 del 24/9/2026 | puntosicuro.it AR-26783; biblus.acca.it; ANCE | CORRETTO | M |
| biblioteca.py:139-142; studioso.py:47 | D.P.R. 1/8/2011 n. 151 (GU del 22/9/2011) | Normattiva (codice redazionale 011G0193) | CORRETTO | B |

### 4. DVR, valutazione dei rischi, figure (D.Lgs. 81/2008)

| file:riga | testo nel codice | fonte | esito | nota / affid. |
|---|---|---|---|---|
| dvr.py:536-538, 579; redattore.py:38, 67 | DVR ai sensi degli artt. 17, 28 e 29; art. 17 c. 1 lett. a) | G10 (Normattiva) | CORRETTO | A |
| dvr.py:580-583; redattore.py:45, 63, 72-86; revisore.py:63-70 | Art. 28 c. 2 lett. a) (criteri, «scelta rimessa al datore di lavoro»), b), c), d), e) (nominativi RSPP, RLS, MC), f); art. 28 c. 1 (gruppi esposti, genere, età, provenienza, contratto); c. 3 | G10 (art. 28 vigente) | CORRETTO | A |
| dvr.py:584-589; redattore.py:95; dvr.py:721-722; esame_revisione.py:27 | Art. 29 c. 3: rielaborazione entro 30 giorni dalle causali | G10 riga 83 (Normattiva) | **IMPRECISO** (dvr.py:584-589) / CORRETTO nelle altre righe | Vedi E2. A |
| dvr.py:727-732; esame_revisione.py:29 | Firme «ai soli fini della prova della data» (DL, RSPP, RLS/RLST, MC ove nominato); in alternativa data certa con le procedure dell'art. 53 | G10 riga 82 (art. 28 c. 2) | CORRETTO | A |
| dvr.py:590-591 | Definizioni: art. 2, lettere a, b, d, e, f, h, i, m, n, q, r, s | G10 (art. 2) | CORRETTO | Le lettere esistono e il testo è copiato dalla legge. La lett. n) è «prevenzione». A |
| dvr.py:596-599 | Misure generali di tutela, art. 15 | G10 (art. 15, compresa la lett. z-bis) | CORRETTO | A |
| dvr.py:718 | Programma di miglioramento: art. 28 c. 2 lett. c-d | G10 | CORRETTO | A |
| dvr.py:701-706; redattore.py:90 | Sorveglianza sanitaria, art. 41 | G10 | CORRETTO | A |
| dvr.py:100-108 | Promemoria per Titoli: II luoghi, III attrezzature ed elettrico, IV cantieri, V segnaletica, VI MMC (sollevamento, spinta e traino, movimenti ripetuti), VII VDT, VIII agenti fisici, IX sostanze (chimici, cancerogeni, amianto), X biologici, XI ATEX | G10 (indice vigente); All. XXXIII (ISO 11228-1, -2, -3) | CORRETTO | «Movimenti ripetuti» sotto il Titolo VI regge, perché l'All. XXXIII richiama la ISO 11228-3. M |
| redattore.py:70, 88, 91, 93 | Art. 29 cc. 1-2; artt. 36-37 e Accordo 17/4/2025; artt. 43-46, D.M. 2/9/2021 e D.M. 388/2003; art. 26 | G10 | CORRETTO | A |
| corsi.py:38, 46, 50 | Art. 34 e All. II (DL-SPP); art. 2 c. 1 lett. d; art. 18 c. 1 lett. b-bis | G10 | CORRETTO | A |
| esame_revisione.py:30-31 | L'art. 43 esiste | G10 (art. 43) | CORRETTO | A |
| esame_revisione.py:42-45, 54 | All. XXXIII per la MMC; art. 280 c. 1 «uso» (agenti biologici dei gruppi 3 e 4); ISO 11228-2 per spinta e traino | conoscenza | CORRETTO | Il testo dell'art. 280 non è stato riletto in questa sessione. B |
| dvr.py:7-9, 182-185, 367-378, 709-715 | D.Lgs. 151/2001 All. A, B, C; artt. 7 e 12; «fino a sette mesi dopo il parto» | Normattiva (atto in biblioteca); conoscenza | CORRETTO | Art. 7 (lavori vietati, anche fino a 7 mesi di età del figlio), art. 11 (valutazione), art. 12 (conseguenze); All. C per l'art. 11. B/M |
| biblioteca.py:120-121 | D.I. 30/11/2012, procedure standardizzate (art. 29 c. 5 e art. 6 c. 8 lett. f; GU n. 285 del 6/12/2012) | conoscenza | CORRETTO | B |
| biblioteca.py:150-152 | Circolare MLPS 18/11/2010, prot. 15/SEGR/0023692 (stress lavoro-correlato) | G10 (manuale INAIL 2017, che la richiama) | CORRETTO | M |

### 5. Cantieri e appalti

| file:riga | testo nel codice | fonte | esito | nota / affid. |
|---|---|---|---|---|
| redattore.py:13-14, 101, 120-139 | POS: art. 89 c. 1 lett. h), art. 96 c. 1 lett. g), All. XV p. 3.2.1, lett. a) 1-7 e lett. b)-l) | tussl.it/allegati/allegato-xv | CORRETTO | Lettere e numeri coincidono. M |
| redattore.py:14, 142 | PSC: art. 100 e All. XV punti 2 (2.1.2) e 4 (costi) | tussl.it/allegati/allegato-xv | CORRETTO | M |
| redattore.py:101, 142; biblioteca.py:118-119 | Modelli semplificati: D.I. 9/9/2014 (in GU n. 212 del 12/9/2014 solo il comunicato) | conoscenza | CORRETTO (atto) / NON VERIFICATO (GU) | B |
| biblioteca.py:48-54; studioso.py:59 | D.M. 18/9/2024 n. 132 (GU n. 221 del 20/9/2024); circolare INL n. 4/2024 | lavoro.gov.it (notizia); GU ELI 24G00151; ancecatania.it (circ. INL 4 del 23/9/2024) | CORRETTO | A/M |
| studioso.py:58, 60 | Titolo IV e All. XV; art. 26 (DUVRI) | G10 | CORRETTO | A |

### 6. Riferimenti superati segnalati dal codice (dvr.py:66-85)

| file:riga | testo nel codice | fonte | esito | nota / affid. |
|---|---|---|---|---|
| dvr.py:67-72 | D.Lgs. 626/1994, D.P.R. 547/1955, D.P.R. 303/1956 (salvo l'art. 64), D.P.R. 164/1956, D.Lgs. 494/1996, D.Lgs. 277/1991 abrogati dall'art. 304 | tussl.it/titolo-xiii-norme-transitorie-e-finali/art-304; testounicosicurezzalavoro.com/testo-unico/articolo-304 | CORRETTO | M |
| dvr.py:74-75 | D.Lgs. 230/1995 e 187/2000 abrogati dal D.Lgs. 101/2020 | conoscenza | CORRETTO | B |
| dvr.py:76 | L. 1204/1971 abrogata dal D.Lgs. 151/2001 | conoscenza (art. 86 del D.Lgs. 151/2001) | CORRETTO | B |
| dvr.py:77 | L. 46/1990 «sostituita, per gli impianti, dal D.M. 37/2008» | conoscenza (il D.M. 37/2008 abroga la L. 46/1990 salvo gli artt. 8, 14 e 16) | CORRETTO | B |
| dvr.py:79-84 | Frasi R superate dalle indicazioni H del CLP; la categoria «nocivo» non esiste nel CLP; ISPESL confluito nell'INAIL (D.L. 78/2010) | conoscenza (Reg. CE 1272/2008; art. 7 del D.L. 78/2010) | CORRETTO | B |
| dvr.py:452-453 | Atti «noti»: D.Lgs. 151/2001, D.M. 37/2008, D.M. 22/1/2008, Reg. 1272/2008, Reg. 1907/2006, D.Lgs. 66/2003, D.Lgs. 101/2020, D.Lgs. 17/2010, D.Lgs. 285/1992 | conoscenza | CORRETTO (atti esistenti) | Il D.Lgs. 17/2010 (macchine) sarà sostituito dal Reg. UE 2023/1230 dal 20/1/2027. B |

### 7. Norme recenti (2025-2026) in biblioteca

| file:riga | testo nel codice | fonte | esito | nota / affid. |
|---|---|---|---|---|
| biblioteca.py:68-71; studioso.py:61 | L. 11/3/2026 n. 34, legge annuale PMI, «artt. 10-12 sicurezza e formazione», GU n. 68 del 23/03/2026 | testo GU (copia dottrinalavoro); def.finanze.it | CORRETTO | Art. 10: art. 30 c. 5-ter, art. 37 c. 4 lett. b-bis (CIG) e nuovo c. 5 (addestramento e registro). Art. 11: lavoro agile. Art. 12: All. VII, piattaforme in frutteto con verifica triennale. In vigore dal 7/4/2026. A |
| biblioteca.py:153-155 | Nota INL prot. 780 del 15/4/2026 | G10; vegaengineering, dottrinalavoro | CORRETTO | M |
| biblioteca.py:72-76; studioso.py:62 | D.L. 31/10/2025 n. 159 coordinato con la L. 29/12/2025 n. 198 (GU n. 301 del 30/12/2025) | G10 (testo coordinato AIFOS); TU37 nota (3) | CORRETTO | M |
| biblioteca.py:77-80; studioso.py:56 | D.Lgs. 31/12/2025 n. 213, amianto, «riscrittura Titolo IX Capo III» (GU n. 6 del 9/1/2026) | Normattiva (URN …2025;213); medicoeleggi (GU n. 6 del 9/1/2026); vegaengineering (in vigore dal 24/1/2026) | CORRETTO | Recepisce la dir. (UE) 2023/2668 e modifica il Capo III, per esempio l'art. 251. «Riscrittura» è un'enfasi. M |
| biblioteca.py:36-39; aggiornamenti.py:6, 189 | Testo coordinato INL del D.Lgs. 81/2008, ed. ottobre 2026 | risultato di ricerca: ispettorato.gov.it/files/2026/10/TU-81-08-Ed.-Ottobre-2026.pdf | CORRETTO (esiste) | Contenuto non letto: host bloccato dalla rete della sessione. M |
| biblioteca.py:82-86, 57-58, 185 | D.P.R. 14/9/2011 n. 177 «… inquinamento o confinanti» (GU n. 260 dell'8/11/2011) | conoscenza | CORRETTO | «Confinanti» è proprio il titolo ufficiale in GU, refuso compreso. B |
| biblioteca.py:157-183 | Art. 2087 c.c.; artt. 40, 43, 589 e 590 c.p.; D.Lgs. 231/2001 art. 25-septies (richiamato dall'art. 30); D.Lgs. 758/1994 artt. 19-25 (prescrizione); R.D. 262/1942; R.D. 1398/1930 | conoscenza | CORRETTO | B |
| biblioteca.py:281 | Sanzioni: artt. 55-59 | conoscenza (Titolo I, Capo IV, Sezione I) | CORRETTO | Nessun importo nel codice; vedi Metodo. B/M |
| esperto.py:107, 178 (commenti) | «3.559,60», «9.112 euro» (esempi) | ipsoa.it 25/10/2023; biblus.acca.it art. 55; nota INL 724/2023 | CORRETTO | Ammenda vigente dell'art. 55 c. 1 (3.559,60-9.112,57 €), dopo la rivalutazione 2023 (+15,9%). M |

### 8. ATECO

| file:riga | testo nel codice | fonte | esito | nota / affid. |
|---|---|---|---|---|
| esempi_dvr.json:4 | 86.23, studio dentistico | ISTAT ATECO 2025: 86.23.00 «Attività odontoiatriche» | CORRETTO | Divisione 86: nel testo HTML dell'Allegato IV non compare (vedi O2). A |
| esempi_dvr.json:91 | 01.47, allevamento avicolo | ISTAT ATECO 2025: 01.47.00 «Allevamento di pollame» | CORRETTO | A |
| esempi_dvr.json:157 | 93.29.99, animazione di feste e villaggi turistici | ISTAT ATECO 2025: 93.29.99 «Altre attività varie di intrattenimento e divertimento n.c.a.» | CORRETTO | È un codice 2025 (in ATECO 2007 era 93.29.90). A |
| esempi_dvr.json:180 | 41.00, impresa edile | ISTAT ATECO 2025: 41.00.00 «Costruzione di edifici residenziali e non residenziali» | CORRETTO | Codice 2025 (in ATECO 2007 era 41.20). Allegato IV: divisione 41 a rischio ALTO. A |
| redattore.py:41 | Esempio «96.30.01» | ISTAT ATECO 2025: 96.30.01 «Servizi di pompe funebri» | CORRETTO | Codice 2025 (in ATECO 2007 era 96.03). La divisione 96 è a rischio BASSO in entrambe le classificazioni. A |
| ateco.py:146-148 | Avvertenza: «l'Allegato IV usa ATECO 2007; con un codice ATECO 2025 la corrispondenza va verificata» | ASR, Allegato IV; ISTAT | CORRETTO | A |

### 9. Altro

| file:riga | testo nel codice | fonte | esito | nota / affid. |
|---|---|---|---|---|
| credito_claude.py:14-15, 94-96 | Festivi: L. 260/1949; 4 ottobre dal 2026 per la L. 151/2025 | certifico (L. 8/10/2025 n. 151, GU n. 236 del 10/10/2025, in vigore dall'1/1/2026); ANCE | CORRETTO | Non riguarda la sicurezza sul lavoro. M |
| strumenti.py:1052-1057; cervello.py:408-409, 457-458; studioso.py:34-62; voce.py:39-42; collaudo.py:237 | Elenchi di temi e atti (Accordo 2025, D.M. 388, D.M. 2/9/2021, D.A. 368/2026, L. 116/2021, D.P.R. 151/2011, D.Lgs. 151/2001, D.Lgs. 213/2025, D.P.R. 177/2011, D.M. 132/2024, L. 34/2026, D.L. 159/2025) | come sopra | CORRETTO | Sono solo titoli di argomenti, senza contenuti numerici. — |
| esperto.py:124-126 | Tabella PERIODICITA | — | vedi O3 | Difetto di logica, non di norma |

## Riferimenti NON VERIFICATI e perché
1. Il numero e la data della GURS del D.A. 368/2026 (n. 15 del 27/3/2026) e del D.A. 698/2022 (n. 40 del 26/8/2022), e la revoca del D.A. 630/2019: non li ho trovati su fonti ufficiali raggiungibili.
2. GU del D.I. 9/9/2014 (n. 212), del D.I. 6/3/2013 (n. 65), del D.I. 30/11/2012 (n. 285) e del D.M. 2/9/2021 (n. 237): non ricontrollate. Per quanto noto sono corrette.
3. Norme CEI 11-27 e CEI EN 50110 (lavori elettrici): sono a pagamento.
4. Inclusione della pesca (ATECO 03) nel «comparto dell'agricoltura» del D.M. 388/2003, punto A-III.
5. Testo coordinato INL di ottobre 2026: host ispettorato.gov.it bloccato dalla rete della sessione.
6. Prossima rivalutazione delle ammende (2028): è una deduzione dalla cadenza quinquennale dell'art. 306, c. 4-bis.
7. D.P.R. 177/2011 art. 1 e art. 280 del D.Lgs. 81/2008: non riletti in questa sessione. Esito dato in base a conoscenza coerente con G10.
