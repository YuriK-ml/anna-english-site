<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../api/_guard.php';

tutor_session_start();

// Destroy student session.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params['path'] ?? '/',
    $params['domain'] ?? '',
    (bool)($params['secure'] ?? false),
    (bool)($params['httponly'] ?? true)
  );
}

@session_destroy();

// Redirect back to Tutor (preserve standalone mode if present)
$mode = (string)($_GET['mode'] ?? '');
$target = '/tutor/';
if ($mode === 'standalone') {
  $target = '/tutor/?mode=standalone';
}
header('Location: ' . $target);
exit;
