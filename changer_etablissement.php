<?php
// Bascule de l'établissement de travail (comptes non rattachés à un établissement, ex. administrateur).
require_once 'auth_functions.php';
require_page_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !can_switch_etab()) {
    redirect('admin.php');
}
$etab = etablissement((int)($_POST['etab_id'] ?? 0));
if ($etab) {
    $_SESSION['etab_id'] = (int)$etab['id'];
    flash('success', 'Vous travaillez maintenant sur : ' . etab_type_name($etab['type']) . ' — ' . $etab['nom'] . '.');
} else {
    flash('error', 'Établissement introuvable.');
}
redirect('admin.php');
