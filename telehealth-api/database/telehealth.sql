-- Telemedicine & Healthcare Booking System
-- Database: telehealth_db
-- Run this in phpMyAdmin or MySQL command line

CREATE DATABASE IF NOT EXISTS telehealth_db;
USE telehealth_db;

-- Table 1: users (patients)
CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone         VARCHAR(20),
    role          ENUM('patient', 'admin') DEFAULT 'patient',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table 2: doctors
CREATE TABLE doctors (
    doctor_id  INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    specialty  VARCHAR(100) NOT NULL,
    email      VARCHAR(150) NOT NULL UNIQUE,
    phone      VARCHAR(20),
    available  TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table 3: appointments
CREATE TABLE appointments (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    doctor_id      INT NOT NULL,
    appt_date      DATETIME NOT NULL,
    reason         VARCHAR(255),
    status         ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(user_id)     ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id) ON DELETE CASCADE
);

-- Table 4: payments
CREATE TABLE payments (
    payment_id     INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL UNIQUE,
    amount         DECIMAL(8,2) NOT NULL,
    payment_status ENUM('pending','paid','refunded') DEFAULT 'pending',
    method         VARCHAR(50) DEFAULT 'online',
    paid_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE
);

-- Sample data
INSERT INTO users (name, email, password_hash, phone, role) VALUES
('Ahmad Faiz',   'ahmad@example.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0123456789', 'patient'),
('Nurul Ain',    'nurul@example.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0198765432', 'patient'),
('Admin Health', 'admin@telehealth.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0111234567', 'admin');
-- Note: all sample passwords are hashed version of "password"

INSERT INTO doctors (name, specialty, email, phone, available) VALUES
('Dr. Sarah Lim',   'General Practice', 'sarah@clinic.com', '0123000001', 1),
('Dr. Ravi Kumar',  'Cardiology',       'ravi@clinic.com',  '0123000002', 1),
('Dr. Aisha Malik', 'Dermatology',      'aisha@clinic.com', '0123000003', 1),
('Dr. James Tan',   'Paediatrics',      'james@clinic.com', '0123000004', 1);

INSERT INTO appointments (user_id, doctor_id, appt_date, reason, status) VALUES
(1, 1, '2026-04-10 09:00:00', 'Fever and cough',     'scheduled'),
(1, 2, '2026-04-11 14:00:00', 'Chest pain checkup',  'scheduled'),
(2, 3, '2026-04-12 10:30:00', 'Skin rash',           'scheduled');

INSERT INTO payments (appointment_id, amount, payment_status, method) VALUES
(1, 50.00,  'paid',    'online'),
(2, 120.00, 'paid',    'online'),
(3, 80.00,  'pending', 'online');