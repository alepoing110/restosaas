#!/bin/bash
# ==========================================================================
# RestoCloud — Deploy Script para cPanel / InfinityFree
# ==========================================================================
# Instrucciones:
#   1. Sube todos los archivos EXCEPTO: node_modules/, __tests__/, .git/, .github/, .codex/
#   2. Sube el archivo .env con tus credenciales reales
#   3. Ejecuta este script desde la terminal de cPanel (o vía SSH si disponible)
#   bash deploy-cpanel.sh
# ==========================================================================

set -e

echo ""
echo "========================================="
echo "  RestoCloud — Deploy cPanel/InfinityFree"
echo "========================================="
echo ""

# 1. Verificar PHP
echo "[1/5] Verificando PHP..."
if command -v php &> /dev/null; then
    php -v | head -1
else
    echo "  ⚠ PHP no encontrado en PATH. Continuando..."
fi

# 2. Verificar permisos de storage
echo "[2/5] Configurando permisos de storage..."
chmod -R 775 storage/ 2>/dev/null || true

# Crear directorios si no existen
mkdir -p storage/logs
mkdir -p storage/sessions
mkdir -p storage/rate_limits
mkdir -p storage/cache
mkdir -p storage/ws
mkdir -p storage/outbox

# 3. Ejecutar migraciones
echo "[3/5] Ejecutando migraciones de base de datos..."
if command -v php &> /dev/null; then
    php migrate.php 2>&1
else
    echo "  ✗ PHP CLI no disponible. Ejecuta migrate.php antes de habilitar el sitio."
    exit 1
fi

# 4. Verificar sintaxis PHP
echo "[4/5] Verificando sintaxis PHP..."
if command -v php &> /dev/null; then
    php -l api.php > /dev/null 2>&1 && echo "  ✓ api.php OK" || echo "  ✗ api.php tiene errores"
    php -l db.php > /dev/null 2>&1 && echo "  ✓ db.php OK" || echo "  ✗ db.php tiene errores"
    php -l backend/auth.php > /dev/null 2>&1 && echo "  ✓ backend/auth.php OK" || echo "  ✗ backend/auth.php tiene errores"
else
    echo "  ⚠ PHP CLI no disponible. Saltando verificación."
fi

# 5. Verificar conexión a BD
echo "[5/5] Verificando conexión a base de datos..."
if command -v php &> /dev/null; then
    php -r "
    require_once 'db.php';
    echo '  ✓ Conexión a base de datos exitosa\n';
    " 2>&1 || echo "  ✗ Error de conexión a BD. Verifica tu archivo .env"
fi

echo ""
echo "========================================="
echo "  Deploy completado"
echo "========================================="
echo ""
echo "Tu aplicación está disponible en:"
echo "  https://simplefoot.ifree.page/"
echo ""
echo "Admin Panel:"
echo "  https://simplefoot.ifree.page/admin.html"
echo ""
echo "Verifica las credenciales, secretos y configuración de WhatsApp antes de habilitar producción."
echo "Configura un cron para ejecutar: php whatsapp-worker.php --limit=10"
echo ""
