<?php
// helpers/email.php
// Sends a booking confirmation email using PHP's built-in mail() function
// NOTE: mail() works when XAMPP is configured with a mail server (like Mercury Mail)
// For testing, you can use Mailtrap.io (free) or comment out the sendConfirmationEmail call

function sendConfirmationEmail($toEmail, $patientName, $doctorName, $specialty, $apptDate, $reason, $apptId) {
    $subject = 'Appointment Confirmation - TeleHealth System';

    $body  = "Dear $patientName,\r\n\r\n";
    $body .= "Your appointment has been successfully booked.\r\n\r\n";
    $body .= "Doctor   : $doctorName ($specialty)\r\n";
    $body .= "Date     : $apptDate\r\n";
    $body .= "Reason   : $reason\r\n";
    $body .= "Appt ID  : $apptId\r\n\r\n";
    $body .= "Please be on time. Thank you for using our service.\r\n";

    $headers = "From: noreply@telehealth.com\r\n";
    $headers .= "Reply-To: noreply@telehealth.com\r\n";

    // mail() returns true if accepted, false if not
    return mail($toEmail, $subject, $body, $headers);
}