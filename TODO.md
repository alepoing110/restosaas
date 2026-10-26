# TODO - RestoCloud

## Estado actual

- [x] Creacion automatica de base y migraciones locales.
- [x] Creacion del superadmin en base sin usuarios mediante variables de entorno.
- [x] Pedidos, stock, reservas y cierres de caja implementados.
- [x] Categorias, descuentos, promociones y cuentas por cobrar implementados.
- [x] Impresion local y agente Windows implementados.
- [x] PWA base, service worker y cola offline de pedidos implementados.
- [x] WebSocket con handshake y frames reales implementado.

## Antes de produccion

- [ ] Crear y validar `.env.production` en el servidor.
- [ ] Ejecutar `php migrate.php` en staging y verificar el superadmin.
- [ ] Corregir y probar todas las consultas con scope `tenant_id + branch_id`.
- [ ] Ejecutar pruebas de aislamiento entre tenants y sucursales.
- [ ] Probar pedidos, reservas, stock, caja, devoluciones y reportes con datos reales de prueba.
- [ ] Configurar `RESTOCLOUD_PRINT_TOKEN` y validar impresion en Windows.
- [ ] Configurar worker de WhatsApp y WebSocket con supervisor o systemd.
- [ ] Configurar backups automaticos y probar restauracion.
- [ ] Completar pruebas de integracion contra una base aislada.
- [ ] Ejecutar prueba piloto antes de abrir el sistema a todos los usuarios.

## Mejoras siguientes

- [ ] Manifest e instalacion PWA con iconos y pruebas Android/iOS.
- [ ] Sincronizacion offline con idempotencia y resolucion de conflictos.
- [ ] Busqueda global paginada.
- [ ] Reapertura controlada de pedidos cerrados.
- [ ] Notificaciones push.
- [ ] API movil versionada.
