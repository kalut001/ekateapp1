# AP Area Clienti – sviluppo

Plugin WordPress dell'area riservata di AP Sicurezza Lavoro. Il codice del plugin è nella cartella
[`ap-area-clienti/`](ap-area-clienti/) (note di versione in `ap-area-clienti/LEGGIMI.txt`).
Il resto della cartella serve solo allo sviluppo e **non** va nel pacchetto installato.

## Pacchetto da installare

```bash
bin/build-zip.sh
```

Crea `build/ap-area-clienti-<versione>.zip` (solo la cartella del plugin).

## Struttura del plugin

| File | Contenuto |
|---|---|
| `operazioni.php` | **Registro unico** dei moduli: funzione, ruolo, permesso, ambito, sola consultazione, firme, transazione |
| `actions.php` | Dispatcher dei moduli e operazioni su aziende, lavoratori, attestati, documenti, scadenze, segnalazioni, utenti |
| `helpers.php`, `forms.php` | Accesso tra aziende, transazioni, reindirizzamenti, moduli che conservano i dati dopo un errore |
| `invii.php` | Protezione dai doppi invii |
| `db.php` | Tabelle, migrazioni, verifica InnoDB |
| `files.php`, `documenti.php` | Cartella protetta, caricamenti, download, versioni, presa visione firmata |
| `utenti.php`, `consensi.php`, `richieste.php` | Livelli di accesso ed email, firme del primo accesso, richieste di accesso |
| `lavoratori.php`, `scadenze.php`, `segnalazioni.php`, `importazione.php` | Formazione per mansione, ricorrenze, foto e richieste di corsi, Excel/CSV |
| `push.php`, `notifiche.php`, `mail.php` | Notifiche sul telefono, avvisi delle scadenze, invio email |
| `portal.php`, `interfaccia.php`, `ui.php`, `webapp.php`, `riepiloghi.php` | Pagine dell'area riservata, web app, riepiloghi |

Per aggiungere un modulo: scrivere `apac_op_<nome>( $azienda_id )` **e** la voce in `operazioni.php`
(un'operazione non registrata viene rifiutata; il test «registro» segnala le funzioni dimenticate).

## Test end-to-end

Servono PHP (con mysqli, curl, zip, gd), Composer e un MySQL/MariaDB con un database **dedicato** (viene svuotato).

```bash
APAC_TEST_DB_HOST=127.0.0.1:3306 APAC_TEST_DB_USER=root APAC_TEST_DB_PASS=root APAC_TEST_DB_NAME=wp_test bin/test-env.sh
```

```bash
php tests/run.php
```

`bin/test-env.sh` installa un WordPress di prova in `.test-env/` con il plugin collegato a questa cartella e avvia il
server su `127.0.0.1:8089`. I test (`tests/cases/`) usano richieste HTTP reali: isolamento tra aziende, utenti revocati,
sola consultazione, firme mancanti, download, rollback con InnoDB e MyISAM, operazioni dello studio, richieste di accesso.
Il plugin di supporto `tests/fixtures/apac-test-helpers.php` (attivo solo con `APAC_TESTING`) cattura le email e simula guasti.

## Analisi statica

```bash
composer install
```

```bash
composer lint && composer phpstan && composer phpcs
```

- PHPStan livello 5 (`phpstan.neon.dist`).
- PHPCS con le sole regole di sicurezza di WordPress (`phpcs.xml.dist`). Le segnalazioni già verificate a mano sono in
  `tests/phpcs-baseline.json`; `bin/phpcs-check.php` fallisce solo su quelle nuove. Dopo aver verificato una nuova
  segnalazione: `php bin/phpcs-check.php --update`.

## Verifica completa e risultati

```bash
bin/verifiche.sh
```

Esegue in sequenza sintassi, PHPStan, PHPCS, test sul plugin del repository, test sul plugin **estratto dallo zip**,
aggiornamento dalla 1.9.13 originale con dati esistenti (`tests/fixtures/ap-area-clienti-1.9.13.zip`) e mutazioni,
e scrive `build/RISULTATI-VERIFICHE.md` con tutti gli output, il commit e l'impronta SHA-256 dello zip.

- **Mutazioni** (`bin/mutation-check.php`): tolgono uno per volta i controlli di sicurezza (isolamento, revoca, ruoli,
  permessi, sola consultazione, firme, nonce e percorsi dei download, registro, transazioni, rollback, doppi invii,
  richieste di accesso). Per ognuno almeno un test deve fallire: è la misura della copertura dei test.
- **Provare uno zip**: estrarlo e indicare la cartella con `APAC_TEST_PLUGIN_DIR=/percorso/ap-area-clienti bin/test-env.sh`.
- **Pacchetto di sviluppo** (plugin + test + strumenti + rapporto): `bin/build-dev-zip.sh`.

## CI

`.github/workflows/ap-area-clienti.yml`: a ogni push analisi statica e test su PHP 7.4 e 8.3 con MariaDB 10.11.
Avviandolo a mano (Actions › AP Area Clienti › Run workflow) esegue anche `bin/verifiche.sh` e pubblica come artefatti
il rapporto e i pacchetti.
