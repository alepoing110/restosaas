<?php

/** Split ALTER clauses without splitting ENUM values or DECIMAL/index lists. */
function rc_split_alter_clauses(string $sql): array
{
    $clauses = [];
    $start = 0;
    $depth = 0;
    $quote = null;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            if ($char === '\\') { $i++; continue; }
            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) { $i++; continue; }
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') $quote = $char;
        elseif ($char === '(') $depth++;
        elseif ($char === ')') $depth--;
        elseif ($char === ',' && $depth === 0) {
            $clauses[] = trim(substr($sql, $start, $i - $start));
            $start = $i + 1;
        }
    }
    $clauses[] = trim(substr($sql, $start));
    return $clauses;
}

/** Additive migrations can be retried after a partial import/installation. */
function rc_execute_migration(PDO $pdo, string $sql): void
{
    $trimmedSql = trim($sql);
    // v69/v70 move legacy global settings to tenant-scoped uniqueness. Keep
    // DROP + ADD in one ALTER so MySQL does not leave a table half-converted.
    if (preg_match('/^ALTER\s+TABLE\s+`?(config_precios|config_general)`?\s+DROP\s+PRIMARY\s+KEY,\s+ADD\s+UNIQUE\s+KEY\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $trimmedSql, $scopeMatch)) {
        $table = $scopeMatch[1];
        $index = $scopeMatch[2];
        $indexCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $indexCheck->execute([$table, $index]);
        if ((int)$indexCheck->fetchColumn() > 0) return;
        $duplicateCheck = $pdo->query("SELECT 1 FROM `$table` GROUP BY `id`, `tenant_id`, `branch_id` HAVING COUNT(*) > 1 LIMIT 1");
        if ($duplicateCheck->fetchColumn()) {
            throw new RuntimeException("No se puede migrar `$table`: existen configuraciones duplicadas por tenant y sucursal.");
        }
        $primaryCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $primaryCheck->execute([$table, 'PRIMARY']);
        $pdo->exec((int)$primaryCheck->fetchColumn() > 0 ? $trimmedSql : preg_replace('/DROP\s+PRIMARY\s+KEY,\s*/i', '', $trimmedSql, 1));
        return;
    }
    // A server may finish CREATE INDEX but terminate before the migration row
    // is recorded. Named index migrations must therefore be safe to retry.
    if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $trimmedSql, $indexMatch)) {
        $indexCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $indexCheck->execute([$indexMatch[2], $indexMatch[1]]);
        if ((int)$indexCheck->fetchColumn() === 0) $pdo->exec($trimmedSql);
        return;
    }
    if (!preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is', $trimmedSql, $match)) {
        $pdo->exec($sql);
        return;
    }
    $table = $match[1];
    $operations = [];
    foreach (rc_split_alter_clauses($match[2]) as $clause) {
        if (preg_match('/^ADD\s+COLUMN\s+`?([A-Za-z0-9_]+)`?\s/i', $clause, $column)) {
            $operations[] = ['sql' => $clause, 'metadata' => 'COLUMNS', 'field' => 'COLUMN_NAME', 'name' => $column[1]];
        } elseif (preg_match('/^ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $clause, $index)) {
            $operations[] = ['sql' => $clause, 'metadata' => 'STATISTICS', 'field' => 'INDEX_NAME', 'name' => $index[1]];
        } elseif (preg_match('/^DROP\s+PRIMARY\s+KEY$/i', $clause)) {
            $operations[] = ['sql' => $clause, 'metadata' => 'STATISTICS', 'field' => 'INDEX_NAME', 'name' => 'PRIMARY', 'drop' => true];
        } else {
            // Never reinterpret destructive, renaming or type-changing migrations.
            $pdo->exec($sql);
            return;
        }
    }
    foreach ($operations as $operation) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.{$operation['metadata']} WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND {$operation['field']} = ?");
        $check->execute([$table, $operation['name']]);
        $exists = (int)$check->fetchColumn() > 0;
        if (!empty($operation['drop']) ? $exists : !$exists) {
            $pdo->exec("ALTER TABLE `$table` " . $operation['sql']);
        }
    }
}
