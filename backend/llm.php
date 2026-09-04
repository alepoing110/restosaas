<?php

function rc_llm_provider_config(?string $requestedProvider = null): array
{
    $provider = strtolower((string)($requestedProvider ?: rc_env('LLM_PROVIDER', 'groq')));
    $providers = [
        'groq' => [
            'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => rc_env('GROQ_API_KEY', ''),
            'model' => rc_env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        ],
        'ollama' => [
            'base_url' => rc_env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
            'api_key' => 'ollama',
            'model' => rc_env('OLLAMA_MODEL', 'qwen3:8b'),
        ],
        'gemini' => [
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'api_key' => rc_env('GEMINI_API_KEY', ''),
            'model' => rc_env('GEMINI_MODEL', 'gemini-2.5-flash'),
        ],
    ];

    return $providers[$provider] ?? $providers['groq'];
}

function rc_llm_chat(array $messages, ?array $tools = null, array $options = []): array
{
    $provider = strtolower((string)rc_env('LLM_PROVIDER', 'groq'));
    $config = rc_llm_provider_config($provider);
    try {
        return rc_llm_chat_with_config($config, $messages, $tools, $options);
    } catch (Throwable $error) {
        $fallbackProvider = strtolower(trim((string)rc_env('LLM_FALLBACK_PROVIDER', '')));
        $retryable = str_contains($error->getMessage(), 'HTTP 429') || str_contains($error->getMessage(), 'HTTP 5');
        if ($fallbackProvider === '' || $fallbackProvider === $provider || !$retryable) {
            throw $error;
        }

        error_log('[LLM] Primary provider unavailable; trying fallback provider ' . $fallbackProvider);
        return rc_llm_chat_with_config(rc_llm_provider_config($fallbackProvider), $messages, $tools, $options);
    }
}

function rc_llm_chat_with_config(array $config, array $messages, ?array $tools, array $options): array
{
    if ($config['api_key'] === '') {
        throw new RuntimeException('LLM API key no configurada para el proveedor actual.');
    }

    $payload = [
        'model' => $config['model'],
        'messages' => $messages,
        'temperature' => $options['temperature'] ?? 0.2,
        'max_tokens' => $options['max_tokens'] ?? 350,
        'stream' => false,
    ];

    if ($tools) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
    }

    $url = rtrim($config['base_url'], '/') . '/chat/completions';
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $timeout = (int)($options['timeout'] ?? 30);

    $response = rc_llm_exec_request($url, $jsonPayload, $config['api_key'], $timeout);

    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log('[LLM] Response not JSON: ' . substr($response ?? '', 0, 500));
        throw new RuntimeException('Respuesta inválida del proveedor LLM. Verifique la configuración.');
    }

    if (isset($data['error'])) {
        error_log('[LLM] API error: ' . json_encode($data['error']));
        throw new RuntimeException('LLM error: ' . ($data['error']['message'] ?? 'unknown'));
    }

    $firstChoice = $data['choices'][0]['message'] ?? [];
    error_log('[LLM] tool_choice=' . ($options['tool_choice'] ?? 'auto') . ' tools_count=' . count($tools ?? []) . ' response_tools=' . count($firstChoice['tool_calls'] ?? []));

    return $data;
}

function rc_llm_exec_request(string $url, string $jsonPayload, string $apiKey, int $timeout): string
{
    if (!function_exists('curl_init')) {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('La extensión cURL de PHP no está habilitada y allow_url_fopen está desactivado.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
                'content' => $jsonPayload,
                'timeout' => max(1, $timeout),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
        $httpCode = (int)($statusMatch[1] ?? 0);
        if ($response === false || $response === '') {
            throw new RuntimeException('No se pudo conectar al proveedor LLM mediante HTTP nativo.');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(rc_llm_http_error_message($httpCode, (string)$response));
        }
        return (string)$response;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('No se pudo inicializar el cliente HTTP del LLM.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $response === '' || $curlError !== '') {
        throw new RuntimeException('No se pudo conectar al proveedor LLM: ' . ($curlError ?: 'respuesta vacía'));
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(rc_llm_http_error_message($httpCode, (string)$response));
    }

    return (string)$response;
}

function rc_llm_http_error_message(int $httpCode, string $response): string
{
    $data = json_decode($response, true);
    $providerMessage = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
    if ($httpCode === 429) {
        return 'El proveedor LLM respondió con HTTP 429: límite de solicitudes o cuota agotada.'
            . ($providerMessage !== '' ? ' ' . $providerMessage : '')
            . ' Configure otro proveedor o espere antes de reintentar.';
    }
    return 'El proveedor LLM respondió con HTTP ' . $httpCode . '.'
        . ($providerMessage !== '' ? ' ' . $providerMessage : '');
}
