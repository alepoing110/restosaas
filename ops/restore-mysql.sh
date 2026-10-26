#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ "${CONFIRM_RESTORE:-}" != "YES" ]]; then
  echo "Restaurar reemplaza datos. Usa CONFIRM_RESTORE=YES." >&2
  exit 1
fi
if [[ $# -ne 1 || ! -f "$1" ]]; then
  echo "Uso: CONFIRM_RESTORE=YES bash ops/restore-mysql.sh backup.sql.gz" >&2
  exit 1
fi
if [[ ! -f .env.production ]]; then
  echo "Falta .env.production" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1091
source ./.env.production
set +a

: "${DB_HOST:?Falta DB_HOST}"
: "${DB_USER:?Falta DB_USER}"
: "${DB_NAME:?Falta DB_NAME}"
: "${DB_PASS:?Falta DB_PASS}"

MYSQL_PWD="$DB_PASS" gzip -dc "$1" | MYSQL_PWD="$DB_PASS" mysql \
  --host="$DB_HOST" \
  --user="$DB_USER" \
  "$DB_NAME"

echo "Restauración completada. Ejecuta php migrate.php y verifica la aplicación."
