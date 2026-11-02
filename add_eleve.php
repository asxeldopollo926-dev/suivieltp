<?php
/**
 * Point d'entrée AJAX : ajout d'un élève dans l'établissement courant (réponse JSON).
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
    'nom'              => trim($_POST['nom'] ?? ''),
    'prenoms'          => trim($_POST['prenoms'] ?? ''),
    'sexe'             => $_POST['sexe'] ?? '',
    'date_naissance'   => $_POST['date_naissance'] ?? '',
    'classe'           => $_POST['classe'] ?? '',
    'filiere'          => $_POST['filiere'] ?? '',
    'parent_nom'       => trim($_POST['parent_nom'] ?? ''),
    'parent_telephone' => trim($_POST['parent_telephone'] ?? ''),
    'parent_email'     => trim($_POST['parent_email'] ?? ''),
];
$errors = [];
if ($d['nom'] === '' || mb_strlen($d['nom']) > 50)          { $errors[] = 'Nom obligatoire (50 caractères max).'; }
if ($d['prenoms'] === '' || mb_strlen($d['prenoms']) > 100) { $errors[] = 'Prénoms obligatoires (100 caractères max).'; }
if (!in_array($d['sexe'], ['M', 'F'], true))                { $errors[] = 'Sexe invalide.'; }
if (!in_array($d['classe'], classe_codes(), true)) {
    $errors[] = 'Classe invalide.';
} elseif (!valid_classe_serie($d['classe'], $d['filiere'])) {
    $errors[] = classe_a_serie($d['classe'])
        ? 'Veuillez choisir une ' . mb_strtolower(serie_label(), 'UTF-8') . ' valide pour la classe ' . $d['classe'] . '.'
        : 'La classe ' . $d['classe'] . ' n\'a pas de ' . mb_strtolower(serie_label(), 'UTF-8') . '.';
}
$dt = DateTime::createFromFormat('Y-m-d', $d['date_naissance']);
if (!$dt || $dt->format('Y-m-d') !== $d['date_naissance'] || $dt > new DateTime()) {
    $errors[] = 'Date de naissance invalide.';
}
if (mb_strlen($d['parent_nom']) > 150) { $errors[] = 'Nom du parent trop long (150 caractères max).'; }
if ($d['parent_telephone'] !== '' && !preg_match('/^[0-9 +()\-]{6,30}$/', $d['parent_telephone'])) {
    $errors[] = 'Téléphone du parent invalide.';
}
if ($d['parent_email'] !== '' && (!filter_var($d['parent_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['parent_email']) > 150)) {
    $errors[] = 'E-mail du parent invalide.';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

try {
    $code = unique_secret_code($pdo);
    $pdo->prepare('INSERT INTO eleves (etablissement_id, nom, prenoms, sexe, date_naissance, filiere, classe, code_secret, parent_nom, parent_telephone, parent_email)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([etab_id(), $d['nom'], $d['prenoms'], $d['sexe'], $d['date_naissance'], $d['filiere'], $d['classe'], $code,
                   $d['parent_nom'], $d['parent_telephone'], $d['parent_email']]);
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
