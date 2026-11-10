# Automatitzacions

Fluxos de correus que s'envien sols. Cada automatització té un **disparador** i
una sèrie de passos (correu, espera, condició, acció) que es dibuixen a l'editor
visual (port sense dependències del FlowBuilder del Panell de Torns).

## Com funciona

- Cada contacte que compleix el disparador **s'inscriu** al flux (una sola
  vegada per automatització; els disparadors de data anuals, un cop l'any).
- El cron (`bin/worker.php`, cada minut) fa avançar cada inscripció pas a pas:
  envia els correus, espera, i a les condicions avalua **en aquell moment**
  (per exemple «ha obert el correu anterior?»). Els correus surten en el mateix
  minut.
- Qui es dona de baixa, rebota, marca com a brossa o és eliminat **surt** de
  tots els fluxos.
- En pausa, els contactes es queden on són i no s'envia res.
- Si s'edita una automatització activa:
  - qui era en un pas eliminat passa al pas següent;
  - qui arriba a un pas incomplet (correu sense assumpte, acció sense llista…)
    s'hi espera fins que es completa.

## Disparadors

| Disparador | Quan |
|---|---|
| S'apunta a una llista | Entra a la llista (formulari, importació, API, sincronització, a mà o una acció d'una altra automatització) després d'activar-la |
| S'inscriu amb un formulari | Confirma l'alta en un formulari concret |
| Esdeveniment per API | Una altra plataforma crida `POST /api/v1/events` |
| Data d'un camp | Cada dia a l'hora indicada: la data del camp (± dies) és avui; cada any o un sol cop |
| Manual | «Inscriu contactes» (llistes o segments) a Configuració |

## Esdeveniments per API (Emergencing, SysRevAI…)

Calen:
- la clau d'una connexió de tipus API del projecte (Sincronització);
- si la connexió té llista, el contacte s'hi afegeix.

Si el contacte no existeix, es crea; si existeix, s'actualitzen les dades que
s'envien. Els camps nous es creen automàticament i es poden fer servir als
correus amb `{{camp:clau}}`.

```bash
curl -X POST https://mailing.soportic.es/api/v1/events \
  -H "Authorization: Bearer mlg_…" \
  -H "Content-Type: application/json" \
  -d '{"event": "registre", "email": "anna@exemple.cat", "first_name": "Anna", "language": "ca", "fields": {"pla": "Pro"}}'
```

Resposta: `{"ok": true, "event": "registre", "contact": "created", "enrolled": 1}`.
Aquí `enrolled` és el nombre d'automatitzacions actives on ha entrat. És 0 si
no n'hi ha cap amb aquest esdeveniment, o si ja hi havia entrat.

Tant `POST /api/v1/events` com `POST /api/v1/contacts` accepten, per a cada
contacte, `"lists": ["Compte de prova"]` i `"remove_lists": ["Compte de pagament"]`
(noms o ids de llistes del projecte; les que no existeixen es creen). Així una
plataforma pot moure els seus usuaris de llista quan canvien de tipus de compte.
Qui s'havia donat de baixa d'una llista no s'hi torna a apuntar, i en treure'l
se'n conserva la baixa, excepte si el contacte porta `"status": "subscribed"`
(una alta explícita, com una casella marcada en un formulari): llavors s'hi torna
a subscriure i, amb `"consent": "Formulari de contacte de …"`, en queda la prova
de consentiment a la seva fitxa. Els camps nous amb un valor `AAAA-MM-DD` es creen de
tipus data, de manera que serveixen per al disparador «Data d'un camp».

El nom de l'esdeveniment pot tenir lletres, números i `_ . : -` (fins a 60
caràcters). Les dades del contacte també es poden enviar dins de `"contact": {…}`.

Exemple en PHP (per a la plataforma que avisa):

```php
function mailing_event(string $event, array $contact): void
{
    $ch = curl_init('https://mailing.soportic.es/api/v1/events');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . getenv('MAILING_API_KEY'), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['event' => $event] + $contact),
    ]);
    curl_exec($ch); // A failure must never block the user's action.
}

mailing_event('registre', ['email' => $user->email, 'first_name' => $user->name, 'language' => $user->lang]);
```

Exemple en Python (FastAPI, en segon pla):

```python
import httpx, os

async def mailing_event(event: str, contact: dict) -> None:
    try:
        async with httpx.AsyncClient(timeout=5) as c:
            await c.post("https://mailing.soportic.es/api/v1/events",
                         headers={"Authorization": f"Bearer {os.environ['MAILING_API_KEY']}"},
                         json={"event": event, **contact})
    except Exception:
        pass  # never block the user's action
```

## Detalls tècnics

- Taules:
  - `automations`: el flux en JSON i el disparador;
  - `automation_runs`: una fila per contacte inscrit i cicle, amb el pas actual i quan toca el següent;
  - `automation_log`: el registre de passos.
- Cada correu del flux és una fila de `campaigns` amb `status = 'auto'` i
  `automation_id`. Així reaprofita l'editor, el renderitzat, el seguiment de
  Mailjet, la baixa en un clic, el «veure al navegador» i l'informe. No surten a
  Campanyes ni al Calendari.
- Els missatges porten `run_id` (0 per a les campanyes); la clau única és
  `(campaign_id, email, run_id)`.
