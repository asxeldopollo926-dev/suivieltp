<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

const CLASSES  = ['2nde', '1ere', 'Tle'];
const FILIERES = ['DWM', 'EA', 'COM', 'IMI', 'F3', 'PM'];

/** Valide les champs d'un élève envoyés en POST. Retourne [données, erreurs]. */
function eleve_from_post(): array
{
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
    if (!in_array($d['filiere'], FILIERES, true))               { $errors[] = 'Filière invalide.'; }
    if (!in_array($d['classe'], CLASSES, true))                 { $errors[] = 'Classe invalide.'; }
    $dt = DateTime::createFromFormat('Y-m-d', $d['date_naissance']);
    if (!$dt || $dt->format('Y-m-d') !== $d['date_naissance'] || $dt > new DateTime()) {
        $errors[] = 'Date de naissance invalide.';
    }
    return [$d, $errors];
}

// Ajout d'un élève
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student') {
    [$d, $errors] = eleve_from_post();
    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect('eleves.php');
    }
    try {
        $code = unique_secret_code($pdo);
        $pdo->prepare('INSERT INTO eleves (nom, prenoms, sexe, date_naissance, filiere, classe, code_secret) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['nom'], $d['prenoms'], $d['sexe'], $d['date_naissance'], $d['filiere'], $d['classe'], $code]);
        $newId = (int)$pdo->lastInsertId();
        flash('success', "Élève ajouté (ID $newId). Code secret parent : $code");
        redirect('eleve_details.php?id=' . $newId);
    } catch (Throwable $e) {
        flash('error', log_error($e));
        redirect('eleves.php');
    }
}

// Répartition par filière et classe
$rows = $pdo->query('SELECT filiere, classe, sexe, COUNT(*) AS total FROM eleves GROUP BY filiere, classe, sexe')->fetchAll();
$matrix = [];
$totalM = $totalF = 0;
foreach ($rows as $r) {
    $matrix[$r['filiere']][$r['classe']] = ($matrix[$r['filiere']][$r['classe']] ?? 0) + (int)$r['total'];
    if ($r['sexe'] === 'M') { $totalM += (int)$r['total']; } else { $totalF += (int)$r['total']; }
}
$total = $totalM + $totalF;
$maxFiliere = 1;
foreach (FILIERES as $f) {
    $maxFiliere = max($maxFiliere, array_sum($matrix[$f] ?? []));
}
$sansCode = (int)$pdo->query("SELECT COUNT(*) FROM eleves WHERE code_secret IS NULL OR code_secret = ''")->fetchColumn();

// Liste filtrée
$filiere = $_GET['filiere'] ?? '';
$classe  = $_GET['classe'] ?? '';
$search  = trim($_GET['search'] ?? '');
$where = ' WHERE 1=1';
$params = [];
if (in_array($filiere, FILIERES, true)) { $where .= ' AND filiere = ?'; $params[] = $filiere; } else { $filiere = ''; }
if (in_array($classe, CLASSES, true))   { $where .= ' AND classe = ?';  $params[] = $classe; } else { $classe = ''; }
if ($search !== '') {
    $where .= ' AND (nom LIKE ? OR prenoms LIKE ? OR id = ?)';
    array_push($params, "%$search%", "%$search%", ctype_digit($search) ? (int)$search : 0);
}
$limit = 15;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM eleves' . $where);
$stmt->execute($params);
$totalFiltered = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$stmt = $pdo->prepare('SELECT * FROM eleves' . $where . ' ORDER BY nom, prenoms LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
$stmt->execute($params);
$eleves = $stmt->fetchAll();

layout_start([
    'title'    => 'Élèves',
    'subtitle' => 'Effectifs, inscriptions et codes d\'accès des parents',
    'actions'  => '<button type="button" class="btn btn-primary" data-modal-open="addStudentModal">' . icon('plus', 16) . ' Ajouter un élève</button>'
                . '<a href="add_code_secret.php" class="btn btn-outline">' . icon('key', 16) . ' Codes parents</a>',
    'head'     => '<style>.bar{display:flex;align-items:center;gap:10px}.bar .progress{flex:1}.bar .progress>span{background:var(--primary)}</style>',
]);
?>
<?php if ($sansCode > 0): ?>
    <div class="alert alert-warning"><?= icon('alert', 18) ?>
        <div><?= $sansCode ?> élève(s) n'ont pas de code secret parent. <a href="add_code_secret.php">Générer les codes</a></div>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><?= icon('users', 22) ?></div>
        <div><div class="stat-label">Total élèves</div><div class="stat-value"><?= $total ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><?= icon('user', 22) ?></div>
        <div><div class="stat-label">Garçons</div><div class="stat-value"><?= $totalM ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><?= icon('user', 22) ?></div>
        <div><div class="stat-label">Filles</div><div class="stat-value"><?= $totalF ?></div></div>
    </div>
</div>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= icon('chart') ?> Répartition par filière et par classe</h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Filière</th><?php foreach (CLASSES as $c): ?><th class="num"><?= e($c) ?></th><?php endforeach; ?><th class="num">Total</th><th style="width:35%">Part</th></tr>
            </thead>
            <tbody>
            <?php foreach (FILIERES as $f): $sum = array_sum($matrix[$f] ?? []); ?>
                <tr>
                    <td><a class="badge badge-primary" href="?filiere=<?= e($f) ?>#liste"><?= e($f) ?></a></td>
                    <?php foreach (CLASSES as $c): ?><td class="num"><?= (int)($matrix[$f][$c] ?? 0) ?></td><?php endforeach; ?>
                    <td class="num fw-bold"><?= $sum ?></td>
                    <td>
                        <div class="bar">
                            <div class="progress"><span style="width: <?= round($sum / $maxFiliere * 100) ?>%"></span></div>
                            <span class="text-muted"><?= $total ? round($sum / $total * 100) : 0 ?> %</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" id="liste">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('users') ?> Liste des élèves</h2>
            <p class="card-subtitle"><?= $totalFiltered ?> élève(s) trouvé(s)</p>
        </div>
    </div>
    <form method="GET" class="filters" action="eleves.php#liste">
        <div class="input-group grow">
            <?= icon('search', 18) ?>
            <input type="search" name="search" placeholder="Nom, prénom ou ID…" value="<?= e($search) ?>">
        </div>
        <select name="classe" data-autosubmit>
            <option value="">Toutes les classes</option>
            <?php foreach (CLASSES as $c): ?><option value="<?= e($c) ?>" <?= $classe === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
        </select>
        <select name="filiere" data-autosubmit>
            <option value="">Toutes les filières</option>
            <?php foreach (FILIERES as $f): ?><option value="<?= e($f) ?>" <?= $filiere === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><?= icon('search', 16) ?> Rechercher</button>
        <?php if ($search !== '' || $classe !== '' || $filiere !== ''): ?>
            <a href="eleves.php#liste" class="btn btn-secondary">Réinitialiser</a>
        <?php endif; ?>
    </form>

    <?php if (!$eleves): ?>
        <div class="empty-state"><?= icon('search', 40) ?><p>Aucun élève ne correspond à votre recherche.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>Nom</th><th>Prénoms</th><th>Sexe</th><th>Naissance</th><th>Filière</th><th>Classe</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($eleves as $el): ?>
                <tr>
                    <td><?= (int)$el['id'] ?></td>
                    <td class="fw-bold"><?= e(mb_strtoupper($el['nom'], 'UTF-8')) ?></td>
                    <td><?= e($el['prenoms']) ?></td>
                    <td><?= e($el['sexe']) ?></td>
                    <td><?= e(date_fr($el['date_naissance'])) ?></td>
                    <td><span class="badge badge-primary"><?= e($el['filiere']) ?></span></td>
                    <td><?= e($el['classe']) ?></td>
                    <td class="actions">
                        <a href="eleve_details.php?id=<?= (int)$el['id'] ?>" class="btn btn-sm btn-outline"><?= icon('eye', 16) ?> Fiche</a>
                        <a href="modifier_eleve.php?id=<?= (int)$el['id'] ?>" class="btn btn-sm btn-secondary"><?= icon('edit', 16) ?> Modifier</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($page, $totalPages) ?>
    <?php endif; ?>
</section>

<div id="addStudentModal" class="modal" aria-hidden="true">
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addStudentTitle">
        <form method="POST" action="eleves.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_student">
            <div class="modal-header">
                <h2 id="addStudentTitle">Ajouter un élève</h2>
                <button type="button" class="modal-close" data-modal-close aria-label="Fermer"><?= icon('x') ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="nom">Nom</label>
                        <input type="text" id="nom" name="nom" maxlength="50" required>
                    </div>
                    <div class="form-group">
                        <label for="prenoms">Prénoms</label>
                        <input type="text" id="prenoms" name="prenoms" maxlength="100" required>
                    </div>
                    <div class="form-group">
                        <label for="sexe">Sexe</label>
                        <select id="sexe" name="sexe" required>
                            <option value="">Sélectionner</option>
                            <option value="M">Masculin</option>
                            <option value="F">Féminin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="date_naissance">Date de naissance</label>
                        <input type="date" id="date_naissance" name="date_naissance" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="filiere">Filière</label>
                        <select id="filiere" name="filiere" required>
                            <option value="">Sélectionner</option>
                            <?php foreach (FILIERES as $f): ?><option value="<?= e($f) ?>"><?= e($f) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="classe">Classe</label>
                        <select id="classe" name="classe" required>
                            <option value="">Sélectionner</option>
                            <?php foreach (CLASSES as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="form-help mt-2">Un code secret parent sera généré automatiquement.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
