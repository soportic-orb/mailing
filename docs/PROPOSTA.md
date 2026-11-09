# Proposta — Plataforma de Mailing (mailing.soportic.es)

> Estat: **proposta per validar**. No s'ha desenvolupat res encara.

## 1. Objectiu

Una plataforma pròpia d'email màrqueting / comunicacions massives, allotjada a
`mailing.soportic.es` (CloudPanel), que:

- s'instal·la des del navegador en un servidor compartit (sense consola ni build);
- s'actualitza sola (OTA) des d'aquest repositori;
- reaprofita l'**editor visual de blocs** del Tauler (iSEM) que ja es va portar al
  Panell de Torns;
- envia a través de **Mailjet**;
- gestiona llistes, contactes, segments, campanyes, calendari, seguiment i estadístiques;
- permet triar l'**adreça d'emissió** a cada enviament.

## 2. Què s'ha revisat dels altres projectes

| D'on | Què en reaprofitem |
|---|---|
| iSEM · `bedesk-plugins/tauler` | Editor visual: `Support/MailDoc.php` (model de document i whitelist de blocs), `Support/MailRenderer.php` (render a HTML compatible amb Outlook), `views/mailing/editor.blade.php` + `_editor_script.blade.php` (editor JS sense build), `MailingImages`, `MailingAi`, versions (`MailingVersion`), plantilles. |
| Torns · `docs/mailing-visual-editor.md` | Com es va adaptar l'editor a un sistema que **envia** (no exporta): diàleg «Programar o enviar» amb comprovacions (assumpte, preheader, prova enviada, enllaços i imatges), estats `draft → scheduled → sending → sent / failed / cancelled`, documents multillengua, traducció amb IA. Integració Mailjet v3.1 (`services/email.py`) i registre de `MessageID`. |
| SysRevAI | Esquelet de plataforma PHP autoinstal·lable: instal·lador web `/install` per passos, `MigrationRunner`, `UpdateService` (OTA per git o per ZIP amb còpia de seguretat, mode manteniment i rollback), `CronSetup`, claus xifrades AES-256-GCM a la BD, i18n. |
| Mòduls BeDesk (`ModuleUpdater`) | OTA via `version.txt` remot + zipball de GitHub (funciona sense `git` al servidor). |

## 3. Arquitectura proposada

**PHP 8.2 + MySQL 8 / MariaDB 10.6, sense framework pesant i sense pas de build**
(mateix patró que SysRevAI). És el que millor encaixa amb «servidor compartit +
OTA»: l'OTA només ha de copiar fitxers PHP/JS/CSS i passar migracions.

```
mailing/
├── public/              ← document root a CloudPanel
│   ├── index.php        ← front controller
│   ├── install/         ← instal·lador web (es bloqueja en acabar)
│   ├── assets/          ← CSS/JS de l'editor i del panell (sense build)
│   ├── t/               ← endpoints públics: baixa, preferències, "veure al navegador", formularis
│   └── uploads/         ← imatges dels correus
├── src/
│   ├── Core/            ← Router, View, Auth, Csrf, Crypto, Database, I18n
│   ├── Editor/          ← MailDoc + MailRenderer portats del Tauler
│   ├── Mailjet/         ← client API (enviament, senders, webhooks, estadístiques)
│   ├── Services/        ← Campaigns, Contacts, Imports, Segments, Scheduler, Stats, UpdateService, MigrationRunner
│   └── Controllers/
├── views/
├── database/migrations/ ← additives i idempotents
├── bin/worker.php       ← cron cada minut (cua d'enviaments, webhooks pendents, sincronitzacions)
├── vendor/              ← inclòs al repo/ZIP (no cal composer al servidor)
├── storage/             ← logs, còpies de seguretat, cua
└── version.txt
```

Dependències mínimes: `phpmailer` (proves i SMTP alternatiu), `phpspreadsheet`
(importar/exportar XLSX), `vlucas/phpdotenv`. Res de Node al servidor.

## 4. Mòduls funcionals

### 4.1 Contactes i llistes
- Contactes amb camps fixos (email, nom, cognoms, idioma) + **camps personalitzats**
  (text, número, data, sí/no, selecció) usables com a *merge tags* (`{{nom}}`).
- **Llistes** (un contacte pot ser a diverses) i **etiquetes**.
- **Segments dinàmics** per regles: camps, etiquetes, llistes, activitat
  («ha obert la campanya X», «no ha obert res en 90 dies», «ha clicat l'enllaç Y»).
- **Importació CSV/XLSX** amb assistent: mapatge de columnes, previsualització,
  validació d'emails, gestió de duplicats (actualitzar / ignorar), assignació a llista.
- Exportació CSV/XLSX.
- **Fitxa del contacte** amb historial: campanyes rebudes, obertures, clics, rebots, baixes.
- **Llista de supressió global**: rebots durs, queixes d'spam, baixes i bloquejos
  de Mailjet; mai s'hi envia, encara que estiguin en una llista.
- **RGPD**: origen i data del consentiment, doble opt-in opcional, exportar/esborrar
  les dades d'un contacte.
- **Formularis d'alta** incrustables (snippet HTML/iframe) amb doble opt-in.
- **Pàgina de baixa i preferències** (per llista), enllaç `List-Unsubscribe` +
  one-click (requisit de Gmail/Yahoo per a enviaments massius).

### 4.2 Adreces d'emissió
- Sincronització amb els **remitents i dominis validats a Mailjet**
  (`/v3/REST/sender`), amb l'estat SPF/DKIM visible.
- Cada remitent: adreça, nom visible, *Reply-To* per defecte, qui el pot fer servir.
- A cada campanya es tria el remitent en un desplegable (només els validats).
- Avís si el domini no té SPF/DKIM correctes.

### 4.3 Editor visual (portat del Tauler)
- Mateixos blocs: capçalera, títol, text, imatge, botó, enllaç, requadre,
  columnes, imatge+text, separador, espai, peu, i `html` lliure.
- Els blocs de continguts propis del Tauler (esdeveniment, enquesta, formulari,
  publicació) es substitueixen per blocs genèrics de plataforma:
  **contingut RSS/WordPress** (opcional, fase 3) i **merge tags**.
- Ajustos globals (colors, tipografia, amplada), preheader, vista mòbil/escriptori,
  versions i desfer, **biblioteca de plantilles**, imatges pujades.
- Peu obligatori amb enllaç de baixa i adreça postal (s'hi afegeix sol si falta).
- IA opcional (Claude) per redactar/millorar text i **traduir** el document
  (com a Torns), activable des de configuració.
- El document es desa en JSON (`project_data`) i es renderitza a HTML al servidor
  en desar (whitelist de `MailDoc`), igual que a Torns.

### 4.4 Campanyes
- Assistent: **destinataris** (llistes/segments + exclusions) → **remitent** →
  assumpte i preheader → **contingut** (editor) → **prova** (a una o diverses adreces)
  → **revisió** (comprovacions de Torns + recompte de destinataris efectiu després
  de supressions) → **enviar ara o programar**.
- Estats: `esborrany → programada → enviant → enviada`, més `pausada`,
  `cancel·lada` i `fallida`. Una campanya en curs es pot pausar.
- Duplicar campanya, campanyes multillengua (cada contacte rep la del seu idioma).
- Fase 2: **test A/B** d'assumpte (percentatge de prova i guanyador automàtic per
  obertures o clics), enviament per zona horària.

### 4.5 Calendari de programació
- Vista mes / setmana / llista amb les campanyes programades i enviades, amb color
  per estat.
- **Arrossegar per reprogramar**, crear una campanya clicant un dia.
- Avís de solapament (dues campanyes a la mateixa llista el mateix dia).
- Zona horària Europe/Madrid configurable.

### 4.6 Enviament i Mailjet
- Recomanació: **API Send v3.1 de Mailjet** en lloc de SMTP pur (lots de fins a
  50 missatges per crida, `CustomID` per contacte i campanya, `CustomCampaign`
  per agrupar estadístiques a Mailjet, errors per missatge). El **SMTP de Mailjet**
  queda com a alternativa configurable.
- **Cua a la BD** processada per `bin/worker.php` (cron cada minut de CloudPanel),
  amb ritme configurable segons el pla de Mailjet, reintents i bloqueig per no
  enviar dues vegades. Si el cron no corre, avís al tauler (com a SysRevAI).
- Personalització per contacte (merge tags) feta al servidor.
- Claus de Mailjet desades **xifrades** a la BD.

### 4.7 Seguiment i estadístiques
- **Webhooks d'esdeveniments de Mailjet** (`sent`, `open`, `click`, `bounce`,
  `spam`, `blocked`, `unsub`) cap a `/t/mailjet/webhook` (protegit amb usuari i
  contrasenya a la URL). Es guarden per contacte i campanya i alimenten la supressió.
- Per campanya: enviats, lliurats, obertures (úniques/totals), clics
  (únics/totals), CTR, CTOR, rebots durs/tous, spam, baixes; evolució per hores;
  **mapa de clics** sobre el correu; llista de destinataris amb el seu estat;
  exportació.
- Tauler general: tendències per mes, salut de la llista (creixement, baixes,
  rebots), comparativa de campanyes, rendiment per remitent.
- Nota: les obertures estan inflades per Apple Mail Privacy Protection; es mostra
  la mètrica de clics com a principal i s'indica la limitació.
- «Veure al navegador» i registre de cada `MessageID` (com `email_message_log` de Torns).

### 4.8 Usuaris i administració
- Rols: **administrador**, **editor** (crea i envia), **lector** (només estadístiques).
  Opcional: «cal aprovació d'un administrador per enviar».
- Configuració: Mailjet, remitents, adreça postal, IA, zona horària, idioma.
- **Actualització OTA** (vegeu §5), registre d'activitat, còpies de seguretat.
- Interfície en **català i castellà**.

## 5. Instal·lació i OTA

### Instal·lació a CloudPanel
1. CloudPanel → *Add Site* → **PHP Site** `mailing.soportic.es`, PHP 8.2+,
   *root directory* `public`; crear una base de dades MySQL.
2. Pujar el ZIP (o `git clone` des de la terminal del site) a la carpeta del site.
3. Obrir `https://mailing.soportic.es` → redirigeix a `/install`:
   requisits → base de dades → taules → usuari administrador → Mailjet (opcional,
   es pot fer després) → **línia de cron** a copiar a CloudPanel → fet.
4. CloudPanel → *Cron Jobs*: `* * * * * php /home/.../bin/worker.php`.
5. A Mailjet: validar el domini (SPF/DKIM) i enganxar la URL del webhook que mostra la plataforma.

### OTA
Pestanya **Actualització** (administradors), patró SysRevAI + `ModuleUpdater`:
- Compara `version.txt` local amb el de `main` d'aquest repositori.
- **Comprovacions prèvies** (espai, permisos, connectivitat) → mode manteniment →
  **còpia de la BD** → descàrrega del zipball (o `git pull` si hi ha `.git`) →
  substitució de fitxers **preservant** `.env`, `storage/`, `public/uploads/` i el
  bloqueig d'instal·lació → migracions → neteja d'OPcache → fi del manteniment.
- Si falla una migració: es restaura el codi anterior i s'indica la còpia de BD.
- Avís al tauler quan hi ha versió nova. El repositori és **públic**, per tant no
  cal token; cap secret no es desa mai al repositori.

## 6. Model de dades (resum)

`users`, `settings` (claus xifrades), `senders`, `contacts`, `contact_fields`,
`contact_field_values`, `lists`, `list_contacts` (estat de subscripció i
consentiment), `tags`, `contact_tags`, `segments` (regles JSON), `suppressions`,
`imports`, `templates`, `campaigns` (`project_data`, `html_body`, remitent,
programació, estat), `campaign_versions`, `campaign_audiences`, `send_queue`,
`messages` (un per destinatari amb `mailjet_id`), `events` (obertura/clic/rebot…),
`links` (per al mapa de clics), `forms`, `activity_log`, `migrations`, `system_updates`.

## 7. Fases

| Fase | Contingut | Resultat |
|---|---|---|
| **0. Base** | Esquelet PHP, instal·lador web, usuaris i rols, configuració, OTA, cron/worker. | Plataforma instal·lable i actualitzable a CloudPanel. |
| **1. MVP** | Contactes, llistes, importació CSV/XLSX, supressió, remitents Mailjet, editor visual portat, plantilles, campanyes amb prova i enviament immediat/programat, baixa one-click, webhooks i estadístiques bàsiques. | Ja es poden fer enviaments reals. |
| **2. Planificació i analítica** | Calendari amb arrossegar, segments dinàmics, estadístiques completes i mapa de clics, fitxa de contacte amb historial, pausa de campanyes, exportacions. | |
| **3. Extres** | Formularis d'alta amb doble opt-in, test A/B, IA (redacció i traducció), multillengua, contingut RSS/WordPress, aprovació d'enviaments. | |

## 8. Decisions a validar

1. **Una sola organització o multi-client?** Si Soportic ha de gestionar el
   mailing de diversos clients (cadascun amb el seu compte Mailjet, remitents i
   contactes separats), cal un model *multi-espai* des de la fase 0. És la decisió
   que més condiciona l'arquitectura.
2. **Base de l'editor**: proposo el del **Tauler** (PHP + JS sense build) en lloc
   del de Torns (React/Next.js), perquè no requereix compilació i l'OTA queda simple.
3. **Mailjet per API v3.1** (recomanat) o només SMTP?
4. **Volum previst** (contactes i enviaments/mes) i pla de Mailjet, per ajustar el ritme de la cua.
5. **Idiomes** de la interfície: català i castellà?
6. **Importacions d'origen**: cal sincronitzar contactes des d'algun sistema
   existent (BeDesk, Botble, Torns) o n'hi ha prou amb CSV/XLSX?
7. **Accés**: usuaris locals, o també inici de sessió amb Microsoft/Google?
