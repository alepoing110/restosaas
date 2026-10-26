# RestoCloud — Historial Completo de Cambios (Revisiones 1–3)

**Fecha:** 2026-07-18
**Propósito:** Documentar todos los cambios realizados en las tres revisiones para referencia futura y mejoras posteriores.

---

## Tabla de Contenidos

1. [Primera Revisión — Bugs y Mejoras](#primera-revisión)
2. [Segunda Revisión — Seguridad y Calidad](#segunda-revisión)
3. [Tercera Revisión — Verificación y Correcciones](#tercera-revisión)
4. [Archivos Modificados](#archivos-modificados)
5. [Archivos Nuevos Creados](#archivos-nuevos)
6. [Archivos Eliminados](#archivos-eliminados)
7. [Mejoras Pendientes Sugeridas](#mejoras-pendientes)

---

## Primera Revisión

22 bugs/mejoras identificados en `RestoCloud-Revision-Bugs-Mejoras.md`. Todos implementados.

### Seguridad

| # | Cambio | Archivo | Línea(s) |
|---|--------|---------|----------|
| 4 | Mensajes de error de DB sanitizados (no exponer SQL) | `api.php` | `errorResponse()` |
| 19 | Session cookies con `httponly`, `samesite=Lax` | `backend/auth.php` | `session_set_cookie_params` |
| 17 | Credenciales DB via `getenv()` | `db.php` | conexión PDO |
| 3 | CORS whitelist (solo localhost/127.0.0.1) | `api.php` | `cors.php` include |
| 9 | Rate limiting en login (`checkRateLimit`/`recordFailedAttempt`) | `backend/auth.php` + `api.php` | auth_login, admin_login |

### Aislamiento Multi-tenant

| # | Cambio | Archivo | Detalle |
|---|--------|---------|---------|
| 1+12 | `ON DUPLICATE KEY UPDATE` → SELECT+INSERT/UPDATE en 7 endpoints | `api.php` | `segundos`, `sopas`, `platos_extras`, `gaseosas`, `products`, `menus`, `tables_config` |
| 2 | IDs generados client-side con `crypto.randomUUID()` | `js/app/helpers.js` | `generateId()` |
| 18 | `save_table` genera ID server-side con `bin2hex(random_bytes(6))` | `api.php` | `save_table` |
| 20 | Constraints UNIQUE en 7 tablas tenant-scoped | `db_migrations.php` | v53 |

### Autenticación y Permisos

| # | Cambio | Archivo | Detalle |
|---|--------|---------|---------|
| 5 | `requirePermission` en 14 endpoints críticos | `api.php` | settings, inventory, orders |
| 6 | `saas_create_user` valida branch→tenant ownership | `api.php` | `saas_create_user` |
| 7 | `saas_edit_user` valida branch→tenant ownership | `api.php` | `saas_edit_user` |
| 8 | `saas_delete_tenant` envuelto en transacción (4 DELETEs) | `api.php` | `saas_delete_tenant` |

### Lógica de Negocio

| # | Cambio | Archivo | Detalle |
|---|--------|---------|---------|
| 10 | `annul_sale` restaura stock de segundos (almuerzo) | `api.php` | `annul_sale` |
| 13 | Validación de timestamps en `save_order` | `api.php` | `save_order` |
| 14 | `delete_caja_movimiento` verifica cierre activo | `api.php` | `delete_caja_movimiento` |
| 11 | Stock management documentado (design decision) | `api.php` | comentarios |
| 22 | Validación de precios consistente | `api.php` | `save_prices` |

### Rendimiento

| # | Cambio | Archivo | Detalle |
|---|--------|---------|---------|
| 16 | Migraciones extraídas a `db_migrations.php` + `migrate.php` CLI | `db_migrations.php`, `migrate.php` | Nuevos archivos |
| 21 | Payload de audit log sanitizado (redacta passwords, tokens) | `backend/auth.php` | `writeAuditLog()` |

---

## Segunda Revisión

12 items de seguridad/calidad en `RestoCloud-Segunda-Revision-Recomendaciones.md`. Todos implementados.

### Seguridad — .htaccess

**Archivo:** `.htaccess` (nuevo)

```apache
# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "DENY"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
    Header set X-XSS-Protection "1; mode=block"
</IfModule>

Options -Indexes

<FilesMatch "^(db\.php|db_migrations\.php|migrate\.php|\.env)$">
    Require all denied
</FilesMatch>

# Bloqueo de storage/
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^storage/logs/ - [F,L]
</IfModule>
```

### CORS — Fix wildcard

```php
// ANTES (vulnerable):
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if ($origin === '*') { header("Access-Control-Allow-Origin: *"); }

// DESPUÉS:
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header("Access-Control-Allow-Origin: " . $origin);
}
```

### Password Validation — Consistencia

Mínimo 8 caracteres en todos los endpoints de creación/edición de usuario:
- `create_tenant_user` → `strlen($password) < 8`
- `edit_tenant_user` → `strlen($password) < 8`
- `saas_create_user` → `strlen($password) < 8`
- `saas_edit_user` → `strlen($password) < 8`

### Roles — `super_admin` no creado desde UI

```php
// ANTES:
$validRoles = ['owner', 'admin', 'cajero'];

// DESPUÉS:
$validRoles = ['owner', 'admin', 'cajero']; // super_admin solo vía DB
```

### Sesión — Expiración por inactividad (8h)

```php
// backend/auth.php — touchUserSession()
function touchUserSession(PDO $pdo, string $sessionId): ?array {
    // ... fetch session ...
    $lastSeen = strtotime($session['last_seen_at']);
    $eightHoursAgo = time() - (8 * 3600);
    if ($lastSeen < $eightHoursAgo) {
        // Sesión expirada
        $stmt = $pdo->prepare("UPDATE `user_sessions` SET `is_active` = 0 WHERE `id` = :id");
        $stmt->execute(['id' => $sessionId]);
        unset($_SESSION['restocloud_session_id']);
        return null; // api.php retorna 401
    }
    // ... actualizar last_seen_at ...
}
```

### Logging — JSON estructurado

```php
// api.php — errorResponse()
function errorResponse(string $action, Throwable $e): void {
    http_response_code(500);
    $logEntry = [
        'timestamp' => date('c'),
        'level' => 'error',
        'action' => $action,
        'message' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ];
    $logFile = __DIR__ . '/storage/logs/error-' . date('Y-m-d') . '.log';
    file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND);
    // ...
}
```

### CI/CD — GitHub Actions

**Archivo:** `.github/workflows/ci.yml`

```yaml
name: CI
on: [push, pull_request]
jobs:
  lint-and-test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2' }
      - uses: actions/setup-node@v4
        with: { node-version: '22' }
      - run: npm ci
      - run: npm run lint:php
      - run: npm run lint:syntax
      - run: npm test
```

### npm Scripts

```json
{
  "lint:syntax": "node -e \"...recursive walk + node --check...\"",
  "lint:php": "php -l api.php && php -l db.php && php -l backend/auth.php"
}
```

---

## Tercera Revisión

27 bugs en `RestoCloud-Tercera-Revision-Verificacion-Correcciones.md`. Todos corregidos.

### Crítico

| # | Bug | Corrección | Archivo:Línea |
|---|-----|------------|---------------|
| C2 | `complete_order` sin filtro de estado — permite re-completar/anular completados | `AND status = 'pendiente'` | `api.php:1704` |
| C3 | `admin_login` sin rate limiting — `createAdminSession()` hacía `exit` | Ahora lanza `RuntimeException` | `backend/auth.php:281-291` |
| C4 | `saas_update_subscription` duplicaba filas (INSERT con id aleatorio nuevo) | SELECT→INSERT/UPDATE | `api.php:840-852` |
| C5 | `db.php` CREATE TABLE faltaban columnas → crash en fresh install | Agregadas todas las columnas (business_type, plan_level, tenant_id, branch_id, active, closure_id) | `db.php` 9 tablas |

### Alto

| # | Bug | Corrección | Archivo:Línea |
|---|-----|------------|---------------|
| A1 | `get_state` sin permiso → cajeros veían datos de caja | `requirePermission($authContext, 'pos')` | `api.php:1215` |
| A2 | `annul_sale` sin transacción → stock parcialmente restaurado | `beginTransaction/commit/rollBack` | `api.php:1788-1854` |
| A3 | `saas_delete_branch` sin transacción → usuarios borrados pero branch no | `beginTransaction/commit` | `api.php:936-951` |
| A4 | `annul_sale` sin fallback `extraId` → stock no restaurado para gaseosas | `extraId \|\| id` fallback | `api.php:1837` |
| A5 | `save_order` INSERT IGNORE mentía sobre éxito | `rowCount() === 0` → error | `api.php:1649-1662` |
| A6 | 35/54 migraciones sin `IF NOT EXISTS` → fallan en re-ejecución | Agregado `IF NOT EXISTS` a v1-v52 | `db_migrations.php` |

### Medio

| # | Bug | Corrección | Archivo |
|---|-----|------------|---------|
| M1 | `DashboardController.init()` nunca se llamaba | Ahora se llama al activar dashboard | `js/views/navigation.js` |
| M2 | `js/core/utils.js` era dead code (ES module no importado) | Recreado como ES module para tests | `js/core/utils.js` |
| M3 | `ROLE_LABELS` faltaba `super_admin` | Agregado `super_admin: 'Super Admin'` | `js/views/tenant-users-view.js` |
| M4 | `escapeHtml` redefinido localmente en tenant-users-view | Eliminado, usa global de helpers.js | `js/views/tenant-users-view.js` |
| M5 | CSS variables undefined: `--bg-card`, `--text`, `--primary-rgb` | `--bg-card`→`--bg-surface`, `--text`→`--text-main`, `--primary-rgb` hardcodeado | `styles.css` |
| M6 | `admin.html` tag `<i>` cerrado con `</div>` | Corregido a `</i>` | `admin.html:20` |
| M7 | `auth.php` no limpiaba sesión en suscripción suspendida | `unset($_SESSION['restocloud_session_id'])` | `backend/auth.php:149` |
| M8 | `delete_item` catch sin logging | Usa `errorResponse($action, $e)` | `api.php:1443` |
| M9 | 8 endpoints sin `requirePermission` | Agregados permisos a `get_pos_state`, `get_active_orders`, `get_inventory`, `get_reservations`, `save_reservation`, `update_reservation_status`, `delete_reservation`, `get_products_by_menu` | `api.php` |

### Bajo

| # | Bug | Corrección | Archivo |
|---|-----|------------|---------|
| B1 | Null checks faltantes en pos-controller.js | 8 guards agregados en getElementById/querySelector | `js/controllers/pos-controller.js` |
| B2 | `addEl` variable implícita global | Agregado `let` | `js/controllers/inventory-controller.js:71` |
| B3 | `confirm()` nativo en reservaciones | Reemplazado por `ConfirmDialog.show()` | `js/controllers/reservations-controller.js` |
| B4 | `setActiveCategoryTab` función no definida | Definida en `window.setActiveCategoryTab` | `js/controllers/pos-controller.js` |
| B5 | Skeleton CSS definido 2 veces | Consolidado en un solo bloque | `styles.css` |
| B6 | `reset_data` idempotency TOCTOU | Reemplazado SELECT check por INSERT atómico con catch de duplicate key | `api.php:1975-1986` |
| B7 | `append_order_items` race condition (read-then-write) | `SELECT ... FOR UPDATE` + transacción | `api.php` `append_order_items` |

---

## Archivos Modificados

### Backend PHP

| Archivo | Cambios principales |
|---------|-------------------|
| `api.php` | 14+ requirePermission, complete_order AND status, annul_sale transaction+fallback, save_order rowCount, saas_update_subscription rewrite, saas_delete_branch transaction, append_order_items locking, reset_data atomic idempotency, get_state permission, 8 endpoints permission, structured error logging, delete_item errorResponse |
| `backend/auth.php` | Session cookie security, touchUserSession 8h timeout, writeAuditLog sanitization, createAdminSession throws (no exit), suspended session cleanup |
| `db.php` | Credenciales via env vars, 9 CREATE TABLE con columnas completas (tenant_id, branch_id, business_type, plan_level, active, closure_id, UNIQUE KEY) |
| `db_migrations.php` | IF NOT EXISTS en v1-v52, v54 unique_closure_per_day |
| `migrate.php` | CLI migration runner (nuevo) |

### Frontend JavaScript

| Archivo | Cambios principales |
|---------|-------------------|
| `js/app/helpers.js` | `generateId()` con crypto.randomUUID |
| `js/core/utils.js` | Recreado como ES module para tests |
| `js/controllers/pos-controller.js` | 8 null checks, `setActiveCategoryTab` definida |
| `js/controllers/inventory-controller.js` | `let addEl` (fix global implícita) |
| `js/controllers/reservations-controller.js` | `ConfirmDialog.show()` en vez de `confirm()` |
| `js/views/navigation.js` | `DashboardController.init()` llamado |
| `js/views/tenant-users-view.js` | `super_admin` en ROLE_LABELS, escapeHtml duplicado eliminado |
| `js/app/state.js` | (sin cambios significativos) |

### HTML/CSS

| Archivo | Cambios principales |
|---------|-------------------|
| `index.html` | (sin cambios de estructura) |
| `admin.html` | Fix `</i>` tag |
| `styles.css` | `--bg-card`→`--bg-surface`, `--text`→`--text-main`, `--primary-rgb` hardcodeado, skeleton consolidado |
| `.htaccess` | Security headers, block sensitive files, block storage/ |
| `ENDPOINTS.md` | Permiso requerido en cada endpoint |

### Configuración

| Archivo | Cambios principales |
|---------|-------------------|
| `package.json` | `lint:syntax` y `lint:php` scripts, removido `glob` devDependency |
| `.github/workflows/ci.yml` | CI pipeline (PHP syntax, JS syntax, vitest) |

---

## Archivos Nuevos

| Archivo | Propósito |
|---------|-----------|
| `db_migrations.php` | Migraciones SQL extraídas de db.php (v1-v54) |
| `migrate.php` | CLI runner para migraciones (`php migrate.php [--force]`) |
| `.htaccess` | Security headers y bloqueo de archivos sensibles |
| `.github/workflows/ci.yml` | CI/CD pipeline |
| `storage/logs/.gitkeep` | Directorio para logs JSON estructurados |

---

## Archivos Eliminados

| Archivo | Razón |
|---------|-------|
| Ninguno | Se recreó `js/core/utils.js` como ES module en vez de eliminarlo |

---

## Endpoints — Permisos Requeridos

| Endpoint | Permiso | Notas |
|----------|---------|-------|
| `auth_login` | Público | Rate limited |
| `admin_login` | Público | Rate limited |
| `auth_me` | Público | |
| `auth_logout` | Cualquier usuario | |
| `get_state` | `pos` | Incluye cajaMovimientos, cajaCierres |
| `get_pos_state` | `pos` | |
| `get_active_orders` | `pos` | |
| `get_inventory` | `inventory` | |
| `get_reports` | `reports` | |
| `get_dashboard` | `dashboard` | |
| `get_products_by_menu` | `pos` | |
| `get_reservations` | `settings` | |
| `save_reservation` | `settings` | |
| `update_reservation_status` | `settings` | |
| `delete_reservation` | `settings` | |
| `save_order` | `orders` | INSERT IGNORE + rowCount check |
| `update_order_state` | `orders` | |
| `complete_order` | `orders` | Filtra `status = 'pendiente'` |
| `cancel_order` | `orders` | |
| `append_order_items` | `orders` | FOR UPDATE + transacción |
| `save_item` | `inventory` | |
| `save_product` | `inventory` | |
| `save_menu` | `inventory` | |
| `delete_item` | `inventory` | |
| `delete_product` | `inventory` | |
| `delete_menu` | `inventory` | |
| `save_prices` | `settings` | |
| `save_business_info` | `settings` | |
| `save_table` | `settings` | |
| `delete_table` | `settings` | |
| `reorder_tables` | `settings` | |
| `annul_sale` | `settings` | Transacción + extraId fallback |
| `save_caja_movimiento` | `settings` | |
| `delete_caja_movimiento` | `settings` | Verifica cierre |
| `save_caja_cierre` | `settings` | |
| `reset_data` | `settings` | Idempotencia atómica (INSERT) |
| `get_tenant_users` | `settings` | |
| `create_tenant_user` | `settings` | Password ≥ 8 chars |
| `edit_tenant_user` | `settings` | Password ≥ 8 chars, tenant scoped |
| `delete_tenant_user` | `settings` | Tenant scoped |
| `export_tenant_data` | `settings` | JSON download |
| `get_saas_admin` | `saas_admin` | |
| `saas_create_tenant` | `saas_admin` | |
| `saas_edit_tenant` | `saas_admin` | |
| `saas_delete_tenant` | `saas_admin` | Transacción |
| `saas_create_branch` | `saas_admin` | |
| `saas_edit_branch` | `saas_admin` | |
| `saas_delete_branch` | `saas_admin` | Transacción |
| `saas_create_user` | `saas_admin` | Valida branch→tenant, no crea super_admin |
| `saas_edit_user` | `saas_admin` | Valida branch→tenant |
| `saas_delete_user` | `saas_admin` | |
| `saas_update_subscription` | `saas_admin` | SELECT→INSERT/UPDATE |

---

## Mejoras Pendientes Sugeridas

### Prioridad Alta

1. **Frontend role-based visibility**: Los permisos de backend existen pero el frontend muestra/oculta tabs basado en `data-role` HTML. Unificar con `AppStore.state.permissions`.

2. **Caja movimientos — cierre automático**: `save_caja_cierre` podría automáticamente ejecutar `reset_data` para simplificar el flujo del usuario.

3. **Logout automático en 401**: El frontend detecta 401 pero no siempre redirige al login. Agregar interceptor global.

### Prioridad Media

4. **Optimización de `get_state`**: Carga todo el catálogo + historial + movimientos. Podría dividirse en endpoints más granulares o usar caché.

5. **Pedidos — WebSocket/polling**: El polling cada 30s para `get_active_orders` es ineficiente. Considerar Server-Sent Events o WebSocket.

6. **Reportes — Paginación**: `get_reports` carga hasta 500 ventas de golpe. Agregar cursor-based pagination.

7. **Auditoría completa**: Algunos endpoints no llaman `writeAuditLog`. Falta log en: `get_state`, `get_pos_state`, `get_inventory`, `get_reports`.

8. **Test coverage**: Solo hay tests para funciones puras de state/helpers. No hay tests de integración para la API PHP.

### Prioridad Baja

9. **Error messages user-friendly**: Algunos errores SQL se muestran tal cual al usuario. Traducir a mensajes amigables.

10. **CSS variables theme**: Crear tema oscuro usando las CSS variables existentes.

11. **PWA / Service Worker**: Para funcionamiento offline en tablets de cocina.

12. **Backup automático**: `export_tenant_data` existe pero no se ejecuta automáticamente. Cron job o botón de backup programado.

13. **Rate limiting en más endpoints**: Solo `auth_login` y `admin_login` tienen rate limiting. Considerar en `save_order`, `annul_sale`.

14. **Compresión de imágenes**: Los logos de tenant se suben sin optimizar. Agregar redimensionamiento server-side.

15. **Database connection pooling**: Cada request crea una nueva conexión PDO. Considerar connection pooling si se migra a un servidor con PHP-FPM.
