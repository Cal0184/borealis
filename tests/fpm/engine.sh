#!/bin/bash
# 1.15 · Real check of the internal notification engine on Nginx + PHP-FPM (the HestiaCP stack),
# with NO cron job, NOBODY signed in and NO browser open.
#
#   tests/fpm/engine.sh <installed-app-dir>
#   SERVER=apache tests/fpm/engine.sh <installed-app-dir>   (1.16: Apache + mod_php, the usual
#                                                          shared hosting managed only by FTP)
#
# The app dir must hold an installed Borealis (config/config.php + storage keys), for example the
# one left by tests/e2e/run.mjs. The script copies it to /srv/borealis-fpm with its own copy of
# the database, serves it with nginx (port 8090, name borealis.test) and php-fpm, starts the fake
# push service of the test-suite (port 8098), and then:
#   1. one anonymous GET /login (no session) — the only request a person makes;
#   2. a task due tomorrow is written directly to the database (no web request);
#   3. waits: the engine must create the reminder and deliver it to the «phone» by itself;
#   4. runs php bin/notify.php (a real cron) — the engine must notice and stop.
#   5. 1.16: the hosting kills the engine; a device that receives a notice (/cron/ping, as the
#      service worker does) starts it again, with nobody signed in.
# Needs root and nginx + php8.x-fpm (or apache2 + libapache2-mod-php). Writes /tmp/engine-<server>.json.
set -u
SRC=${1:?app dir}
SERVER=${SERVER:-fpm}
ACCESS=/var/log/borealis-engine-access.log
APP=/srv/borealis-fpm
DB=borealis_fpm
PORT=8090
FS=8098
INTERVAL=${ENGINE_INTERVAL:-15}
FPM=$(ls /usr/sbin/php-fpm* | head -1); V=$(basename "$FPM" | sed 's/php-fpm//')
PASS=0; FAIL=0; OUT=()
check() { if [ "$2" = 1 ]; then PASS=$((PASS+1)); echo "  ✓ $1"; OUT+=("{\"ok\":true,\"name\":$(printf '%s' "$1" | php -r 'echo json_encode(stream_get_contents(STDIN));')}"); else FAIL=$((FAIL+1)); echo "  ✗ $1 ${3:-}"; OUT+=("{\"ok\":false,\"name\":$(printf '%s' "$1" | php -r 'echo json_encode(stream_get_contents(STDIN));'),\"detail\":$(printf '%s' "${3:-}" | php -r 'echo json_encode(stream_get_contents(STDIN));')}"); fi; }
sql() { mysql -uroot -N -B $DB -e "$1"; }

pkill -f "nginx: master" 2>/dev/null; pkill -f php-fpm 2>/dev/null; pkill -f "fake-services/server.php" 2>/dev/null; sleep 1
rm -rf $APP; mkdir -p $APP
(cd "$SRC" && tar --exclude=.git --exclude=node_modules --exclude=tests/e2e/screenshots -cf - .) | tar -xf - -C $APP
rm -f $APP/storage/heartbeat.json $APP/storage/notify.lock
SRCDB=$(php -r "echo (require '$SRC/config/config.php')['db']['database'];")
mysql -uroot -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $DB.* TO 'bt'@'127.0.0.1';"
mysqldump -uroot "$SRCDB" | mysql -uroot $DB
php -r '
$f = "'$APP'/config/config.php"; $c = require $f;
$c["db"]["database"] = "'$DB'";
$c["app"]["url"] = "http://borealis.test:'$PORT'";
$c["cron"] = ["heartbeat_seconds" => '$INTERVAL'];
$c["push"]["test_hosts"] = "127.0.0.1:'$FS'";
file_put_contents($f, "<?php\nreturn " . var_export($c, true) . ";\n");'
grep -q borealis.test /etc/hosts || echo "127.0.0.1 borealis.test" >> /etc/hosts
chown -R www-data:www-data $APP/storage $APP/config

: > $ACCESS; chmod 666 $ACCESS
if [ "$SERVER" = apache ]; then
  apache2ctl stop 2>/dev/null; sleep 1
  a2dismod -q mpm_event >/dev/null 2>&1; a2enmod -q mpm_prefork rewrite php$V >/dev/null 2>&1 || a2enmod -q mpm_prefork rewrite "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" >/dev/null
  cat > /etc/apache2/sites-enabled/borealis-engine.conf <<AP
Listen $PORT
<VirtualHost *:$PORT>
  ServerName borealis.test
  DocumentRoot $APP/public
  <Directory $APP/public>
    AllowOverride All
    Require all granted
  </Directory>
  CustomLog $ACCESS "%h %t \"%r\" %>s"
</VirtualHost>
AP
  rm -f /etc/apache2/sites-enabled/000-default.conf
  apache2ctl start
else
cat > /etc/nginx/sites-enabled/borealis-fpm.conf <<NG
server {
  listen $PORT; server_name borealis.test; access_log $ACCESS;
  root $APP/public; index index.php;
  location / { try_files \$uri /index.php?\$query_string; }
  location ~ \.php\$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; fastcgi_pass unix:/run/php/borealis-fpm.sock; fastcgi_read_timeout 300; }
}
NG
rm -f /etc/nginx/sites-enabled/default
mkdir -p /run/php
cat > /etc/php/$V/fpm/pool.d/borealis.conf <<PL
[borealis]
user = www-data
group = www-data
listen = /run/php/borealis-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
request_terminate_timeout = 300s
PL
rm -f /etc/php/$V/fpm/pool.d/www.conf
$FPM -D && nginx
fi
FAKE_SERVICES_LOG=/tmp/engine-push.jsonl php -S 127.0.0.1:$FS $APP/tests/fake-services/server.php >/dev/null 2>&1 &
sleep 1; curl -s -X POST http://127.0.0.1:$FS/_reset >/dev/null

# a «phone» of Ana, subscribed before (as it would be from a previous visit); keys kept for decryption
php $APP/tests/fpm/phone.php setup $DB "http://127.0.0.1:$FS/push/ana-telefono" > /tmp/engine-phone.json
UID_ANA=$(sql "SELECT id FROM users WHERE email='ana@example.test'")
sql "DELETE FROM sessions" 2>/dev/null
sql "UPDATE system_jobs SET last_started_at = NULL, cli_ok_at = NULL, last_source = NULL"
sql "UPDATE notifications SET pushed_at = UTC_TIMESTAMP(), read_at = UTC_TIMESTAMP() WHERE user_id = $UID_ANA"

# the first visit also runs the «visit» fallback once; what follows must come from the engine
echo "1. Seguridad de la dirección del motor"
check "POST /cron/tick sin clave se rechaza (403)" $([ "$(curl -s -o /dev/null -w '%{http_code}' -X POST http://borealis.test:$PORT/cron/tick)" = 403 ] && echo 1 || echo 0)
check "GET /cron/tick no hace nada (405)" $([ "$(curl -s -o /dev/null -w '%{http_code}' http://borealis.test:$PORT/cron/tick)" = 405 ] && echo 1 || echo 0)

echo "2. Una sola visita anónima, sin iniciar sesión"
T0=$(date +%s)
curl -s -o /dev/null http://borealis.test:$PORT/login
sleep 3
HB=$(cat $APP/storage/heartbeat.json 2>/dev/null)
check "La visita a la página de inicio de sesión arranca el motor" $(echo "$HB" | grep -q '"chain":"[a-f0-9]\{16\}"' && echo 1 || echo 0) "$HB"

echo "3. Tarea que vence mañana, escrita directamente en la base (nadie usa Borealis)"
PID=$(sql "SELECT t.project_id FROM tasks t WHERE t.assignee_id = $UID_ANA LIMIT 1")
STATUS=$(sql "SELECT s.id FROM statuses s JOIN tasks t ON t.status_id = s.id WHERE t.project_id = $PID AND s.category = 'todo' LIMIT 1")
NUM=$(sql "SELECT COALESCE(MAX(number),0)+1 FROM tasks WHERE project_id = $PID")
TZ=$(php -r "echo (require '$APP/config/config.php')['app']['timezone'];")
TOMORROW=$(TZ=$TZ date -d tomorrow +%F)
sql "INSERT INTO tasks (project_id, number, title, status_id, assignee_id, reporter_id, due_date, position, created_at, updated_at) VALUES ($PID, $NUM, 'Entregar el informe del motor', $STATUS, $UID_ANA, $UID_ANA, '$TOMORROW', 9999, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
TID=$(sql "SELECT id FROM tasks WHERE project_id = $PID AND number = $NUM")
echo "   tarea $TID vence $TOMORROW; esperando hasta $((INTERVAL*4+30)) s sin ninguna visita…"
GOT=0
for i in $(seq 1 $((INTERVAL*4+30))); do
  sleep 1
  if php $APP/tests/fpm/phone.php read /tmp/engine-phone.json "http://127.0.0.1:$FS" ana-telefono 2>/dev/null | grep -q 'informe del motor'; then GOT=1; break; fi
done
T1=$(date +%s)
check "Sin cron, sin sesión y sin pestañas abiertas, el aviso llega al teléfono (en $((T1-T0)) s)" $GOT
NOTIF=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id = $UID_ANA AND task_id = $TID")
check "El recordatorio de la tarea se creó en la campana" $([ "$NOTIF" -ge 1 ] && echo 1 || echo 0) "$NOTIF"
MSG=$(php $APP/tests/fpm/phone.php read /tmp/engine-phone.json "http://127.0.0.1:$FS" ana-telefono)
check "El teléfono descifra el aviso y nombra la tarea" $(echo "$MSG" | grep -q 'informe del motor' && echo 1 || echo 0) "$(echo "$MSG" | grep 'informe del motor')"
SRC_=$(sql "SELECT last_source FROM system_jobs WHERE name='notify'")
check "La ejecución quedó registrada como «motor interno»" $([ "$SRC_" = engine ] && echo 1 || echo 0) "$SRC_"
LOGINS=$(grep 'GET /' $ACCESS | grep -vc 'GET /cron/')
check "Entre tanto ninguna persona hizo otra petición (solo la visita inicial)" $([ "$LOGINS" -le 1 ] && echo 1 || echo 0) "GET: $LOGINS"

echo "4. Sigue latiendo"
sleep $((INTERVAL+5))
TICKS=$(php -r '$j = json_decode(file_get_contents("'$APP'/storage/heartbeat.json"), true); echo (int)$j["ticks"];')
check "El motor late de forma continua (≥ 2 latidos)" $([ "$TICKS" -ge 2 ] && echo 1 || echo 0) "ticks=$TICKS"
DUP=$(php $APP/tests/fpm/phone.php read /tmp/engine-phone.json "http://127.0.0.1:$FS" ana-telefono | grep -c 'informe del motor')
check "El mismo aviso no se envía dos veces" $([ "$DUP" = 1 ] && echo 1 || echo 0) "envíos=$DUP"
CHAINS=$(grep -c 'POST /cron/tick' $ACCESS); EXPECT=$(( ( $(date +%s) - T0 ) / INTERVAL + 2 ))
check "Una sola cadena (no se multiplican los latidos)" $([ "$CHAINS" -le "$EXPECT" ] && echo 1 || echo 0) "latidos=$CHAINS máx=$EXPECT"

echo "5. Con un cron real, el motor se detiene solo"
(cd $APP && sudo -u www-data php bin/notify.php >/dev/null 2>&1 || php bin/notify.php >/dev/null 2>&1)
sleep $((INTERVAL+8))
STOP=$(php -r '$j = json_decode(file_get_contents("'$APP'/storage/heartbeat.json"), true); echo $j["stopped"] ?? "";')
check "Al detectar el cron, el motor se detiene («cron»)" $([ "$STOP" = cron ] && echo 1 || echo 0) "stopped=$STOP"

echo "6. 1.16 · El alojamiento corta el motor; un dispositivo que recibe un aviso lo reanuda"
sql "UPDATE system_jobs SET cli_ok_at = NULL, last_source = 'engine', last_started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE)"
php -r '$f = "'$APP'/storage/heartbeat.json"; $j = json_decode(file_get_contents($f), true); $j["beat"] = time() - 900; $j["stopped"] = "error"; $j["attempt"] = time() - 900; file_put_contents($f, json_encode($j));'
OLDCHAIN=$(php -r '$j = json_decode(file_get_contents("'$APP'/storage/heartbeat.json"), true); echo $j["chain"];')
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://borealis.test:$PORT/cron/ping?src=push")
sleep 4
NEWCHAIN=$(php -r '$j = json_decode(file_get_contents("'$APP'/storage/heartbeat.json"), true); echo ($j["stopped"] ?? "") === "" && $j["beat"] >= time() - 30 ? $j["chain"] : "";')
check "/cron/ping (service worker) responde 204 y vuelve a arrancar el motor sin sesión" $([ "$CODE" = 204 ] && [ -n "$NEWCHAIN" ] && [ "$NEWCHAIN" != "$OLDCHAIN" ] && echo 1 || echo 0) "código=$CODE cadena=$NEWCHAIN"
SRC2=$(sql "SELECT last_source FROM system_jobs WHERE name='notify'")
check "…y la tarea programada se ejecutó en ese momento" $([ "$SRC2" = device ] || [ "$SRC2" = engine ] && echo 1 || echo 0) "$SRC2"
CL=$(curl -s -D - -o /dev/null http://borealis.test:$PORT/login | tr -d '\r' | grep -i '^content-length:' | awk '{print $2}')
[ "$SERVER" = apache ] && check "Apache (modo compatible): la página indica su tamaño para que el navegador no espere el trabajo posterior" $([ -n "$CL" ] && echo 1 || echo 0) "content-length=$CL"

echo "Resultado: $PASS correctas, $FAIL con fallas"
( IFS=,; echo "{\"server\":\"$SERVER\",\"passed\":$PASS,\"failed\":$FAIL,\"interval\":$INTERVAL,\"checks\":[${OUT[*]}]}" ) > /tmp/engine-$SERVER.json
cp /tmp/engine-$SERVER.json /tmp/engine.json
if [ "$SERVER" = apache ]; then apache2ctl stop; else nginx -s stop; pkill -f php-fpm; fi
pkill -f "fake-services/server.php"
[ $FAIL = 0 ]
