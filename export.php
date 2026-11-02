<?php
/**
 * Exports Excel (.xlsx) de l'établissement courant.
 *   export.php?type=eleves|codes|paiements|historique [&classe=…&filiere=…&search=…]
 * Les filtres sont ceux des pages de liste (mêmes noms de paramètres).
 */
require_once 'auth_functions.php';
require_once 'includes/frais.php';
require_once 'includes/xlsx.php';
require_page_access();

const EXPORTS = [
    'eleves'     => ['page' => 'eleves.php',    'titre' => 'Élèves'],
    'codes'      => ['page' => 'eleves.php',    'titre' => 'Codes parents'],
    'paiements'  => ['page' => 'paiements.php', 'titre' => 'Situation des paiements'],
    'historique' => ['page' => 'paiements.php', 'titre' => 'Historique des paiements'],
];

$type = $_GET['type'] ?? '';
if (!isset(EXPORTS[$type])) {
    flash('error', "Type d'export inconnu.");
    redirect('admin.php');
}
if (!canAccessPage(EXPORTS[$type]['page'])) {
    redirect('unauthorized.php');
}

// Filtres communs (même logique que les pages de liste)
$classe  = $_GET['classe'] ?? '';
$filiere = $_GET['filiere'] ?? '';
$search  = trim($_GET['search'] ?? '');
$where = ' WHERE e.etablissement_id = ?';
$params = [etab_id()];
if (in_array($classe, classe_codes(), true)) { $where .= ' AND e.classe = ?'; $params[] = $classe; }
if (in_array($filiere, serie_codes(), true)) { $where .= ' AND e.filiere = ?'; $params[] = $filiere; }
if ($search !== '') {
    $where .= ' AND (e.nom LIKE ? OR e.prenoms LIKE ? OR e.id = ?)';
    array_push($params, "%$search%", "%$search%", ctype_digit($search) ? (int)$search : 0);
}
$order = ' ORDER BY ' . sql_order_classe('e.classe') . ', e.filiere, e.nom, e.prenoms';
$serieLabel = serie_label();

switch ($type) {
    case 'eleves':
        $stmt = $pdo->prepare('SELECT e.* FROM eleves e' . $where . $order);
        $stmt->execute($params);
        $headers = ['ID', 'Nom', 'Prénoms', 'Sexe', 'Date de naissance', 'Classe', $serieLabel, 'Parent / tuteur', 'Téléphone parent', 'E-mail parent'];
        $rows = [];
        foreach ($stmt as $e) {
            $rows[] = [(int)$e['id'], mb_strtoupper($e['nom'], 'UTF-8'), $e['prenoms'], $e['sexe'], date_fr_simple($e['date_naissance']),
                       $e['classe'], $e['filiere'], $e['parent_nom'], $e['parent_telephone'], $e['parent_email']];
        }
        break;

    case 'codes':
        $stmt = $pdo->prepare('SELECT e.* FROM eleves e' . $where . $order);
        $stmt->execute($params);
        $headers = ['ID', 'Nom', 'Prénoms', 'Classe', $serieLabel, 'Code secret parent'];
        $rows = [];
        foreach ($stmt as $e) {
            $rows[] = [(int)$e['id'], mb_strtoupper($e['nom'], 'UTF-8'), $e['prenoms'], $e['classe'], $e['filiere'], $e['code_secret']];
        }
        break;

    case 'paiements':
        $stmt = $pdo->prepare('SELECT e.*, COALESCE(SUM(p.montant), 0) AS paye FROM eleves e LEFT JOIN paiements p ON p.eleve_id = e.id'
                              . $where . ' GROUP BY e.id' . $order);
        $stmt->execute($params);
        $headers = ['ID', 'Nom', 'Prénoms', 'Sexe', 'Classe', $serieLabel, 'Frais annuels (FCFA)', 'Payé (FCFA)', 'Reste (FCFA)', 'Statut'];
        $rows = [];
        foreach ($stmt as $e) {
            $du = montant_total($e);
            $paye = (int)round((float)$e['paye']);
            $reste = max(0, $du - $paye);
            $statut = $du > 0 && $reste === 0 ? 'Soldé' : ($paye > 0 ? 'En cours' : 'Aucun paiement');
            $rows[] = [(int)$e['id'], mb_strtoupper($e['nom'], 'UTF-8'), $e['prenoms'], $e['sexe'], $e['classe'], $e['filiere'], $du, $paye, $reste, $statut];
        }
        break;

    case 'historique':
        $stmt = $pdo->prepare('SELECT p.*, e.nom, e.prenoms, e.classe, e.filiere FROM paiements p JOIN eleves e ON e.id = p.eleve_id'
                              . $where . ' ORDER BY p.date_paiement DESC, p.id DESC');
        $stmt->execute($params);
        $headers = ['Date', 'N° de reçu', 'ID élève', 'Nom', 'Prénoms', 'Classe', $serieLabel, 'Montant (FCFA)', 'Mode de paiement'];
        $rows = [];
        foreach ($stmt as $p) {
            $rows[] = [date_fr_simple($p['date_paiement']),
                       'REC-' . date('Y', strtotime($p['date_paiement'])) . '-' . str_pad((string)$p['id'], 6, '0', STR_PAD_LEFT),
                       (int)$p['eleve_id'], mb_strtoupper($p['nom'], 'UTF-8'), $p['prenoms'], $p['classe'], $p['filiere'],
                       (int)round((float)$p['montant']), $p['mode_paiement']];
        }
        break;
}

$etab = current_etab();
$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $etab['nom'] ?? 'etab') ?: 'etab')), '-');
xlsx_download($type . '_' . substr($slug, 0, 40) . '_' . date('Y-m-d') . '.xlsx', EXPORTS[$type]['titre'], $headers, $rows);

function date_fr_simple(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '';
}
