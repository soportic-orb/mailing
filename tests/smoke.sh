#!/usr/bin/env bash
# End-to-end smoke test against a running instance (php -S) and the mock server.
#   php -S 127.0.0.1:8080 -t public public/index.php &
#   php -S 127.0.0.1:8099 tests/mock_server.php &
#   bash tests/smoke.sh
set -u
B=${B:-http://127.0.0.1:8080}
DB=${DB_NAME:-mailing}
MYSQL=${MYSQL:-"mysql -uroot"}
J=$(mktemp); FAIL=0
ok()   { echo "  ✓ $1"; }
bad()  { echo "  ✗ $1"; FAIL=1; }
tok()  { curl -s -c $J -b $J "$B$1" | grep -oE 'name="csrf-token" content="[^"]*"|name="_csrf" value="[^"]*"' | head -1 | sed -E 's/.*(content|value)="([^"]*)"/\2/'; }
post() { local url=$1; shift; curl -s -c $J -b $J -X POST "$B$url" --data-urlencode "_csrf=$T" "$@" -o /dev/null -w "%{http_code} %{redirect_url}"; }
ajax() { local url=$1; shift; curl -s -c $J -b $J -X POST "$B$url" -H "X-CSRF-TOKEN: $T" -H "X-Requested-With: XMLHttpRequest" -H "Accept: application/json" "$@"; }
sql()  { $MYSQL --default-character-set=utf8mb4 -N $DB -e "$1"; }
expect() { [ "$2" = "$3" ] && ok "$1 ($3)" || bad "$1: esperat «$3», obtingut «$2»"; }

echo "== Sessió"
T=$(tok /login)
r=$(post /login --data-urlencode email=admin@soportic.es --data-urlencode password=contrasenya123); [[ $r == 302* ]] && ok "login" || bad "login $r"
T=$(tok /)
post /projecte -d project_id=1 >/dev/null

echo "== Configuració i remitents"
post /configuracio -d section=mailjet -d mailjet_key=KEY123 -d mailjet_secret=SECRET456 -d send_rate=600 >/dev/null
expect "clau Mailjet xifrada" "$(sql "SELECT secret FROM settings WHERE k='mailjet_key'")" "1"
post /configuracio -d section=ai -d ai_enabled=1 -d anthropic_key=sk-ant-test -d openai_key=sk-test -d ai_default_text=claude -d claude_model=claude-opus-5-5 -d openai_text_model=gpt-5 -d openai_image_model=gpt-image-1 >/dev/null
post /remitents -d email=info@emergencing.com -d name=Emergencing -d reply_to=hola@emergencing.com -d register=1 >/dev/null
expect "remitent validat a Mailjet" "$(sql "SELECT mailjet_status FROM senders WHERE email='info@emergencing.com'")" "Active"
post /remitents/sincronitza >/dev/null; ok "sincronització de remitents"

echo "== Llistes, camps i importació"
post /llistes -d name=Clients -d description=Actius >/dev/null
LIST=$(sql "SELECT id FROM lists WHERE name='Clients' AND project_id=1")
printf 'Correu;Nom;Cognoms;Idioma;Empresa\nanna@exemple.cat;Anna;Puig;ca;Hospital del Mar\njoan@exemple.cat;Joan;Vila;es;Clínica Sol\nreject@exemple.cat;Rebutjat;X;;\nmalament-no-es-correu;X;Y;;\nmarta@exemple.cat;Marta;Roca;en;CAP Nord\n' > /tmp/import.csv
UP=$(curl -s -c $J -b $J -X POST "$B/contactes/importa" -F "_csrf=$T" -F "list_id=$LIST" -F "file=@/tmp/import.csv;type=text/csv")
ITOK=$(echo "$UP" | grep -o 'name="token" value="[^"]*"' | cut -d'"' -f4)
echo "$UP" | grep -q 'value="email" selected' && ok "detecta la columna del correu" || bad "mapatge automàtic"
r=$(post /contactes/importa/executa -d token=$ITOK -d filename=import.csv -d "map[0]=email" -d "map[1]=first_name" -d "map[2]=last_name" -d "map[3]=language" -d "map[4]=new" -d "newname[4]=Empresa" -d list_id=$LIST -d overwrite=1 -d consent=1)
expect "contactes importats" "$(sql "SELECT COUNT(*) FROM contacts WHERE project_id=1")" "4"
expect "camp personalitzat creat" "$(sql "SELECT \`key\` FROM fields WHERE project_id=1")" "empresa"
expect "valor del camp" "$(sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(fields,'$.empresa')) FROM contacts WHERE email='anna@exemple.cat'")" "Hospital del Mar"
expect "a la llista" "$(sql "SELECT COUNT(*) FROM list_contacts WHERE list_id=$LIST")" "4"
r=$(post /contactes -d email=pere@exemple.cat -d first_name=Pere -d status=subscribed -d consent=1 -d "lists[]=$LIST"); expect "contacte manual" "$(sql "SELECT COUNT(*) FROM list_contacts WHERE list_id=$LIST")" "5"
curl -s -c $J -b $J "$B/contactes?q=anna" | grep -q 'anna@exemple.cat' && ok "cerca de contactes" || bad "cerca"
curl -s -c $J -b $J "$B/contactes/exporta" | grep -q 'Hospital del Mar' && ok "exportació CSV" || bad "exportació"

echo "== Segments"
C=$(ajax /segments/compta --data-urlencode 'rules={"match":"all","conditions":[{"type":"field","field":"camp:empresa","op":"contains","value":"Hosp"}]}')
expect "compte del segment" "$(echo $C | grep -o '"count":[0-9]*')" '"count":1'
post /segments --data-urlencode name=Hospitals --data-urlencode 'rules={"match":"any","conditions":[{"type":"field","field":"camp:empresa","op":"contains","value":"Hosp"},{"type":"field","field":"language","op":"eq","value":"en"}]}' >/dev/null
SEG=$(sql "SELECT id FROM segments WHERE name='Hospitals'")
curl -s -c $J -b $J "$B/segments" | grep -q '<b>2</b>' && ok "segment amb 2 contactes" || bad "segment"

echo "== Campanya i editor"
r=$(post /campanyes -d name="Butlletí de novembre" -d sender_id=1 -d template_id=)
CID=$(sql "SELECT MAX(id) FROM campaigns")
[[ $r == *"/campanyes/$CID/editor"* ]] && ok "campanya creada → editor" || bad "creació $r"
ED=$(curl -s -c $J -b $J "$B/campanyes/$CID/editor")
echo "$ED" | grep -q 'id="me-cfg"' && ok "pàgina de l'editor" || bad "editor"
DOC=$(echo "$ED" | sed -n 's/.*<script type="application\/json" id="me-cfg">\(.*\)<\/script>.*/\1/p' | php -r '$c=json_decode(stream_get_contents(STDIN),true); $d=$c["doc"]; $p=$d["primary"]; $d["langs"][$p]["subject"]="Hola {{nom|amiga}}, novetats de novembre"; $d["langs"][$p]["preheader"]="Tot el que ha passat aquest mes"; $d["langs"][$p]["doc"]["blocks"][2]["html"]="<p>Hola {{nom}}, de {{camp:empresa}}.</p><script>alert(1)</script>"; $d["langs"][$p]["doc"]["blocks"][3]["href"]="https://emergencing.com/novetats"; echo json_encode($d, JSON_UNESCAPED_UNICODE);')
S=$(ajax /campanyes/$CID/doc -H "Content-Type: application/json" -d "{\"doc\":$DOC,\"base_version\":1}")
echo "$S" | grep -q '"version":2' && ok "desar el document (versió 2)" || bad "desar: $S"
S=$(ajax /campanyes/$CID/doc -H "Content-Type: application/json" -d "{\"doc\":$DOC,\"base_version\":1}")
echo "$S" | grep -q 'versió més nova' && ok "detecta conflicte de versions" || bad "conflicte: $S"
sql "SELECT doc FROM campaigns WHERE id=$CID" | grep -q '<script>' && bad "script no filtrat" || ok "HTML filtrat (sense <script>)"
TR=$(ajax /editor/tradueix -H "Content-Type: application/json" -d "{\"from\":\"ca\",\"to\":\"es\",\"subject\":\"Hola\",\"preheader\":\"Resum\",\"doc\":$(echo $DOC | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode($d["langs"][$d["primary"]]["doc"]);')}")
echo "$TR" | grep -q '"subject":"\[ES\] Hola"' && ok "traducció automàtica" || bad "traducció: ${TR:0:200}"
DOC2=$(echo "$DOC" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $tr=json_decode($argv[1],true); $d["langs"]["es"]=["subject"=>$tr["subject"],"preheader"=>$tr["preheader"],"doc"=>$tr["doc"]]; echo json_encode($d, JSON_UNESCAPED_UNICODE);' "$TR")
S=$(ajax /campanyes/$CID/doc -H "Content-Type: application/json" -d "{\"doc\":$DOC2,\"base_version\":2}")
echo "$S" | grep -q '"version":3' && ok "desar amb dos idiomes" || bad "desar idiomes: $S"
AI=$(ajax /editor/ia/text -H "Content-Type: application/json" -d '{"provider":"claude","prompt":"Escriu una benvinguda","format":"paragraphs"}')
echo "$AI" | grep -q 'Text generat' && ok "IA: text" || bad "IA text: $AI"
IM=$(ajax /editor/ia/imatge -F prompt="Un hospital" -F shape=landscape -F style=photo -F no_text=1)
echo "$IM" | grep -q '"src":"img:' && ok "IA: imatge (OpenAI) desada" || bad "IA imatge: $IM"
IMG=$(echo "$IM" | grep -o '/img/[A-Za-z0-9]*' | head -1); expect "imatge pública" "$(curl -s -o /dev/null -w '%{http_code} %{content_type}' $B$IMG)" "200 image/jpeg"
PV=$(curl -s -c $J -b $J "$B/campanyes/$CID/previsualitza?lang=es")
echo "$PV" | grep -q '{{unsubscribe_url}}' && ok "previsualització amb peu de baixa" || bad "previsualització"
echo "$PV" | grep -q 'lang="es"' && ok "versió en castellà" || bad "lang"
H=$(curl -s -c $J -b $J "$B/campanyes/$CID/versions"); echo "$H" | grep -q '"version":3' && ok "historial de versions" || bad "versions"
post /projectes/1 -d name=Emergencing -d slug=emergencing -d website=https://emergencing.com -d company="Soportic SL" --data-urlencode "postal_address=Carrer Major 1, Barcelona" -d default_language=ca --data-urlencode "color=#d6336c" --data-urlencode "footer_why[ca]=Reps aquest butlletí perquè ets client de {projecte}." >/dev/null
PV=$(curl -s -c $J -b $J "$B/campanyes/$CID/previsualitza?lang=ca"); echo "$PV" | grep -q 'Reps aquest butlletí perquè ets client de Emergencing.' && ok "text legal del peu personalitzat" || bad "text del peu"
PV=$(curl -s -c $J -b $J "$B/campanyes/$CID/previsualitza?lang=es"); echo "$PV" | grep -q 'Recibes este correo porque formas parte de los contactos de Emergencing.' && ok "els altres idiomes mantenen el text per defecte" || bad "text per defecte"

r=$(post /campanyes -d name="Campanya en castellà" -d sender_id=1 -d language=es)
CES=$(sql "SELECT MAX(id) FROM campaigns")
expect "idioma triat en crear la campanya" "$(sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(doc,'$.primary')) FROM campaigns WHERE id=$CES")" "es"
curl -s -c $J -b $J "$B/campanyes/$CES/previsualitza" | grep -q 'Darte de baja de esta lista' && ok "peu legal en castellà" || bad "peu en castellà"
post /plantilles -d name="Template EN" -d language=en >/dev/null
TEN=$(sql "SELECT id FROM templates WHERE name='Template EN'")
curl -s -c $J -b $J "$B/plantilles/$TEN/previsualitza" | grep -q 'Unsubscribe from this list' && ok "plantilla: peu legal en anglès" || bad "peu en anglès"
r=$(post /campanyes -d name="Des de plantilla EN en català" -d template_id=$TEN -d language=ca)
CFT=$(sql "SELECT MAX(id) FROM campaigns")
expect "plantilla en un altre idioma: el correu queda en l'idioma triat" "$(sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(doc,'$.primary')) FROM campaigns WHERE id=$CFT")" "ca"
post /campanyes/$CES/elimina >/dev/null; post /campanyes/$CFT/elimina >/dev/null

echo "== Destinataris, comprovacions i enviament"
post /campanyes/$CID -d name="Butlletí de novembre" -d sender_id=1 -d "lists[]=$LIST" -d "segments[]=$SEG" >/dev/null
curl -s -c $J -b $J "$B/campanyes/$CID/destinataris?lists[]=$LIST" | grep -q '"count":5' && ok "recompte de destinataris" || bad "recompte"
r=$(ajax /campanyes/$CID/prova -H "Content-Type: application/json" -d '{"emails":"proves@soportic.es"}'); echo "$r" | grep -q '"ok":true' && ok "correu de prova" || bad "prova: $r"
grep -q '"Subject":"\[PROVA\] Hola amiga' storage/logs/mock.log && ok "camps a la prova ({{nom|amiga}})" || bad "camps prova"
D=$(date -d tomorrow +%Y-%m-%d)
post /campanyes/$CID/programa -d date=$D -d time=10:30 >/dev/null
expect "programada" "$(sql "SELECT status FROM campaigns WHERE id=$CID")" "scheduled"
curl -s -c $J -b $J "$B/calendari?mes=$(date -d tomorrow +%Y-%m)" | grep -q "Butlletí de novembre" && ok "apareix al calendari" || bad "calendari"
D2=$(date -d '+3 days' +%Y-%m-%d)
r=$(ajax /campanyes/$CID/data -H "Content-Type: application/json" -d "{\"date\":\"$D2\"}"); echo "$r" | grep -q '"ok":true' && ok "reprogramar arrossegant" || bad "moure: $r"
post /campanyes/$CID/desprograma >/dev/null
r=$(post /campanyes/$CID/programa -d when=now)
php bin/worker.php -v > /tmp/worker.json; cat /tmp/worker.json | tr -d '\n ' | head -c 300; echo
expect "campanya enviada" "$(sql "SELECT status FROM campaigns WHERE id=$CID")" "sent"
expect "missatges enviats" "$(sql "SELECT COUNT(*) FROM messages WHERE campaign_id=$CID AND status='sent'")" "4"
expect "rebutjat per Mailjet" "$(sql "SELECT COUNT(*) FROM messages WHERE campaign_id=$CID AND status='failed'")" "1"
expect "idioma per contacte" "$(sql "SELECT language FROM messages WHERE campaign_id=$CID AND email='joan@exemple.cat'")" "es"
grep '"/v3.1/send"\|POST /v3.1/send' storage/logs/mock.log | tail -1 | grep -q 'Hola Anna, de Hospital del Mar' && ok "camps personalitzats al correu" || bad "merge tags"
grep 'POST /v3.1/send' storage/logs/mock.log | tail -1 | grep -q 'List-Unsubscribe-Post' && ok "capçalera de baixa en un clic" || bad "List-Unsubscribe"
grep 'POST /v3.1/send' storage/logs/mock.log | tail -1 | grep -q '\[ES\] Hola Joan' && ok "Joan rep el correu en castellà" || bad "castellà"

echo "== Idioma forçat a la campanya"
post /campanyes/$CID/duplica >/dev/null
CFL=$(sql "SELECT MAX(id) FROM campaigns")
post /campanyes/$CFL -d name="Butlletí forçat" -d sender_id=1 -d "lists[]=$LIST" -d "segments[]=$SEG" -d lang=xx >/dev/null
expect "idioma no vàlid: automàtic" "$(sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(audience,'$.lang')) FROM campaigns WHERE id=$CFL")" ""
post /campanyes/$CFL -d name="Butlletí forçat" -d sender_id=1 -d "lists[]=$LIST" -d "segments[]=$SEG" -d lang=es >/dev/null
expect "idioma forçat desat" "$(sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(audience,'$.lang')) FROM campaigns WHERE id=$CFL")" "es"
P=$(curl -s -c $J -b $J $B/campanyes/$CFL)
echo "$P" | grep -q 'value="es" selected>Tothom en' && ok "selector d'idioma a la configuració" || bad "selector idioma"
echo "$P" | grep -q 'idioma forçat' && ok "comprovacions amb l'idioma forçat" || bad "comprovació idioma forçat"
post /campanyes/$CFL/programa -d when=now >/dev/null
php bin/worker.php >/dev/null
expect "idioma forçat: tothom en castellà" "$(sql "SELECT COUNT(*) FROM messages WHERE campaign_id=$CFL AND language<>'es'")" "0"
expect "idioma forçat: hi ha destinataris" "$(sql "SELECT COUNT(*) FROM messages WHERE campaign_id=$CFL")" "5"
grep 'POST /v3.1/send' storage/logs/mock.log | tail -1 | grep -q '\[ES\] Hola Anna' && ok "Anna (català) rep el castellà" || bad "forçat castellà"
post /campanyes/$CFL/elimina >/dev/null

echo "== Seguiment (webhooks)"
WK=$(sql "SELECT v FROM settings WHERE k='webhook_key'")
M1=$(sql "SELECT id FROM messages WHERE campaign_id=$CID AND email='anna@exemple.cat'")
M2=$(sql "SELECT id FROM messages WHERE campaign_id=$CID AND email='joan@exemple.cat'")
M3=$(sql "SELECT id FROM messages WHERE campaign_id=$CID AND email='marta@exemple.cat'")
NOW=$(date +%s)
curl -s -X POST "$B/webhook/mailjet?key=$WK" -H "Content-Type: application/json" -d "[{\"event\":\"sent\",\"CustomID\":\"m$M1\",\"time\":$NOW},{\"event\":\"open\",\"CustomID\":\"m$M1\",\"time\":$NOW},{\"event\":\"click\",\"CustomID\":\"m$M1\",\"url\":\"https://emergencing.com/novetats\",\"time\":$NOW},{\"event\":\"bounce\",\"CustomID\":\"m$M2\",\"hard_bounce\":true,\"error\":\"user unknown\",\"time\":$NOW},{\"event\":\"spam\",\"CustomID\":\"m$M3\",\"time\":$NOW}]" | grep -q '"processed":5' && ok "5 esdeveniments processats" || bad "webhook"
expect "webhook sense clau" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/webhook/mailjet?key=x" -d '[]')" "403"
expect "rebot dur → contacte exclòs" "$(sql "SELECT status FROM contacts WHERE email='joan@exemple.cat'")" "bounced"
expect "queixa → contacte exclòs" "$(sql "SELECT status FROM contacts WHERE email='marta@exemple.cat'")" "complained"
RP=$(curl -s -c $J -b $J "$B/campanyes/$CID/informe")
echo "$RP" | grep -q 'emergencing.com/novetats' && ok "informe: enllaç clicat" || bad "informe enllaços"
echo "$RP" | grep -q '25,0 %' && ok "informe: taxa d'obertura (1/4)" || bad "informe taxa"
curl -s -c $J -b $J "$B/campanyes/$CID/destinataris.csv" | grep -q 'anna@exemple.cat' && ok "exportació de destinataris" || bad "csv"

echo "== Baixa i «veure al navegador»"
UT=$(php -r 'require "src/bootstrap.php"; echo Mailing\Services\Links::token((int)$argv[1]);' $M1)
curl -s "$B/u/$UT" | grep -q 'Vols deixar de rebre' && ok "pàgina de baixa" || bad "baixa GET"
curl -s "$B/v/$UT" | grep -q 'Hola Anna' && ok "veure al navegador amb camps" || bad "view"
curl -s "$B/u/$UT" | grep -q 'Dona&#039;m de baixa d&#039;aquesta llista' && ok "ofereix la baixa de la llista" || bad "opció de llista"
expect "baixa en un clic (RFC 8058)" "$(curl -s -X POST "$B/u/$UT" -d 'List-Unsubscribe=One-Click')" "OK"
expect "un clic: fora de la llista" "$(sql "SELECT lc.status FROM list_contacts lc JOIN contacts c ON c.id=lc.contact_id WHERE c.email='anna@exemple.cat' AND lc.list_id=$LIST")" "unsubscribed"
expect "un clic: continua subscrita al projecte" "$(sql "SELECT status FROM contacts WHERE email='anna@exemple.cat'")" "subscribed"
curl -s -X POST "$B/u/$UT" -d scope=all | grep -q 'Ja no rebràs més correus' && ok "baixa de tot" || bad "baixa de tot"
expect "contacte de baixa" "$(sql "SELECT status FROM contacts WHERE email='anna@exemple.cat'")" "unsubscribed"
expect "enllaç manipulat" "$(curl -s -o /dev/null -w '%{http_code}' "$B/u/${UT%%.*}.AAAAAAAAAAAA")" "404"

echo "== Sincronització"
post /sincronitzacio -d type=api -d name="Clients API" -d list_id=$LIST -d active=1 >/dev/null
API=$(curl -s -c $J -b $J "$B/sincronitzacio" | grep -o 'mlg_[A-Za-z0-9]*' | head -1)
[ -n "$API" ] && ok "clau d'API generada" || bad "token"
expect "ping API" "$(curl -s -H "Authorization: Bearer $API" $B/api/v1/ping | grep -o '"ok":true')" '"ok":true'
R=$(curl -s -X POST -H "Authorization: Bearer $API" -H "Content-Type: application/json" $B/api/v1/contacts -d '{"contacts":[{"email":"nou@api.cat","first_name":"Nou","language":"ca","external_id":"55","fields":{"pla":"Pro"}},{"email":"anna@exemple.cat","first_name":"Anna"}]}')
echo "$R" | grep -q '"created":1' && ok "API: alta" || bad "API: $R"
expect "API no re-subscriu qui és de baixa" "$(sql "SELECT status FROM contacts WHERE email='anna@exemple.cat'")" "unsubscribed"
expect "API sense clau" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $B/api/v1/contacts -d '{}')" "401"
$MYSQL sourceapp -e "CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY, mail VARCHAR(190), nom VARCHAR(60), ciutat VARCHAR(60), newsletter TINYINT); REPLACE INTO users VALUES (1,'s1@sql.cat','Sílvia','Girona',1),(2,'s2@sql.cat','Sergi','Lleida',1),(3,'s3@sql.cat','Sara','Reus',0);"
post /sincronitzacio -d type=mysql -d name="BD origen" -d new_list="Usuaris SQL" -d host=127.0.0.1 -d port=3306 -d database=sourceapp -d username=mailing -d password=secret123 --data-urlencode "query=SELECT mail AS email, nom AS first_name, id AS external_id, ciutat FROM users WHERE newsletter = 1" -d interval=360 -d mode=mirror -d active=1 -d test=1 >/dev/null
expect "sincronització MySQL" "$(sql "SELECT COUNT(*) FROM contacts WHERE source LIKE 'sync:%' AND email LIKE 's%@sql.cat'")" "2"
expect "columna extra → camp" "$(sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(fields,'$.ciutat')) FROM contacts WHERE email='s1@sql.cat'")" "Girona"
$MYSQL sourceapp -e "UPDATE users SET newsletter=0 WHERE id=2"
SC=$(sql "SELECT id FROM connectors WHERE type='mysql'"); post /sincronitzacio/$SC/executa >/dev/null
expect "mode mirall treu de la llista" "$(sql "SELECT COUNT(*) FROM list_contacts lc JOIN connectors c ON c.list_id=lc.list_id WHERE c.id=$SC")" "1"
post /sincronitzacio -d type=mysql -d name="Perillosa" -d host=127.0.0.1 -d database=sourceapp -d username=mailing -d password=secret123 --data-urlencode "query=DELETE FROM users" -d active=1 -d test=1 >/dev/null
expect "rebutja SQL que no és SELECT" "$(sql "SELECT last_status FROM connectors WHERE name='Perillosa'")" "error"

echo "== Plantilles, usuaris i projectes"
post /campanyes/$CID/com-a-plantilla -d name="Butlletí base" >/dev/null
expect "desa com a plantilla" "$(sql "SELECT COUNT(*) FROM templates WHERE name='Butlletí base'")" "1"
post /usuaris -d name=Editora -d email=editora@soportic.es -d role=editor -d password=contrasenya456 -d active=1 -d "projects[]=2" >/dev/null
J2=$(mktemp); T2=$(curl -s -c $J2 -b $J2 "$B/login" | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
curl -s -c $J2 -b $J2 -X POST $B/login -d "_csrf=$T2&email=editora@soportic.es&password=contrasenya456" -o /dev/null
expect "editora: no veu el projecte 1" "$(curl -s -c $J2 -b $J2 -o /dev/null -w '%{http_code}' $B/campanyes/$CID)" "404"
expect "editora: sense administració" "$(curl -s -c $J2 -b $J2 -o /dev/null -w '%{http_code}' $B/configuracio)" "403"

echo "== Formularis d'alta"
post /formularis -d title="Butlletí Emergencing" -d "lists[]=$LIST" -d "fields[]=first_name" -d "required[]=first_name" -d language=ca -d double_optin=1 -d active=1 >/dev/null
FS=$(sql "SELECT slug FROM forms WHERE title='Butlletí Emergencing'"); expect "adreça del formulari" "$FS" "butlleti-emergencing"
PAGE=$(curl -s "$B/f/$FS"); echo "$PAGE" | grep -q 'name="website"' && ok "pàgina pública amb camp parany" || bad "pàgina pública"
STAMP=$(echo "$PAGE" | grep -o 'name="_t" value="[^"]*"' | cut -d'"' -f4)
curl -s -X POST "$B/f/$FS" --data-urlencode "_t=$STAMP" -d "email=rapid@bot.cat&first_name=Bot&consent=1" | grep -q 'No s&#039;ha pogut enviar' && ok "rebutja enviaments massa ràpids" || bad "temps mínim"
sleep 2
curl -s -X POST "$B/f/$FS" --data-urlencode "_t=$STAMP" -d "email=parany@bot.cat&first_name=Bot&consent=1&website=http://spam" | grep -q 'No s&#039;ha pogut enviar' && ok "rebutja el camp parany" || bad "parany"
curl -s -X POST "$B/f/$FS" --data-urlencode "_t=$STAMP" -d "email=laia@form.cat&consent=1" | grep -q 'Omple el camp' && ok "camp obligatori" || bad "obligatori"
curl -s -X POST "$B/f/$FS" --data-urlencode "_t=$STAMP" -d "email=laia@form.cat&first_name=Laia" | grep -q 'Cal que acceptis' && ok "consentiment obligatori" || bad "consentiment"
R=$(curl -s -X POST "$B/f/$FS" --data-urlencode "_t=$STAMP" -d "email=laia@form.cat&first_name=Laia&consent=1")
echo "$R" | grep -q 'revisa\|Gairebé fet' && ok "doble confirmació: demana confirmar" || bad "doble confirmació: ${R:0:300}"
echo "$R" | grep -q 'class="pf-party"' && bad "no hi ha d'haver celebració abans de confirmar" || ok "sense celebració mentre falta confirmar"
expect "encara no és contacte" "$(sql "SELECT COUNT(*) FROM contacts WHERE email='laia@form.cat'")" "0"
grep 'POST /v3.1/send' storage/logs/mock.log | tail -1 | grep -q 'Confirma la teva subscripció' && ok "correu de confirmació enviat" || bad "correu de confirmació"
TK=$(sql "SELECT token FROM form_signups WHERE email='laia@form.cat'")
CF=$(curl -s "$B/f/$FS/confirma/$TK"); echo "$CF" | grep -q 'Subscripció confirmada' && ok "enllaç de confirmació" || bad "confirmació"
echo "$CF" | grep -q 'class="pf-party"' && echo "$CF" | grep -q 'id="pf-confetti"' && ok "🎉 i confeti en confirmar" || bad "celebració"
expect "apuntada a la llista" "$(sql "SELECT lc.status FROM list_contacts lc JOIN contacts c ON c.id=lc.contact_id WHERE c.email='laia@form.cat' AND lc.list_id=$LIST")" "subscribed"
expect "prova de consentiment" "$(sql "SELECT COUNT(*) FROM events e JOIN contacts c ON c.id=e.contact_id WHERE c.email='laia@form.cat' AND e.type='consent'")" "1"
FID=$(sql "SELECT id FROM forms WHERE slug='$FS'")
post /formularis/$FID -d title="Butlletí Emergencing" -d slug=$FS -d "lists[]=$LIST" -d language=ca -d active=1 >/dev/null
curl -s -X POST "$B/f/$FS" --data-urlencode "_t=$STAMP" -d "email=anna@exemple.cat&consent=1" | grep -q 'Gràcies' && ok "sense doble confirmació: alta directa" || bad "alta directa"
expect "torna a subscriure qui s'apunta" "$(sql "SELECT status FROM contacts WHERE email='anna@exemple.cat'")" "subscribed"
expect "torna a la llista" "$(sql "SELECT lc.status FROM list_contacts lc JOIN contacts c ON c.id=lc.contact_id WHERE c.email='anna@exemple.cat' AND lc.list_id=$LIST")" "subscribed"
curl -s "$B/f/$FS?embed=1" | grep -q 'class="embed"' && ok "versió per incrustar" || bad "embed"

echo "== Importar i exportar plantilles"
SPEC=$(curl -s -c $J -b $J "$B/plantilles/format"); echo "$SPEC" | grep -q 'soportic-mailing-template' && ok "guia del format per a Claude" || bad "guia"
EX=$(echo "$SPEC" | awk '/^## Exemple complet/{f=1} f&&/^```json/{g=1;next} g&&/^```/{exit} g')
R=$(curl -s -c $J -b $J -X POST "$B/plantilles/importa" -F "_csrf=$T" -F "json=Aquí tens la plantilla:
\`\`\`json
$EX
\`\`\`" -o /dev/null -w '%{redirect_url}')
TID=$(sql "SELECT id FROM templates WHERE name='Butlletí mensual'")
[ -n "$TID" ] && ok "importa el JSON de Claude (amb text i \`\`\`)" || bad "importació: $R"
expect "blocs importats" "$(sql "SELECT JSON_LENGTH(doc, '$.langs.ca.doc.blocks') FROM templates WHERE id=$TID")" "10"
OUT=$(curl -s -c $J -b $J "$B/plantilles/$TID/exporta"); echo "$OUT" | grep -q '"format": "soportic-mailing-template"' && ok "exporta en el mateix format" || bad "exportació"
echo "$OUT" > /tmp/exported.json
curl -s -c $J -b $J -X POST "$B/plantilles/importa" -F "_csrf=$T" -F "file=@/tmp/exported.json;type=application/json" -F "name=Reimportada" -o /dev/null
expect "torna a importar l'exportació" "$(sql "SELECT JSON_LENGTH(doc, '$.langs.ca.doc.blocks') FROM templates WHERE name='Reimportada'")" "10"
curl -s -c $J -b $J -X POST "$B/plantilles/importa" -F "_csrf=$T" -F 'json={"format":"altra-cosa"}' -o /dev/null
expect "rebutja un format desconegut" "$(sql "SELECT COUNT(*) FROM templates WHERE name='Plantilla importada'")" "0"

TJ='{"format":"soportic-mailing-template","version":1,"name":"Bilingue","primary":"ca","langs":{"ca":{"subject":"Hola","preheader":"","doc":{"blocks":[{"type":"heading","text":"Títol en català"}]}},"es":{"subject":"Hola","preheader":"","doc":{"blocks":[{"type":"heading","text":"Título en castellano"}]}}}}'
curl -s -c $J -b $J -X POST "$B/plantilles/importa" -F "_csrf=$T" -F "json=$TJ" -o /dev/null
TB=$(sql "SELECT id FROM templates WHERE name='Bilingue'")
curl -s -c $J -b $J "$B/plantilles/$TB/previsualitza?lang=es" | grep -q 'Título en castellano' && ok "previsualització de plantilla en l'idioma triat" || bad "previsualització per idioma"
curl -s -c $J -b $J "$B/plantilles/$TB/previsualitza" | grep -q 'Títol en català' && ok "sense idioma: el principal" || bad "idioma principal"
curl -s -c $J -b $J "$B/plantilles" | grep -q "previsualitza?lang=es" && ok "enllaç per idioma al llistat" || bad "enllaços per idioma"

echo "== Actualització OTA i còpies"
post /actualitzacio/copia >/dev/null; ls storage/backups/*.sql.gz >/dev/null 2>&1 && ok "còpia de seguretat" || bad "còpia"

[ $FAIL = 0 ] && echo "TOT CORRECTE" || echo "HI HA ERRADES"
exit $FAIL
