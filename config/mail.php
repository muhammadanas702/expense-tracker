<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendResetMail($email, $token) {

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();

        $mail->Host = 'smtp.gmail.com';

        $mail->SMTPAuth = true;

        $mail->Username = 'YOUR_GMAIL@gmail.com';

        $mail->Password = 'YOUR_APP_PASSWORD';

        $mail->SMTPSecure = 'tls';

        $mail->Port = 587;

        $mail->setFrom(
            'YOUR_GMAIL@gmail.com',
            'ExpenseFlow'
        );

        $mail->addAddress($email);

        $resetLink =
        "http://localhost/auth/reset-password.php?token=$token";

        $mail->isHTML(true);

        $mail->Subject = 'ExpenseFlow Password Reset';

        $mail->Body = "
            <h2>Password Reset</h2>

            <p>
                Click below to reset password:
            </p>

            <a href='$resetLink'>
                Reset Password
            </a>
        ";

        $mail->send();

        return true;

    } catch (Exception $e) {

        return false;
    }
}