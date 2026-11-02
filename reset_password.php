<?php
/**
 * Point d'accès JSON : réinitialise le mot de passe d'un professeur.
 * POST id=<id professeur>, avec l'en-tête X-CSRF-Token (App.post).
 * Réponse : {"success": true, "password": "..."} — le mot de passe n'est affiché qu'une fois.
 */
require_once 'auth_functions.php';
require_page_access();

header('Content-Type: application/json; charset=utf-8');

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

try {
    $password = random_password();
    $stmt = $pdo->prepare('UPDATE professeurs SET mot_de_passe = ? WHERE id = ?');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Professeur introuvable.']);
        exit;
    }
    echo json_encode(['success' => true, 'password' => $password]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => log_error($e)]);
}
