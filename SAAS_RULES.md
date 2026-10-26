# Regla SaaS Obligatoria

RestoCloud es un sistema SaaS multi-tenant y multi-sucursal. **Ninguna operación puede ejecutarse fuera del alcance combinado de `tenant_id` y `branch_id`.**

## Regla Principal

Toda lectura, creación, modificación, eliminación, bloqueo, impresión y reporte debe respetar siempre:

```text
tenant_id = contexto autenticado
branch_id = sucursal activa del contexto autenticado
```

Nunca se deben aceptar `tenant_id` ni `branch_id` del navegador como autoridad. El backend debe obtenerlos del contexto autenticado y validar la sucursal activa.

## Backend Y Base De Datos

- Todas las consultas `SELECT` deben filtrar por `tenant_id` y `branch_id` cuando la entidad sea sucursal-específica.
- Todas las consultas `INSERT` deben guardar ambos valores desde el contexto autenticado.
- Todas las consultas `UPDATE` y `DELETE` deben incluir ambos valores en el `WHERE`.
- Todas las operaciones `FOR UPDATE`, validaciones de existencia y comprobaciones de duplicados deben usar ambos valores.
- Los `JOIN`, agregados, reportes, historial, pagos, inventario, reservas y devoluciones deben conservar el mismo alcance.
- No confiar en IDs enviados por el cliente sin comprobar que pertenecen al tenant y branch actuales.
- Las transacciones deben validar pertenencia y disponibilidad antes de modificar datos.
- Las respuestas de error no deben revelar datos de otro tenant o sucursal.

## Frontend

- Enviar el ID de la sucursal solo como dato operativo; nunca tratarlo como autorización.
- No reutilizar objetos, pedidos, mesas, clientes, impresoras o configuraciones de otra sucursal.
- Al cambiar de sucursal, limpiar o recargar todo el estado dependiente de la sucursal anterior.
- Los pedidos, agregados, pagos e impresiones deben conservar el mismo contexto SaaS.

## Pedidos Y Mesas

- Un pedido solo puede usar productos, mesa y configuración de su `tenant_id + branch_id`.
- La ocupación de una mesa debe validarse dentro del mismo tenant y branch, idealmente dentro de una transacción con bloqueo.
- Agregar productos debe actualizar el pedido correcto dentro del mismo tenant y branch.
- Los lotes de productos agregados, tickets y estados de entrega deben pertenecer al pedido y contexto correctos.

## Impresión

- Las impresoras y perfiles de impresión se cargan de la configuración del tenant y branch activos.
- Cocina, cliente, pagos y mesero nunca deben usar configuración de otra sucursal.
- Los tickets deben imprimirse únicamente para pedidos pertenecientes al tenant y branch autenticados.
- El agente local solo recibe el trabajo ya validado por la aplicación; no decide el alcance SaaS.

## Checklist Antes De Aprobar Cambios

- [ ] La operación obtiene el contexto autenticado.
- [ ] Cada `SELECT` está limitado por `tenant_id + branch_id`.
- [ ] Cada `INSERT` guarda `tenant_id + branch_id`.
- [ ] Cada `UPDATE` y `DELETE` filtra por `tenant_id + branch_id`.
- [ ] Los IDs recibidos fueron validados dentro del mismo alcance.
- [ ] Las transacciones y bloqueos conservan el alcance.
- [ ] El frontend no puede cambiar el tenant ni escapar de la sucursal activa.
- [ ] Las pruebas cubren aislamiento entre tenants y sucursales.

**Esta regla es obligatoria para cualquier código nuevo o modificación existente.**
