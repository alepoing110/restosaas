# Arquitectura — RestoCloud

RestoCloud es un POS/SaaS multi-tenant para restaurantes/pensiones, construido en PHP puro (sin framework) en el backend y JavaScript modular (sin build step) en el frontend. No usa ORM ni bundler: la simplicidad de despliegue en hosting compartido (InfinityFree/cPanel) es una restricción de diseño explícita.

## Principios de diseño

1. **Sin framework pesado.** El objetivo es que el proyecto corra en cualquier hosting PHP compartido con MySQL, sin `composer install` ni pasos de build en el servidor.
2. **Multi-tenant de esquema único.** Todas las tablas de negocio llevan `tenant_id` (y la mayoría también `branch_id`). El aislamiento entre tenants se garantiza a nivel de **código de aplicación**, no de base de datos separada — por eso toda query de lectura/escritura debe ir *siempre* scopeada por `tenant_id`/`branch_id`, sin excepciones. Ver la sección "Reglas de aislamiento multi-tenant" más abajo: es la regla más importante de todo el proyecto.
3. **Backend modular por dominio.** `api.php` es solo un dispatcher; toda la lógica vive en `backend/api/*.php`, un archivo por dominio de negocio.
4. **Frontend por capas (vista / controlador / estado).** Sin framework de UI: `AppStore` centraliza estado, las vistas solo renderizan, los controladores manejan eventos.

## Backend

### Flujo de una request

```
Cliente (fetch) → api.php (dispatcher)
                     ├─ CORS (whitelist de orígenes)
                     ├─ resolveAuthContext()   (sesión → tenant_id/branch_id/role)
                     ├─ requireCsrfToken()     (solo POST, acciones no públicas)
                     └─ $actionHandlers[$action]($pdo, $authContext, $input)
                            ↓
                     backend/api/<dominio>.php
                            ↓
                     requirePermission($authContext, 'permiso')
                            ↓
                     Query SQL siempre con WHERE tenant_id = :tid [AND branch_id = :bid]
```

### Módulos (`backend/api/`)

| Archivo | Dominio |
|---|---|
| `auth.php` | Login, logout, `auth_me`, emisión de token CSRF |
| `orders.php` | Pedidos: crear, actualizar estado, completar, cancelar, agregar ítems |
| `inventory.php` | Catálogo (productos, menús, mesas) — ítems legacy y tabla `products` unificada |
| `cash.php` | Caja: movimientos, cierres, reporte diario, anulación de ventas |
| `tables.php` | Mesas y su orden en el plano |
| `reservations.php` | Reservas |
| `discounts.php` | Descuentos porcentuales, fijos y promociones (buy-X-get-Y) |
| `categories.php` | Categorías de producto personalizadas (CRUD + reordenamiento) |
| `stock.php` | Historial de stock, snapshots diarios |
| `dashboard.php` | Métricas agregadas para el panel de KPIs |
| `export.php` | Exportación de datos por tenant (backup en JSON) |
| `saas.php` | Panel de administración SaaS: alta/baja de tenants, sucursales, usuarios, planes |
| `ws-broadcast.php` | Publica eventos al servidor WebSocket para actualizaciones en tiempo real |
| `helpers.php` | Utilidades compartidas: `requirePermission`, `errorResponse`, `getCatalogForTenant` (con caché), etc. |

Archivos de infraestructura fuera de `backend/api/`:

- **`db.php`** — conexión PDO. Detecta el entorno según `DOCUMENT_ROOT` (`infinityfree`/`epizy` → producción, carga `.env.production`; si no, desarrollo, carga `.env.local`) y solo corre migraciones si `RUN_MIGRATIONS=1` o `APP_ENV=development` (en producción las migraciones se aplican con `migrate.php`, no en cada request).
- **`db_migrations.php`** — definición de las migraciones versionadas (`_migrations` como tabla de control).
- **`migrate.php`** — script de línea de comandos para aplicar migraciones en producción (`php migrate.php --force`).
- **`backend/auth.php`** — sesiones (`ensureSessionStarted` con cookies `HttpOnly`/`Secure`/`SameSite=Lax`), `resolveAuthContext`, rate limiting de login basado en archivos, generación/validación de CSRF (`generateCsrfToken`/`requireCsrfToken`), auditoría (`writeAuditLog`, con redacción de campos sensibles).
- **`backend/cache.php`** — caché de archivo con TTL, con claves siempre compuestas `tenant_id:branch_id` para que nunca se mezcle catálogo entre tenants.
- **`backend/websocket-server.php`** — servidor WebSocket para push de eventos (pedidos nuevos, cambios de estado) a las pantallas conectadas, evitando polling.
- **`health.php`** — healthcheck público (sin autenticación) para monitoreo externo. Solo debe exponer `status: ok/error`, nunca detalle de excepciones.

### Reglas de aislamiento multi-tenant (no negociables)

- Ninguna tabla de catálogo puede depender solo de una PK global (`id`). El patrón correcto para guardar es: `SELECT ... WHERE id = :id AND tenant_id = :tid [AND branch_id = :bid]` y decidir explícitamente INSERT o UPDATE — **nunca** `INSERT ... ON DUPLICATE KEY UPDATE` sin ese chequeo previo, porque MySQL resuelve el conflicto solo por PK/índice único, sin importar el tenant.
- Toda tabla de catálogo debe tener `UNIQUE (tenant_id, branch_id, id)` además de su PK, como cinturón de seguridad a nivel de base de datos.
- Todo endpoint que mute datos debe llamar `requirePermission($authContext, '<permiso>')` explícitamente — la UI puede ocultar botones, pero la única barrera real es el backend.

### Modelo de permisos

`getRolePermissions()` define qué puede hacer cada rol (`cajero`, `admin`, `owner`, `saas_admin`, `super_admin`). Cada acción mutante en `backend/api/*.php` debe declarar el permiso mínimo que requiere (`inventory`, `settings`, `orders`, `reports`, `dashboard`, etc.). Si agregás una acción nueva y no estás seguro de qué permiso pedirle, pedí el más restrictivo por defecto y bajalo después si hace falta — nunca al revés.

## Frontend

```
index.html
  └─ app.js (orquestador de navegación y eventos globales)
       ├─ js/core/api-client.js   → único punto de fetch; adjunta X-CSRF-Token automáticamente
       ├─ js/core/store.js        → estado compartido (AppStore)
       ├─ js/core/view-controller.js → registro de vistas por pestaña
       ├─ js/app/*.js             → helpers, cálculo de stock, carrito, navegación, cabecera
       ├─ js/views/*.js           → una vista por pantalla (pos, orders, menu-config, inventory, reports, dashboard)
       ├─ js/controllers/*.js     → eventos y acciones de usuario por pantalla
       └─ js/ui/{toast,modal}.js  → componentes de UI compartidos
```

CSS dividido en `css/_*.css` por componente (variables, layout, sidebar, POS, modales, responsive, etc.), en vez del monolito anterior de +4000 líneas.

Un Service Worker (`sw.js`) cachea assets estáticos (estrategia network-first para llamadas a la API) para soporte offline básico tipo PWA.

## Entornos y despliegue

| Archivo | Entorno | `DB_HOST` típico |
|---|---|---|
| `.env.local` | Desarrollo (XAMPP/Laragon) | `127.0.0.1` |
| `.env.production` | Producción (InfinityFree) | `sql301.infinityfree.com` |
| `.env` | Fallback si no existen los anteriores | — |

**Importante:** `.env.production` y `.env.local` contienen credenciales reales y **no deben quedar accesibles por HTTP**. Confirmá siempre que `.htaccess` los bloquee explícitamente (no alcanza con bloquear `.env` a secas si el archivo real que se sirve es `.env.production`). Ver `CONTRIBUTING.md` → "Checklist de seguridad antes de desplegar".

`deploy-cpanel.sh` documenta el proceso de subida manual a cPanel/InfinityFree. Scripts de utilidad como `create-admin.php` y cualquier script de diagnóstico son **solo para CLI**: deben rechazar ejecución vía HTTP y bloquearse también en `.htaccess`.

## Testing y CI

- `__tests__/*.js` — tests unitarios de frontend (Vitest) sobre `js/core` y `js/app`.
- `tests/api-test.php` — tests de integración sobre el backend (existencia de funciones, permisos, patrones de seguridad como CSRF).
- `phpstan.neon` — análisis estático (nivel 5) sobre `backend/`, `api.php`, `db.php`, `db_migrations.php`.
- `.github/workflows/ci.yml` — corre `php -l`, `npm run lint:syntax` y `npm test` en cada push/PR.

> Nota de mantenimiento: `lint:php` y el workflow de CI hoy solo validan sintaxis de `api.php`, `db.php`, `db_migrations.php`, `migrate.php` y `backend/auth.php`. Si agregás archivos nuevos a `backend/api/` o a la raíz (como `health.php`, `create-admin.php`), sumalos también a esa lista — si no, el CI puede pasar en verde con un archivo roto.