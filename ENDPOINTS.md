# Endpoints API

Este archivo centraliza los endpoints actuales de `RestoCloud` para facilitar:

- migraciones a otro servidor,
- cambios de dominio o subcarpeta,
- refactor del backend hacia rutas REST futuras,
- auditoría rápida de contratos frontend/backend.

## Base URL actual

El frontend consume un único entrypoint:

- `api.php?action=<nombre_endpoint>`

Ejemplos:

- `http://localhost/RestoCloud/api.php?action=auth_me`
- `http://localhost/RestoCloud/api.php?action=get_state`

## Dónde cambiar la URL base

La URL base del API se resuelve en:

- [js/core/api-client.js](/abs/path/C:/xampp/htdocs/RestoCloud/js/core/api-client.js:1)

Prioridad de resolución:

1. `window.APP_CONFIG.API_BASE_URL`
2. URL derivada del script actual
3. fallback local `http://localhost/RestoCloud/api.php` si la app se abrió como `file://`

Para despliegue en servidor, la opción recomendada es definir:

```html
<script>
  window.APP_CONFIG = {
    API_BASE_URL: 'https://tu-dominio.com/RestoCloud/api.php'
  };
</script>
```

## Endpoints

### Auth

- `auth_login`
  - método: `POST`
  - body: `{ email, password }`
  - uso: iniciar sesión
  - permiso: **Público** (sin autenticación)

- `auth_me`
  - método: `GET`
  - uso: recuperar sesión actual
  - permiso: **Público** (sin autenticación)

- `auth_logout`
  - método: `POST`
  - body: `{}`
  - uso: cerrar sesión
  - permiso: **Cualquier usuario autenticado**

### SaaS Admin

- `get_saas_admin`
  - método: `GET`
  - uso: cargar resumen, tenants, sucursales y usuarios
  - permiso: **saas_admin**

- `saas_create_tenant`
  - método: `POST`
  - body: `{ tenant_name, tenant_slug, owner_name, owner_email, owner_password, branch_name, plan_code, subscription_status }`
  - uso: alta completa de tenant
  - permiso: **saas_admin**

- `saas_create_branch`
  - método: `POST`
  - body: `{ tenant_id, branch_name }`
  - uso: crear sucursal
  - permiso: **saas_admin**

- `saas_create_user`
  - método: `POST`
  - body: `{ tenant_id, branch_id, name, email, password, role }`
  - uso: crear usuario
  - permiso: **saas_admin**

- `saas_update_subscription`
  - método: `POST`
  - body: `{ tenant_id, plan_code, status }`
  - uso: actualizar plan/estado de suscripción
  - permiso: **saas_admin**

### Estado general y carga de pantallas

- `get_state`
  - método: `GET`
  - uso: bootstrap general de la app
  - permiso: **Cualquier usuario autenticado**

- `get_pos_state`
  - método: `GET`
  - uso: datos del POS
  - permiso: **Cualquier usuario autenticado**

- `get_active_orders`
  - método: `GET`
  - uso: pedidos activos y mesas
  - permiso: **Cualquier usuario autenticado**

- `get_inventory`
  - método: `GET`
  - uso: inventario y catálogos
  - permiso: **Cualquier usuario autenticado**

- `get_reports`
  - método: `GET`
  - uso: ventas, caja y cierres
  - permiso: **reports**
  - respuesta incluye `reportContext` con tenant, sucursal, negocio, usuario generador y filtros de reporte.

- `get_dashboard`
  - método: `POST`
  - body: `{ start_date, end_date }`
  - uso: dashboard ejecutivo
  - permiso: **dashboard**
  - respuesta incluye `reportContext` con tenant, sucursal, negocio, usuario generador y rango de fechas.

### Configuración de negocio

- `save_prices`
  - método: `POST`
  - body: `{ almuerzo, segundo, sopa }`
  - permiso: **settings**

- `save_business_info`
  - método: `POST`
  - body: `{ nombre_restaurante, direccion, telefono }`
  - permiso: **settings**

- `export_tenant_data`
  - método: `POST`
  - uso: exportar datos del tenant
  - permiso: **settings**

### Catálogo legacy

- `save_item`
  - método: `POST`
  - body base: `{ type, id, name, stock, active? }`
  - variantes:
    - `segundo`
    - `sopa`
    - `plato_extra` con `price`
    - `extra` con `price`
  - permiso: **inventory**

- `delete_item`
  - método: `POST`
  - body: `{ type, id }`
  - permiso: **inventory**

### Pedidos

- `save_order`
  - método: `POST`
  - body: `{ id, customer, items, total, paymentMethod, serviceState, status, timestamp }`
  - permiso: **orders**

- `update_order_state`
  - método: `POST`
  - body: `{ id, state }`
  - permiso: **orders**

- `complete_order`
  - método: `POST`
  - body: `{ id, paymentMethod }`
  - permiso: **orders**

- `cancel_order`
  - método: `POST`
  - body: `{ id }`
  - permiso: **orders**

- `annul_sale`
  - método: `POST`
  - body: `{ id }`
  - permiso: **settings**

### Caja

- `save_caja_movimiento`
  - método: `POST`
  - body: `{ id, type, description, amount, timestamp }`
  - permiso: **settings**

- `delete_caja_movimiento`
  - método: `POST`
  - body: `{ id }`
  - permiso: **settings**

- `save_caja_cierre`
  - método: `POST`
  - body: `{ id, fecha, caja_inicial, ingresos_efectivo, egresos, efectivo_esperado, efectivo_real, diferencia, utilidad_neta, almuerzos_vendidos, segundos_vendidos, sopas_vendidas, extras_vendidos }`
  - permiso: **settings**

- `reset_data`
  - método: `POST`
  - body: `{ closure_id }`
  - permiso: **settings**

### Productos y menús

- `save_product`
  - método: `POST`
  - body: `{ id, name, type, price, stock, menu_id }`
  - permiso: **inventory**

- `delete_product`
  - método: `POST`
  - body: `{ id }`
  - permiso: **inventory**

- `save_menu`
  - método: `POST`
  - body: `{ id, name, start_time, end_time, active }`
  - permiso: **inventory**

- `delete_menu`
  - método: `POST`
  - body: `{ id }`
  - permiso: **inventory**

- `get_products_by_menu`
  - método: `POST`
  - body: `{ menu_id }`
  - permiso: **inventory**

### Mesas

- `save_table`
  - método: `POST`
  - body: `{ id?, name, icon, sort_order }`
  - permiso: **settings**

- `delete_table`
  - método: `POST`
  - body: `{ id }`
  - permiso: **settings**

- `reorder_tables`
  - método: `POST`
  - body: `{ order: string[] }`
  - permiso: **settings**

## Inventario rápido del frontend

Métodos usados directamente por `AppApi`:

- `getState`
- `getPosState`
- `getActiveOrders`
- `getInventory`
- `getReports`
- `getDashboard`
- `getSaasAdmin`
- `login`
- `logout`
- `me`

## Recomendación de despliegue

Antes de mover el sistema a un servidor productivo:

1. Crear `.env.production` con las credenciales del hosting
2. Verificar que `db.php` detecta correctamente el entorno (production vs local)
3. Definir `window.APP_CONFIG.API_BASE_URL` solo si frontend y backend están en dominios distintos
4. Revisar `Access-Control-Allow-Origin` en `api.php` (el dominio de producción debe estar en la lista)
5. Permisos `775` en `storage/` y subcarpetas
6. Ejecutar `php migrate.php --force` para crear/esquema de la BD

## Contrato HTTP vigente

`api.php` aplica esta política en servidor. Una acción de lectura solo acepta
`GET`; toda acción que calcula con datos sensibles o modifica estado solo acepta
`POST`. Los `POST` autenticados requieren `X-CSRF-Token` y tienen rate limiting.
Las excepciones públicas son `auth_login`, `admin_login` y `public_register`;
también están limitadas por intentos en sus propios handlers.

| Dominio | GET | POST |
| --- | --- | --- |
| Autenticación | `auth_me` | `auth_login`, `admin_login`, `auth_logout`, `switch_branch` |
| Estado y paneles | `get_state`, `get_pos_state`, `get_active_orders`, `get_inventory`, `get_reports`, `get_dashboard`, `get_customers`, `get_receivables` | - |
| Pedidos | - | `save_order`, `update_order_state`, `complete_order`, `cancel_order`, `append_order_items`, `annul_sale` |
| Catálogo y menús | `get_products_by_menu` | `save_item`, `delete_item`, `save_product`, `delete_product`, `save_menu`, `delete_menu` |
| Caja y finanzas | `get_daily_report`, `get_financial_report`, `get_branch_comparison`, `get_cash_flow`, `get_control_report`, `get_customers`, `get_receivables` | `save_caja_movimiento`, `delete_caja_movimiento`, `save_caja_cierre`, `reset_data`, `save_financial_expense`, `delete_financial_expense`, `save_customer`, `save_receivable_payment` |
| Mesas y configuración | - | `save_table`, `delete_table`, `reorder_tables`, `save_prices`, `save_business_info` |
| Reservas | `get_reservations` | `save_reservation`, `update_reservation_status`, `delete_reservation` |
| Administración SaaS | `get_saas_admin`, `saas_get_plans` | `saas_create_tenant`, `saas_create_branch`, `saas_create_user`, `saas_update_subscription`, `saas_edit_tenant`, `saas_delete_tenant`, `saas_edit_branch`, `saas_delete_branch`, `saas_edit_user`, `saas_delete_user`, `saas_create_plan`, `saas_update_plan`, `saas_delete_plan`, `saas_approve_payment`, `saas_reject_payment`, `public_register` |
| Usuarios del tenant | `get_tenant_users` | `create_tenant_user`, `edit_tenant_user`, `delete_tenant_user` |
| Categorías | `get_categories` | `save_category`, `delete_category`, `reorder_categories` |
| Datos y stock | `export_tenant_data`, `get_stock_history`, `get_stock_report` | `import_tenant_data` |
| Descuentos | `get_discounts` | `save_discount`, `delete_discount`, `calculate_discount` |
| Chatbot | `get_chatbot_conversations`, `get_chatbot_messages` | `chatbot_simulate`, `chatbot_close_conversation`, `delete_chatbot_conversation`, `chatbot_simulate_local`, `chatbot_save_reply_local`, `chatbot_execute_tool` |

Los filtros de lecturas se envían como query string. Ejemplo:

```text
GET /api.php?action=get_reports&start_date=2026-08-01&end_date=2026-08-31
```

Las respuestas JSON siguen el sobre `{ status, message?, ...datos }`. Para
errores de contrato el API devuelve `400` (acción ausente), `404` (acción
desconocida), `405` (método incorrecto), `401` (sesión) o `403` (CSRF/permisos).
