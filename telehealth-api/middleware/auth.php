<?php
// middleware/auth.php
// JWT authentication — built without any external library
// Uses HMAC-SHA256 to sign and verify tokens

define('JWT_SECRET', 'telehealth_secret_key_123');

// ── Create a JWT token ──────────────────────────────────────────────────────
function createToken($user_id, $role) {
    $header  = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64UrlEncode(json_encode([
        'user_id' => $user_id,
        'role'    => $role,
        'exp'     => time() + (2 * 60 * 60)  // expires in 2 hours
    ]));
    $signature = base64UrlEncode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );
    return "$header.$payload.$signature";
}

// ── Verify a JWT token and return the payload ───────────────────────────────
function verifyToken() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Token must be: Authorization: Bearer <token>
    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Access denied. No token provided.']);
        exit;
    }

    $token  = substr($authHeader, 7); // remove "Bearer "
    $parts  = explode('.', $token);

    if (count($parts) !== 3) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token format.']);
        exit;
    }

    [$header, $payload, $signature] = $parts;

    // Re-compute the signature and compare
    $expectedSig = base64UrlEncode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );

    if ($signature !== $expectedSig) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token signature.']);
        exit;
    }

    $data = json_decode(base64UrlDecode($payload), true);

    // Check expiry
    if ($data['exp'] < time()) {
        http_response_code(403);
        echo json_encode(['error' => 'Token has expired. Please login again.']);
        exit;
    }

    return $data; // returns ['user_id' => ..., 'role' => ...]
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}