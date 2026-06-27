# Learners Progress Monitoring System

PHP, MySQL, Bootstrap 5, and jQuery system for managing learners, classes, courses, and course enrollments.

## Local Login

- Email: `admin@learnersprogress.local`
- Password: `Admin@12345`

## Setup

1. Confirm the existing database is available in MySQL.
2. Confirm database credentials in `config/Database.php`.
3. For email notifications, either set `KIWI_SMTP_PASSWORD` in the server environment or copy `config/Mailer.local.example.php` to `config/Mailer.local.php` and set the mailbox password there.
4. Visit `index.php`, then login.
