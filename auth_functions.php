<?php
/**
 * Authentification et rôles de SuiviLTP.
 *
 * Utilisation en tête d'une page d'administration :
 *     require_once 'auth_functions.php';
 *     require_page_access();          // contrôle connexion + droits de la page courante
 *
 * Pour modifier qui a accès à quoi, il suffit de changer ROLE_PAGES ci-dessous :
 * le menu latéral et les contrôles d'accès se mettent à jour automatiquement.
 */
require_once __DIR__ . '/config.php';

/** Rôles disponibles pour le personnel administratif. */
const ROLES = [
    'admin'      => ['name' => 'Administrateur', 'description' => 'Accès complet, gestion des comptes utilisateurs'],
    'censeur'    => ['name' => 'Censeur',        'description' => 'Élèves, codes parents et bulletins'],
    'comptable'  => ['name' => 'Comptable',      'description' => 'Paiements et frais scolaires'],
    'secretaire' => ['name' => 'Secrétaire',     'description' => 'Élèves, codes parents et professeurs'],
];

/**
 * Pages d'administration => rôles autorisés (le rôle "admin" a toujours accès à tout).
 * Une page absente de cette liste est refusée par require_page_access().
 */
const ROLE_PAGES = [
    'admin.php'                   => ['censeur', 'comptable', 'secretaire'],
    'parametres.php'              => ['censeur', 'comptable', 'secretaire'],
    'eleves.php'                  => ['censeur', 'secretaire'],
    'eleve_details.php'           => ['censeur', 'secretaire'],
    'modifier_eleve.php'          => ['censeur', 'secretaire'],
    'add_eleve.php'               => ['censeur', 'secretaire'],
    'add_code_secret.php'         => ['censeur', 'secretaire'],
    'bulletins.php'               => ['censeur'],
    'paiements.php'               => ['comptable'],
    'paiement_details.php'        => ['comptable'],
    'ajouter_paiement.php'        => ['comptable'],
    'reinitialiser_paiements.php' => ['comptable'],
    'gestion_professeur.php'      => ['secretaire'],
    'reset_password.php'          => ['secretaire'],
    'utilisateurs.php'            => [],          // administrateur uniquement
];

/** Entrées du menu latéral (affichées seulement si l'utilisateur a accès à la page). */
const ADMIN_MENU = [
    ['href' => 'admin.php',              'label' => 'Tableau de bord', 'icon' => 'home'],
    ['href' => 'eleves.php',             'label' => 'Élèves',          'icon' => 'users'],
    ['href' => 'bulletins.php',          'label' => 'Bulletins',       'icon' => 'file'],
    ['href' => 'paiements.php',          'label' => 'Paiements',       'icon' => 'wallet'],
    ['href' => 'gestion_professeur.php', 'label' => 'Professeurs',     'icon' => 'teacher'],
    ['href' => 'utilisateurs.php',       'label' => 'Utilisateurs',    'icon' => 'shield'],
    ['href' => 'parametres.php',         'label' => 'Paramètres',      'icon' => 'settings'],
];

// ---------------------------------------------------------------------------
// Personnel administratif
// ---------------------------------------------------------------------------
function isLoggedIn(): bool
{
    return isset($_SESSION['admin_id'], $_SESSION['admin_role']);
}

function currentRole(): ?string
{
    return $_SESSION['admin_role'] ?? null;
}

function hasRole(string $role): bool
{
    return isLoggedIn() && $_SESSION['admin_role'] === $role;
}

function canAccessPage(string $page): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    if (currentRole() === 'admin') {
        return true;
    }
    return in_array(currentRole(), ROLE_PAGES[$page] ?? [], true);
}

function getCurrentRoleInfo(): ?array
{
    return ROLES[currentRole()] ?? null;
}

/** Ouvre la session d'un membre du personnel après vérification du mot de passe. */
function login_admin(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id']       = (int)$user['id'];
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_role']     = $user['role'];
}

/**
 * Vérifie que l'utilisateur est connecté (compte toujours existant en base)
 * et qu'il a le droit d'ouvrir la page courante.
 */
function require_page_access(?string $page = null): void
{
    global $pdo;
    $page = $page ?? basename($_SERVER['SCRIPT_NAME']);
    $isAjax = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_SERVER['HTTP_X_CSRF_TOKEN']);

    if (isLoggedIn()) {
        // Le compte peut avoir été supprimé ou son rôle modifié depuis la connexion.
        $stmt = $pdo->prepare('SELECT id, username, role FROM admin WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $user = $stmt->fetch();
        if ($user && isset(ROLES[$user['role']])) {
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];
        } else {
            unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role']);
        }
    }

    if (!isLoggedIn()) {
        if ($isAjax) {
            deny_json('Votre session a expiré, reconnectez-vous.', 401);
        }
        redirect('loginadmin.php');
    }
    if (!canAccessPage($page)) {
        if ($isAjax) {
            deny_json("Vous n'avez pas les droits pour cette action.", 403);
        }
        redirect('unauthorized.php');
    }
}

function deny_json(string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ---------------------------------------------------------------------------
// Professeurs
// ---------------------------------------------------------------------------
function require_professeur(): array
{
    global $pdo;
    if (!empty($_SESSION['professeur_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM professeurs WHERE id = ?');
        $stmt->execute([$_SESSION['professeur_id']]);
        $prof = $stmt->fetch();
        if ($prof) {
            return $prof;
        }
        unset($_SESSION['professeur_id'], $_SESSION['professeur_nom'], $_SESSION['professeur_prenom']);
    }
    redirect('loginprof.php');
}

// ---------------------------------------------------------------------------
// Parents
// ---------------------------------------------------------------------------
function require_parent(): array
{
    global $pdo;
    if (!empty($_SESSION['eleve_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ?');
        $stmt->execute([$_SESSION['eleve_id']]);
        $eleve = $stmt->fetch();
        if ($eleve) {
            return $eleve;
        }
        unset($_SESSION['eleve_id'], $_SESSION['eleve_nom'], $_SESSION['eleve_prenom']);
    }
    redirect('login_parent.php');
}
