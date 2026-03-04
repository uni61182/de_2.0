<?php

declare(strict_types=1);

define('PROJECT_ROOT_PATH', dirname(__DIR__, 2) . '/');
require PROJECT_ROOT_PATH . 'inccon.php';

if (!isset($_SESSION)) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$secret = trim((string)($GLOBALS['env_api_v1_secret'] ?? ''));
if ($secret === '') {
    apiError(500, 'API v1 secret is not configured');
}

$accessTtlSeconds = 60 * 30;
$refreshTtlSeconds = 60 * 60 * 24 * 30;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/+#', '/', $path);
$path = preg_replace('#/+#', '/', $path);
$endpoint = preg_replace('#^/api/v1#', '', $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function jsonBody(): array
{
    $raw = trim((string)file_get_contents('php://input'));
    if ($raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        apiError(400, 'Invalid JSON payload');
    }

    return $data;
}

function apiError(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiOk(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function createToken(int $userId, string $type, int $ttlSeconds, string $secret): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'sub' => $userId,
        'type' => $type,
        'iat' => time(),
        'exp' => time() + $ttlSeconds,
        'jti' => bin2hex(random_bytes(8)),
    ];

    $encodedHeader = base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_UNICODE));
    $encodedPayload = base64UrlEncode((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
    $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);

    return $encodedHeader . '.' . $encodedPayload . '.' . base64UrlEncode($signature);
}

function parseToken(string $token, string $expectedType, string $secret): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        apiError(401, 'Invalid token format');
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $computedSignature = base64UrlEncode(hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true));

    if (!hash_equals($computedSignature, $encodedSignature)) {
        apiError(401, 'Invalid token signature');
    }

    $payloadJson = base64_decode(strtr($encodedPayload, '-_', '+/'));
    $payload = json_decode((string)$payloadJson, true);
    if (!is_array($payload)) {
        apiError(401, 'Invalid token payload');
    }

    if (($payload['type'] ?? '') !== $expectedType) {
        apiError(401, 'Invalid token type');
    }

    if ((int)($payload['exp'] ?? 0) < time()) {
        apiError(401, 'Token expired');
    }

    return $payload;
}

function bearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        apiError(401, 'Missing bearer token');
    }

    return trim($matches[1]);
}

function ensureApiUserActive(int $userId): void
{
    $result = mysqli_execute_query(
        $GLOBALS['dbi'],
        'SELECT status FROM de_login WHERE user_id=? LIMIT 1',
        [$userId]
    );

    $row = mysqli_fetch_assoc($result);
    if (!$row || (int)$row['status'] !== 1) {
        apiError(403, 'Inactive account');
    }
}

function currentUserId(string $secret): int
{
    $token = bearerToken();
    $payload = parseToken($token, 'access', $secret);
    $userId = (int)$payload['sub'];
    ensureApiUserActive($userId);

    return $userId;
}

function applyRateLimit(string $scope, int $maxRequests, int $windowSeconds): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $now = time();
    $key = hash('sha256', $scope . '|' . $ip);
    $file = sys_get_temp_dir() . '/de2_api_v1_rate_limit.json';

    $entries = [];
    if (is_file($file)) {
        $content = file_get_contents($file);
        $decoded = json_decode((string)$content, true);
        if (is_array($decoded)) {
            $entries = $decoded;
        }
    }

    foreach ($entries as $entryKey => $entry) {
        if (!is_array($entry)) {
            unset($entries[$entryKey]);
            continue;
        }
        $start = (int)($entry['start'] ?? 0);
        if ($start + $windowSeconds < $now) {
            unset($entries[$entryKey]);
        }
    }

    if (!isset($entries[$key])) {
        $entries[$key] = ['start' => $now, 'count' => 0];
    }

    $entries[$key]['count'] = (int)$entries[$key]['count'] + 1;
    if ((int)$entries[$key]['count'] > $maxRequests) {
        file_put_contents($file, (string)json_encode($entries, JSON_UNESCAPED_UNICODE), LOCK_EX);
        apiError(429, 'Too many requests');
    }

    file_put_contents($file, (string)json_encode($entries, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function validCredentials(string $username, string $password): ?int
{
    $result = mysqli_execute_query(
        $GLOBALS['dbi'],
        'SELECT user_id, pass FROM de_login WHERE nic=? AND status=1 LIMIT 1',
        [$username]
    );

    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        return null;
    }

    $stored = (string)($row['pass'] ?? '');
    $matches = false;

    if ($stored !== '') {
        $legacyMd5 = md5($password);
        $matches = hash_equals($stored, $legacyMd5) || password_verify($password, $stored);
    }

    return $matches ? (int)$row['user_id'] : null;
}

function getPlayerOverview(int $userId): array
{
    $result = mysqli_execute_query(
        $GLOBALS['dbi'],
        'SELECT user_id, spielername, score, fleetscore, ehscore, sector, system, ally_id, allytag, rasse, tick, newnews FROM de_user_data WHERE user_id=? LIMIT 1',
        [$userId]
    );

    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        apiError(404, 'Player not found');
    }

    $systemResult = mysqli_execute_query($GLOBALS['dbi'], 'SELECT wt, kt, lasttick, lastmtick FROM de_system LIMIT 1');
    $system = mysqli_fetch_assoc($systemResult) ?: [];

    return [
        'player' => $row,
        'server' => [
            'wt' => (int)($system['wt'] ?? 0),
            'kt' => (int)($system['kt'] ?? 0),
            'lasttick' => $system['lasttick'] ?? null,
            'lastmtick' => $system['lastmtick'] ?? null,
            'server_time' => gmdate('c'),
        ],
    ];
}

function getPlayerResources(int $userId): array
{
    $result = mysqli_execute_query(
        $GLOBALS['dbi'],
        'SELECT restyp01, restyp02, restyp03, restyp04, restyp05, col, col_build, sonde, agent FROM de_user_data WHERE user_id=? LIMIT 1',
        [$userId]
    );

    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        apiError(404, 'Player resources not found');
    }

    return $row;
}

function getPlayerFleets(int $userId): array
{
    $result = mysqli_execute_query(
        $GLOBALS['dbi'],
        'SELECT user_id, hsec, hsys, zielsec, zielsys, aktion, zeit, aktzeit, gesrzeit, e81, e82, e83, e84, e85, e86, e87, e88, e89, e90, fleetsize FROM de_user_fleet WHERE user_id LIKE ? ORDER BY user_id ASC',
        [$userId . '-%']
    );

    $fleets = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $fleets[] = $row;
    }

    return $fleets;
}

function moveFleet(int $userId, int $slot, int $targetSector, int $targetSystem): void
{
    if ($slot < 0 || $slot > 3) {
        apiError(422, 'fleet_slot must be between 0 and 3');
    }

    if ($targetSector < 1 || $targetSystem < 1) {
        apiError(422, 'target_sector and target_system must be >= 1');
    }

    $fleetId = $userId . '-' . $slot;
    $existing = mysqli_execute_query(
        $GLOBALS['dbi'],
        'SELECT user_id FROM de_user_fleet WHERE user_id=? LIMIT 1',
        [$fleetId]
    );

    if (!mysqli_fetch_assoc($existing)) {
        apiError(404, 'Fleet not found');
    }

    mysqli_execute_query(
        $GLOBALS['dbi'],
        'UPDATE de_user_fleet SET zielsec=?, zielsys=?, aktion=1 WHERE user_id=? LIMIT 1',
        [$targetSector, $targetSystem, $fleetId]
    );
}

if ($method === 'POST' && $endpoint === '/auth/login') {
    applyRateLimit('auth_login', 20, 60);

    $body = jsonBody();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
        apiError(422, 'username and password are required');
    }

    if (mb_strlen($username) > 100 || mb_strlen($password) > 200) {
        apiError(422, 'Invalid credential length');
    }

    $userId = validCredentials($username, $password);
    if ($userId === null) {
        apiError(401, 'Invalid credentials');
    }

    mysqli_execute_query($GLOBALS['dbi'], 'UPDATE de_login SET last_login=NOW(), last_click=NOW() WHERE user_id=?', [$userId]);

    $access = createToken($userId, 'access', $accessTtlSeconds, $secret);
    $refresh = createToken($userId, 'refresh', $refreshTtlSeconds, $secret);

    apiOk([
        'access_token' => $access,
        'refresh_token' => $refresh,
        'token_type' => 'Bearer',
        'expires_in' => $accessTtlSeconds,
        'user_id' => $userId,
    ]);
}

if ($method === 'POST' && $endpoint === '/auth/refresh') {
    applyRateLimit('auth_refresh', 60, 60);

    $body = jsonBody();
    $refreshToken = (string)($body['refresh_token'] ?? '');
    if ($refreshToken === '') {
        apiError(422, 'refresh_token is required');
    }

    $payload = parseToken($refreshToken, 'refresh', $secret);
    $userId = (int)$payload['sub'];
    ensureApiUserActive($userId);

    $access = createToken($userId, 'access', $accessTtlSeconds, $secret);
    apiOk([
        'access_token' => $access,
        'token_type' => 'Bearer',
        'expires_in' => $accessTtlSeconds,
        'user_id' => $userId,
    ]);
}

if ($method === 'POST' && $endpoint === '/auth/logout') {
    apiOk(['status' => 'ok']);
}

if ($method === 'GET' && $endpoint === '/player/overview') {
    $userId = currentUserId($secret);
    apiOk(getPlayerOverview($userId));
}

if ($method === 'GET' && $endpoint === '/player/resources') {
    $userId = currentUserId($secret);
    apiOk(['resources' => getPlayerResources($userId)]);
}

if ($method === 'GET' && $endpoint === '/player/fleets') {
    $userId = currentUserId($secret);
    apiOk(['fleets' => getPlayerFleets($userId)]);
}

if ($method === 'POST' && $endpoint === '/fleet/move') {
    applyRateLimit('fleet_move', 30, 60);

    $userId = currentUserId($secret);
    $body = jsonBody();

    $fleetSlot = (int)($body['fleet_slot'] ?? -1);
    $targetSector = (int)($body['target_sector'] ?? 0);
    $targetSystem = (int)($body['target_system'] ?? 0);

    moveFleet($userId, $fleetSlot, $targetSector, $targetSystem);
    apiOk([
        'status' => 'ok',
        'fleet_slot' => $fleetSlot,
        'target_sector' => $targetSector,
        'target_system' => $targetSystem,
    ]);
}

apiError(404, 'Endpoint not found');
