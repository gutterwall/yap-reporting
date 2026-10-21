#!/usr/bin/env bash
# End-to-end test of the release zips on real servers:
#
#   fake YAP database ──> webservice zip, installed inside a fake YAP folder, over HTTPS
#                          ├─ Apache + PHP-FPM with the shipped .htaccess files (YAP's supported server)
#                          └─ nginx + PHP-FPM with deploy/nginx.conf.example
#   fake BMLT root server ─┘
#   WordPress (latest, default block theme) with the plugin zip ──> headless Chrome as each kind of user
#
# Every figure is compared with the fake backend's oracle (fake-yap/bin/fake-yap expected).
#
# Usage: bin/e2e.sh <work-dir>      (the directory is created; KEEP=1 leaves servers' files behind)
#
# Environment (defaults suit Ubuntu with apache2, nginx, php-fpm and google-chrome installed):
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_ADMIN_USER=root DB_ADMIN_PASS=
#   APACHE=apache2  APACHE_MODULES=/usr/lib/apache2/modules  NGINX=nginx  PHP_FPM=php-fpm8.3  CHROME=google-chrome
#   WP_CORE=<directory with WordPress files> (default: wp core download)   PORT_BASE=8440
#   PLUGIN_ZIP, SERVICE_ZIP (default: this version's zips in dist/, built if missing)
set -euo pipefail

[ $# -eq 1 ] || { sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
ROOT=$(cd "$(dirname "$0")/.." && pwd)
W=$(mkdir -p "$1" && cd "$1" && pwd)
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}
DB_ADMIN_USER=${DB_ADMIN_USER:-root}
DB_ADMIN_PASS=${DB_ADMIN_PASS:-}
APACHE=${APACHE:-apache2}
APACHE_MODULES=${APACHE_MODULES:-/usr/lib/apache2/modules}
NGINX=${NGINX:-nginx}
PHP_FPM=${PHP_FPM:-php-fpm8.3}
P=${PORT_BASE:-8440}
HTTPS_APACHE=$((P + 3)) HTTP_APACHE=$((P + 4)) HTTPS_NGINX=$((P + 5)) WP_PORT=$((P + 6))
SITE="http://127.0.0.1:$WP_PORT"
API="https://127.0.0.1:$HTTPS_APACHE/yap/reporting-api"
TZ_REPORT=America/New_York
fake() { php "$ROOT/fake-yap/bin/fake-yap" "$1" --host="$DB_HOST" --port="$DB_PORT" "${@:2}"; }

pass() { echo "  ok   $*"; }
fail() { echo "  FAIL $*" >&2; exit 1; }
section() { echo; echo "== $*"; }

V=$(sed -n 's/^ \* Version: *//p' "$ROOT/wordpress/yap-reporting/yap-reporting.php")
# PLUGIN_ZIP / SERVICE_ZIP test other zips (for example an older release, to see a test fail).
PLUGIN_ZIP=${PLUGIN_ZIP:-$ROOT/dist/yap-reporting-wordpress-$V.zip}
SERVICE_ZIP=${SERVICE_ZIP:-$ROOT/dist/yap-reporting-webservice-$V.zip}
[ -f "$PLUGIN_ZIP" ] && [ -f "$SERVICE_ZIP" ] || "$ROOT/bin/build.sh" --dev --allow-dirty

PIDS=()
cleanup() {
	for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
	[ -f "$W/run/apache.pid" ] && kill "$(cat "$W/run/apache.pid")" 2>/dev/null || true
	mysql_admin -e "DROP DATABASE IF EXISTS e2e_fake_yap; DROP DATABASE IF EXISTS e2e_wp" 2>/dev/null || true
	if [ "${KEEP:-0}" != 1 ]; then rm -rf "$W"; fi
}
trap cleanup EXIT
mysql_admin() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_ADMIN_USER" ${DB_ADMIN_PASS:+-p"$DB_ADMIN_PASS"} "$@"; }

rm -rf "${W:?}"/*
mkdir -p "$W/run" "$W/logs" "$W/tls" "$W/www/yap" "$W/www/bmlt/main_server/client_interface/json" "$W/nginx"

section "Fake YAP backend"
TODAY=$(TZ=$TZ_REPORT date +%F)
NOW=$(date +%s)
SCENARIO=(--end="$TODAY" --days=100 --seed=5 --now="$NOW" --tz="$TZ_REPORT")
fake install-db --db=e2e_fake_yap --user=e2e_yap --pass=e2e-yap-pass --reader-user=e2e_reader --reader-pass=e2e-reader-pass \
	--admin-user="$DB_ADMIN_USER" --admin-pass="$DB_ADMIN_PASS" --fresh >/dev/null
fake seed --db=e2e_fake_yap --user=e2e_yap --pass=e2e-yap-pass "${SCENARIO[@]}"
fake yap-root --dir="$W/www/yap" --db=e2e_fake_yap --user=e2e_yap --pass=e2e-yap-pass >/dev/null
printf '<?php\nrequire %s;\n' "'$ROOT/fake-yap/bmlt/index.php'" > "$W/www/bmlt/main_server/client_interface/json/index.php"
pass "YAP 4.5.2 schema, $(mysql_admin -N -e 'SELECT COUNT(*) FROM e2e_fake_yap.records_events') events; YAP folder with config.php"

section "Webservice zip installed in the YAP folder"
unzip -q "$SERVICE_ZIP" -d "$W/www/yap"
SVC="$W/www/yap/reporting-api"
printf "<?php\nreturn ['default_timezone' => '%s'];\n" "$TZ_REPORT" > "$SVC/config/reporting-config.php"
php "$SVC/bin/yapr" init >/dev/null
CHECK=$(php "$SVC/bin/yapr" check) || { echo "$CHECK"; fail "bin/yapr check"; }
grep -q "READ ONLY" <<<"$CHECK" && grep -q "yap-config.php" <<<"$CHECK" && pass "bin/yapr check: settings read from YAP's config.php, session READ ONLY" || fail "check output: $CHECK"
grep -q "NO —" <<<"$CHECK" && fail "a metric is unavailable: $CHECK"
pass "every metric available with YAP 4.5.2"
SITE_TOKEN=$(php "$SVC/bin/yapr" client:create --name="E2E WordPress site" --all --allow-unassigned --scopes=summary,call_details,volunteer_stats,volunteer_names | grep -o 'yapr_[0-9a-f]\{12\}_[A-Za-z0-9_-]\{43\}')
AREA_TOKEN=$(php "$SVC/bin/yapr" client:create --name="Riverside only" --areas=1001 --scopes=summary | grep -o 'yapr_[0-9a-f]\{12\}_[A-Za-z0-9_-]\{43\}')
[ -n "$SITE_TOKEN" ] && [ -n "$AREA_TOKEN" ] && pass "credentials created (shown once)" || fail "client:create"
[ "$(stat -c %a "$SVC/data/clients.json")" = 600 ] && [ "$(stat -c %a "$SVC/data/secret.key")" = 600 ] && pass "data/clients.json and secret.key are 0600" || fail "data file permissions"

section "TLS, PHP-FPM, Apache, nginx"
(cd "$W/tls" && openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=E2E test CA" -keyout ca.key -out ca.pem -addext basicConstraints=critical,CA:TRUE >/dev/null 2>&1 \
	&& openssl req -newkey rsa:2048 -nodes -subj "/CN=127.0.0.1" -keyout server.key -out server.csr >/dev/null 2>&1 \
	&& printf 'subjectAltName=IP:127.0.0.1,DNS:localhost\n' > san.ext \
	&& openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 2 -extfile san.ext -out server.pem >/dev/null 2>&1)
cat > "$W/run/fpm.conf" <<EOF
[global]
error_log = $W/logs/fpm.log
pid = $W/run/fpm.pid
daemonize = no
; The YAP server and the WordPress site are separate machines in real life: separate pools, so a WordPress request
; waiting for the webservice never waits for a worker of its own pool.
[yap]
listen = $W/run/fpm-yap.sock
pm = static
pm.max_children = 6
clear_env = no
catch_workers_output = yes
php_admin_value[error_log] = $W/logs/php.log
php_admin_flag[log_errors] = on
[wp]
listen = $W/run/fpm-wp.sock
pm = static
pm.max_children = 6
clear_env = no
catch_workers_output = yes
php_admin_value[error_log] = $W/logs/php.log
php_admin_flag[log_errors] = on
EOF
"$PHP_FPM" -y "$W/run/fpm.conf" -F >"$W/logs/fpm.out" 2>&1 &
PIDS+=($!)

mod() { echo "LoadModule ${1}_module $APACHE_MODULES/mod_$1.so"; }
{
	echo "ServerRoot $W/run"
	echo "ServerName 127.0.0.1"
	echo "PidFile $W/run/apache.pid"
	echo "ErrorLog $W/logs/apache-error.log"
	echo "Mutex file:$W/run"
	echo "<IfModule !mpm_event_module>"; mod mpm_event; echo "</IfModule>"
	echo "<IfModule !unixd_module>"; mod unixd; echo "</IfModule>"
	for m in authz_core authz_host dir mime rewrite proxy proxy_fcgi setenvif ssl socache_shmcb headers env log_config; do
		[ -f "$APACHE_MODULES/mod_$m.so" ] && echo "<IfModule !${m}_module>" && mod "$m" && echo "</IfModule>"
	done
	cat <<EOF
LogFormat "%h \"%r\" %>s" e2e
TypesConfig /dev/null
AddType text/css .css
AddType application/javascript .js
DirectoryIndex index.php index.html
SSLSessionCache shmcb:$W/run/ssl_scache(512000)
<Directory />
	AllowOverride None
	Require all denied
</Directory>
# The webservice as on shared hosting: only the shipped .htaccess files protect it (no vhost rules).
Listen 127.0.0.1:$HTTPS_APACHE
Listen 127.0.0.1:$HTTP_APACHE
Listen 127.0.0.1:$WP_PORT
<Directory $W/www>
	AllowOverride All
	Require all granted
	<FilesMatch "\.php$">
		SetHandler "proxy:unix:$W/run/fpm-yap.sock|fcgi://yap"
	</FilesMatch>
</Directory>
<VirtualHost 127.0.0.1:$HTTPS_APACHE>
	DocumentRoot $W/www
	CustomLog $W/logs/apache-https.log e2e
	SSLEngine on
	SSLCertificateFile $W/tls/server.pem
	SSLCertificateKeyFile $W/tls/server.key
</VirtualHost>
<VirtualHost 127.0.0.1:$HTTP_APACHE>
	DocumentRoot $W/www
	CustomLog $W/logs/apache-http.log e2e
</VirtualHost>
<Directory $W/wp>
	AllowOverride All
	Require all granted
	<FilesMatch "\.php$">
		SetHandler "proxy:unix:$W/run/fpm-wp.sock|fcgi://wp"
	</FilesMatch>
</Directory>
<VirtualHost 127.0.0.1:$WP_PORT>
	DocumentRoot $W/wp
	CustomLog $W/logs/apache-wp.log e2e
</VirtualHost>
EOF
} > "$W/run/apache.conf"
"$APACHE" -f "$W/run/apache.conf" -t >/dev/null 2>"$W/logs/apache-configtest.log" || { cat "$W/logs/apache-configtest.log"; fail "apache configuration"; }
"$APACHE" -f "$W/run/apache.conf" -k start

cat > "$W/nginx/fastcgi_params" <<'EOF'
fastcgi_param QUERY_STRING $query_string;
fastcgi_param REQUEST_METHOD $request_method;
fastcgi_param CONTENT_TYPE $content_type;
fastcgi_param CONTENT_LENGTH $content_length;
fastcgi_param SCRIPT_NAME $fastcgi_script_name;
fastcgi_param REQUEST_URI $request_uri;
fastcgi_param DOCUMENT_URI $document_uri;
fastcgi_param DOCUMENT_ROOT $document_root;
fastcgi_param SERVER_PROTOCOL $server_protocol;
fastcgi_param REMOTE_ADDR $remote_addr;
fastcgi_param REMOTE_PORT $remote_port;
fastcgi_param SERVER_ADDR $server_addr;
fastcgi_param SERVER_PORT $server_port;
fastcgi_param SERVER_NAME $server_name;
EOF
{
	cat <<EOF
pid $W/run/nginx.pid;
error_log $W/logs/nginx-error.log;
daemon off;
worker_processes 1;
events { worker_connections 64; }
http {
	access_log $W/logs/nginx-access.log;
	client_body_temp_path $W/run/nb; proxy_temp_path $W/run/np; fastcgi_temp_path $W/run/nf; uwsgi_temp_path $W/run/nu; scgi_temp_path $W/run/ns;
	server {
		listen 127.0.0.1:$HTTPS_NGINX ssl;
		server_name 127.0.0.1;
		ssl_certificate $W/tls/server.pem;
		ssl_certificate_key $W/tls/server.key;
		root $W/www;
		# deploy/nginx.conf.example from the release zip, unchanged except for the PHP-FPM socket:
EOF
	sed "s#fastcgi_pass unix:/run/php/php8.3-fpm.sock;#fastcgi_pass unix:$W/run/fpm-yap.sock;#" "$SVC/deploy/nginx.conf.example" | sed 's/^/\t\t/'
	echo "	}"
	echo "}"
} > "$W/nginx/nginx.conf"
grep -q "fastcgi_pass unix:$W/run/fpm-yap.sock" "$W/nginx/nginx.conf" || fail "deploy/nginx.conf.example no longer has the expected fastcgi_pass line"
"$NGINX" -e "$W/logs/nginx-early.log" -p "$W/nginx" -c "$W/nginx/nginx.conf" -t >/dev/null 2>"$W/logs/nginx-configtest.log" || { cat "$W/logs/nginx-configtest.log"; fail "nginx configuration"; }
"$NGINX" -e "$W/logs/nginx-early.log" -p "$W/nginx" -c "$W/nginx/nginx.conf" >"$W/logs/nginx.out" 2>&1 &
PIDS+=($!)
for i in $(seq 1 50); do
	curl -s -o /dev/null --cacert "$W/tls/ca.pem" "https://127.0.0.1:$HTTPS_APACHE/" && curl -s -o /dev/null --cacert "$W/tls/ca.pem" "https://127.0.0.1:$HTTPS_NGINX/" && [ -S "$W/run/fpm-yap.sock" ] && [ -S "$W/run/fpm-wp.sock" ] && break
	sleep 0.2
done
pass "Apache $("$APACHE" -v | sed -n 's/Server version: Apache\/\([0-9.]*\).*/\1/p'), nginx $("$NGINX" -v 2>&1 | sed 's/.*nginx\///'), PHP-FPM $(php -r 'echo PHP_VERSION;')"

# GET with curl; prints "<status> <body>".
get() { # <url> [curl args...]
	local url=$1
	shift
	curl -s --cacert "$W/tls/ca.pem" -o "$W/run/body" -w '%{http_code}' "$@" "$url"
	printf ' '
	cat "$W/run/body"
}
expected() { fake expected "${SCENARIO[@]}" "$@"; }
FROM30=$(TZ=$TZ_REPORT date -d "$TODAY -29 days" +%F)
E1001=$(expected --from="$FROM30" --to="$TODAY" --areas=1001)
jget() { php -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $d = $d[$k] ?? null; } echo json_encode($d);' "$1"; }

service_checks() { # <name> <base url of reporting-api>
	local name=$1 base=$2 r
	r=$(get "$base/api/v1/health")
	[ "${r%% *}" = 200 ] && grep -q '"status":"ok"' <<<"$r" && pass "$name: health over HTTPS" || fail "$name: health: $r"
	r=$(get "$base/api/v1/summary?period=30d" -D "$W/run/headers")
	[ "${r%% *}" = 401 ] && pass "$name: no credential, 401" || fail "$name: without a credential: $r"
	grep -qi '^strict-transport-security: max-age' "$W/run/headers" && grep -qi '^cache-control: private, no-store' "$W/run/headers" && pass "$name: HSTS and no-store headers" || fail "$name: headers $(cat "$W/run/headers")"
	r=$(get "$base/api/v1/summary?start_date=$FROM30&end_date=$TODAY&timezone=$TZ_REPORT&areas=1001" -H "Authorization: Bearer $SITE_TOKEN")
	[ "${r%% *}" = 200 ] || fail "$name: summary with the credential: $r"
	local got want
	for k in total_calls answered_calls volunteer_dial_attempts answer_rate; do
		got=$(jget "data.metrics.$k.value" <<<"${r#* }")
		want=$(jget "$k" <<<"$E1001")
		[ "$got" = "$want" ] || fail "$name: $k is $got, the scenario says $want"
	done
	pass "$name: the Authorization header reaches PHP; Riverside's last 30 days match the scenario"
	r=$(get "$base/api/v1/summary?period=30d&areas=1002" -H "Authorization: Bearer $AREA_TOKEN")
	[ "${r%% *}" = 403 ] && pass "$name: a Riverside-only credential cannot read Hillcrest" || fail "$name: area scope: $r"
	r=$(get "$base/api/v1/summary?period=30d&token=$SITE_TOKEN")
	[ "${r%% *}" = 400 ] && pass "$name: a credential in the URL is refused" || fail "$name: token in query: $r"
	for f in data/clients.json data/secret.key config/reporting-config.php config/reporting-config.example.php src/App.php bin/yapr tests/run.php docs/API.md deploy/nginx.conf.example composer.json .htaccess public/.htaccess; do
		r=$(get "$base/$f")
		case "${r%% *}" in 200) fail "$name: $f is downloadable" ;; esac
		grep -q 'clients\|<?php\|RewriteRule\|BEGIN\|fastcgi' <<<"${r#* }" && fail "$name: $f leaked its content"
	done
	pass "$name: data, config, source, tests, docs and dot files are not served"
	r=$(get "$base/setup")
	[ "${r%% *}" = 404 ] && pass "$name: setup console is off" || fail "$name: setup: $r"
}

section "Webservice behind Apache (.htaccess only)"
service_checks Apache "$API"
r=$(get "http://127.0.0.1:$HTTP_APACHE/yap/reporting-api/api/v1/health")
[ "${r%% *}" = 403 ] && grep -q https_required <<<"$r" && pass "Apache: plain HTTP refused (https_required)" || fail "plain HTTP: $r"
r=$(get "$API/public/index.php/api/v1/health")
[ "${r%% *}" = 200 ] && pass "Apache: the documented fallback address public/index.php/api/v1/… works" || fail "fallback: $r"
touch "$SVC/config/ENABLE_SETUP"
r=$(get "$API/setup")
[ "${r%% *}" = 200 ] && grep -qi 'setup-code.txt' <<<"$r" && pass "setup console asks for the one-time code when unlocked" || fail "setup console: ${r:0:300}"
[ -s "$SVC/config/setup-code.txt" ] || fail "no setup-code.txt written"
r=$(get "$API/config/setup-code.txt")
grep -q "$(cat "$SVC/config/setup-code.txt")" <<<"$r" && fail "the setup code is downloadable"
pass "the setup code itself cannot be downloaded"
rm -f "$SVC/config/ENABLE_SETUP" "$SVC/config/setup-code.txt"

section "Webservice behind nginx (deploy/nginx.conf.example)"
service_checks nginx "https://127.0.0.1:$HTTPS_NGINX/yap/reporting-api"

section "WordPress with the plugin zip"
WP=(wp --path="$W/wp" --quiet)
if [ -n "${WP_CORE:-}" ]; then
	mkdir -p "$W/wp" && (cd "$WP_CORE" && tar --exclude=./wp-config.php --exclude=./wp-content/uploads -cf - .) | tar -xf - -C "$W/wp"
else
	"${WP[@]}" core download --path="$W/wp" >/dev/null
fi
mysql_admin -e "CREATE DATABASE e2e_wp CHARACTER SET utf8mb4"
"${WP[@]}" config create --dbname=e2e_wp --dbuser="$DB_ADMIN_USER" --dbpass="$DB_ADMIN_PASS" --dbhost="$DB_HOST:$DB_PORT" --skip-check --force \
	--extra-php <<'EOF'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
// The test webservice is on 127.0.0.1: allowed only because this constant is set in wp-config.php.
define( 'YAPR_ALLOW_PRIVATE_HOSTS', true );
EOF
"${WP[@]}" core install --url="$SITE" --title="E2E Area" --admin_user=admin --admin_password=Admin-pass-1 --admin_email=admin@example.org --skip-email
mkdir -p "$W/wp/wp-content/mu-plugins"
# Trust the test CA for the test webservice only (production uses WordPress's own CA bundle).
cat > "$W/wp/wp-content/mu-plugins/e2e-trust.php" <<EOF
<?php
add_filter( 'http_request_args', static function ( \$args, \$url ) {
	if ( str_starts_with( (string) \$url, 'https://127.0.0.1:$HTTPS_APACHE/' ) ) {
		\$args['sslcertificates'] = '$W/tls/ca.pem';
	}
	return \$args;
}, 10, 2 );
EOF
"${WP[@]}" rewrite structure '' # plain ?page_id= links: the vhost has no rewrite rules
"${WP[@]}" plugin install "$PLUGIN_ZIP" --activate
THEME=$("${WP[@]}" theme list --status=active --field=name)
BLOCK=$("${WP[@]}" eval 'echo wp_is_block_theme() ? "yes" : "no";')
pass "WordPress $("${WP[@]}" core version), theme $THEME (block theme: $BLOCK), plugin $("${WP[@]}" plugin get yap-reporting --field=version)"

"${WP[@]}" eval "
	YAPR\\Settings::update( array( 'api_url' => '$API', 'bmlt_root' => 'https://127.0.0.1:$HTTPS_APACHE/bmlt/main_server', 'timezone' => '$TZ_REPORT',
		'default_period' => '30d', 'role_areas' => array( 'yapr_phoneline_admin' => 'all', 'yapr_area_reporter' => array( 1001 ) ),
		'public_areas' => array( 1001 ), 'public_metrics' => array( 'volunteer_calls', 'calls_answered', 'answer_rate' ) ) );
	YAPR\\Settings::set_token( '$SITE_TOKEN' );"
r=$("${WP[@]}" eval '$r = YAPR\ApiClient::test_connection(); echo ( $r["ok"] ? "ok " : "FAIL " ) . $r["message"];')
[ "${r%% *}" = ok ] && pass "Test connection over HTTPS: ${r#ok }" || fail "Test connection: $r"
r=$("${WP[@]}" eval '$r = YAPR\Sync::run( "bmlt", true ); echo $r["items"];')
[ "$r" = 7 ] && pass "7 service bodies loaded from the fake BMLT server" || fail "BMLT refresh: $r"
"${WP[@]}" eval 'YAPR\Areas::set_managed( array( 1000, 1001, 1002, 1003 ) );'
r=$("${WP[@]}" eval '$r = YAPR\Sync::run( "recent", true ); echo ( $r["ok"] ? "ok " : "FAIL " ) . $r["items"] . " " . $r["message"];')
[ "${r%% *}" = ok ] && pass "background sync: ${r#ok }" || fail "sync: $r"
r=$("${WP[@]}" eval '$r = YAPR\Sync::run( "history", true ); echo ( $r["ok"] ? "ok " : "FAIL " ) . $r["items"];')
[ "${r%% *}" = ok ] || fail "history sync: $r"
"${WP[@]}" user create phoneline phoneline@example.org --role=yapr_phoneline_admin --user_pass=Phone-pass-1
"${WP[@]}" user create reporter reporter@example.org --role=yapr_area_reporter --user_pass=Report-pass-1
REPORTS=$("${WP[@]}" post create --post_type=page --post_status=publish --post_title=Reports --post_content='[yap_reporting view="all" period="30d"]' --porcelain)
CALLS=$("${WP[@]}" post create --post_type=page --post_status=publish --post_title=Calls --post_content='[yap_phoneline_admin period="30d"]' --porcelain)
AREA=$("${WP[@]}" post create --post_type=page --post_status=publish --post_title='Riverside figures' --post_content='[yap_area_dashboard area="1001" period="30d" comparison="yes"]' --porcelain)

section "Public Area dashboard (anonymous visitor)"
r=$(get "$SITE/?page_id=$AREA")
[ "${r%% *}" = 200 ] || fail "area page: ${r:0:200}"
figure() { php -r 'preg_match("/yapr-metric-" . $argv[1] . "\b.*?<span class=\"yapr-figure\">([^<]*)</s", stream_get_contents(STDIN), $m); echo str_replace(",", "", html_entity_decode($m[1] ?? "missing"));' "$1"; }
for pair in volunteer_calls:volunteer_routed_calls calls_answered:answered_calls answer_rate:answer_rate; do
	got=$(figure "${pair%%:*}" <<<"${r#* }")
	want=$(jget "${pair#*:}" <<<"$E1001")
	[ "${pair%%:*}" = answer_rate ] && want=$(printf '%.1f%%' "$want")
	[ "$got" = "$want" ] || fail "public ${pair%%:*} shows $got, the scenario says $want"
done
grep -q 'yapr-metric-jft' <<<"$r" && fail "an unpublished figure is shown publicly"
grep -Eq 'yapr_[0-9a-f]{12}_|5555550(1[1-5]|1[5-9]|[6-9])' <<<"$r" && fail "credential or phone number in the page"
pass "published figures match the scenario; unpublished figures and numbers are not shown"
r=$(get "$SITE/?page_id=$REPORTS")
grep -q 'Log in' <<<"$r" && ! grep -q 'data-yapr-config' <<<"$r" && pass "the private report asks visitors to log in" || fail "private report for a visitor"

section "Browser (headless Chrome)"
expected --from="$FROM30" --to="$TODAY" --areas=1000,1001,1002,1003 --unassigned > "$W/run/all.json"
printf '%s' "$E1001" > "$W/run/1001.json"
python3 "$ROOT/bin/browser-e2e.py" "$SITE" "$REPORTS" "$CALLS" "$W/run/all.json" "$W/run/1001.json" "127.0.0.1:$HTTPS_APACHE"

section "Logs"
if grep -v 'PHP Deprecated' "$W/logs/php.log" 2>/dev/null | grep -q 'PHP '; then
	grep 'PHP ' "$W/logs/php.log" | tail -20
	fail "PHP errors in the server log"
fi
if [ -s "$W/wp/wp-content/debug.log" ] && grep -q 'yap-reporting' "$W/wp/wp-content/debug.log"; then
	grep 'yap-reporting' "$W/wp/wp-content/debug.log" | tail -20
	fail "the plugin logged PHP notices or errors"
fi
pass "no PHP warnings or errors from the plugin or the webservice"
grep -q "\" 5[0-9][0-9]$" "$W/logs/apache-https.log" && fail "a 5xx answer from the webservice: $(grep '" 5' "$W/logs/apache-https.log" | head -3)"
pass "no server errors"
echo
echo "End-to-end test passed."
