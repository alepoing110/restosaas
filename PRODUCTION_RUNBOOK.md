# RestoCloud Production Runbook

## Configuracion

1. Copiar `.env.production.example` a `.env.production`.
2. Completar credenciales reales de MySQL, administrador inicial, impresion y WebSocket.
3. Verificar que `.env.production` no sea accesible por HTTP.
4. Confirmar permisos de `storage/` y `storage/backups/`.
5. Verificar extensiones PHP `pdo_mysql`, `curl` y `sockets` si se usara WebSocket.

## Migracion inicial

```bash
php migrate.php
```

Confirmar:

- Las migraciones terminan sin `[ERROR]`.
- La tabla `users` contiene el superadmin inicial.
- La cuenta tiene rol `super_admin` y esta activa.
- Las tablas criticas existen.

## Primer superadmin en hosting sin terminal

Subir juntos `db.php`, `db_migrations.php`, `backend/bootstrap.php` y
`backend/migrations.php`. No basta con subir solo el frontend.

- Si el esquema está incompleto, activar temporalmente `RUN_MIGRATIONS=1` en
  `.env.production` y abrir `api.php?action=auth_me` desde el mismo dominio de la app.
  Las migraciones aditivas completan únicamente columnas/índices que faltan.
- Cuando el esquema esté listo, volver a `RUN_MIGRATIONS=0`.
- Con `users` vacío, cualquier conexión normal inicializa el primer superadmin
  usando `INITIAL_ADMIN_EMAIL` y `INITIAL_ADMIN_PASSWORD`, incluso con migraciones desactivadas.
- Verificar en phpMyAdmin: `SELECT email, role, active FROM users;`.
- Si ya hay usuarios, no se insertan, promueven ni restablecen cuentas automáticamente.
  Cambiar la contraseña en el entorno no cambia la contraseña de una cuenta existente.
- El bootstrap selecciona un tenant y una sucursal activos o los crea, y asigna
  `user_branch_access`. Un bloqueo de MySQL evita dos creaciones simultáneas.
- Si falla, revisar el registro PHP del hosting. Nunca publicar contraseñas ni
  habilitar `display_errors` en producción para diagnosticarlo.

Pruebas locales: `php tests/admin-bootstrap-integration-test.php` y
`php tests/production-bootstrap-test.php`. La segunda crea una base temporal
local y la elimina al terminar; no debe ejecutarse contra producción.

## Backup

Crear un backup antes de cada despliegue:

```bash
bash ops/backup-mysql.sh
```

Restaurar solo con confirmacion explicita:

```bash
CONFIRM_RESTORE=YES bash ops/restore-mysql.sh storage/backups/archivo.sql.gz
```

Despues de restaurar:

```bash
php migrate.php
```

## Workers

Ejecutar `whatsapp-worker.php` cada minuto con cron, supervisor, systemd o un servicio equivalente:

```cron
* * * * * cd /var/www/restocloud && /usr/bin/php whatsapp-worker.php --limit=10 >> storage/logs/whatsapp-worker.log 2>&1
```

Ejecutar `backend/websocket-server.php` con la extension PHP `sockets` habilitada y un reverse proxy TLS si se expone fuera de localhost.

## Prueba piloto

1. Crear un tenant piloto y dos sucursales.
2. Crear usuarios con permisos distintos.
3. Confirmar que cada sucursal solo ve sus clientes, productos, pedidos, reservas y caja.
4. Registrar un pedido y verificar stock, pagos, impresion y reporte.
5. Crear dos reservas solapadas para la misma mesa y verificar el rechazo.
6. Probar cierre de caja, devolucion y restauracion desde backup.
7. Revisar logs durante al menos una jornada antes de abrir el sistema.
