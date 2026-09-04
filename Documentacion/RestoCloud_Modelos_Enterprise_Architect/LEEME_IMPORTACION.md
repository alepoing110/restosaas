# RestoCloud — Modelos para Sparx Enterprise Architect

Este paquete contiene los modelos de análisis y diseño de RestoCloud aplicando ICONIX.

## Importación principal en Enterprise Architect

1. Cree un proyecto nuevo en Sparx Enterprise Architect (`.qea` o `.eapx`).
2. En el navegador del proyecto, seleccione el paquete raíz.
3. Abra **Publish / Model Exchange / Import XMI** (el nombre puede variar según la versión).
4. Para cargar los elementos semánticos, seleccione `RestoCloud_ICONIX_UML2.1.xmi`.
5. Repita la importación seleccionando `RestoCloud_Diagramas_EA_XMI1.1.xml`; este segundo archivo contiene los lienzos y posiciones específicas de Enterprise Architect.
6. Active la opción para conservar identificadores globales si EA la muestra.
7. Importe el modelo.

## Método recomendado para crear los diagramas visuales

Si su versión de Enterprise Architect importa los elementos pero no muestra los lienzos:

1. Abra **Specialize > Tools > Scripting**.
2. Cree un grupo de scripts de tipo **JScript**.
3. Cree un script nuevo dentro del grupo.
4. Abra `Crear_Diagramas_RestoCloud_EA.js`, copie todo su contenido y péguelo en el editor de EA.
5. En el Browser de EA, seleccione el paquete raíz donde desea crear los modelos.
6. Ejecute el script.
7. Actualice el Browser con `F5`.

El script utiliza el Automation Interface de EA y crea directamente 12 diagramas nativos con objetos, coordenadas y conectores.

El XMI contiene los elementos UML y su organización por paquetes. XMI estandariza los elementos semánticos, pero no garantiza el mismo diseño visual del lienzo entre versiones de EA. Por esa razón se incluyen fuentes PlantUML para reproducir los diagramas y ajustar su presentación académica.

## Contenido

- `RestoCloud_ICONIX_UML2.1.xmi`: modelo UML semántico importable.
- `RestoCloud_Diagramas_EA_XMI1.1.xml`: 12 lienzos específicos de Enterprise Architect.
- `Crear_Diagramas_RestoCloud_EA.js`: script nativo recomendado para crear los 12 diagramas dentro de EA.
- `catalogo_casos_uso.csv`: 36 casos de uso con actores y módulos.
- `matriz_trazabilidad.csv`: requisitos funcionales/no funcionales y casos de uso.
- `diagramas_plantuml/`: 15 diagramas fuente.

## Paquetes del modelo

1. Requisitos.
2. Actores y casos de uso.
3. Modelo de dominio.
4. Análisis de robustez.
5. Diagramas de secuencia.
6. Modelo de clases lógico.
7. Máquinas de estado.
8. Componentes y arquitectura.
9. Despliegue.
10. Trazabilidad.

## Nota metodológica

Los diagramas de robustez y secuencia se prepararon para los procesos críticos. Los CRUD repetitivos se documentan en el catálogo y pueden agregarse como diagramas adicionales si la guía de tesis exige uno por cada caso de uso.

## Validación pendiente con el tesista

Los nombres y estados se extrajeron de la implementación disponible. Antes de cerrar la versión final de la tesis deben validarse con las reglas reales del restaurante, especialmente el flujo exacto de estados del pedido y los actores externos.
