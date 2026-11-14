#!/usr/bin/env bash
# Runs every API test suite against a throwaway database ("finserve_test") through a separate API
# container (api-test). Your working "finserve" database is never touched.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d api-test >/dev/null 2>&1

docker compose exec -T mysql mysql -uroot -proot_local -e \
  "DROP DATABASE IF EXISTS finserve_test; CREATE DATABASE finserve_test; GRANT ALL ON finserve_test.* TO 'finserve'@'%';" 2>/dev/null
docker compose exec -T api-test php bin/migrate.php
docker compose exec -T -e SEED_PASSWORD=Admin@12345 api-test php bin/seed.php --demo > /dev/null

echo "== Finance suite"
docker compose exec -T api-test php tests/finance_test.php
echo "== Feature suite"
docker compose exec -T api-test php tests/features_test.php
echo "== Smoke suite"
docker compose exec -T api-test php tests/smoke_test.php
echo "== Core banking suite"
docker compose exec -T api-test php tests/core_banking_test.php
echo "== Self-service suite"
docker compose exec -T api-test php tests/self_service_test.php
echo "== Tenure suite"
docker compose exec -T api-test php tests/tenure_test.php
echo "== Collection rules suite"
docker compose exec -T api-test php tests/collection_rules_test.php
echo "== Field suite"
docker compose exec -T api-test php tests/field_test.php
echo "== Migration suite"
docker compose exec -T api-test php tests/migration_test.php
