<?php
declare(strict_types=1);

require_once __DIR__ . '/include/bootstrap.php';

admin_session_start();
admin_logout();

header('Location: /admin/login.php');
exit;
