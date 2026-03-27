<?php
// routes/appointments.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/email.php';

$method     = $_SERVER['REQUEST_METHOD'];
$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
$basePath   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$path       = trim(substr($requestUri, strlen($basePath)), '/');
$parts      = explode('/', $path);
$action     = $parts[1] ?? '';

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── GET /appointments ─────────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    verifyToken();
    $db  = getDB();
    $sql = '
        SELECT
            a.appointment_id,
            a.user_id,
            u.name      AS patient_name,
            d.name      AS doctor_name,
            d.specialty,
            a.appt_date,
            a.reason,
            a.status,
            a.created_at
        FROM appointments a
        JOIN users   u ON a.user_id   = u.user_id
        JOIN doctors d ON a.doctor_id = d.doctor_id
        ORDER BY a.appt_date ASC
    ';
    $result       = $db->query($sql);
    $appointments = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($appointments);
    $db->close(); exit;
}

// ── GET /appointments/:id ─────────────────────────────────────────────────
if ($method === 'GET' && is_numeric($action)) {
    verifyToken();
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM appointments WHERE appointment_id = ?');
    $stmt->bind_param('i', $action);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appt) { http_response_code(404); echo json_encode(['error' => 'Appointment not found.']); }
    else { echo json_encode($appt); }
    $db->close(); exit;
}

// ── POST /appointments ─────────────────────────────────────────────────────
if ($method === 'POST' && $action === '') {
    verifyToken();
    $user_id   = (int)($body['user_id'] ?? 0);
    $doctor_id = (int)($body['doctor_id'] ?? 0);
    $appt_date = trim($body['appt_date'] ?? '');
    $reason    = trim($body['reason'] ?? '');

    if (!$user_id || !$doctor_id || !$appt_date) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id, doctor_id, and appt_date are required.']);
        exit;
    }

    $db   = getDB();
    $stmt = $db->prepare('INSERT INTO appointments (user_id, doctor_id, appt_date, reason) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $user_id, $doctor_id, $appt_date, $reason);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['error' => $db->error]);
        $stmt->close(); $db->close(); exit;
    }

    $appt_id = $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare('SELECT name, email FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $db->prepare('SELECT name, specialty FROM doctors WHERE doctor_id = ?');
    $stmt->bind_param('i', $doctor_id);
    $stmt->execute();
    $doctor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    sendConfirmationEmail($user['email'], $user['name'], $doctor['name'], $doctor['specialty'], $appt_date, $reason, $appt_id);

    $db->close();
    http_response_code(201);
    echo json_encode(['message' => 'Appointment booked. Confirmation email sent.', 'appointment_id' => $appt_id]);
    exit;
}

// ── PUT /appointments/:id ─────────────────────────────────────────────────
if ($method === 'PUT' && is_numeric($action)) {
    verifyToken();
    $status = trim($body['status'] ?? '');
    $db     = getDB();
    $stmt   = $db->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
    $stmt->bind_param('si', $status, $action);
    $stmt->execute();
    echo json_encode(['message' => 'Appointment updated.']);
    $stmt->close(); $db->close(); exit;
}

// ── DELETE /appointments/:id ──────────────────────────────────────────────
if ($method === 'DELETE' && is_numeric($action)) {
    verifyToken();
    $db   = getDB();
    $stmt = $db->prepare('DELETE FROM appointments WHERE appointment_id = ?');
    $stmt->bind_param('i', $action);
    $stmt->execute();
    echo json_encode(['message' => 'Appointment cancelled.']);
    $stmt->close(); $db->close(); exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found.']);