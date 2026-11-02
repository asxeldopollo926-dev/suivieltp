<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

$etabId = etab_id();

// Filtres (limités aux classes / séries de l'établissement)
$filiere = in_array($_GET['filiere'] ?? '', serie_codes(), true) ? $_GET['filiere'] : '';
$classe  = in_array($_GET['classe'] ?? '', classe_codes(), true) ? $_GET['classe'] : '';
$search  = trim($_GET['search'] ?? '');

$where = ' WHERE etablissement_id = ?';
$params = [$etabId];
if ($filiere !== '') { $where .= ' AND filiere = ?'; $params[] = $filiere; }
if ($classe !== '')  { $where .= ' AND classe = ?';  $params[] = $classe; }
if ($search !== '')  {
    $where .= ' AND (nom LIKE ? OR prenoms LIKE ? OR id = ?)';
    array_push($params, "%$search%", "%$search%", ctype_digit($search) ? (int)$search : 0);
}

// Pagination
$limit = 10;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM eleves' . $where);
$stmt->execute($params);
$totalFiltered = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);

$stmt = $pdo->prepare('SELECT * FROM eleves' . $where . ' ORDER BY nom, prenoms LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
$stmt->execute($params);
$eleves = $stmt->fetchAll();

// Indicateurs de l'établissement
$q = function (string $sql) use ($pdo, $etabId) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$etabId]);
    return $stmt->fetchColumn();
};
$stats = [
    'eleves'    => (int)$q('SELECT COUNT(*) FROM eleves WHERE etablissement_id = ?'),
    'profs'     => (int)$q('SELECT COUNT(*) FROM professeurs WHERE etablissement_id = ?'),
    'bulletins' => (int)$q("SELECT COUNT(*) FROM bulletins WHERE status = 'oui' AND etablissement_id = ?"),
    'encaisse'  => (float)$q('SELECT COALESCE(SUM(p.montant), 0) FROM paiements p JOIN eleves e ON e.id = p.eleve_id WHERE e.etablissement_id = ?'),
    'absences'  => (int)$q("SELECT COUNT(*) FROM absences a JOIN eleves e ON e.id = a.eleve_id
                            WHERE e.etablissement_id = ? AND a.statut IN ('Absent','Absente') AND a.date_absence >= (CURDATE() - INTERVAL 30 DAY)"),
];
$stmt = $pdo->prepare('SELECT * FROM professeurs WHERE etablissement_id = ? ORDER BY nom');
$stmt->execute([$etabId]);
$professeurs = $stmt->fetchAll();

$canSeeStudents = canAccessPage('eleve_details.php');
$canSeePayments = canAccessPage('paiements.php');

layout_start([
    'title'    => 'Tableau de bord',
    'subtitle' => 'Bonjour ' . ($_SESSION['admin_username'] ?? '') . ', voici la situation de : ' . (current_etab()['nom'] ?? '') . '.',
]);
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><?= icon('users', 22) ?></div>
        <div><div class="stat-label">Élèves inscrits</div><div class="stat-value"><?= $stats['eleves'] ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><?= icon('teacher', 22) ?></div>
        <div><div class="stat-label">Professeurs</div><div class="stat-value"><?= $stats['profs'] ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><?= icon('file', 22) ?></div>
        <div><div class="stat-label">Bulletins publiés</div><div class="stat-value"><?= $stats['bulletins'] ?></div></div>
    </div>
    <?php if ($canSeePayments): ?>
    <div class="stat-card">
        <div class="stat-icon warning"><?= icon('wallet', 22) ?></div>
        <div><div class="stat-label">Total encaissé</div><div class="stat-value"><?= e(fcfa($stats['encaisse'])) ?></div></div>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-icon danger"><?= icon('calendar', 22) ?></div>
        <div><div class="stat-label">Absences (30 jours)</div><div class="stat-value"><?= $stats['absences'] ?></div></div>
    </div>
</div>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('users') ?> Liste des élèves</h2>
            <p class="card-subtitle"><?= $totalFiltered ?> élève(s) trouvé(s)</p>
        </div>
    </div>

    <form method="GET" class="filters">
        <div class="input-group grow">
            <?= icon('search', 18) ?>
            <input type="search" name="search" placeholder="Nom, prénom ou ID…" value="<?= e($search) ?>">
        </div>
        <?= classe_serie_selects($classe, $filiere, ['filter' => true]) ?>
        <button type="submit" class="btn btn-primary"><?= icon('search', 16) ?> Rechercher</button>
        <?php if ($search !== '' || $classe !== '' || $filiere !== ''): ?>
            <a href="admin.php" class="btn btn-secondary">Réinitialiser</a>
        <?php endif; ?>
    </form>

    <?php if (!$eleves): ?>
        <div class="empty-state"><?= icon('search', 40) ?><p>Aucun élève ne correspond à votre recherche.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th><th>Nom</th><th>Prénoms</th><th>Sexe</th><th>Naissance</th><th>Classe</th><th><?= e(serie_label()) ?></th>
                    <?php if ($canSeeStudents || $canSeePayments): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($eleves as $eleve): ?>
                <tr>
                    <td><?= (int)$eleve['id'] ?></td>
                    <td class="fw-bold"><?= e(mb_strtoupper($eleve['nom'], 'UTF-8')) ?></td>
                    <td><?= e($eleve['prenoms']) ?></td>
                    <td><?= e($eleve['sexe']) ?></td>
                    <td><?= e(date_fr($eleve['date_naissance'])) ?></td>
                    <td><?= e($eleve['classe']) ?></td>
                    <td><?= $eleve['filiere'] !== '' ? '<span class="badge badge-primary">' . e($eleve['filiere']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                    <?php if ($canSeeStudents || $canSeePayments): ?>
                    <td class="actions">
                        <?php if ($canSeeStudents): ?>
                            <a href="eleve_details.php?id=<?= (int)$eleve['id'] ?>" class="btn btn-sm btn-outline"><?= icon('eye', 16) ?> Fiche</a>
                        <?php endif; ?>
                        <?php if ($canSeePayments): ?>
                            <a href="paiement_details.php?id=<?= (int)$eleve['id'] ?>" class="btn btn-sm btn-outline"><?= icon('wallet', 16) ?> Paiements</a>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($page, $totalPages) ?>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= icon('teacher') ?> Professeurs</h2>
        <?php if (canAccessPage('gestion_professeur.php')): ?>
            <a href="gestion_professeur.php" class="btn btn-sm btn-outline">Gérer les professeurs</a>
        <?php endif; ?>
    </div>
    <?php if (!$professeurs): ?>
        <div class="empty-state"><p>Aucun professeur enregistré.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nom</th><th>Prénoms</th><th><?= e(serie_label()) ?></th><th>Email</th><th>Téléphone</th></tr></thead>
            <tbody>
            <?php foreach ($professeurs as $prof): ?>
                <tr>
                    <td class="fw-bold"><?= e(mb_strtoupper($prof['nom'], 'UTF-8')) ?></td>
                    <td><?= e($prof['prenoms']) ?></td>
                    <td><?= $prof['filiere'] !== '' ? '<span class="badge badge-primary">' . e($prof['filiere']) . '</span>' : '<span class="text-muted">Toutes</span>' ?></td>
                    <td><?= e($prof['email']) ?></td>
                    <td><?= e($prof['telephone']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
