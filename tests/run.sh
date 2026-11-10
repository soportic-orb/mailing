#!/usr/bin/env bash
# Fresh install + end-to-end smoke test. Needs PHP 8.1+, a local MySQL/MariaDB
# reachable as root without password (CI service) and curl.
#   bash tests/run.sh
set -eu
cd "$(dirname "$0")/.."
DB=${DB_NAME:-mailing_test}
MYSQL="mysql --default-character-set=utf8mb4 -uroot ${MYSQL_ARGS:-}"
$MYSQL -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'mailing'@'%' IDENTIFIED BY 'secret123'; CREATE USER IF NOT EXISTS 'mailing'@'localhost' IDENTIFIED BY 'secret123'; GRANT ALL ON $DB.* TO 'mailing'@'%'; GRANT ALL ON $DB.* TO 'mailing'@'localhost'; CREATE DATABASE IF NOT EXISTS sourceapp; GRANT SELECT ON sourceapp.* TO 'mailing'@'%'; GRANT SELECT ON sourceapp.* TO 'mailing'@'localhost'; FLUSH PRIVILEGES;"
rm -f .env config/installed.lock storage/logs/mock.log storage/logs/push-*.json
php -S 127.0.0.1:8080 -t public public/index.php > /tmp/mailing-app.log 2>&1 & APP=$!
php -S 127.0.0.1:8099 tests/mock_server.php > /tmp/mailing-mock.log 2>&1 & MOCK=$!
trap 'kill $APP $MOCK 2>/dev/null || true' EXIT
sleep 1
J=$(mktemp); B=http://127.0.0.1:8080/install/
CSRF=$(curl -s -c $J -b $J "$B?pas=bd" | grep -o 'name="csrf" value="[^"]*"' | cut -d'"' -f4)
curl -s -c $J -b $J -X POST "$B?pas=bd" -d "csrf=$CSRF&host=127.0.0.1&port=3306&database=$DB&username=mailing&password=secret123" -o /dev/null
curl -s -c $J -b $J -X POST "$B?pas=admin" --data-urlencode "csrf=$CSRF" -d "name=Admin&email=admin@soportic.es&password=contrasenya123&password2=contrasenya123&tz=Europe/Madrid" --data-urlencode "url=http://127.0.0.1:8080" -o /dev/null
curl -s -c $J -b $J -X POST "$B?pas=mailjet" -d "csrf=$CSRF&mj_key=&mj_secret=" -o /dev/null
test -f config/installed.lock || { echo "La instal·lació ha fallat"; cat /tmp/mailing-app.log; exit 1; }
cat >> .env <<ENV
MAILJET_BASE=http://127.0.0.1:8099
AI_ANTHROPIC_URL=http://127.0.0.1:8099/anthropic
AI_OPENAI_URL=http://127.0.0.1:8099/openai
APP_DEBUG=true
PUSH_ALLOW_HTTP=true
UPDATE_RAW_BASE=http://127.0.0.1:8099/github
ENV
# Two projects, as an administrator would create them.
T=$(curl -s -c $J -b $J http://127.0.0.1:8080/login | grep -o 'name="_csrf" value="[^"]*"' | cut -d'"' -f4)
curl -s -c $J -b $J -X POST http://127.0.0.1:8080/login -d "_csrf=$T&email=admin@soportic.es&password=contrasenya123" -o /dev/null
T=$(curl -s -c $J -b $J http://127.0.0.1:8080/ | grep -o 'name="csrf-token" content="[^"]*"' | cut -d'"' -f4)
curl -s -c $J -b $J -X POST http://127.0.0.1:8080/projectes --data-urlencode "_csrf=$T" -d "name=Emergencing&website=emergencing.com&company=Soportic+SL&default_language=ca" --data-urlencode "color=#d6336c" --data-urlencode "postal_address=Carrer Major 1, Barcelona" -o /dev/null
curl -s -c $J -b $J -X POST http://127.0.0.1:8080/projectes --data-urlencode "_csrf=$T" -d "name=SysRevAI&website=sysrevai.com&default_language=en" --data-urlencode "color=#2563a8" -o /dev/null
# The format guide in docs/ must match the code that generates it.
php bin/console.php template-format | diff -q - docs/format-plantilles.md >/dev/null || { echo "docs/format-plantilles.md no està al dia: php bin/console.php template-format > docs/format-plantilles.md"; exit 1; }
rm -f storage/logs/deprecated-*.log
DB_NAME=$DB MYSQL="mysql -uroot ${MYSQL_ARGS:-}" bash tests/smoke.sh
# Deprecations are only logged in production; in tests they fail the run.
if ls storage/logs/deprecated-*.log >/dev/null 2>&1; then
    echo "Avisos de funcions obsoletes en aquesta versió de PHP:"; cat storage/logs/deprecated-*.log; exit 1
fi
