# Changelog

Todos los cambios relevantes de RestoCloud se documentan en este archivo. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/).

## [Unreleased] — Seguridad (a resolver antes de confirmar el próximo despliegue)

Hallazgos de la revisión de seguridad post-2.0.0, pendientes de merge:

### Por hacer — Crítico
- Rotar la contraseña de la base de datos de producción (credencial ya expuesta).
- Agregar `.env.production` y `.env.local` al bloque `<FilesMatch>` de `.htaccess` (hoy solo bloquea `.env`/`.env.example`).
- Agregar guard `php_sapi_name() !== 'cli'` a `create-admin.php` y bloquearlo también en `.htaccess`.
- Eliminar `db_diagnostico_delete_me.php` del repo y del servidor de producción.
- Sanitizar el mensaje de excepción devuelto por `health.php` en caso de fallo de conexión (no exponer `$e->getMessage()` públicamente).

### Por hacer — Alto
- Bloquear `storage/cache/` en `.htaccess` y sumarlo a `.gitignore` (contiene catálogo real de tenants sin cifrar).
- Agregar `requirePermission($authContext, 'pos')` a `handle_calculate_discount` en `backend/api/discounts.php` para consistencia con el resto del módulo.

### Por hacer — Menor
- Typo: "Sesión expirada por inaktividad" → "inactividad" (`backend/auth.php` / mensajes de sesión).
- Sumar `backend/api/*.php`, `health.php` y `create-admin.php` a `lint:php` en `package.json` y al workflow de CI (hoy solo cubren `api.php`, `db.php`, `db_migrations.php`, `migrate.php`, `backend/auth.php`).

## [2.0.0] - 2026-07-21

Refactor mayor: proyecto renombrado de SumajFood a **RestoCloud**.

### Added
- **Protección CSRF**: token por sesión, requerido en todas las requests POST que mutan datos.
- **API modular**: `api.php` monolítico dividido en 13 módulos de dominio (`backend/api/{auth,orders,inventory,cash,tables,reservations,saas,dashboard,export,categories,stock,discounts,ws-broadcast}.php`).
- **Sistema de caché**: caché de archivo con TTL de 30s, con invalidación e índice siempre scopeados por tenant.
- **Categorías personalizadas**: CRUD de categorías de producto con reordenamiento drag-and-drop.
- **Importación de backup**: importar datos de un tenant desde un JSON de respaldo, con vista previa de confirmación.
- **Reporte de caja diario**: endpoint de reporte detallado con desglose de medios de pago y gastos.
- **Historial de stock**: seguimiento multi-día con snapshots diarios y registro de eventos.
- **Sistema de descuentos**: porcentuales, monto fijo y promociones tipo "llevá X pagá Y", con rangos de fecha.
- **CSS modular**: `styles.css` (4.298 líneas) dividido en 10 archivos por componente (`css/_*.css`).
- **Módulos ES**: exports agregados a los módulos core (`helpers`, `toast`, `modal`, `store`, `api-client`).
- **Health check**: endpoint `/health.php` para monitoreo externo (base de datos, storage, versión de PHP, extensiones).
- **Especificación OpenAPI**: documentación completa de la API en `openapi.json`.
- **Service Worker (PWA)**: caché offline de assets estáticos, estrategia network-first para la API.

### Changed
- `api.php`: de 2.374 líneas a 203, usando patrón dispatcher.
- `helpers.js`: funciones de conteo de stock movidas desde `stock.js` para eliminar duplicación.
- Suite de tests: de 60 a 72 tests de integración.

### Security
- Corrección del bug crítico de aislamiento multi-tenant: `save_item`/`save_product`/`save_menu`/`save_table` ya no usan `INSERT ... ON DUPLICATE KEY UPDATE` sin validar `tenant_id` — ahora hacen `SELECT` scopeado antes de decidir INSERT/UPDATE (evita que un tenant sobrescriba datos de otro por colisión de ID).
- `requirePermission()` agregado a los endpoints mutantes que antes no lo exigían (precios, info del negocio, catálogo, pedidos, caja).
- Validación de pertenencia `tenant_id`↔`branch_id` en `saas_create_user`/`saas_edit_user`.
- `saas_delete_tenant` envuelto en transacción.
- CORS restringido a whitelist de orígenes (antes reflejaba cualquier `Origin` con `Allow-Credentials: true`).
- Cookies de sesión con `HttpOnly`, `Secure` (dinámico según HTTPS) y `SameSite=Lax`.
- Rate limiting en `auth_login`/`admin_login` (5 intentos / 15 min por cuenta).
- Redacción de campos sensibles (`password`, `token`, `secret`, etc.) antes de escribir en `audit_logs`.
- Mensajes de error de conexión a base de datos sanitizados en `db.php` (ya no se expone `$e->getMessage()` al cliente).
- Migraciones ya no corren en cada request: `db.php` solo las ejecuta si `RUN_MIGRATIONS=1`/`APP_ENV=development`; en producción se aplican con `php migrate.php`.
- Credenciales de base de datos movidas a variables de entorno (`.env.local`/`.env.production`) en vez de estar hardcodeadas.

### Fixed
- `annul_sale`: al anular una venta cerrada que incluía un "almuerzo" (combo sopa + segundo), ahora restaura el stock de **ambos** componentes; antes solo restauraba la sopa, generando drift de inventario en el segundo.
- `save_order`: reemplazado el `ON DUPLICATE KEY UPDATE id = id` (no-op confuso) por `INSERT IGNORE`, más explícito.
- `save_order`/`save_caja_movimiento`: validación de formato de `timestamp` antes de usarlo (evita warning/valor inválido si falta o está mal formado).
- `delete_caja_movimiento`: ya no permite borrar un movimiento que ya pertenece a un cierre de caja (`closure_id IS NOT NULL`).
- `saas_create_user`: ya no permite crear un rol `super_admin` sin restricción adicional.

## [1.0.0] - Releases anteriores (como SumajFood)

### Features
- Sistema POS multi-tenant para restaurantes.
- Gestión de pedidos en tiempo real.
- Control de inventario con stock por ítem.
- Configuración de mesas y plano del salón.
- Sistema de reservas.
- Caja registradora con cierres diarios.
- Panel de administración SaaS para gestión de tenants.
- Dashboard con gráficos y analíticas.
- Soporte de impresión de tickets (formato térmico).
- Selector de tema claro/oscuro.