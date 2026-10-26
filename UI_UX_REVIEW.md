# Revisión de UI/UX y responsive — 2026-10-21

## Hallazgos corregidos

- La hoja de componentes se importaba después de las reglas responsive, anulando ajustes de móvil.
- El contenido conservaba un ancho descontando la barra lateral incluso cuando esta estaba fuera de pantalla.
- El encabezado no podía acomodar nombres largos, empresa, sucursal y acciones en tablet.
- La sesión se ocultaba por completo en pantallas pequeñas, incluyendo cambiar de contexto y cerrar sesión.
- Columnas con tamaño mínimo automático y canvas desbordaban el viewport en inventario, finanzas y dashboard.
- Algunos botones en toolbars verticales recibían `flex-basis: 140px`, aumentando innecesariamente su altura.
- Las pestañas ejecutaban clic en `touchstart`, además del clic nativo, e interferían con el desplazamiento horizontal.
- El menú cerrado seguía disponible al tabular; ahora utiliza `inert` en móvil.
- Los modales cerrados seguían teniendo controles potencialmente enfocables; se ocultan también mediante `visibility`.
- El diálogo de confirmación no resolvía su promesa al cerrarse con Escape.
- El indicador de carga podía duplicarse y no exponía su estado a tecnologías de asistencia.
- La pantalla Clientes existía sin acceso en el menú; se añadió con el permiso `financial_reports` que requiere su backend.

## Organización de estilos

`index.html` carga módulos CSS directamente para permitir descargas en paralelo:

1. Variables y base.
2. Sidebar y layout.
3. Componentes de POS, pedidos, reportes y modales.
4. Componentes adicionales.
5. `_ui-system.css`: reglas compartidas de consistencia, contexto, foco y contención.
6. `_responsive-mobile.css`: reglas responsive al final de la cascada.

`styles.css` mantiene el mismo orden como punto de entrada compatible con importaciones antiguas.
`_public.css` comparte ajustes de landing y pago sin cargar estilos de la aplicación privada.
Las reglas nuevas de la aplicación están limitadas a `screen` para no alterar los tickets térmicos.

## Interacción y accesibilidad

- Foco visible, enlace para saltar navegación y `aria-current` actualizado al navegar.
- Campos Empresa y Sucursal etiquetados y visibles cuando están disponibles.
- Menú móvil: Escape, recuperación de foco, tabulación contenida e inactividad del menú cerrado.
- Gestión de foco con exclusión de controles ocultos/desactivados y soporte de modales anidados.
- Login etiquetado como diálogo, mensaje anunciable y botón de contraseña con nombre/estado.
- Toasts anunciables; carga con `aria-busy`; confirmación con título asociado y cancelación por Escape.
- Mayor contraste del texto secundario y de las etiquetas sobre naranja.
- Modales y login con límites por viewport dinámico y desplazamiento para contenido largo.
- Formularios públicos con autocompletado, tamaño de entrada legible y errores anunciables.

## Verificaciones ejecutadas

Navegador: Microsoft Edge headless mediante Playwright, con rutas locales y API simulada.
Los recursos externos de fuentes/iconos fueron bloqueados en esta comprobación para hacerla independiente de la red.

- 11 pantallas: POS, pedidos activos, configuración, inventario, reportes, clientes, finanzas, dashboard, reservas, usuarios y SaaS.
- 7 anchos: 320, 390, 568, 768, 1024, 1440 y 1920 px. Altura de 320 px en el caso horizontal de 568 px.
- 77 combinaciones sin desbordamiento horizontal de página ni encabezado.
- Controles de sesión visibles en todas las combinaciones.
- Sin excepciones JavaScript durante estas comprobaciones.
- Menú móvil, Escape, `inert`, sidebar colapsada, abrir/cerrar carrito, cancelar confirmación y carga sin duplicados.
- Límites de tamaño de los modales estáticos a 320 px y en orientación horizontal.
- Comprobación de desbordamiento del POS en tema claro en los siete anchos.
- Landing y pago sin desbordamiento a 320 px.
- `npm run lint:syntax`: 48 archivos correctos; `node --check app.js` y `node --check sw.js` correctos.
- `php tests/api-test.php`: 136 comprobaciones correctas.

## Publicación y comprobación en dispositivos

Subir conjuntamente `index.html`, `landing.html`, `pago.html`, `styles.css`, `css/`, los módulos JavaScript modificados, `app.js` y `sw.js`.
El Service Worker usa la versión v13 y las hojas/scripts modificados tienen versión en su URL.

Verificar en un dispositivo real con los datos del negocio: teclado virtual, zoom, fuentes/iconos CDN,
tablas con muchos registros, formularios de reservas/pago y vista previa/impresión de tickets.
Estas comprobaciones de layout no certifican cumplimiento WCAG completo ni validan operaciones de producción.
