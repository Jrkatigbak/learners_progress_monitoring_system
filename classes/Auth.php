<?php

class Auth
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function attempt(string $email, string $password): bool
    {
        $statement = $this->db->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email ORDER BY FIELD(role, "admin", "teacher", "learner")');
        $statement->execute(['email' => $email]);
        $users = $statement->fetchAll();
        $user = null;

        // The same email may have separate learner and teacher accounts, so match by password too.
        foreach ($users as $candidate) {
            if (password_verify($password, $candidate['password_hash'])) {
                $user = $candidate;
                break;
            }
        }

        if (!$user) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];

        return true;
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function isAdmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    public function isLearner(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'learner';
    }

    public function isTeacher(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'teacher';
    }

    public function redirectPath(): string
    {
        // Route each role to its own landing page after login and direct index visits.
        if ($this->isLearner()) {
            return 'learner_dashboard.php';
        }

        if ($this->isTeacher()) {
            return 'teacher_dashboard.php';
        }

        return 'dashboard.php';
    }

    public function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }
}
