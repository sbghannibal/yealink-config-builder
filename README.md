# Yealink Config Builder

Ondersteunt naast bestaande Yealink-configuraties nu ook:

- `cisco_atabox_192`
- `fasttel_ft600`

## Nieuwe devices

### Cisco ATABOX 192
- Gebruikte inputvelden: `SIP_SERVER_ADDRESS`, `SIP_SERVER_PORT`, `SIP_USERNAME`, `SIP_PASSWORD`, `SIP_DISPLAY_NAME`, `SIP_PHONE_NUMBER`, `SIP_OUTBOUND_PROXY_SERVER_1`, `SIP_OUTBOUND_PROXY_SERVER_1_PORT`
- Outputbestand: `init.cfg`
- Defaults in generator:
  - `SIP_SERVER_PORT=5060` wanneer geen poort beschikbaar is
  - outbound proxy velden blijven leeg als ze niet zijn ingevuld

### Fasttel FT600
- Gebruikte inputvelden:
  - `SIP_SERVER_ADDRESS`
  - `SIP_SERVER_PORT`
  - `SIP_OUTBOUND_PROXY_SERVER_1`
  - `SIP_OUTBOUND_PROXY_SERVER_1_PORT`
  - `SIP_DISPLAY_NAME`
  - `SIP_PHONE_NUMBER`
  - `SIP_USERNAME`
  - `SIP_PASSWORD`
  - `SIP_RTP_PORT`
  - `SIP_USE_TLS`
  - `SIP_DTMF_RECV_RTP`
- Outputbestand: XML-config (`fasttel_ft600_<MAC>.xml` bij device-downloads)
- Defaults in generator:
  - `SIP_SERVER_PORT=5060`
  - `SIP_RTP_PORT=5004`
  - `SIP_USE_TLS=0`
  - `SIP_DTMF_RECV_RTP=1`

## Provisioning en downloads

- Bestaande Yealink `*.cfg` provisioning blijft ongewijzigd ondersteund.
- `/provision` past de user-agent blokkade nu alleen nog toe op Yealink-types, zodat Cisco ATABOX 192 en Fasttel FT600 niet onterecht worden geblokkeerd.
- Admin/device-downloads gebruiken device-specifieke bestandsnamen:
  - Yealink: `yealink_<MAC>.cfg`
  - Cisco ATABOX 192: `init.cfg`
  - Fasttel FT600: `fasttel_ft600_<MAC>.xml`

### Mass Update (`/massupdate/`)

- Alleen `/massupdate/<MAC>.cfg` is ondersteund, bv. `https://example.com/massupdate/249ad8667732.cfg`: precies 12 hexadecimale tekens, hoofdletterongevoelig. Een Yealink user-agent met model en firmwareversie is verplicht. Een MAC in query of user-agent moet overeenkomen met de bestandsnaam en kan die niet vervangen.
- Modelbestanden zoals `/massupdate/y000000000146.cfg`, `/massupdate/` en `/massupdate/index.php` geven altijd een lege `404` voor GET/HEAD, ook met een geldige MAC in query of user-agent, zonder database, quotum of logging.
- Een geleverde config begint altijd met `#!version:1.0.0.1`, gevolgd door alleen `firmware.url = ...`.
- HTTP-statussen (altijd zonder body, behalve bij 200):
  - `200`: config geleverd (GET).
  - `404`: niet-ondersteunde bestanden (bv. `.boot`, `.enc`), ongeldige aanvragen of geen config beschikbaar (geen/inactieve campagne, al up-to-date, dagquotum bereikt). Niet-ondersteunde bestanden raken de database niet.
  - `204`: HEAD op een ondersteunde URL. HEAD gebruikt nooit de database en verbruikt nooit quotum.
  - `405`: andere methodes dan GET/HEAD. `503`: database-/interne fout.
- Het menu **Mass Update** bevat **Massupdate config** (de bestaande campagnepagina) en **Massupdate logging** (`/admin/massupdate_logging.php`). Beide zijn uitsluitend voor actieve Owners.
- Voer vóór deployment `php setup/run_migrations.php` uit. Migratie `20_massupdate_log.sql` voegt `massupdate_log` toe met MAC-adres, toestelmodel, oude en nieuwe firmwareversie, campagne, resultaat, IP, User-Agent en tijdstip; tijdstip en MAC-adres zijn geïndexeerd. Bij verwijdering van een campagne blijft de log bewaard met een lege campagneverwijzing.
- Ondersteunde GET-aanvragen loggen `served`, `up-to-date`, `quota`, `inactive` of `no-campaign`. HEAD, ongeldige/niet-ondersteunde aanvragen en andere methodes loggen niet en raken de database niet. Log- en opschoonfouten veranderen de provisioningresponse niet. `served` betekent dat de configuratie geleverd is, niet dat de firmware-installatie bevestigd is.
- De loggingpagina toont uitsluitend `served`-aanvragen (HTTP 200) met bekende, niet-lege en verschillende oude en nieuwe firmwareversies, ook voor historische logregels. Nieuwste aanvragen staan eerst (50 per pagina), met filters op MAC-adres, toesteltype en inclusief datumbereik. Tellingen, paginering en aantallen aanvragen/unieke MAC-adressen per toesteltype en nieuwe softwareversie volgen dezelfde selectie en filters. Andere resultaten blijven diagnostisch opgeslagen tot de bewaartermijn verloopt.
- **Logbewaartermijn (dagen)** staat standaard op **30**, is instelbaar van 1 tot 3650 op de loggingpagina en wordt opgeslagen als `settings.massupdate_log_retention_days`. Oudere logregels worden verwijderd bij het openen van de pagina en bij ongeveer 1% van de ondersteunde GET-aanvragen. **Nu opschonen** verwijdert direct alleen verlopen logregels via een CSRF-beveiligde POST. Zonder aanvragen of paginabezoek vindt geen opschoning plaats. Campagnes en quotumtellers blijven ongewijzigd.

## Installatie

Bij een lege database kun je nu direct `install.php` openen:

1. Alle nog niet uitgevoerde SQL-bestanden in `migrations/` worden in volgorde uitgevoerd.
2. `schema_migrations` wordt automatisch bijgehouden.
3. Standaard device types worden idempotent gesynchroniseerd.
4. Je maakt eenmalig de eerste admin aan via het formulier in `install.php`.
5. Na succesvolle installatie toont `install.php` een successtatus met een link naar `login.php`.
6. De installer zet een lock via `settings.installed_at` en een fallback-bestand `settings/.installed`.

Voor een bewuste reset in development: verwijder de `installed_at` rij uit `settings` en verwijder `settings/.installed`.

De CLI-scripts blijven beschikbaar en idempotent:

```bash
php setup/run_migrations.php
php seed.php
```
