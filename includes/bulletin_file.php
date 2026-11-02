<?php
/**
 * Envoi sécurisé d'un bulletin PDF (consultation ou téléchargement).
 * Accès autorisé :
 *   - au personnel ayant accès aux élèves ou aux bulletins ;
 *   - au parent connecté, uniquement pour son enfant et si le bulletin est publié.
 */
require_once __DIR__ . '/../auth_functions.php';

const BULLETINS_DIR = __DIR__ . '/../uploads/bulletins';

/** Chemin du PDF d'un élève pour un bulletin (ou null s'il n'existe pas). */
function bulletin_pdf_path(int $bulletinId, int $eleveId): ?string
{
    $candidates = [
        BULLETINS_DIR . "/$bulletinId/$eleveId.pdf",
        BULLETINS_DIR . "/$bulletinId/$eleveId/$eleveId.pdf", // ancien format de rangement
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function bulletin_fail(int $status, string $message): void
{
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Bulletin</title>'
       . '<link rel="stylesheet" href="assets/css/app.css"></head><body class="auth-page">'
       . '<div class="auth-card"><h1>Bulletin indisponible</h1><p class="text-muted">' . e($message) . '</p>'
       . '<a class="btn btn-primary btn-block" href="javascript:history.back()">Retour</a></div></body></html>';
    exit;
}

function serve_bulletin(bool $download): void
{
    global $pdo;

    $bulletinId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $eleveId    = filter_input(INPUT_GET, 'eleve_id', FILTER_VALIDATE_INT);
    if (!$bulletinId || !$eleveId) {
        bulletin_fail(400, 'Lien de bulletin invalide.');
    }

    $isStaff  = canAccessPage('eleve_details.php') || canAccessPage('bulletins.php');
    $isParent = !empty($_SESSION['eleve_id']) && (int)$_SESSION['eleve_id'] === $eleveId;
    if (!$isStaff && !$isParent) {
        bulletin_fail(403, "Vous n'avez pas accès à ce bulletin. Connectez-vous d'abord.");
    }

    $stmt = $pdo->prepare('SELECT * FROM bulletins WHERE id = ?');
    $stmt->execute([$bulletinId]);
    $bulletin = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ?');
    $stmt->execute([$eleveId]);
    $eleve = $stmt->fetch();

    if (!$bulletin || !$eleve
        || (int)$bulletin['etablissement_id'] !== (int)$eleve['etablissement_id']
        || $bulletin['classe'] !== $eleve['classe']
        || $bulletin['filiere'] !== $eleve['filiere']) {
        bulletin_fail(404, 'Ce bulletin est introuvable.');
    }
    // Le personnel ne voit que les bulletins de l'établissement sur lequel il travaille.
    if ($isStaff && !$isParent && (int)$eleve['etablissement_id'] !== etab_id()) {
        bulletin_fail(404, 'Ce bulletin est introuvable dans cet établissement.');
    }
    if (!$isStaff && $bulletin['status'] !== 'oui') {
        bulletin_fail(403, "Ce bulletin n'est pas encore disponible.");
    }

    $path = bulletin_pdf_path($bulletinId, $eleveId);
    if ($path === null) {
        bulletin_fail(404, "Le fichier PDF de ce bulletin n'a pas encore été déposé.");
    }

    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $eleve['nom'] . '_' . $eleve['prenoms']);
    $filename = 'bulletin_' . trim($name, '_') . '_semestre' . $bulletin['semestre'] . '.pdf';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
