<?php
/**
 * Simple file-based cache for frequently accessed data.
 * Cache files are stored in storage/cache/ with TTL-based expiration.
 */

function _cacheDir(): string {
    $dir = __DIR__ . '/../storage/cache';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir;
}

function _cacheKey(string $namespace, string $key): string {
    return _cacheDir() . '/' . $namespace . '_' . md5($key) . '.json';
}

/**
 * Get cached value or execute callback and cache result.
 * @param string $namespace Cache namespace (e.g., 'catalog', 'dashboard')
 * @param string $key Cache key (e.g., tenant+branch id)
 * @param int $ttlSeconds Time to live in seconds (default 60s)
 * @param callable $callback Function to execute if cache miss
 * @return mixed Cached or fresh value
 */
function cacheGetOrSet(string $namespace, string $key, int $ttlSeconds, callable $callback) {
    $file = _cacheKey($namespace, $key);
    
    if (is_file($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['expires_at']) && $data['expires_at'] > time()) {
            return $data['value'];
        }
        @unlink($file);
    }
    
    $value = $callback();
    
    $cacheData = [
        'expires_at' => time() + $ttlSeconds,
        'created_at' => date('c'),
        'value' => $value
    ];
    file_put_contents($file, json_encode($cacheData, JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    return $value;
}

/**
 * Invalidate all cache entries for a namespace.
 */
function cacheInvalidate(string $namespace): void {
    $dir = _cacheDir();
    $pattern = $dir . '/' . $namespace . '_*.json';
    foreach (glob($pattern) as $file) {
        @unlink($file);
    }
}

/**
 * Invalidate cache for a specific tenant+branch.
 */
function cacheInvalidateTenant(string $namespace, string $tenantId, string $branchId): void {
    $key = $tenantId . ':' . $branchId;
    $file = _cacheKey($namespace, $key);
    if (is_file($file)) @unlink($file);
}
