<?php
// routes/payments.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$method     = $_SERVER['REQUEST_METHOD'];
$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
$basePath   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$path       = trim(substr($requestUri, strlen($basePath)), '/');
$parts      = explode('/', $path);
$action     = $parts[1] ?? '';

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── GET /payments ─────────────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    verifyToken();
    $db  = getDB();
    $sql = '
        SELECT
            p.payment_id,
            p.appointment_id,
            a.user_id,
            u.name       AS patient_name,
            d.name       AS doctor_name,
            a.appt_date,
            p.amount,
            p.payment_status,
            p.method,
            p.paid_at
        FROM payments p
        JOIN appointments a ON p.appointment_id = a.appointment_id
        JOIN users        u ON a.user_id         = u.user_id
        JOIN doctors      d ON a.doctor_id        = d.doctor_id
        ORDER BY p.paid_at DESC
    ';
    $result   = $db->query($sql);
    $payments = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($payments);
    $db->close(); exit;
}

// ── GET /payments/:id ─────────────────────────────────────────────────────
if ($method === 'GET' && is_numeric($action)) {
    verifyToken();
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM payments WHERE payment_id = ?');
    $stmt->bind_param('i', $action);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment) { http_response_code(404); echo json_encode(['error' => 'Payment not found.']); }
    else { echo json_encode($payment); }
    $db->close(); exit;
}

// ── POST /payments ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === '') {
    verifyToken();
    $appointment_id = (int)($body['appointment_id'] ?? 0);
    $amount         = (float)($body['amount'] ?? 0);
    $method_pay     = trim($body['method'] ?? 'online');
    $db   = getDB();
    $stmt = $db->prepare('INSERT INTO payments (appointment_id, amount, payment_status, method) VALUES (?, ?, "paid", ?)');
    $stmt->bind_param('ids', $appointment_id, $amount, $method_pay);
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['message' => 'Payment recorded.', 'payment_id' => $db->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $db->error]);
    }
    $stmt->close(); $db->close(); exit;
}

// ── PUT /payments/:id ─────────────────────────────────────────────────────
if ($method === 'PUT' && is_numeric($action)) {
    verifyToken();
    $payment_status = trim($body['payment_status'] ?? '');
    $db   = getDB();
    $stmt = $db->prepare('UPDATE payments SET payment_status = ? WHERE payment_id = ?');
    $stmt->bind_param('si', $payment_status, $action);
    $stmt->execute();
    echo json_encode(['message' => 'Payment status updated.']);
    $stmt->close(); $db->close(); exit;
}

// ── DELETE /payments/:id ──────────────────────────────────────────────────
if ($method === 'DELETE' && is_numeric($action)) {
    verifyToken();
    $db   = getDB();
    $stmt = $db->prepare('DELETE FROM payments WHERE payment_id = ?');
    $stmt->bind_param('i', $action);
    $stmt->execute();
    echo json_encode(['message' => 'Payment deleted.']);
    $stmt->close(); $db->close(); exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found.']);