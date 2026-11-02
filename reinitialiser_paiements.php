<?php
// Supprime tous les paiements d'un élève (POST + jeton CSRF uniquement).
require_once 'auth_functions.php';
require_page_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('paiements.php');
}

$eleveId = (int)($_POST['eleve_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM eleves WHERE id = ?');
$stmt->execute([$eleveId]);
if (!$stmt->fetch()) {
    flash('error', 'Élève introuvable.');
    redirect('paiements.php');
}

try {
    $stmt = $pdo->prepare('DELETE FROM paiements WHERE eleve_id = ?');
    $stmt->execute([$eleveId]);
    flash('success', $stmt->rowCount() . ' paiement(s) supprimé(s).');
} catch (Throwable $e) {
    flash('error', log_error($e));
}
redirect('paiement_details.php?id=' . $eleveId);
