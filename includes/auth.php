<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

session_start();

function is_logged_in(): bool
{
    return isset($_SESSION['auth']) && $_SESSION['auth'] === true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function require_login_for_api(): void
{
    if (!is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function attempt_login(string $username, string $password): bool
{
    global $config;
    if ($username === $config['auth']['username'] && $password === $config['auth']['password']) {
        $_SESSION['auth'] = true;
        return true;
    }
    return false;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}
