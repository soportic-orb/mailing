# App instal·lable (PWA), avisos push i menús adaptats

## Instal·lació

- `public/manifest.json`: «Mailing», pantalla completa, inici a `/`, icones a
  `public/icons/` (192, 512, adaptable 512, `apple-touch-icon` 180 i
  `badge-96` per a la barra d'estat d'Android).
- `favicon_tag(true)` posa el manifest i les metadades d'Apple **només** a
  l'aplicació i a la pàgina d'accés. Les pàgines públiques (formularis,
  baixa) no són instal·lables.
- `public/sw.js` (registrat a `app.js`):
  - mostra els avisos push i obre `data.url` en tocar-los (reaprofita la finestra oberta);
  - ensenya `public/offline.html` si una pàgina no carrega;
  - no desa cap dada de la plataforma al dispositiu.
- La targeta d'instal·lació (`#install-card`, a `app.js`) només surt als dispositius tàctils:
  - a Android, «Instal·la» (beforeinstallprompt);
  - a l'iPhone i l'iPad, els dos passos de Safari;
  - un cop instal·lada, «Activa els avisos».
  - Cada targeta es tanca per sempre en aquell dispositiu (localStorage).

## Avisos

- `Notify` crea els avisos per a cada destinatari que els vol (preferències a
  `settings.notify_prefs_<usuari>`), i el cron els envia (`Notify::flush`),
  mai durant l'enviament.
- Es guarden a `notifications` (la campaneta, `/avisos`).
- Destinataris: els editors del projecte i tots els administradors. «Comença
  l'enviament» només el rep qui la va programar; «Sistema», només els
  administradors.

| Tipus | Quan | Per defecte |
|---|---|---|
| `campaign_sent` | Acaba l'enviament (resum de lliurament) | sí |
| `campaign_failed` | Sense destinataris, sense remitent o Mailjet sense configurar (pausa), 3 intents fallits o cap missatge acceptat | sí |
| `campaign_started` | Una campanya programada comença a enviar-se | no |
| `bounces` | Primeres 24 h, a partir de 20 enviats: rebots > 3 % o queixes > 0,1 % | sí |
| `automation` | Algú queda retingut en un pas incomplet, o un correu del flux falla (com a molt un cada 12 h per automatització) | sí |
| `sync` | Una connexió MySQL/JSON dona error (un cada 12 h) | no |
| `system` | Cron aturat més de 30 min (detectat en obrir la plataforma) o actualització OTA disponible (comprovació diària) | sí |

- **Web Push** (`WebPush`), sense llibreries:
  - VAPID (JWT ES256) i xifratge `aes128gcm` (RFC 8291) amb l'OpenSSL de PHP;
  - comprovat amb el vector de test de l'RFC 8291;
  - el parell de claus VAPID es crea en activar els avisos (la privada, amb `Crypto`).
- Subscripcions:
  - es desen a `push_subscriptions`, una per dispositiu;
  - en tancar la sessió, el dispositiu es dona de baixa;
  - les que responen 404/410 s'esborren.
- Interruptor general a Configuració › «Avisos push al mòbil». Desactivat, no s'envia res
  als dispositius i la pàgina no ofereix l'interruptor del dispositiu.
- iPhone/iPad: només amb l'app instal·lada (iOS/iPadOS 16.4 o posterior).
- `PUSH_ALLOW_HTTP=true` (només per als tests) accepta servidors push en `http://`.

## Menús

| Amplada | Menú |
|---|---|
| < 700 px (telèfon) | Capçalera d'una línia (logotip, projecte, campaneta) i barra inferior: Inici, Campanyes, Automatitzacions, Contactes i «Més» |
| 700–1.099 px (tauleta) | Barra lateral d'icones: Inici, Campanyes, Automatitzacions, Calendari, Contactes, Llistes i «Més» |
| ≥ 1.100 px (ordinador) | El menú superior de sempre |

«Més» obre un full (`#nav-sheet`) amb les seccions agrupades (Enviar, Audiència,
Administració), el perfil i el botó de tancar la sessió. Al telèfon i a la
tauleta, les taules es desplacen lateralment dins la seva targeta.
