#!/usr/bin/env bash
# Builds the two Android apps (release APKs, signed with mobile/keystore) into dist-apk/.
#   ./scripts/build-apk.sh                 # server = https://kbsave.in
#   API_SERVER=http://192.168.1.5:8095 ./scripts/build-apk.sh   # test build against this Mac
#   API_SERVER=https://finance.example.in VERSION_CODE=2 VERSION_NAME=1.1 ./scripts/build-apk.sh
# The server address is fixed in the app; people cannot change it.
# With an https:// server the APK refuses plain-HTTP traffic entirely (production setting).
# Raise VERSION_CODE for every release you hand out, so phones accept it as an update.
set -euo pipefail
cd "$(dirname "$0")/.."
export JAVA_HOME="${JAVA_HOME:-$(ls -d ~/Library/Java/JavaVirtualMachines/jdk-21*/Contents/Home | head -1)}"
export ANDROID_HOME="${ANDROID_HOME:-$HOME/Library/Android/sdk}"
SERVER="${API_SERVER:-https://kbsave.in}"
VERSION_CODE="${VERSION_CODE:-1}"
VERSION_NAME="${VERSION_NAME:-1.0}"
case "$SERVER" in https://*) CLEARTEXT=false ;; *) CLEARTEXT=true ;; esac

mkdir -p dist-apk
for APP in agent customer; do
  printf "VITE_APP_MODE=%s\nVITE_API_SERVER=%s\nVITE_APP_VERSION_CODE=%s\nVITE_APP_VERSION_NAME=%s\n" "$APP" "$SERVER" "$VERSION_CODE" "$VERSION_NAME" > "web/.env.$APP"
  (cd web && npm run "build:$APP" >/dev/null)
  sed -i '' -E "s/\"cleartext\": (true|false)/\"cleartext\": $CLEARTEXT/" "mobile/$APP/capacitor.config.json"
  sed -i '' -E "s/versionCode [0-9]+/versionCode $VERSION_CODE/; s/versionName \"[^\"]*\"/versionName \"$VERSION_NAME\"/" "mobile/$APP/android/app/build.gradle"
  (cd "mobile/$APP" && npx cap sync android >/dev/null)
  (cd "mobile/$APP/android" && ./gradlew --quiet assembleRelease)
  NAME=$([ "$APP" = agent ] && echo "KBSAVE-Agent" || echo "KBSAVE-Customer")
  cp "mobile/$APP/android/app/build/outputs/apk/release/app-release.apk" "dist-apk/$NAME.apk"
  echo "Built dist-apk/$NAME.apk (server $SERVER, version $VERSION_NAME/$VERSION_CODE, cleartext $CLEARTEXT)"
done
