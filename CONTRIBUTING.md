# Guía de contribución — RestoCloud

## Antes de cada cambio (obligatorio)

```bash
npm run lint:syntax   # sintaxis de todos los archivos en js/**
npm run lint:php      # sintaxis de los archivos PHP registrados (ver nota abajo)
npm test              # tests de integración (Vitest + tests/api-test.php)
```

Si cualquiera de estos falla, **no confirmes el cambio** hasta corregirlo. El workflow de CI (`.github/workflows/ci.yml`) corre lo mismo en cada push/PR — si falla en tu máquina, va a fallar ahí también.

> **Nota:** `lint:php` y el CI hoy solo verifican `api.php`, `db.php`, `db_migrations.php`, `migrate.php` y `backend/auth.php`. Si tu cambio toca un archivo en `backend/api/` o agrega un script nuevo en la raíz (como pasó con `health.php`/`create-admin.php`), sumalo a `package.json` y al workflow — si no, puede quedar roto sin que el CI lo detecte.

---

## No modifiques lo que ya funciona

Antes de tocar un archivo, verificá que **realmente necesitás cambiarlo**. Regla general:

- **Si el código funciona correctamente y no está involucrado en tu tarea, no lo modifiques.** No refactorices "de paso", no renombres variables por gusto, no muevas funciones de archivo sin necesidad.
- **Si encontrás código que te parece mejorable pero funciona, documentalo en un issue o comentario** — no lo cambies en el mismo PR que resuelve otro bug o feature. Mezclar cambios funcionales con cosméticos dificulta el review y el rollback.
- **Antes de editar, verificá que el cambio no rompa funcionalidad existente.** Ejecutá `npm test`, `npm run lint:syntax`, y `npm run lint:php` después de cada modificación. Si tocaste PHP, probá el flujo afectado manualmente en el navegador.
- **Si encontrás código que parece muerto**, validá con una búsqueda exhaustiva (`grep` por nombre de función/variable) antes de eliminarlo. A veces tiene un uso indirecto (ejecutado desde HTML inline, desde otro módulo, o en el backend).

---

## Reglas de aislamiento multi-tenant (las más importantes del proyecto)

RestoCloud usa un esquema único de base de datos para todos los tenants. La separación entre negocios depende 100% de que el código respete estas reglas — un error acá significa que un tenant puede ver o sobrescribir datos de otro.

1. **Toda query de lectura o escritura sobre datos de negocio debe filtrar por `tenant_id`** (y `branch_id` cuando aplique). Sin excepciones, ni siquiera "por ahora, ya lo agrego después".
2. **Nunca uses `INSERT ... ON DUPLICATE KEY UPDATE`** en tablas de catálogo sin haber validado antes que el registro con ese `id` pertenece al tenant actual. MySQL resuelve el conflicto solo por índice único/PK, sin mirar el `tenant_id` del payload — si dos tenants generan el mismo `id`, uno puede sobrescribir al otro. Patrón correcto:
   ```php
   $stmt = $pdo->prepare("SELECT id FROM products WHERE id = :id AND tenant_id = :tid AND branch_id = :bid");
   $stmt->execute([':id' => $id, ':tid' => $tenantId, ':bid' => $branchId]);
   if ($stmt->fetch()) {
       // UPDATE explícito, con WHERE tenant_id/branch_id
   } else {
       // INSERT
   }
   ```
3. **Toda tabla de catálogo nueva** debe tener, además de su PK, un `UNIQUE (tenant_id, branch_id, id)` como segunda capa de protección a nivel de base de datos.
4. **Todo endpoint que muta datos** debe llamar `requirePermission($authContext, '<permiso>')` con el permiso mínimo correspondiente (`inventory`, `settings`, `orders`, `reports`, `dashboard`, etc.), incluso si te parece "obvio" que solo un admin lo va a usar. La UI no es una barrera de seguridad — cualquiera puede pegarle a la API directo con curl/Postman.
5. Antes de mergear un endpoint nuevo, preguntate: *"¿Qué pasa si otro tenant intenta usar este mismo `id`/`email`/`branch_id`?"* Si la respuesta no es obvia, agregá un test.

## Reglas de sintaxis (JS)

### Llaves, paréntesis y corchetes balanceados

```javascript
// ✅ CORRECTO
if (condicion) {
    hacerAlgo();
}

// ❌ INCORRECTO — falta cerrar
if (condicion) {
    hacerAlgo();
// falta }
```

Mismo criterio para `()` y `[]`: cada apertura debe tener su cierre correspondiente en el mismo bloque lógico.

### Strings y template literals

```javascript
// ✅ CORRECTO
const msg = `Menú "${menu.name}" creado.`;

// ❌ INCORRECTO — comilla/backtick sin cerrar
const msg = `Menú "${menu.name} creado.`;
```

### Funciones y asignaciones — cierre completo

```javascript
async function handleAddItem(e) {
    e.preventDefault();
    if (condicion) {
        hacerAlgo();
    }
}  // ← cierra función

window.deleteItem = async function(id) {
    if (condicion) {
        hacerAlgo();
    }
};  // ← cierra asignación, con punto y coma
```

### Bloques condicionales — siempre con llaves

```javascript
// ✅ CORRECTO
if (stock < 0) {
    stock = 0;
}

// ❌ EVITAR — propenso a errores al agregar una línea después
if (stock < 0) stock = 0;
```

---

## Checklist de seguridad antes de desplegar

Repasar esto antes de cada `deploy-cpanel.sh` a producción:

- [ ] `.env.production` y `.env.local` están bloqueados en `.htaccess` (`<FilesMatch>` con el patrón exacto del archivo, no solo `.env`).
- [ ] Ningún script pensado para CLI (`create-admin.php`, `migrate.php`, scripts de diagnóstico) es alcanzable por HTTP sin un guard `php_sapi_name() !== 'cli'` **y** sin estar bloqueado en `.htaccess`.
- [ ] No quedan archivos de diagnóstico temporales en el repo (si el nombre incluye "delete_me", "temp", "debug" — bórralo antes de commitear, no antes de desplegar).
- [ ] `health.php` y cualquier endpoint público sin autenticación no devuelven mensajes de excepción crudos (`$e->getMessage()`) — solo logs internos vía `error_log()`.
- [ ] `storage/cache/`, `storage/logs/`, `storage/sessions/`, `storage/rate_limits/` están bloqueados en `.htaccess` y listados en `.gitignore`.
- [ ] Las migraciones nuevas se probaron con `php migrate.php` en un entorno real y aparecen como `[OK]`, no `[SKIP]` (sintaxis SQL como `ADD INDEX IF NOT EXISTS` no siempre es compatible entre versiones de MySQL/MariaDB).
- [ ] Cualquier endpoint nuevo que mute datos tiene su `requirePermission()` correspondiente.

## Estructura para cambios nuevos

- **¿Es lógica de negocio de un dominio existente?** Va en `backend/api/<dominio>.php`, nunca de vuelta en `api.php` (que es solo dispatcher).
- **¿Es un dominio nuevo?** Creá `backend/api/<nuevo-dominio>.php`, registralo en el `$actionHandlers` de `api.php`, y sumalo a `phpstan.neon` y a los checks de CI.
- **¿Toca UI?** Vista en `js/views/`, eventos/acciones en `js/controllers/`, nunca lógica de fetch directa en la vista — siempre a través de `js/core/api-client.js`.
- **¿Cambia el esquema de base de datos?** Migración nueva en `db_migrations.php`, versionada y con nombre descriptivo (`vNN_descripcion_corta`). No modifiques migraciones ya aplicadas en producción — agregá una nueva.

## Documentación relacionada

- `ARCHITECTURE.md` — arquitectura completa y flujo de request.
- `CHANGELOG.md` — historial de cambios (mantenelo actualizado en cada release).
- `ENDPOINTS.md` — inventario de endpoints de la API.