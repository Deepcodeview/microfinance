#!/usr/bin/env bash
# Builds the cPanel package: dist-cpanel/kbsave-cpanel.zip
#
#   ./scripts/build-cpanel.sh            (DOMAIN defaults to kbsave.in)
#
# Extract the zip directly in the domain's folder (its Document Root) and open the site: the one-time
# installer (kbsave-setup.php) asks for the database and your owner account, then sets everything up and
# switches itself off. The same zip updates an existing install: code is replaced, finserve/api/.env,
# uploads and the database are kept (the installer only applies migrations then).
#
# Layout inside the website folder:
#   index.html, assets/, .htaccess        the web app (+ HTTPS, installer redirect, blocks for private paths)
#   api/index.php                         API entry -> finserve/api
#   finserve/                             PHP code, .env, uploads, backups — every request is refused (403)
#   apps/                                 download page (kbsave.in/apps) with QR codes + the Android APKs
#   kbsave-setup.php                      one-time installer (switches itself off via installed.lock)
set -euo pipefail
cd "$(dirname "$0")/.."

DOMAIN="${DOMAIN:-kbsave.in}"
VERSION_CODE="${VERSION_CODE:-23}"
VERSION_NAME="${VERSION_NAME:-3.3}"
OUT=dist-cpanel/site
rm -rf dist-cpanel
mkdir -p "$OUT/api" "$OUT/apps" "$OUT/finserve/api" "$OUT/finserve/database" "$OUT/finserve/backups"

echo "1/4 Android apps for https://${DOMAIN} ..."
API_SERVER="https://${DOMAIN}" VERSION_CODE="$VERSION_CODE" VERSION_NAME="$VERSION_NAME" ./scripts/build-apk.sh >/dev/null
cp dist-apk/KBSAVE-Agent.apk dist-apk/KBSAVE-Customer.apk "$OUT/apps/"
# Download page (kbsave.in/apps) with QR codes; large QR PNGs go next to the zip for printing / WhatsApp.
cp deploy/apps/index.html deploy/apps/print.html deploy/apps/.htaccess "$OUT/apps/"
cp mobile/customer/android/app/src/main/res/mipmap-xxxhdpi/ic_launcher.png "$OUT/apps/icon-customer.png"
cp mobile/agent/android/app/src/main/res/mipmap-xxxhdpi/ic_launcher.png "$OUT/apps/icon-agent.png"
mb() { awk -v b="$(stat -f%z "$1")" 'BEGIN { printf "%.1f", b / 1048576 }'; }
sed -i '' -e "s/__VERSION__/${VERSION_NAME}/g" -e "s/__CUSTOMER_SIZE__/$(mb dist-apk/KBSAVE-Customer.apk)/" \
  -e "s/__AGENT_SIZE__/$(mb dist-apk/KBSAVE-Agent.apk)/" "$OUT/apps/index.html"
mkdir -p dist-cpanel/qr-codes
node scripts/app-qr.cjs "$DOMAIN" "$OUT/apps" dist-cpanel/qr-codes

echo "2/4 Website ..."
(cd web && npm run build >/dev/null)
cp -R web/dist/. "$OUT/"
# Setup is shown until the database has this package's newest migration (see web/public/.htaccess).
SCHEMA=$(basename "$(ls database/migrations/*.sql | sort | tail -1)" .sql)
sed -i '' "s/__SCHEMA__/${SCHEMA}/" "$OUT/.htaccess"
cp api/public/.htaccess "$OUT/api/.htaccess"
cp api/public/.user.ini "$OUT/api/.user.ini"
cat > "$OUT/api/index.php" <<'PHP'
<?php
// Public entry for the API. The code lives in ../finserve/api (refused to browsers by finserve/.htaccess).
// Looks upwards so it also works if the finserve folder sits next to the website folder instead.
for ($dir = dirname(__DIR__), $i = 0; $i < 4; $dir = dirname($dir), $i++) {
    if (is_file($dir . '/finserve/api/public/index.php') && is_file($dir . '/finserve/api/.env')) {
        require $dir . '/finserve/api/public/index.php';
        return;
    }
}
http_response_code(503);
header('Content-Type: application/json');
echo '{"error":{"code":"NOT_INSTALLED","message":"KBSAVE is not set up yet. Open /kbsave-setup.php on this site."}}';
PHP
cp deploy/cpanel/kbsave-setup.php "$OUT/kbsave-setup.php"

echo "3/4 API ..."
cp -R api/src api/bin api/public "$OUT/finserve/api/"
# The apps compare their own version with this and offer the newer APK (GET /api/v1/public/app-version).
cat > "$OUT/finserve/api/app-versions.json" <<JSON
{"agent":{"version_code":${VERSION_CODE},"version_name":"${VERSION_NAME}","url":"https://${DOMAIN}/apps/KBSAVE-Agent.apk"},
 "customer":{"version_code":${VERSION_CODE},"version_name":"${VERSION_NAME}","url":"https://${DOMAIN}/apps/KBSAVE-Customer.apk"}}
JSON
cp -R database/migrations "$OUT/finserve/database/"
mkdir -p "$OUT/finserve/api/storage/documents" "$OUT/finserve/api/storage/payment-proofs"
for d in finserve finserve/api/storage finserve/backups; do printf 'Require all denied\nDeny from all\n' > "$OUT/$d/.htaccess"; done
# Keep empty folders in the zip.
touch "$OUT/finserve/api/storage/documents/.keep" "$OUT/finserve/api/storage/payment-proofs/.keep" "$OUT/finserve/backups/.keep"

echo "4/4 Zipping ..."
(cd "$OUT" && zip -qr ../kbsave-cpanel.zip . -x '.DS_Store' '*/.DS_Store')
cp docs/DEPLOY-CPANEL.md dist-cpanel/DEPLOY-GUIDE.md
echo "Done: dist-cpanel/kbsave-cpanel.zip — extract it in the website folder of ${DOMAIN}, then open https://${DOMAIN}"
