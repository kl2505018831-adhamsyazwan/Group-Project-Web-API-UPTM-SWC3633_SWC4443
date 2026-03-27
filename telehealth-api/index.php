<?php
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $headers['authorization'];
        }
    }
}
// ── CORS Headers ─────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

// ── Database ──────────────────────────────────────────────────
function getDB() {
    $conn = new mysqli('localhost', 'root', '', 'telehealth_db');
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }
    $conn->set_charset('utf8');
    return $conn;
}

// ── JWT ───────────────────────────────────────────────────────
define('JWT_SECRET', 'telehealth_secret_key_123');

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}
function createToken($user_id, $role) {
    $header    = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload   = base64UrlEncode(json_encode([
        'user_id' => $user_id,
        'role'    => $role,
        'exp'     => time() + 7200
    ]));
    $signature = base64UrlEncode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}
function verifyToken() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Access denied. No token provided.']);
        exit;
    }
    $token  = substr($authHeader, 7);
    $parts  = explode('.', $token);
    if (count($parts) !== 3) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token.']);
        exit;
    }
    [$header, $payload, $signature] = $parts;
    $expected = base64UrlEncode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if ($signature !== $expected) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token signature.']);
        exit;
    }
    $data = json_decode(base64UrlDecode($payload), true);
    if ($data['exp'] < time()) {
        http_response_code(403);
        echo json_encode(['error' => 'Token expired. Please login again.']);
        exit;
    }
    return $data;
}

// ── Router ────────────────────────────────────────────────────
$method     = $_SERVER['REQUEST_METHOD'];
$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
$basePath   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$path       = trim(substr($requestUri, strlen($basePath)), '/');
$parts      = explode('/', $path);
$resource   = $parts[0] ?? '';
$action     = $parts[1] ?? '';
$body       = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Helper ────────────────────────────────────────────────────
function sendEmail($to, $patientName, $doctorName, $specialty, $date, $reason, $id) {
    $subject  = 'Appointment Confirmation - TeleHealth';
    $message  = "Dear $patientName,\r\n\r\nYour appointment is confirmed.\r\n";
    $message .= "Doctor: $doctorName ($specialty)\r\nDate: $date\r\nReason: $reason\r\nID: $id\r\n";
    $headers  = "From: noreply@telehealth.com\r\n";
    @mail($to, $subject, $message, $headers);
}

// ============================================================
// USERS
// ============================================================
if ($resource === 'users') {

    // POST /users/register
    if ($method === 'POST' && $action === 'register') {
        $name     = trim($body['name'] ?? '');
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $phone    = trim($body['phone'] ?? '');
        if (!$name || !$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Name, email and password are required.']); exit;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db   = getDB();
        $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, phone) VALUES (?,?,?,?)');
        $stmt->bind_param('ssss', $name, $email, $hash, $phone);
        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(['message' => 'Account created successfully.', 'user_id' => $db->insert_id]);
        } else {
            http_response_code($db->errno === 1062 ? 409 : 500);
            echo json_encode(['error' => $db->errno === 1062 ? 'Email already registered.' : $db->error]);
        }
        $stmt->close(); $db->close(); exit;
    }

    // POST /users/login
    if ($method === 'POST' && $action === 'login') {
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        if (!$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Email and password are required.']); exit;
        }
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found.']); $db->close(); exit;
        }
        if (!password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Incorrect password.']); $db->close(); exit;
        }
        $token = createToken($user['user_id'], $user['role']);
        echo json_encode(['message' => 'Login successful.', 'token' => $token]);
        $db->close(); exit;
    }

    // GET /users
    if ($method === 'GET' && $action === '') {
        verifyToken();
        $db     = getDB();
        $result = $db->query('SELECT user_id, name, email, phone, role, created_at FROM users');
        echo json_encode($result->fetch_all(MYSQLI_ASSOC));
        $db->close(); exit;
    }

    // PUT /users/:id
    if ($method === 'PUT' && is_numeric($action)) {
        verifyToken();
        $name  = trim($body['name'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $db    = getDB();
        $stmt  = $db->prepare('UPDATE users SET name=?, phone=? WHERE user_id=?');
        $stmt->bind_param('ssi', $name, $phone, $action);
        $stmt->execute();
        echo json_encode(['message' => 'User updated.']);
        $stmt->close(); $db->close(); exit;
    }

    // DELETE /users/:id
    if ($method === 'DELETE' && is_numeric($action)) {
        verifyToken();
        $db   = getDB();
        $stmt = $db->prepare('DELETE FROM users WHERE user_id=?');
        $stmt->bind_param('i', $action);
        $stmt->execute();
        echo json_encode(['message' => 'User deleted.']);
        $stmt->close(); $db->close(); exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found.']); exit;
}

// ============================================================
// DOCTORS
// ============================================================
if ($resource === 'doctors') {

    // GET /doctors
    if ($method === 'GET' && $action === '') {
        $db     = getDB();
        $result = $db->query('SELECT * FROM doctors ORDER BY name ASC');
        echo json_encode($result->fetch_all(MYSQLI_ASSOC));
        $db->close(); exit;
    }

    // GET /doctors/:id
    if ($method === 'GET' && is_numeric($action)) {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM doctors WHERE doctor_id=?');
        $stmt->bind_param('i', $action);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close(); $db->close();
        if (!$doc) { http_response_code(404); echo json_encode(['error' => 'Doctor not found.']); }
        else { echo json_encode($doc); }
        exit;
    }

    // POST /doctors
    if ($method === 'POST' && $action === '') {
        verifyToken();
        $name      = trim($body['name'] ?? '');
        $specialty = trim($body['specialty'] ?? '');
        $email     = trim($body['email'] ?? '');
        $phone     = trim($body['phone'] ?? '');
        $db        = getDB();
        $stmt      = $db->prepare('INSERT INTO doctors (name, specialty, email, phone) VALUES (?,?,?,?)');
        $stmt->bind_param('ssss', $name, $specialty, $email, $phone);
        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(['message' => 'Doctor added.', 'doctor_id' => $db->insert_id]);
        } else {
            http_response_code(500); echo json_encode(['error' => $db->error]);
        }
        $stmt->close(); $db->close(); exit;
    }

    // PUT /doctors/:id
    if ($method === 'PUT' && is_numeric($action)) {
        verifyToken();
        $name      = trim($body['name'] ?? '');
        $specialty = trim($body['specialty'] ?? '');
        $phone     = trim($body['phone'] ?? '');
        $available = isset($body['available']) ? (int)$body['available'] : 1;
        $db        = getDB();
        $stmt      = $db->prepare('UPDATE doctors SET name=?, specialty=?, phone=?, available=? WHERE doctor_id=?');
        $stmt->bind_param('sssii', $name, $specialty, $phone, $available, $action);
        $stmt->execute();
        echo json_encode(['message' => 'Doctor updated.']);
        $stmt->close(); $db->close(); exit;
    }

    // DELETE /doctors/:id
    if ($method === 'DELETE' && is_numeric($action)) {
        verifyToken();
        $db   = getDB();
        $stmt = $db->prepare('DELETE FROM doctors WHERE doctor_id=?');
        $stmt->bind_param('i', $action);
        $stmt->execute();
        echo json_encode(['message' => 'Doctor removed.']);
        $stmt->close(); $db->close(); exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found.']); exit;
}

// ============================================================
// APPOINTMENTS
// ============================================================
if ($resource === 'appointments') {

    // GET /appointments
    if ($method === 'GET' && $action === '') {
        verifyToken();
        $db     = getDB();
        $result = $db->query('
            SELECT a.appointment_id, a.user_id,
                   u.name AS patient_name, d.name AS doctor_name, d.specialty,
                   a.appt_date, a.reason, a.status, a.created_at
            FROM appointments a
            JOIN users   u ON a.user_id   = u.user_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            ORDER BY a.appt_date ASC
        ');
        echo json_encode($result->fetch_all(MYSQLI_ASSOC));
        $db->close(); exit;
    }

    // GET /appointments/:id
    if ($method === 'GET' && is_numeric($action)) {
        verifyToken();
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM appointments WHERE appointment_id=?');
        $stmt->bind_param('i', $action);
        $stmt->execute();
        $appt = $stmt->get_result()->fetch_assoc();
        $stmt->close(); $db->close();
        if (!$appt) { http_response_code(404); echo json_encode(['error' => 'Not found.']); }
        else { echo json_encode($appt); }
        exit;
    }

    // POST /appointments
    if ($method === 'POST' && $action === '') {
        verifyToken();
        $user_id   = (int)($body['user_id'] ?? 0);
        $doctor_id = (int)($body['doctor_id'] ?? 0);
        $appt_date = trim($body['appt_date'] ?? '');
        $reason    = trim($body['reason'] ?? '');
        if (!$user_id || !$doctor_id || !$appt_date) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id, doctor_id and appt_date are required.']); exit;
        }
        $db   = getDB();
        $stmt = $db->prepare('INSERT INTO appointments (user_id, doctor_id, appt_date, reason) VALUES (?,?,?,?)');
        $stmt->bind_param('iiss', $user_id, $doctor_id, $appt_date, $reason);
        if (!$stmt->execute()) {
            http_response_code(500); echo json_encode(['error' => $db->error]);
            $stmt->close(); $db->close(); exit;
        }
        $appt_id = $db->insert_id; $stmt->close();
        $stmt = $db->prepare('SELECT name, email FROM users WHERE user_id=?');
        $stmt->bind_param('i', $user_id); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $stmt = $db->prepare('SELECT name, specialty FROM doctors WHERE doctor_id=?');
        $stmt->bind_param('i', $doctor_id); $stmt->execute();
        $doctor = $stmt->get_result()->fetch_assoc(); $stmt->close();
        sendEmail($user['email'], $user['name'], $doctor['name'], $doctor['specialty'], $appt_date, $reason, $appt_id);
        $db->close();
        http_response_code(201);
        echo json_encode(['message' => 'Appointment booked. Confirmation email sent.', 'appointment_id' => $appt_id]);
        exit;
    }

    // PUT /appointments/:id
    if ($method === 'PUT' && is_numeric($action)) {
        verifyToken();
        $status = trim($body['status'] ?? '');
        $db     = getDB();
        $stmt   = $db->prepare('UPDATE appointments SET status=? WHERE appointment_id=?');
        $stmt->bind_param('si', $status, $action);
        $stmt->execute();
        echo json_encode(['message' => 'Appointment updated.']);
        $stmt->close(); $db->close(); exit;
    }

    // DELETE /appointments/:id
    if ($method === 'DELETE' && is_numeric($action)) {
        verifyToken();
        $db   = getDB();
        $stmt = $db->prepare('DELETE FROM appointments WHERE appointment_id=?');
        $stmt->bind_param('i', $action);
        $stmt->execute();
        echo json_encode(['message' => 'Appointment cancelled.']);
        $stmt->close(); $db->close(); exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found.']); exit;
}

// ============================================================
// PAYMENTS
// ============================================================
if ($resource === 'payments') {

    // GET /payments
    if ($method === 'GET' && $action === '') {
        verifyToken();
        $db     = getDB();
        $result = $db->query('
            SELECT p.payment_id, p.appointment_id, a.user_id,
                   u.name AS patient_name, d.name AS doctor_name,
                   a.appt_date, p.amount, p.payment_status, p.method, p.paid_at
            FROM payments p
            JOIN appointments a ON p.appointment_id = a.appointment_id
            JOIN users        u ON a.user_id         = u.user_id
            JOIN doctors      d ON a.doctor_id        = d.doctor_id
            ORDER BY p.paid_at DESC
        ');
        echo json_encode($result->fetch_all(MYSQLI_ASSOC));
        $db->close(); exit;
    }

    // GET /payments/:id
    if ($method === 'GET' && is_numeric($action)) {
        verifyToken();
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM payments WHERE payment_id=?');
        $stmt->bind_param('i', $action);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close(); $db->close();
        if (!$pay) { http_response_code(404); echo json_encode(['error' => 'Not found.']); }
        else { echo json_encode($pay); }
        exit;
    }

    // POST /payments
    if ($method === 'POST' && $action === '') {
        verifyToken();
        $appointment_id = (int)($body['appointment_id'] ?? 0);
        $amount         = (float)($body['amount'] ?? 0);
        $pay_method     = trim($body['method'] ?? 'online');
        $db             = getDB();
        $stmt           = $db->prepare('INSERT INTO payments (appointment_id, amount, payment_status, method) VALUES (?,?,"paid",?)');
        $stmt->bind_param('ids', $appointment_id, $amount, $pay_method);
        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(['message' => 'Payment recorded.', 'payment_id' => $db->insert_id]);
        } else {
            http_response_code(500); echo json_encode(['error' => $db->error]);
        }
        $stmt->close(); $db->close(); exit;
    }

    // PUT /payments/:id
    if ($method === 'PUT' && is_numeric($action)) {
        verifyToken();
        $status = trim($body['payment_status'] ?? '');
        $db     = getDB();
        $stmt   = $db->prepare('UPDATE payments SET payment_status=? WHERE payment_id=?');
        $stmt->bind_param('si', $status, $action);
        $stmt->execute();
        echo json_encode(['message' => 'Payment updated.']);
        $stmt->close(); $db->close(); exit;
    }

    // DELETE /payments/:id
    if ($method === 'DELETE' && is_numeric($action)) {
        verifyToken();
        $db   = getDB();
        $stmt = $db->prepare('DELETE FROM payments WHERE payment_id=?');
        $stmt->bind_param('i', $action);
        $stmt->execute();
        echo json_encode(['message' => 'Payment deleted.']);
        $stmt->close(); $db->close(); exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found.']); exit;
}

// ── Default ───────────────────────────────────────────────────
http_response_code(200);
echo json_encode(['message' => 'TeleHealth API is running.']);