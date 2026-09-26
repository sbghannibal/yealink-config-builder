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
