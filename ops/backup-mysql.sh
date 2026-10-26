#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

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

BACKUP_DIR="${BACKUP_DIR:-storage/backups}"
mkdir -p "$BACKUP_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"

MYSQL_PWD="$DB_PASS" mysqldump \
  --host="$DB_HOST" \
  --user="$DB_USER" \
  --single-transaction \
  --routines \
  --triggers \
  "$DB_NAME" | gzip -9 > "$TARGET"

chmod 600 "$TARGET"
echo "Backup creado: $TARGET"
