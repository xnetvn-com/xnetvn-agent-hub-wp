#!/usr/bin/env bash
# Owned-container TLS fixture. Private key never leaves the container or web root boundary.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab fixture required.\n' >&2; exit 2; }
fixture_dir=/opt/xnetvn/client-tls
install -d -m 0700 "$fixture_dir"
if [[ ! -e "$fixture_dir/server.crt" && ! -e "$fixture_dir/server.key" ]]; then
  umask 077
  openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 7 \
    -subj '/CN=wp69' -addext 'subjectAltName=DNS:wp69,DNS:xnv-pinning.test' \
    -keyout "$fixture_dir/server.key" -out "$fixture_dir/server.crt" >/dev/null 2>&1
fi
[[ -f "$fixture_dir/server.crt" && -f "$fixture_dir/server.key" && ! -L "$fixture_dir/server.crt" && ! -L "$fixture_dir/server.key" ]] || exit 2
openssl x509 -in "$fixture_dir/server.crt" -noout -checkend 86400 >/dev/null
openssl x509 -in "$fixture_dir/server.crt" -noout -checkhost wp69 >/dev/null
chmod 0600 "$fixture_dir/server.key"
a2enmod ssl >/dev/null
cat > /etc/apache2/sites-available/xnetvn-client-tls.conf <<'APACHE'
<VirtualHost *:443>
    ServerName wp69
    DocumentRoot /var/www/html
    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCertificateFile /opt/xnetvn/client-tls/server.crt
    SSLCertificateKeyFile /opt/xnetvn/client-tls/server.key
    AliasMatch "^/xnetvn-fetch-fixture$" "/opt/xnetvn/fetch-fixture.php"
    <Directory /opt/xnetvn>
        AllowOverride None
        Options -Indexes
        <Files fetch-fixture.php>
            Require all granted
        </Files>
    </Directory>
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
APACHE
a2ensite xnetvn-client-tls >/dev/null
apache2ctl configtest
apache2ctl graceful
printf 'Owned-container TLS fixture ready.\n'
