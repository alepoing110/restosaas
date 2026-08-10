<?php
// ============================================================
// DIAGNÓSTICO DE BASE DE DATOS - BORRAR DESPUÉS DE USAR
// Archivo temporal para diagnosticar problemas de conexión DB
// ============================================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIAGNÓSTICO DB - BORRAR ESTE ARCHIVO</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #0f0f23; color: #e0e0e0; padding: 20px; }
        h1 { color: #ff4444; text-align: center; padding: 20px; background: #1a1a2e; border-radius: 8px; margin-bottom: 20px; border: 2px solid #ff4444; }
        h1 small { display: block; color: #ff8888; font-size: 14px; margin-top: 8px; }
        .section { background: #1a1a2e; border-radius: 8px; padding: 20px; margin-bottom: 16px; border: 1px solid #333; }
        .section h2 { color: #6C63FF; font-size: 16px; margin-bottom: 12px; border-bottom: 1px solid #333; padding-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 8px 12px; text-align: left; border-bottom: 1px solid #2a2a3e; font-size: 13px; }
        th { color: #888; width: 40%; }
        td { font-family: 'Share Tech Mono', monospace; word-break: break-all; }
        .ok { color: #44ff88; }
        .fail { color: #ff4444; }
        .warn { color: #ffaa44; }
        .code { background: #0d0d1a; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; white-space: pre-wrap; margin-top: 8px; border: 1px solid #333; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-ok { background: #1a3d1a; color: #44ff88; border: 1px solid #44ff88; }
        .badge-fail { background: #3d1a1a; color: #ff4444; border: 1px solid #ff4444; }
        .badge-warn { background: #3d3d1a; color: #ffaa44; border: 1px solid #ffaa44; }
        .summary { text-align: center; padding: 16px; border-radius: 8px; margin-top: 20px; font-size: 18px; font-weight: bold; }
        .summary-ok { background: #1a3d1a; border: 2px solid #44ff88; color: #44ff88; }
        .summary-fail { background: #3d1a1a; border: 2px solid #ff4444; color: #ff4444; }
    </style>
</head>
<body>

<h1>
    DIAGNÓSTICO DE BASE DE DATOS
    <small>⚠️ BORRAR ESTE ARCHIVO DESPUÉS DE USARLO ⚠️</small>
</h1>

<?php
$results = [];
$hasError = false;

// ============================================================
// 1. ENTORNO PHP
// ============================================================
echo '<div class="section"><h2>1. Entorno PHP</h2><table>';
echo '<tr><th>PHP Version</th><td>' . phpversion() . '</td></tr>';
echo '<tr><th>PDO Extension</th><td>' . (extension_loaded('pdo') ? '<span class="badge badge-ok">✅ CARGADA</span>' : '<span class="badge badge-fail">❌ NO CARGADA</span>') . '</td></tr>';
echo '<tr><th>PDO MySQL</th><td>' . (extension_loaded('pdo_mysql') ? '<span class="badge badge-ok">✅ CARGADA</span>' : '<span class="badge badge-fail">❌ NO CARGADA</span>') . '</td></tr>';
echo '<tr><th>MySQLi</th><td>' . (extension_loaded('mysqli') ? '<span class="badge badge-ok">✅ CARGADA</span>' : '<span class="badge badge-warn">⚠️ NO CARGADA</span>') . '</td></tr>';
echo '<tr><th>JSON Extension</th><td>' . (extension_loaded('json') ? '<span class="badge badge-ok">✅ CARGADA</span>' : '<span class="badge badge-fail">❌ NO CARGADA</span>') . '</td></tr>';
$pdoOk = extension_loaded('pdo') && extension_loaded('pdo_mysql');
if (!$pdoOk) $hasError = true;
echo '</table></div>';

// ============================================================
// 2. VARIABLES DE ENTORNO
// ============================================================
echo '<div class="section"><h2>2. Variables de Entorno del Servidor</h2><table>';
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? 'NO DEFINIDO';
$scriptDir = __DIR__;
$serverName = $_SERVER['SERVER_NAME'] ?? 'NO DEFINIDO';
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'NO DEFINIDO';
echo '<tr><th>DOCUMENT_ROOT</th><td><strong>' . htmlspecialchars($docRoot) . '</strong></td></tr>';
echo '<tr><th>__DIR__ (este archivo)</th><td>' . htmlspecialchars($scriptDir) . '</td></tr>';
echo '<tr><th>SERVER_NAME</th><td>' . htmlspecialchars($serverName) . '</td></tr>';
echo '<tr><th>SERVER_SOFTWARE</th><td>' . htmlspecialchars($serverSoftware) . '</td></tr>';
echo '<tr><th>PHP SAPI</th><td>' . php_sapi_name() . '</td></tr>';

// Test putenv availability
$testKey = 'RC_PUTENV_TEST_' . uniqid();
$testVal = 'works';
@putenv("$testKey=$testVal");
$putenvWorks = (getenv($testKey) === $testVal);
echo '<tr><th>putenv() / getenv()</th><td>' . ($putenvWorks ? '<span class="badge badge-ok">✅ FUNCIONAN</span>' : '<span class="badge badge-fail">❌ DESHABILITADOS</span>') . '</td></tr>';
if ($putenvWorks) @putenv("$testKey=");

echo '</table></div>';

// ============================================================
// 3. DETECCIÓN DE ENTORNO
// ============================================================
echo '<div class="section"><h2>3. Detección de Entorno (db.php actual)</h2><table>';
$docRootLower = strtolower($docRoot);
$isInf = (strpos($docRootLower, 'infinityfree') !== false);
$isEpi = (strpos($docRootLower, 'epizy') !== false);
$isIfast = (strpos($docRootLower, 'ifastnet') !== false);
$isHonor = (strpos($docRootLower, 'honor') !== false);
$isXampp = (strpos($docRootLower, 'xampp') !== false);
$isHtdocs = (strpos($docRootLower, 'htdocs') !== false);

echo '<tr><th>Contiene "infinityfree"</th><td>' . ($isInf ? '<span class="ok">✅ SÍ</span>' : '<span class="fail">❌ NO</span>') . '</td></tr>';
echo '<tr><th>Contiene "epizy"</th><td>' . ($isEpi ? '<span class="ok">✅ SÍ</span>' : '<span class="fail">❌ NO</span>') . '</td></tr>';
echo '<tr><th>Contiene "ifastnet"</th><td>' . ($isIfast ? '<span class="ok">✅ SÍ</span>' : '<span class="fail">❌ NO</span>') . '</td></tr>';
echo '<tr><th>Contiene "honor"</th><td>' . ($isHonor ? '<span class="ok">✅ SÍ</span>' : '<span class="fail">❌ NO</span>') . '</td></tr>';
echo '<tr><th>Contiene "xampp"</th><td>' . ($isXampp ? '<span class="warn">⚠️ SÍ (local)</span>' : '—') . '</td></tr>';
echo '<tr><th>Contiene "htdocs"</th><td>' . ($isHtdocs ? '<span class="warn">⚠️ SÍ (posiblemente local)</span>' : '—') . '</td></tr>';

$isProductionDetected = $isInf || $isEpi || $isIfast || $isHonor;
$envFile = $isProductionDetected ? '.env.production' : '.env.local';
echo '<tr><th>Detección actual db.php</th><td>' . ($isProductionDetected ? '<span class="badge badge-ok">PRODUCCIÓN → carga ' . $envFile . '</span>' : '<span class="badge badge-fail">DESARROLLO → carga ' . $envFile . '</span>') . '</td></tr>';
echo '</table></div>';

// ============================================================
// 4. ARCHIVOS .ENV DISPONIBLES
// ============================================================
echo '<div class="section"><h2>4. Archivos .env Detectados</h2><table>';
$envFiles = ['.env.production', '.env.local', '.env'];
foreach ($envFiles as $f) {
    $path = __DIR__ . '/' . $f;
    $exists = file_exists($path);
    $readable = $exists ? is_readable($path) : false;
    $size = $exists ? filesize($path) : 0;
    $status = !$exists ? '<span class="badge badge-fail">❌ NO EXISTE</span>' : (!$readable ? '<span class="badge badge-fail">❌ NO LEGIBLE</span>' : '<span class="badge badge-ok">✅ OK (' . $size . ' bytes)</span>');
    echo '<tr><th>' . $f . '</th><td>' . $status . '</td></tr>';
}
echo '</table></div>';

// ============================================================
// 5. LECTURA DE .env.production (si existe)
// ============================================================
echo '<div class="section"><h2>5. Contenido de .env.production (credenciales)</h2>';
$prodEnvPath = __DIR__ . '/.env.production';
if (file_exists($prodEnvPath)) {
    $lines = file($prodEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $envData = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $envData[trim($key)] = trim(trim($value), '"\'');
    }
    echo '<table>';
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'RUN_MIGRATIONS', 'APP_ENV'] as $key) {
        $val = $envData[$key] ?? 'NO DEFINIDO';
        $display = $key === 'DB_PASS' ? str_repeat('*', strlen($val)) . ' (' . strlen($val) . ' chars)' : htmlspecialchars($val);
        echo '<tr><th>' . $key . '</th><td>' . $display . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p class="fail">❌ Archivo .env.production NO encontrado en: ' . htmlspecialchars($prodEnvPath) . '</p>';
}
echo '</div>';

// ============================================================
// 6. TEST CONEXIÓN PDO (con credenciales de .env.production)
// ============================================================
echo '<div class="section"><h2>6. Test Conexión PDO (credenciales de .env.production)</h2>';

if (!file_exists($prodEnvPath)) {
    echo '<p class="fail">❌ No se puede probar: .env.production no existe</p>';
} else {
    $lines = file($prodEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $envData = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $envData[trim($key)] = trim(trim($value), '"\'');
    }

    $host = $envData['DB_HOST'] ?? '';
    $user = $envData['DB_USER'] ?? '';
    $pass = $envData['DB_PASS'] ?? '';
    $dbName = $envData['DB_NAME'] ?? '';

    echo '<table>';
    echo '<tr><th>Host</th><td>' . htmlspecialchars($host) . '</td></tr>';
    echo '<tr><th>User</th><td>' . htmlspecialchars($user) . '</td></tr>';
    echo '<tr><th>Database</th><td>' . htmlspecialchars($dbName) . '</td></tr>';
    echo '</table>';

    // Test 1: Conexión al servidor MySQL (sin seleccionar DB)
    echo '<h3 style="color:#6C63FF; margin:16px 0 8px;">Test 6a: Conexión al servidor MySQL (host)</h3>';
    try {
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10
        ]);
        echo '<p class="ok">✅ Conexión al servidor MySQL exitosa</p>';

        // Test 2: Seleccionar base de datos
        echo '<h3 style="color:#6C63FF; margin:16px 0 8px;">Test 6b: Seleccionar base de datos</h3>';
        try {
            $pdo->exec("USE `$dbName`");
            echo '<p class="ok">✅ Base de datos "' . htmlspecialchars($dbName) . '" seleccionada</p>';

            // Test 3: Listar tablas
            echo '<h3 style="color:#6C63FF; margin:16px 0 8px;">Test 6c: Listar tablas</h3>';
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (count($tables) > 0) {
                echo '<p class="ok">✅ ' . count($tables) . ' tablas encontradas:</p>';
                echo '<div class="code">' . htmlspecialchars(implode("\n", $tables)) . '</div>';
            } else {
                echo '<p class="warn">⚠️ Base de datos vacía (sin tablas)</p>';
            }

            // Test 4: Verificar tabla pedidos y delivery_type
            echo '<h3 style="color:#6C63FF; margin:16px 0 8px;">Test 6d: Verificar columnas de pedidos</h3>';
            if (in_array('pedidos', $tables)) {
                $cols = $pdo->query("SHOW COLUMNS FROM pedidos")->fetchAll(PDO::FETCH_COLUMN);
                $hasDeliveryType = in_array('delivery_type', $cols);
                echo '<p>' . ($hasDeliveryType ? '<span class="ok">✅ Columna delivery_type existe en pedidos</span>' : '<span class="fail">❌ Columna delivery_type NO existe en pedidos</span>') . '</p>';

                $hasClosureId = in_array('closure_id', $cols);
                echo '<p>' . ($hasClosureId ? '<span class="ok">✅ Columna closure_id existe</span>' : '<span class="fail">❌ Columna closure_id NO existe</span>') . '</p>';
            } else {
                echo '<p class="fail">❌ Tabla pedidos no encontrada</p>';
            }

            // Test 5: Verificar tabla reservations
            echo '<h3 style="color:#6C63FF; margin:16px 0 8px;">Test 6e: Verificar columnas de reservations</h3>';
            if (in_array('reservations', $tables)) {
                $cols = $pdo->query("SHOW COLUMNS FROM reservations")->fetchAll(PDO::FETCH_COLUMN);
                $hasDeliveryType = in_array('delivery_type', $cols);
                echo '<p>' . ($hasDeliveryType ? '<span class="ok">✅ Columna delivery_type existe en reservations</span>' : '<span class="fail">❌ Columna delivery_type NO existe en reservations</span>') . '</p>';
            } else {
                echo '<p class="warn">⚠️ Tabla reservations no encontrada (puede que no exista aún)</p>';
            }

            // Test 6: Verificar tabla discounts
            echo '<h3 style="color:#6C63FF; margin:16px 0 8px;">Test 6f: Verificar tabla discounts</h3>';
            if (in_array('discounts', $tables)) {
                echo '<p class="ok">✅ Tabla discounts existe</p>';
            } else {
                echo '<p class="warn">⚠️ Tabla discounts no encontrada</p>';
            }

            // Test 7: Verificar migraciones
            echo '<h3 style="color:#6C63FF; margin:16px 0 8px;">Test 6g: Migraciones aplicadas</h3>';
            if (in_array('_migrations', $tables)) {
                $migrations = $pdo->query("SELECT name FROM _migrations ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
                echo '<p class="ok">✅ ' . count($migrations) . ' migraciones aplicadas (últimas 10):</p>';
                echo '<div class="code">' . htmlspecialchars(implode("\n", $migrations)) . '</div>';
            } else {
                echo '<p class="warn">⚠️ Tabla _migrations no encontrada</p>';
            }

        } catch (Throwable $e) {
            echo '<p class="fail">❌ Error al seleccionar DB: ' . htmlspecialchars($e->getMessage()) . '</p>';
            $hasError = true;
        }

    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        echo '<p class="fail">❌ Error de conexión: ' . htmlspecialchars($errorMsg) . '</p>';

        // Diagnóstico específico
        echo '<h3 style="color:#ffaa44; margin:16px 0 8px;">Diagnóstico del error:</h3>';
        if (strpos($errorMsg, 'Connection refused') !== false) {
            echo '<p class="warn">→ El servidor MySQL rechazó la conexión. El host puede estar incorrecto o el servidor está caído.</p>';
        } elseif (strpos($errorMsg, 'Unknown MySQL server host') !== false || strpos($errorMsg, 'getaddrinfo') !== false) {
            echo '<p class="warn">→ No se puede resolver el hostname "' . htmlspecialchars($host) . '". Verifica que sea correcto.</p>';
        } elseif (strpos($errorMsg, 'Access denied') !== false) {
            echo '<p class="warn">→ Acceso denegado. Usuario o contraseña incorrectos.</p>';
        } elseif (strpos($errorMsg, 'Unknown database') !== false) {
            echo '<p class="warn">→ La base de datos "' . htmlspecialchars($dbName) . '" no existe.</p>';
        } elseif (strpos($errorMsg, 'timed out') !== false || strpos($errorMsg, 'timeout') !== false) {
            echo '<p class="warn">→ La conexión expiró. El servidor puede estar bloqueando conexiones externas.</p>';
        } else {
            echo '<p class="warn">→ Error no categorizado. Revisa el mensaje arriba.</p>';
        }
        $hasError = true;
    }
}
echo '</div>';

// ============================================================
// 7. TEST CON MYSQLI (fallback)
// ============================================================
echo '<div class="section"><h2>7. Test Conexión MySQLi (alternativa)</h2>';
if (extension_loaded('mysqli') && file_exists($prodEnvPath)) {
    $lines = file($prodEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $envData = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $envData[trim($key)] = trim(trim($value), '"\'');
    }

    $host = $envData['DB_HOST'] ?? '';
    $user = $envData['DB_USER'] ?? '';
    $pass = $envData['DB_PASS'] ?? '';
    $dbName = $envData['DB_NAME'] ?? '';

    $conn = @new mysqli($host, $user, $pass, $dbName, 3306, 10);
    if ($conn->connect_error) {
        echo '<p class="fail">❌ MySQLi falló: ' . htmlspecialchars($conn->connect_error) . '</p>';
    } else {
        echo '<p class="ok">✅ MySQLi conexión exitosa</p>';
        echo '<p>Versión MySQL: ' . $conn->server_info . '</p>';
        $conn->close();
    }
} elseif (!extension_loaded('mysqli')) {
    echo '<p class="warn">⚠️ Extensión mysqli no disponible</p>';
} else {
    echo '<p class="fail">❌ .env.production no encontrado</p>';
}
echo '</div>';

// ============================================================
// 8. TEST CON HOSTS ALTERNATIVOS
// ============================================================
echo '<div class="section"><h2>8. Test con Hosts Alternativos</h2>';
if (file_exists($prodEnvPath)) {
    $lines = file($prodEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $envData = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $envData[trim($key)] = trim(trim($value), '"\'');
    }

    $user = $envData['DB_USER'] ?? '';
    $pass = $envData['DB_PASS'] ?? '';
    $dbName = $envData['DB_NAME'] ?? '';

    $altHosts = [
        $envData['DB_HOST'] ?? 'N/A',
        'localhost',
        '127.0.0.1',
        'mysql.' . ($_SERVER['SERVER_NAME'] ?? 'example.com'),
        'sql.' . ($_SERVER['SERVER_NAME'] ?? 'example.com'),
    ];
    $altHosts = array_unique($altHosts);

    echo '<table>';
    foreach ($altHosts as $altHost) {
        try {
            $testPdo = new PDO("mysql:host=$altHost;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);
            echo '<tr><th>' . htmlspecialchars($altHost) . '</th><td><span class="ok">✅ CONEXIÓN EXITOSA</span></td></tr>';
        } catch (Throwable $e) {
            $short = substr($e->getMessage(), 0, 80);
            echo '<tr><th>' . htmlspecialchars($altHost) . '</th><td><span class="fail">❌ ' . htmlspecialchars($short) . '</span></td></tr>';
        }
    }
    echo '</table>';
} else {
    echo '<p class="fail">❌ .env.production no encontrado</p>';
}
echo '</div>';

// ============================================================
// RESUMEN
// ============================================================
echo '<div class="summary ' . ($hasError ? 'summary-fail' : 'summary-ok') . '">';
if ($hasError) {
    echo '⚠️ SE ENCONTRARON ERRORES - Revisa los detalles arriba ⚠️';
} else {
    echo '✅ TODOS LOS TESTS PASARON - La conexión funciona correctamente';
}
echo '</div>';

echo '<div style="text-align:center; margin-top:20px; padding:16px; background:#3d1a1a; border-radius:8px; border:1px solid #ff4444;">';
echo '<p style="color:#ff4444; font-weight:bold;">⚠️ IMPORTANTE: BORRA ESTE ARCHIVO DESPUÉS DE USARLO ⚠️</p>';
echo '<p style="color:#ff8888; font-size:12px; margin-top:8px;">Nombre del archivo: <code>db_diagnostico_delete_me.php</code></p>';
echo '</div>';

?>
</body>
</html>
