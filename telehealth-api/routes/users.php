<?php
// routes/users.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

// Parse URL to get the sub-action or ID
// e.g. /telehealth-api/users/login => parts = ['users','login']
// e.g. /telehealth-api/users/3     => parts = ['users','3']
$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
$basePath   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$path       = trim(substr($requestUri, strlen($basePath)), '/');
$parts      = explode('/', $path);
$action     = $parts[1] ?? ''; // 'login', 'register', or a numeric ID

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── POST /users/register ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'register') {
    $name     = trim($body['name'] ?? '');
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $phone    = trim($body['phone'] ?? '');

    if (!$name || !$email || !$password) {
        http_response_code(400);
        echo json_encode(['error' => 'Name, email, and password are required.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db   = getDB();
    $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, phone) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $name, $email, $hash, $phone);

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['message' => 'Account created successfully.', 'user_id' => $db->insert_id]);
    } else {
        if ($db->errno === 1062) {
            http_response_code(409);
            echo json_encode(['error' => 'This email is already registered.']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => $db->error]);
        }
    }
    $stmt->close(); $db->close(); exit;
}

// ── POST /users/login ───────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$email || !$password) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password are required.']);
        exit;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found.']);
        $db->close(); exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Incorrect password.']);
        $db->close(); exit;
    }

    $token = createToken($user['user_id'], $user['role']);
    echo json_encode(['message' => 'Login successful.', 'token' => $token]);
    $db->close(); exit;
}

// ── GET /users ──────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    verifyToken();
    $db     = getDB();
    $result = $db->query('SELECT user_id, name, email, phone, role, created_at FROM users');
    $users  = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($users);
    $db->close(); exit;
}

// ── PUT /users/:id ──────────────────────────────────────────────────────────
if ($method === 'PUT' && is_numeric($action)) {
    verifyToken();
    $name  = trim($body['name'] ?? '');
    $phone = trim($body['phone'] ?? '');
    $db    = getDB();
    $stmt  = $db->prepare('UPDATE users SET name = ?, phone = ? WHERE user_id = ?');
    $stmt->bind_param('ssi', $name, $phone, $action);
    $stmt->execute();
    echo json_encode(['message' => 'User updated successfully.']);
    $stmt->close(); $db->close(); exit;
}

// ── DELETE /users/:id ───────────────────────────────────────────────────────
if ($method === 'DELETE' && is_numeric($action)) {
    verifyToken();
    $db   = getDB();
    $stmt = $db->prepare('DELETE FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $action);
    $stmt->execute();
    echo json_encode(['message' => 'User deleted successfully.']);
    $stmt->close(); $db->close(); exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found.']);