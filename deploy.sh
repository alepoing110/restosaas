#!/bin/bash
# RestoCloud — Deploy Script
# Uso: bash deploy.sh [--no-backup]

set -e

echo "=== RestoCloud Deploy ==="
echo ""

# 1. Pull latest code
echo "[1/6] Pulling latest code..."
git pull origin main

# 2. Install JS dependencies (lint only, no dev)
echo "[2/6] Installing dependencies..."
npm ci --omit=dev 2>/dev/null || npm install --omit=dev

# 3. Run migrations
echo "[3/6] Running migrations..."
php migrate.php --force

# 4. Fix storage permissions
echo "[4/6] Setting storage permissions..."
chmod -R 775 storage/
if command -v chown &> /dev/null; then
    chown -R www-data:www-data storage/ 2>/dev/null || true
fi

# 5. Clear OPcache if available
echo "[5/6] Clearing OPcache..."
php -r "if(function_exists('opcache_reset')) opcache_reset();" 2>/dev/null || true

# 6. Verify
echo "[6/6] Verifying..."
php -l api.php > /dev/null
php -l db.php > /dev/null
php -l backend/auth.php > /dev/null
npm run lint:syntax > /dev/null 2>&1

echo ""
echo "=== Deploy complete ==="
echo ""
echo "IMPORTANT: Change the default admin password before going live!"
echo "  Default: owner@legacy.restocloud.local / admin12345"
