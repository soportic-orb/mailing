# Format de plantilla de la plataforma de Mailing Soportic

Dissenya un correu electrònic i retorna'l com **un sol objecte JSON** (sense text abans ni després) amb aquesta estructura. La plataforma el converteix en un correu HTML compatible amb tots els programes (Outlook inclòs), d'una amplada de 600 px que s'adapta al mòbil.

```json
{
    "format": "soportic-mailing-template",
    "version": 1,
    "name": "Nom de la plantilla",
    "primary": "ca",
    "langs": {
        "ca": {
            "subject": "Assumpte del correu",
            "preheader": "Resum que es veu a la safata d'entrada",
            "doc": {
                "settings": {
                    "…": "…"
                },
                "blocks": [
                    {
                        "type": "header",
                        "…": "…"
                    },
                    {
                        "type": "heading",
                        "…": "…"
                    }
                ]
            }
        }
    }
}
```

## Regles

- `langs`: un objecte per idioma (`ca`, `es`, `en`, `fr`). `primary` és l'idioma principal. Pots fer-ne un de sol.
- Els blocs es dibuixen en ordre, de dalt a baix. Màxim 150 blocs. Cada bloc té `type` i els seus camps; els que no posis prenen el valor per defecte.
- Colors sempre en format `"#rrggbb"`. Mides en píxels (números).
- Imatges: només adreces `https://` públiques (o `"brand:logo"` per al logotip del projecte). Sempre amb `alt`.
- Camps del contacte dins dels textos: `{{nom}}`, `{{cognoms}}`, `{{nom_complet}}`, `{{email}}`, `{{camp:clau}}`; amb valor per defecte: `{{nom|amiga}}`.
- No cal afegir l'enllaç de baixa ni l'adreça postal: la plataforma afegeix sola un peu legal a tots els correus.
- Recomanat: `header` al principi i `footer` al final; botons amb `href`; textos curts i escanejables.

## Estils generals (`settings`)

| Camp | Per defecte | Descripció |
|---|---|---|
| `bodyBg` | `"#ffffff"` | fons de fora del correu |
| `contentBg` | `"#ffffff"` | fons del correu |
| `font` | `"arial"` | tipus de lletra: "arial" | "segoe" | "verdana" | "georgia" |
| `textColor` | `"#000000"` | color del text |
| `textSize` | `16` | mida del text, px (12-22) |
| `headingColor` | `"#333333"` | color dels subtítols i títols de columna |
| `linkColor` | `"#ac1966"` | color dels enllaços |
| `accent` | `"#ac1966"` | color dels botons |
| `padX` | `20` | marges laterals, px (0-48) |

## Tipus de bloc

### `header` — Capçalera

```json
{"type":"header","src":"brand:logo","alt":"","width":220,"align":"center","href":"","borderTop":"#ac1966","borderWidth":0,"pt":20,"pb":20,"bg":"","hideMobile":false}
```

- `src`: imatge: adreça https://… o "brand:logo" (logotip del projecte)
- `alt`: text alternatiu de la imatge
- `width`: amplada de la imatge en px (0 = tota l'amplada)
- `align`: "left" | "center" | "right"
- `href`: enllaç: https://…, mailto:…, tel:… o un camp com {{unsubscribe_url}}
- `borderTop`: color de la línia superior o ""
- `borderWidth`: gruix de la línia superior, px

### `heading` — Títol

```json
{"type":"heading","text":"Títol","level":1,"size":18,"color":"#333333","align":"left","pt":20,"pb":6,"bg":"","hideMobile":false}
```

- `text`: text pla
- `level`: 1 = títol, 2 = subtítol
- `size`: mida del text en px (0 = la general)
- `color`: color del text "#rrggbb" o "" (el general)
- `align`: "left" | "center" | "right"

### `text` — Text

```json
{"type":"text","html":"<p>Escriu aquí el text.</p>","size":0,"color":"","align":"left","pt":10,"pb":10,"bg":"","hideMobile":false}
```

- `html`: text amb format: només <p>, <br>, <strong>, <em>, <u>, <s>, <a href>, <ul>, <ol>, <li>, <h3>, <h4>, <blockquote>
- `size`: mida del text en px (0 = la general)
- `color`: color del text "#rrggbb" o "" (el general)
- `align`: "left" | "center" | "right"

### `image` — Imatge

```json
{"type":"image","src":"","alt":"","width":0,"align":"center","href":"","radius":0,"pt":10,"pb":10,"bg":"","hideMobile":false}
```

- `src`: imatge: adreça https://… o "brand:logo" (logotip del projecte)
- `alt`: text alternatiu de la imatge
- `width`: amplada de la imatge en px (0 = tota l'amplada)
- `align`: "left" | "center" | "right"
- `href`: enllaç: https://…, mailto:…, tel:… o un camp com {{unsubscribe_url}}
- `radius`: cantonades arrodonides, px

### `button` — Botó

```json
{"type":"button","text":"Més informació","href":"","btnBg":"","color":"#ffffff","align":"center","full":false,"mobileFull":true,"radius":8,"size":16,"pt":16,"pb":16,"bg":"","hideMobile":false}
```

- `text`: text pla
- `href`: enllaç: https://…, mailto:…, tel:… o un camp com {{unsubscribe_url}}
- `btnBg`: color del botó o "" (el d'accent)
- `color`: color del text "#rrggbb" o "" (el general)
- `align`: "left" | "center" | "right"
- `full`: botó de tota l'amplada (true/false)
- `mobileFull`: al mòbil, tota l'amplada (true/false)
- `radius`: cantonades arrodonides, px
- `size`: mida del text en px (0 = la general)

### `link` — Enllaç

```json
{"type":"link","text":"Més informació >","href":"","color":"","bold":true,"align":"left","size":0,"pt":10,"pb":10,"bg":"","hideMobile":false}
```

- `text`: text pla
- `href`: enllaç: https://…, mailto:…, tel:… o un camp com {{unsubscribe_url}}
- `color`: color del text "#rrggbb" o "" (el general)
- `bold`: negreta (true/false)
- `align`: "left" | "center" | "right"
- `size`: mida del text en px (0 = la general)

### `box` — Requadre

```json
{"type":"box","html":"<p><strong>Text destacat</strong></p>","boxBg":"#f9eaf2","color":"","radius":10,"size":0,"pt":10,"pb":10,"bg":"","hideMobile":false}
```

- `html`: text amb format: només <p>, <br>, <strong>, <em>, <u>, <s>, <a href>, <ul>, <ol>, <li>, <h3>, <h4>, <blockquote>
- `boxBg`: color de fons del requadre
- `color`: color del text "#rrggbb" o "" (el general)
- `radius`: cantonades arrodonides, px
- `size`: mida del text en px (0 = la general)

### `columns` — Columnes

```json
{"type":"columns","count":2,"cols":[{"src":"","alt":"","title":"Títol","html":"<p>Text de la columna.</p>","linkText":"","href":""},{"src":"","alt":"","title":"Títol","html":"<p>Text de la columna.</p>","linkText":"","href":""}],"pt":10,"pb":10,"bg":"","hideMobile":false}
```

- `count`: nombre de columnes: 2 o 3
- `cols`: llista de columnes: {src, alt, title, html, linkText, href}

### `imagetext` — Imatge i text

```json
{"type":"imagetext","side":"left","src":"","alt":"","title":"Títol","html":"<p>Text de la columna.</p>","linkText":"","href":"","pt":10,"pb":10,"bg":"","hideMobile":false}
```

- `side`: imatge a "left" o "right"
- `src`: imatge: adreça https://… o "brand:logo" (logotip del projecte)
- `alt`: text alternatiu de la imatge
- `title`: títol
- `html`: text amb format: només <p>, <br>, <strong>, <em>, <u>, <s>, <a href>, <ul>, <ol>, <li>, <h3>, <h4>, <blockquote>
- `linkText`: text de l'enllaç (opcional)
- `href`: enllaç: https://…, mailto:…, tel:… o un camp com {{unsubscribe_url}}

### `divider` — Separador

```json
{"type":"divider","color":"#cccccc","thickness":1,"widthPct":100,"pt":10,"pb":10,"bg":"","hideMobile":false}
```

- `color`: color del text "#rrggbb" o "" (el general)
- `thickness`: gruix de la línia, px
- `widthPct`: amplada de la línia, % (10-100)

### `spacer` — Espai

```json
{"type":"spacer","height":24,"pt":0,"pb":0,"bg":"","hideMobile":false}
```

- `height`: alçada, px (4-160)

### `footer` — Peu

```json
{"type":"footer","src":"brand:logo","alt":"","width":0,"name":"","links":[],"note":"","lineColor":"#e5e7eb","color":"#8a909c","linkColor":"","pt":32,"pb":32,"bg":"","hideMobile":false}
```

- `src`: imatge: adreça https://… o "brand:logo" (logotip del projecte)
- `alt`: text alternatiu de la imatge
- `width`: amplada de la imatge en px (0 = tota l'amplada)
- `name`: nom de l'organització
- `links`: fins a 6 enllaços {text, href}
- `note`: text petit legal (salts de línia permesos)
- `lineColor`: color de la línia superior o ""
- `color`: color del text "#rrggbb" o "" (el general)
- `linkColor`: color dels enllaços o "" (el general)

### `event` — Esdeveniment

```json
{"type":"event","refId":"","title":"Nom de l'esdeveniment","when":"","day":"","month":"","location":"","desc":"","btnText":"Més informació >","href":"","boxBg":"#f6f7f9","pt":12,"pb":12,"bg":"","hideMobile":false}
```

- `refId`: deixa-ho buit ""
- `title`: títol
- `when`: quan (text)
- `day`: dia, p. ex. "14" (requadre de color)
- `month`: mes curt, p. ex. "nov."
- `location`: on (text)
- `desc`: descripció (text pla, salts de línia permesos)
- `btnText`: text de l'enllaç
- `href`: enllaç: https://…, mailto:…, tel:… o un camp com {{unsubscribe_url}}
- `boxBg`: color de fons del requadre

### `post` — Article

```json
{"type":"post","refId":"","title":"Títol de l'article","desc":"Resum de l'article.","date":"","src":"","alt":"","layout":"card","btnText":"Llegeix-lo >","href":"","pt":12,"pb":12,"bg":"","hideMobile":false}
```

- `refId`: deixa-ho buit ""
- `title`: títol
- `desc`: descripció (text pla, salts de línia permesos)
- `date`: data (text)
- `src`: imatge: adreça https://… o "brand:logo" (logotip del projecte)
- `alt`: text alternatiu de la imatge
- `layout`: imatge "card" (a dalt) o "side" (al costat)
- `btnText`: text de l'enllaç
- `href`: enllaç: https://…, mailto:…, tel:… o un camp com {{unsubscribe_url}}

Tots els blocs admeten també `pt`, `pb` (espais, px), `bg` (fons de la franja) i `hideMobile`.

## Exemple complet

```json
{
    "format": "soportic-mailing-template",
    "version": 1,
    "name": "Butlletí mensual",
    "primary": "ca",
    "langs": {
        "ca": {
            "subject": "Novetats de {{nom|la comunitat}}: el que ha passat aquest mes",
            "preheader": "Tres notícies, un esdeveniment i una oferta per a tu",
            "doc": {
                "settings": {
                    "bodyBg": "#f4f5f7",
                    "contentBg": "#ffffff",
                    "font": "arial",
                    "textColor": "#1f2430",
                    "textSize": 16,
                    "headingColor": "#0b5cab",
                    "linkColor": "#0b5cab",
                    "accent": "#0b5cab",
                    "padX": 24
                },
                "blocks": [
                    {
                        "type": "header",
                        "src": "brand:logo",
                        "alt": "Logotip",
                        "width": 180,
                        "align": "center"
                    },
                    {
                        "type": "image",
                        "src": "https://images.example.com/portada.jpg",
                        "alt": "Equip treballant",
                        "radius": 8
                    },
                    {
                        "type": "heading",
                        "text": "Hola {{nom|amiga}}!",
                        "level": 1,
                        "size": 26,
                        "align": "left"
                    },
                    {
                        "type": "text",
                        "html": "<p>Aquest mes hem estrenat <strong>tres novetats</strong> que et volem explicar.</p><ul><li>Nou servei de formació</li><li>Horaris ampliats</li><li>App renovada</li></ul>"
                    },
                    {
                        "type": "button",
                        "text": "Descobreix-les",
                        "href": "https://example.com/novetats",
                        "align": "center"
                    },
                    {
                        "type": "divider",
                        "color": "#e5e7eb"
                    },
                    {
                        "type": "columns",
                        "count": 2,
                        "cols": [
                            {
                                "src": "https://images.example.com/a.jpg",
                                "alt": "Formació",
                                "title": "Formació",
                                "html": "<p>Cursos en línia cada dijous.</p>",
                                "linkText": "Inscriu-t'hi >",
                                "href": "https://example.com/formacio"
                            },
                            {
                                "src": "https://images.example.com/b.jpg",
                                "alt": "App",
                                "title": "Nova app",
                                "html": "<p>Més ràpida i més fàcil.</p>",
                                "linkText": "Descarrega-la >",
                                "href": "https://example.com/app"
                            }
                        ]
                    },
                    {
                        "type": "event",
                        "title": "Jornada de portes obertes",
                        "day": "14",
                        "month": "des.",
                        "when": "Dissabte 14 de desembre, 10-14 h",
                        "location": "Carrer Major, 1 · Barcelona",
                        "btnText": "Apunta-t'hi >",
                        "href": "https://example.com/jornada"
                    },
                    {
                        "type": "box",
                        "html": "<p><strong>Oferta per a contactes:</strong> un 10 % de descompte fins a final de mes.</p>",
                        "boxBg": "#eef4ff"
                    },
                    {
                        "type": "footer",
                        "src": "brand:logo",
                        "width": 0,
                        "name": "Organització",
                        "links": [
                            {
                                "text": "Web",
                                "href": "https://example.com"
                            },
                            {
                                "text": "Contacte",
                                "href": "mailto:info@example.com"
                            }
                        ]
                    }
                ]
            }
        }
    }
}
```
