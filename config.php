<?php
/**
 * Configuration centrale de SuiviLTP.
 * Ce fichier est le SEUL point de configuration : base de données, session, erreurs.
 * Toutes les pages l'incluent (directement ou via auth_functions.php).
 */

// ---------------------------------------------------------------------------
// Base de données
// ---------------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'ltp');
define('DB_USER', 'root');
define('DB_PASS', '');

// Les établissements (nom, ville, contact affichés sur les reçus) se gèrent dans la page « Établissements ».

// ---------------------------------------------------------------------------
// Mode debug : à laisser à false (sinon les erreurs PHP s'affichent à l'écran)
// ---------------------------------------------------------------------------
define('APP_DEBUG', false);

ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Session sécurisée (démarrée une seule fois, ici)
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SUIVILTP');
    session_start();
}

// ---------------------------------------------------------------------------
// Connexion PDO ($pdo ; $conn est conservé comme alias pour l'ancien code)
// ---------------------------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $conn = $pdo;
} catch (PDOException $e) {
    error_log('[SuiviLTP] Connexion BDD impossible : ' . $e->getMessage());
    http_response_code(500);
    die('Le service est momentanément indisponible. Veuillez réessayer plus tard.');
}

require_once __DIR__ . '/includes/security.php';

// Toute requête POST doit porter un jeton CSRF valide.
csrf_verify_request();
