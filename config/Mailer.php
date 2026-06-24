<?php

return [
    // SMTP sender for Kiwi business email notifications.
    'host' => getenv('KIWI_SMTP_HOST') ?: 'smtp.hostinger.com',
    'port' => (int) (getenv('KIWI_SMTP_PORT') ?: 465),
    'encryption' => getenv('KIWI_SMTP_ENCRYPTION') ?: 'ssl',
    'username' => getenv('KIWI_SMTP_USERNAME') ?: 'info@kiwidigitaltech.com',
    'password' => getenv('KIWI_SMTP_PASSWORD') ?: '',
    'from_email' => getenv('KIWI_MAIL_FROM_EMAIL') ?: 'info@kiwidigitaltech.com',
    'from_name' => getenv('KIWI_MAIL_FROM_NAME') ?: 'Kiwi Digital Tech',
];
