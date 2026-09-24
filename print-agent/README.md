# RestoCloud Print Agent

Agente local para Windows que lista las impresoras instaladas y recibe trabajos de texto desde RestoCloud.

## Uso

1. Instalar Node.js LTS en la computadora de caja.
2. Abrir PowerShell en esta carpeta.
3. Ejecutar `npm start`.
4. En RestoCloud, abrir `Configuración del negocio > Impresión`.
5. Pulsar `Actualizar impresoras` y guardar la configuración.

El agente escucha solamente en `127.0.0.1:3210`. Si se define `RESTOCLOUD_PRINT_TOKEN`, la aplicación debe enviar el mismo token mediante el mecanismo de configuración del agente.
