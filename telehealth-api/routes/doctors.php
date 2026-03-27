<?php
// routes/doctors.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$method     = $_SERVER['REQUEST_METHOD'];
$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
$basePath   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$path       = trim(substr($requestUri, strlen($basePath)), '/');
$parts      = explode('/', $path);
$action     = $parts[1] ?? '';

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── GET /doctors ──────────────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    $db      = getDB();
    $result  = $db->query('SELECT * FROM doctors ORDER BY name ASC');
    $doctors = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($doctors);
    $db->close(); exit;
}

// ── GET /doctors/:id ──────────────────────────────────────────────────────
if ($method === 'GET' && is_numeric($action)) {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM doctors WHERE doctor_id = ?');
    $stmt->bind_param('i', $action);
    $stmt->execute();
    $doctor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$doctor) { http_response_code(404); echo json_encode(['error' => 'Doctor not found.']); }
    else { echo json_encode($doctor); }
    $db->close(); exit;
}

// ── POST /doctors ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === '') {
    verifyToken();
    $name      = trim($body['name'] ?? '');
    $specialty = trim($body['specialty'] ?? '');
    $email     = trim($body['email'] ?? '');
    $phone     = trim($body['phone'] ?? '');
    $db   = getDB();
    $stmt = $db->prepare('INSERT INTO doctors (name, specialty, email, phone) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $name, $specialty, $email, $phone);
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['message' => 'Doctor added.', 'doctor_id' => $db->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $db->error]);
    }
    $stmt->close(); $db->close(); exit;
}

// ── PUT /doctors/:id ──────────────────────────────────────────────────────
if ($method === 'PUT' && is_numeric($action)) {
    verifyToken();
    $name      = trim($body['name'] ?? '');
    $specialty = trim($body['specialty'] ?? '');
    $phone     = trim($body['phone'] ?? '');
    $available = isset($body['available']) ? (int)$body['available'] : 1;
    $db   = getDB();
    $stmt = $db->prepare('UPDATE doctors SET name=?, specialty=?, phone=?, available=? WHERE doctor_id=?');
    $stmt->bind_param('sssii', $name, $specialty, $phone, $available, $action);
    $stmt->execute();
    echo json_encode(['message' => 'Doctor updated.']);
    $stmt->close(); $db->close(); exit;
}

// ── DELETE /doctors/:id ───────────────────────────────────────────────────
if ($method === 'DELETE' && is_numeric($action)) {
    verifyToken();
    $db   = getDB();
    $stmt = $db->prepare('DELETE FROM doctors WHERE doctor_id = ?');
    $stmt->bind_param('i', $action);
    $stmt->execute();
    echo json_encode(['message' => 'Doctor removed.']);
    $stmt->close(); $db->close(); exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found.']);