<?php
require_once 'config.php';

// Déconnexion complète : données de session, cookie de session et ancien cookie "remember_me".
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
setcookie('remember_me', '', time() - 3600, '/');
session_destroy();

header('Location: bienvenue.html');
exit;
