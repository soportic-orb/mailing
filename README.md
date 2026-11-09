# Mailing Soportic

Plataforma d'enviaments massius per als clients dels projectes de Soportic
(Emergencing, SysRevAI…), allotjada a **mailing.soportic.es** (CloudPanel).

- **Multiprojecte**: cada projecte té els seus contactes, llistes, segments,
  remitents, plantilles i campanyes, separats. Els usuaris veuen només els
  projectes assignats (o tots, si són administradors).
- **Editor visual** de blocs portat del Mailing del Tauler (iSEM): arrossegar i
  deixar anar, text directament al correu, columnes, botons compatibles amb
  Outlook, retall d'imatges, historial de versions, desfer/refer.
- **IA a l'editor**: textos (Claude o OpenAI), **traducció automàtica** de tot el
  correu a un altre idioma i **imatges amb OpenAI**. Cada contacte rep el correu
  en el seu idioma.
- **Mailjet** (API Send v3.1) com a proveïdor, amb **adreça d'emissió** a triar a
  cada campanya entre els remitents validats.
- **Contactes**: llistes, camps personalitzats (`{{camp:empresa}}`), segments
  dinàmics, importació CSV/Excel, exportació, RGPD (consentiment, exportació i
  supressió de dades), llista de supressió automàtica (rebots, queixes, baixes).
- **Formularis d'alta**: pàgines públiques (`/f/…`) amb un formulari per
  apuntar-se a les llistes, amb doble confirmació per correu opcional, prova
  del consentiment, protecció antispam i codi per incrustar-les en una web.
- **Baixa per llista**: l'enllaç del peu de cada correu permet deixar només la
  llista que l'ha enviat o totes les comunicacions del projecte.
- **Plantilles importables i exportables** en JSON (`.mailing.json`): Claude en
  pot dissenyar una seguint la [guia del format](docs/format-plantilles.md).
- **Sincronització** amb les altres plataformes: per API (en temps real), llegint
  la seva base de dades MySQL o una adreça JSON, cada N minuts.
- **Campanyes**: comprovacions abans d'enviar, correu de prova, enviament
  immediat o programat, pausa i represa, **calendari** amb arrossegar per
  reprogramar.
- **Seguiment i estadístiques**: lliuraments, obertures, clics per enllaç,
  rebots, queixes i baixes (webhooks de Mailjet), gràfic per hores i
  exportació. Baixa en un clic (RFC 8058) i «veure al navegador».
- **Instal·lació des del navegador** i **actualització OTA** des d'aquest
  repositori, amb còpia de seguretat i retorn enrere automàtic.

Interfície en català. PHP 8.1+ i MySQL/MariaDB, **sense dependències** (ni
Composer ni Node): funciona en qualsevol allotjament compartit.

## Instal·lació a CloudPanel

1. **Lloc**: *Sites › Add Site › Create a PHP Site*, domini `mailing.soportic.es`,
   PHP 8.2 o superior. A *Settings* del lloc, posa el **Root Directory** a
   `htdocs/mailing.soportic.es/public` (l'arrel ha de ser la carpeta `public/`).
2. **SSL**: *SSL/TLS › Actions › New Let's Encrypt Certificate*.
3. **Base de dades**: *Databases › Add Database* (apunta el nom, l'usuari i la
   contrasenya).
4. **Codi**: amb SSH (usuari del lloc):
   ```bash
   cd ~/htdocs && rm -rf mailing.soportic.es && git clone https://github.com/soportic-orb/mailing.git mailing.soportic.es
   ```
   O baixa el ZIP de GitHub i puja'l amb el gestor de fitxers.
5. **Instal·lador**: obre `https://mailing.soportic.es` → requisits → base de
   dades → primer administrador → claus de Mailjet (opcional) → fet.
6. **Cron**: *Cron Jobs › Add Cron Job*, cada minut (`* * * * *`):
   ```bash
   php /home/<usuari>/htdocs/mailing.soportic.es/bin/worker.php >/dev/null 2>&1
   ```
   La línia exacta surt al final de l'instal·lador i a *Configuració*. Sense cron
   la plataforma també envia, però només mentre algú la té oberta.

CloudPanel fa servir Nginx: el *vhost* per defecte d'un lloc PHP ja envia a
`index.php` les adreces que no són fitxers. Amb Apache hi ha `public/.htaccess`.

## Mailjet

1. **Claus**: *Configuració › Mailjet* (API Key i Secret Key del compte; es desen
   xifrades). Un projecte pot tenir un compte de Mailjet propi (a *Projectes*).
2. **Dominis**: a Mailjet, valida cada domini des del qual s'envia i afegeix al
   DNS els registres **SPF** i **DKIM** que indica. *Remitents › Comprova els
   dominis* en mostra l'estat.
3. **Remitents**: a cada projecte, *Remitents › Remitent nou*. Si l'adreça no és a
   Mailjet, es registra i Mailjet hi envia un correu de validació.
4. **Webhooks**: *Configuració › Configura-ho a Mailjet automàticament*. Sense
   webhooks no hi ha estadístiques d'obertures i clics ni exclusió automàtica de
   rebots.

## IA

*Configuració › IA*: clau d'Anthropic (Claude: textos i traducció) i/o
d'OpenAI (imatges i textos). Models configurables.

## Plantilles dissenyades per Claude

A *Plantilles › Importa* hi ha la guia del format (també a
[docs/format-plantilles.md](docs/format-plantilles.md)). Dona-la a Claude amb el
que vols i demana-li el JSON; després enganxa la resposta (pot incloure text i
```` ``` ````) o puja el fitxer. Cada plantilla es pot exportar en el mateix
format, per passar-la a una altra instal·lació o perquè Claude la modifiqui.

## Sincronització amb les altres plataformes

A cada projecte, *Sincronització*:

- **API** (recomanat): la plataforma envia els contactes quan canvien.
  ```bash
  curl -X POST https://mailing.soportic.es/api/v1/contacts \
    -H "Authorization: Bearer mlg_…" -H "Content-Type: application/json" \
    -d '{"contacts":[{"email":"anna@exemple.cat","first_name":"Anna","last_name":"Puig","language":"ca","external_id":"123","fields":{"empresa":"Hospital X"}}]}'
  ```
  També `POST /api/v1/contacts/unsubscribe` i `POST /api/v1/contacts/remove`
  amb `{"email":"…"}`, i `GET /api/v1/ping` per provar la clau.
- **Base de dades MySQL**: una consulta `SELECT` que torni `email` (i
  opcionalment `first_name`, `last_name`, `language`, `external_id`, `status` o
  altres columnes, que es desen com a camps). Recomanat: un usuari de només
  lectura.
- **Adreça JSON**: una URL que torni la llista de contactes.

Mode **mirall**: treu de la llista qui ja no surt a l'origen. Mai es torna a
subscriure qui s'ha donat de baixa o ha rebotat.

## Actualitzacions (OTA)

*Actualització › Comprova si n'hi ha › Actualitza*. Es compara `version.txt`
amb el de la branca `main`; si és més nova: mode manteniment → còpia de la base
de dades (`storage/backups`) → descàrrega → substitució dels fitxers (es
conserven `.env` i `storage/`) → migracions → fet. Si una migració falla, es
tornen a posar els fitxers anteriors.

Des de la consola: `php bin/console.php update` (també `status`, `migrate`,
`backup`, `cron` i `user <email> <contrasenya>` per crear o restablir un
administrador).

**Per publicar una versió**: fusiona a `main` amb `version.txt` incrementat.

## Desenvolupament

```bash
php -S 127.0.0.1:8080 -t public public/index.php   # i obre http://127.0.0.1:8080
bash tests/run.sh                                  # instal·lació neta + proves de punta a punta
```

`tests/run.sh` necessita MySQL/MariaDB local (root sense contrasenya) i fa
servir `tests/mock_server.php` en lloc de Mailjet, Anthropic i OpenAI. El mateix
s'executa a GitHub Actions a cada PR.

| Carpeta | Contingut |
|---|---|
| `public/` | Arrel del web: `index.php`, `install/`, `assets/` (CSS i JS de l'editor) |
| `src/Core` | Encaminador, base de dades, sessió, autenticació, xifratge |
| `src/Editor` | `MailDoc`, `MailRenderer`, `RichText` (portats del Tauler), documents multillengua, camps i imatges |
| `src/Services` | Contactes, segments, importació, sincronització, campanyes, cua d'enviament, webhooks, estadístiques, IA, OTA |
| `src/Mailjet` | Client de l'API de Mailjet |
| `src/Controllers`, `views/` | Pantalles |
| `database/migrations` | SQL additiu i idempotent |
| `bin/` | `worker.php` (cron) i `console.php` |
| `storage/` | Imatges, registres, còpies, cua (no es versiona) |
