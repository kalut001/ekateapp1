# Revisione di «Vega» — copia del 12/11/2026

Analisi del codice e prove reali in un ambiente isolato (Python 3.13 con tutte le dipendenze, **rete disattivata**: nessuna chiamata a Groq, Gemini, Claude, Google o alla posta). Le parti che richiedono Windows, microfono o servizi online sono indicate come non verificate (sezione 6). Il sorgente non è stato modificato.

Questa revisione **non** dichiara Vega "senza errori": dice cosa è stato verificato, come, e cosa resta da verificare.

## 1. Sintesi

| Gravità | Numero | Note |
|---|---|---|
| Critico | **0** | |
| Alto | **7** | 5 riprodotti con una prova, 2 dimostrati dal codice |
| Medio | **50** | |
| Basso | **64** | |
| **Totale difetti** | **121** | dopo aver unito i doppioni tra i gruppi (6 accorpamenti) |
| Miglioramenti (non difetti) | 11 | |
| Rilievi normativi | 6 | 1 errore + 5 imprecisioni; 1 punto non verificabile |

Elenco completo, voce per voce: `elenco-completo.md`. Dettagli (riproduzione, conseguenza, correzione proposta): rapporti in `rapporti/`.

**Cosa funziona bene (verificato):** sintassi corretta su Python 3.11, 3.13 e 3.15; dipendenze esistenti su PyPI, con pacchetti per Windows, e senza conflitti; 48 moduli si importano senza errori; i server locali (interfaccia e telefono) ascoltano solo su 127.0.0.1 con token a 256 bit confrontato a tempo costante; nessun `shell=True`/`os.system`; PowerShell riceve i percorsi tramite variabili d'ambiente; nessuna scrittura di file in percorsi scelti dal modello; la posta è davvero in sola lettura; la matrice di rischio P×D e la generazione del DVR in Word funzionano; la traduzione dei messaggi verso l'API di Claude è corretta (salvo i punti sotto); prezzi di Claude 5.5 corretti; la grande maggioranza dei riferimenti normativi è esatta.

## 2. I 7 difetti alti

| ID | Dove | Problema | Prova | Conseguenza | Correzione |
|---|---|---|---|---|---|
| VC-01 | posta.py:243; configura_posta.py:44 | Collegamento IMAP «SSL» **senza verifica del certificato** (`imaplib.IMAP4_SSL` senza `ssl_context`). | Verificato su 3.11/3.13/3.15: contesto con `verify_mode=0`, `check_hostname=False`; un server finto ha ricevuto utente e password. | Su una rete non fidata (Wi-Fi pubblica, hotel) le password di posta e PEC possono essere rubate a ogni controllo (ogni 15 minuti). | `ssl_context=ssl.create_default_context()`. |
| VA-03 | strumenti.py:626-634; strumenti.py:330-359; biblioteca.py:689-713 | `apri_sito` apre qualunque indirizzo **senza conferma**; `studia_documento` con un URL scarica senza conferma, anche dal telefono e anche con il perimetro «lavoro». | Codice + prova del secondo canale. | Un'istruzione nascosta in una mail o pagina letta può far inviare dati a un sito esterno (nell'indirizzo), senza che l'utente veda nulla. | Conferma per ogni indirizzo non in una lista di siti ufficiali; bloccare questi strumenti dopo letture di contenuti esterni. |
| VA-01 | strumenti.py:611; vega.py:244 | La conferma vocale di `esegui_comando` legge la **spiegazione scritta dal modello**, non il comando. | Codice. | L'utente può approvare a voce un comando diverso da quello descritto. | Leggere/mostrare il comando reale; per comandi distruttivi conferma a schermo. |
| VA-07 | claude_ponte.py:101, 111-116, 145-153; cervello.py:816, 839-840; redattore.py:304-319 | Con Claude Haiku 5.5 / Sonnet 5.5 il **ragionamento è attivo di default e consuma `max_tokens`** (documentazione Anthropic verificata); Vega usa 1024-2500 token senza margine e **non legge `stop_reason`** (`max_tokens`, `refusal`). | Client Claude simulato: risposta vuota → «Non ho una risposta da darle», senza passare a Groq/Gemini; DVR e verbali possono uscire troncati. | Risposte vuote o tagliate prese per buone, anche nei documenti per i clienti. | Leggere `stop_reason`; margine su `max_tokens` (o `output_config.effort` basso); in caso di `max_tokens`/`refusal` riprovare o cambiare cervello. |
| VA-13 | risposte_pronte.py:25, 56-62 | Le «risposte pronte» si riusano senza ragionare con somiglianza ≥ 0,8 tra parole: «può» e «non può» danno 1,0. | Riprodotto anche dal coordinatore. | A una domanda tecnica viene data la risposta di una domanda diversa (anche opposta). | Considerare negazioni e parole decisive (iniziale/aggiornamento, alto/basso); soglia più alta. |
| VC-02 | crea_pacchetto_prova.py:27-28, 135-142 | Il «pacchetto di prova» da dare a terzi include `conoscenza.json` (testo integrale di ogni documento studiato) e la cartella `biblioteca/`. | Prova: lo zip conteneva un DVR fittizio con codice fiscale. | Documenti dei clienti consegnati a terzi. | Escludere quei file; controllare il contenuto, non solo i nomi. |
| VD2-02 | ateco.py:42 | «codice ATECO 2025 43.21» e «ateco 2007 41.20» vengono letti come **divisione 20** (prodotti chimici). | Riprodotto anche dal coordinatore. | Classe di rischio e ore di formazione sbagliate nel DVR e nel piano dei corsi. | Saltare l'anno («2007», «2025») prima del codice. |

## 3. Difetti medi più rilevanti (estratto)

- **Cancellazioni senza conferma:** `dimentica("")` cancella tutti i ricordi (memoria.py:303-311, VC-03) e tutti i documenti della biblioteca (biblioteca.py:858-867); la biblioteca si ricostruisce al riavvio, la memoria no.
- **Telefono:** con il PIN nel messaggio ogni conferma del turno passa da sola (VA-02); il PIN scritto «482.913» o «482-913» non viene riconosciuto e finisce nel diario e ai servizi online (VC-04); il vecchio codice resta valido fino al riavvio (VC-09).
- **Lista dei comandi bloccati aggirabile** (VA-05, provato: 9 comandi pericolosi passano); `apri_file` senza conferma e con estensioni pericolose non bloccate (VA-06).
- **Dati dei clienti ai servizi online senza anonimizzazione** per POS/PSC/PEE e corsi (VD1-04); qualunque file locale può essere «studiato» e finire nei prompt (VD2-05); mail e impegni inviati in chiaro a ntfy.sh (VC-06, VB-17); token Google e chiave Groq in chiaro, contro quanto dice il LEGGIMI (VC-08).
- **Affermazioni false che si autoconfermano:** le schede scritte da Vega diventano «fonti» per i controlli successivi (VD2-12); titoli di documenti scaricati salvati come «correzioni dell'Ingegnere» (F5-01); `controlla_numeri` confronta il numero ma non l'unità (VD2-13).
- **Revisore del DVR:** scarta come inesistenti i rilievi che citano nomi, codici fiscali, email (VD1-01); ignora intestazioni e piè di pagina del Word (VD1-08); un campo malformato del modello fa perdere l'intero DVR dopo le chiamate a pagamento (VD1-11).
- **Microfono:** se il riavvio dell'audio fallisce Vega si chiude (VB-02); comandi brevi scartati come eco («no» dentro «buongiorno», VB-06); una frase che contiene «esci» chiude Vega (VB-07); con la cartella utente accentata i collegamenti .vbs possono non partire (VB-08, da confermare su Windows).
- **Timeout di 30 s** per Claude senza nuovi tentativi né streaming, su richieste fino a 9000 token (VD1-03).
- **config.py:** `CLAUDE_CREDITO_MESE=100,00` (virgola) impedisce l'avvio (VA-17).
- **Nome e cognome completo** di una persona ancora presente in un commento (dvr.py:241), contrariamente al LEGGIMI.

## 4. Contenuti normativi (verificati sulle fonti)

Verifica su Normattiva, sul testo dell'Accordo Stato-Regioni 17/04/2025 pubblicato dal Ministero del Lavoro e sui D.M. Confermati tra l'altro: durate e aggiornamenti dell'Accordo 2025 (lavoratori 8/12/16 ore, aggiornamento 6 ore ogni 5 anni; preposti 12 ore, aggiornamento 6 ore ogni 2 anni; dirigenti 12, datore di lavoro 16; RSPP/ASPP; coordinatori; attrezzature), D.M. 2/9/2021 (4/8/16 ore, aggiornamento quinquennale), D.M. 388/2003 (16/12 ore, aggiornamento triennale), RLS 32 ore, RLST 64 ore, L. 34/2026.

| ID | Esito | Dove | Problema |
|---|---|---|---|
| E1 | ERRATO (affidabilità alta) | ateco.py:85-122; dvr.py:601-603 | Primo soccorso: nel gruppo A mancano centrali termoelettriche, impianti e laboratori nucleari, lavori in sotterraneo (D.M. 388/2003 art. 1). Con 5 lavoratori o meno Vega indica B/C (12 ore invece di 16). Gravità: medio (casi rari; sopra 5 lavoratori il DVR scrive già «da verificare»). |
| E2 | IMPRECISO | dvr.py:584-589 | Art. 29 c. 3: rielaborazione della valutazione «immediata», documento entro 30 giorni; mancano aggiornamento delle misure ed evidenza al RLS. |
| E3 | IMPRECISO | dvr.py:604-608 | Cassetta di pronto soccorso per gruppi A e B, pacchetto di medicazione per il gruppo C: il DVR li indica sempre entrambi. |
| E4 | IMPRECISO | redattore.py:212 | Esercitazioni antincendio: D.M. 2/9/2021 All. I p. 1.3, non All. II p. 2.2. |
| E5 | IMPRECISO (solo il riferimento) | cervello.py:53 | Aggiornamento preposti: Parte III p. 1.2 dell'Accordo, non Parte II p. 2.2 (valori corretti). |
| E6 | MINORE | corsi.py:60 | RLST cercato all'art. 47 invece che all'art. 48. |
| E7 | NON VERIFICATO | ateco.py:108 | Pesca (ATECO 03) trattata come «comparto dell'agricoltura» per il gruppo A punto III: nessuna fonte trovata. |

Da tenere presente: l'Allegato IV dell'Accordo usa i codici **ATECO 2007**, le visure dal 2025 riportano **ATECO 2025**; Vega lo segnala ma non ha una tabella di raccordo.

## 5. Metodo

| Prova | Esito |
|---|---|
| Compilazione su Python 3.11.18, 3.13.18, 3.15.2 (55 file .py) | nessun errore |
| Pacchetti di requirements.txt su PyPI | tutti esistenti; pacchetti Windows per cp313 e cp315 presenti; `pip check` senza conflitti |
| Importazione dei moduli su 3.13 con le dipendenze | 48/48 senza errori (7 esclusi perché avviano il programma) |
| ruff (regole di correttezza e sicurezza) e bandit | 426 e 6 segnalazioni, valutate nei rapporti; sha1 e parsing XML risultati non pericolosi |
| Lettura completa degli 80 file | 5 revisori (VA, VB, VC, VD1, VD2), ognuno con le righe lette «1-N di N» |
| Verifica normativa | revisore dedicato (VN) sulle fonti ufficiali |
| Controllo indipendente | un revisore separato ha ricontrollato tutti gli alti e 13 medi: confermati, gravità di 9 voci corrette, 1 esempio scartato come falso positivo, 3 difetti nuovi + 1 canale di fuga dei dati aggiunto |

## 6. Cosa NON è stato possibile verificare

- Tutto ciò che richiede **Windows**: avvio reale, icona, PowerShell, file .vbs, registro, audio (microfono, altoparlanti, MCI).
- Le risposte reali delle API (Claude, Groq, Gemini, Whisper): la rete era disattivata. In particolare tempi reali rispetto al timeout di 30 s e frequenza delle risposte troncate.
- I **nomi dei modelli Groq e Gemini** e i loro parametri (`reasoning_effort`, TTS `gemini-3.8-flash-tts` ecc.).
- Il comportamento reale di **Tailscale** e di Google OAuth.
- Quanto i modelli seguano davvero istruzioni nascoste: è dimostrata solo l'assenza dei controlli.
- Le cartelle escluse dalla copia (`dati/`, `conoscenze/`, `prove/`), quindi il collaudo completo.
- Alcune fonti normative (norme CEI e UNI a pagamento, GURS di decreti regionali, testo coordinato INL di ottobre 2026 per blocco di rete).
