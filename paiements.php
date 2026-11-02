<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

/** Montant annuel dû (à déplacer plus tard vers la table frais_scolaires). */
function montant_total(array $eleve): int
{
    if ($eleve['filiere'] === 'COM') {
        return 53000;
    }
    return $eleve['sexe'] === 'M' ? 120000 : 80000;
}

// Filtres
$filiere = $_GET['filiere'] ?? '';
$classe  = $_GET['classe'] ?? '';
$search  = trim($_GET['search'] ?? '');

$where = ' WHERE 1=1';
$params = [];
if ($filiere !== '') { $where .= ' AND e.filiere = ?'; $params[] = $filiere; }
if ($classe !== '')  { $where .= ' AND e.classe = ?';  $params[] = $classe; }
if ($search !== '')  {
    $where .= ' AND (e.nom LIKE ? OR e.prenoms LIKE ? OR e.id = ?)';
    array_push($params, "%$search%", "%$search%", ctype_digit($search) ? (int)$search : 0);
}

// Pagination
$limit = 15;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM eleves e' . $where);
$stmt->execute($params);
$totalFiltered = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);

$stmt = $pdo->prepare(
    'SELECT e.*, COALESCE(SUM(p.montant), 0) AS paye
     FROM eleves e LEFT JOIN paiements p ON p.eleve_id = e.id'
    . $where .
    ' GROUP BY e.id ORDER BY e.nom, e.prenoms LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit)
);
$stmt->execute($params);
$eleves = $stmt->fetchAll();

$filieres = $pdo->query('SELECT DISTINCT filiere FROM eleves ORDER BY filiere')->fetchAll(PDO::FETCH_COLUMN);
$classes  = $pdo->query("SELECT DISTINCT classe FROM eleves ORDER BY FIELD(classe, '2nde', '1ere', 'Tle')")->fetchAll(PDO::FETCH_COLUMN);

// Indicateurs globaux
$totalEncaisse = (float)$pdo->query('SELECT COALESCE(SUM(montant), 0) FROM paiements')->fetchColumn();
$totalDu = 0;
$soldes = 0;
$rows = $pdo->query('SELECT e.filiere, e.sexe, COALESCE(SUM(p.montant), 0) AS paye FROM eleves e LEFT JOIN paiements p ON p.eleve_id = e.id GROUP BY e.id')->fetchAll();
foreach ($rows as $r) {
    $du = montant_total($r);
    $totalDu += $du;
    if ($r['paye'] >= $du) {
        $soldes++;
    }
}

layout_start([
    'title'    => 'Paiements',
    'subtitle' => 'Suivi des frais de scolarité par élève',
]);
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon success"><?= icon('wallet', 22) ?></div>
        <div><div class="stat-label">Total encaissé</div><div class="stat-value"><?= e(fcfa($totalEncaisse)) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><?= icon('alert', 22) ?></div>
        <div><div class="stat-label">Reste à encaisser</div><div class="stat-value"><?= e(fcfa(max(0, $totalDu - $totalEncaisse))) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><?= icon('check', 22) ?></div>
        <div><div class="stat-label">Élèves soldés</div><div class="stat-value"><?= $soldes ?> / <?= count($rows) ?></div></div>
    </div>
</div>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('users') ?> Situation des élèves</h2>
            <p class="card-subtitle"><?= $totalFiltered ?> élève(s) trouvé(s)</p>
        </div>
    </div>

    <form method="GET" class="filters">
        <div class="input-group grow">
            <?= icon('search', 18) ?>
            <input type="search" name="search" placeholder="Nom, prénom ou ID…" value="<?= e($search) ?>">
        </div>
        <select name="classe" data-autosubmit>
            <option value="">Toutes les classes</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= e($c) ?>" <?= $classe === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="filiere" data-autosubmit>
            <option value="">Toutes les filières</option>
            <?php foreach ($filieres as $f): ?>
                <option value="<?= e($f) ?>" <?= $filiere === $f ? 'selected' : '' ?>><?= e($f) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><?= icon('search', 16) ?> Rechercher</button>
        <?php if ($search !== '' || $classe !== '' || $filiere !== ''): ?>
            <a href="paiements.php" class="btn btn-secondary">Réinitialiser</a>
        <?php endif; ?>
    </form>

    <?php if (!$eleves): ?>
        <div class="empty-state"><?= icon('search', 40) ?><p>Aucun élève ne correspond à votre recherche.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th><th>Nom</th><th>Prénoms</th><th>Classe</th><th>Filière</th>
                    <th class="num">Payé</th><th class="num">Reste</th><th>Statut</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($eleves as $eleve):
                $du = montant_total($eleve);
                $reste = max(0, $du - (float)$eleve['paye']);
            ?>
                <tr>
                    <td><?= (int)$eleve['id'] ?></td>
                    <td class="fw-bold"><?= e(mb_strtoupper($eleve['nom'], 'UTF-8')) ?></td>
                    <td><?= e($eleve['prenoms']) ?></td>
                    <td><?= e($eleve['classe']) ?></td>
                    <td><span class="badge badge-primary"><?= e($eleve['filiere']) ?></span></td>
                    <td class="num"><?= e(fcfa($eleve['paye'])) ?></td>
                    <td class="num"><?= e(fcfa($reste)) ?></td>
                    <td>
                        <?php if ($reste <= 0): ?>
                            <span class="badge badge-success">Soldé</span>
                        <?php elseif ($eleve['paye'] > 0): ?>
                            <span class="badge badge-warning">En cours</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Aucun paiement</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a href="paiement_details.php?id=<?= (int)$eleve['id'] ?>" class="btn btn-sm btn-outline"><?= icon('eye', 16) ?> Détails</a>
                        <a href="ajouter_paiement.php?id=<?= (int)$eleve['id'] ?>" class="btn btn-sm btn-primary"><?= icon('plus', 16) ?> Paiement</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($page, $totalPages) ?>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
