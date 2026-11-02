<?php
/**
 * Fonctions de sécurité communes : échappement, CSRF, messages flash,
 * génération de codes aléatoires et limitation des tentatives de connexion.
 */

/** Échappe une valeur pour l'affichage HTML. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Champ caché à placer dans chaque formulaire POST. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Vérifie le jeton CSRF de toute requête POST ; arrête la requête sinon. */
function csrf_verify_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent)) {
        return;
    }

    http_response_code(403);
    $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $message = empty($_POST)
        ? "La requête est vide (le fichier envoyé est peut-être trop volumineux) ou la session a expiré."
        : "Votre session a expiré ou la requête n'est pas valide. Rechargez la page et réessayez.";

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        $back = e($_SERVER['HTTP_REFERER'] ?? 'bienvenue.html');
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Session expirée</title>'
           . '<link rel="stylesheet" href="assets/css/app.css"></head><body class="auth-page">'
           . '<div class="auth-card"><h1>Session expirée</h1><p class="text-muted">' . e($message) . '</p>'
           . '<a class="btn btn-primary btn-block" href="' . $back . '">Revenir à la page</a></div></body></html>';
    }
    exit;
}

// ---------------------------------------------------------------------------
// Messages flash (affichés une seule fois après une redirection)
// ---------------------------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int, array{type:string, message:string}> */
function flash_pull(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Redirige et termine le script. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------------------
// Codes et mots de passe aléatoires (cryptographiquement sûrs)
// ---------------------------------------------------------------------------
/** Code sans caractères ambigus (pas de 0/O, 1/I/L). */
function random_code(int $length = 6, string $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'): string
{
    $code = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}

function random_password(int $length = 10): string
{
    return random_code($length, 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789');
}

/** Génère un code secret parent qui n'existe pas encore en base. */
function unique_secret_code(PDO $pdo): string
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM eleves WHERE code_secret = ?');
    do {
        $code = random_code(6);
        $check->execute([$code]);
    } while ($check->fetchColumn() > 0);
    return $code;
}

// ---------------------------------------------------------------------------
// Limitation des tentatives de connexion (anti force brute)
// ---------------------------------------------------------------------------
const LOGIN_MAX_ATTEMPTS = 8;      // tentatives échouées autorisées…
const LOGIN_WINDOW_MINUTES = 15;   // …sur cette durée

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'inconnue', 0, 45);
}

/** Retourne true si l'IP est temporairement bloquée pour ce type de connexion. */
function login_is_blocked(PDO $pdo, string $type): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND type = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)'
    );
    $stmt->execute([client_ip(), $type]);
    return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function login_record_failure(PDO $pdo, string $type): void
{
    $pdo->prepare('INSERT INTO login_attempts (ip, type) VALUES (?, ?)')->execute([client_ip(), $type]);
}

function login_clear_failures(PDO $pdo, string $type): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND type = ?')->execute([client_ip(), $type]);
    // Ménage : on ne garde pas l'historique au-delà d'un jour.
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

// ---------------------------------------------------------------------------
// Erreurs
// ---------------------------------------------------------------------------
/** Journalise l'exception et retourne un message neutre pour l'utilisateur. */
function log_error(Throwable $e, string $publicMessage = 'Une erreur est survenue. Veuillez réessayer.'): string
{
    error_log('[SuiviLTP] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return $publicMessage;
}
