<?php
/**
 * Point d'entrée AJAX : ajout d'un élève (réponse JSON).
 * Appel : App.post('add_eleve.php', formData) — le jeton CSRF est envoyé en en-tête.
 */
require_once 'auth_functions.php';
require_page_access();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$d = [
    'nom'            => trim($_POST['nom'] ?? ''),
    'prenoms'        => trim($_POST['prenoms'] ?? ''),
    'sexe'           => $_POST['sexe'] ?? '',
    'date_naissance' => $_POST['date_naissance'] ?? '',
    'filiere'        => $_POST['filiere'] ?? '',
    'classe'         => $_POST['classe'] ?? '',
];
$errors = [];
if ($d['nom'] === '' || mb_strlen($d['nom']) > 50)          { $errors[] = 'Nom obligatoire (50 caractères max).'; }
if ($d['prenoms'] === '' || mb_strlen($d['prenoms']) > 100) { $errors[] = 'Prénoms obligatoires (100 caractères max).'; }
if (!in_array($d['sexe'], ['M', 'F'], true))                { $errors[] = 'Sexe invalide.'; }
if (!in_array($d['filiere'], ['DWM', 'EA', 'COM', 'IMI', 'F3', 'PM'], true)) { $errors[] = 'Filière invalide.'; }
if (!in_array($d['classe'], ['2nde', '1ere', 'Tle'], true)) { $errors[] = 'Classe invalide.'; }
$dt = DateTime::createFromFormat('Y-m-d', $d['date_naissance']);
if (!$dt || $dt->format('Y-m-d') !== $d['date_naissance'] || $dt > new DateTime()) {
    $errors[] = 'Date de naissance invalide.';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

try {
    $code = unique_secret_code($pdo);
    $pdo->prepare('INSERT INTO eleves (nom, prenoms, sexe, date_naissance, filiere, classe, code_secret) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$d['nom'], $d['prenoms'], $d['sexe'], $d['date_naissance'], $d['filiere'], $d['classe'], $code]);
    echo json_encode([
        'success'     => true,
        'message'     => 'Élève ajouté avec succès',
        'id'          => (int)$pdo->lastInsertId(),
        'code_secret' => $code,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => log_error($e)]);
}
