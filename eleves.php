<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

$etabId = etab_id();

/** Valide les champs d'un élève envoyés en POST (structure de l'établissement courant). Retourne [données, erreurs]. */
function eleve_from_post(): array
{
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
        $pdo->prepare('INSERT INTO eleves (etablissement_id, nom, prenoms, sexe, date_naissance, filiere, classe, code_secret, parent_nom, parent_telephone, parent_email)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$etabId, $d['nom'], $d['prenoms'], $d['sexe'], $d['date_naissance'], $d['filiere'], $d['classe'], $code,
                       $d['parent_nom'], $d['parent_telephone'], $d['parent_email']]);
        $newId = (int)$pdo->lastInsertId();
        flash('success', "Élève ajouté (ID $newId). Code secret parent : $code");
        redirect('eleve_details.php?id=' . $newId);
    } catch (Throwable $e) {
        flash('error', log_error($e));
        redirect('eleves.php');
    }
}

// Répartition par classe et série (structure de l'établissement)
$stmt = $pdo->prepare('SELECT classe, filiere, sexe, COUNT(*) AS total FROM eleves WHERE etablissement_id = ? GROUP BY classe, filiere, sexe');
$stmt->execute([$etabId]);
$matrix = [];
$totalM = $totalF = 0;
foreach ($stmt->fetchAll() as $r) {
    $matrix[$r['classe']][$r['filiere']] = ($matrix[$r['classe']][$r['filiere']] ?? 0) + (int)$r['total'];
    if ($r['sexe'] === 'M') { $totalM += (int)$r['total']; } else { $totalF += (int)$r['total']; }
}
$total = $totalM + $totalF;
$classes = classe_codes();
$series = serie_codes();
$hasSansSerie = (bool)classes_sans_serie();
$colonnes = $series;
if ($hasSansSerie) {
    $colonnes[] = ''; // colonne « — » pour les classes sans série
}
$maxClasse = 1;
foreach ($classes as $c) {
    $maxClasse = max($maxClasse, array_sum($matrix[$c] ?? []));
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM eleves WHERE etablissement_id = ? AND (code_secret IS NULL OR code_secret = '')");
$stmt->execute([$etabId]);
$sansCode = (int)$stmt->fetchColumn();

// Liste filtrée
$filiere = in_array($_GET['filiere'] ?? '', $series, true) ? $_GET['filiere'] : '';
$classe  = in_array($_GET['classe'] ?? '', $classes, true) ? $_GET['classe'] : '';
$search  = trim($_GET['search'] ?? '');
$where = ' WHERE etablissement_id = ?';
$params = [$etabId];
if ($filiere !== '') { $where .= ' AND filiere = ?'; $params[] = $filiere; }
if ($classe !== '')  { $where .= ' AND classe = ?';  $params[] = $classe; }
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
$stmt = $pdo->prepare('SELECT * FROM eleves' . $where . ' ORDER BY ' . sql_order_classe('classe') . ', filiere, nom, prenoms LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
$stmt->execute($params);
$eleves = $stmt->fetchAll();

$filtres = array_filter(['classe' => $classe, 'filiere' => $filiere, 'search' => $search], fn($v) => $v !== '');
$exportUrl = 'export.php?' . http_build_query(['type' => 'eleves'] + $filtres);

layout_start([
    'title'    => 'Élèves',
    'subtitle' => (current_etab()['nom'] ?? '') . ' — effectifs, inscriptions et codes d\'accès des parents',
    'actions'  => '<button type="button" class="btn btn-primary" data-modal-open="addStudentModal">' . icon('plus', 16) . ' Ajouter un élève</button>'
                . '<a href="add_code_secret.php" class="btn btn-outline">' . icon('key', 16) . ' Codes parents</a>'
                . '<a href="' . e($exportUrl) . '" class="btn btn-outline">' . icon('excel', 16) . ' Exporter Excel</a>',
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
        <h2 class="card-title"><?= icon('chart') ?> Répartition par classe et par <?= e(mb_strtolower(serie_label(), 'UTF-8')) ?></h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Classe</th>
                    <?php foreach ($colonnes as $s): ?><th class="num"><?= $s === '' ? '—' : e($s) ?></th><?php endforeach; ?>
                    <th class="num">Total</th><th style="width:30%">Part</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($classes as $c):
                $sum = array_sum($matrix[$c] ?? []);
                $avecSerie = classe_a_serie($c); ?>
                <tr>
                    <td><a class="badge badge-primary" href="?classe=<?= e(urlencode($c)) ?>#liste"><?= e($c) ?></a></td>
                    <?php foreach ($colonnes as $s): ?>
                        <td class="num">
                            <?php if ($avecSerie === ($s !== '')): ?>
                                <?= (int)($matrix[$c][$s] ?? 0) ?>
                            <?php else: ?>
                                <span class="text-muted">·</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="num fw-bold"><?= $sum ?></td>
                    <td>
                        <div class="bar">
                            <div class="progress"><span style="width: <?= round($sum / $maxClasse * 100) ?>%"></span></div>
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
        <a href="<?= e($exportUrl) ?>" class="btn btn-sm btn-outline"><?= icon('excel', 16) ?> Exporter cette liste</a>
    </div>
    <form method="GET" class="filters" action="eleves.php#liste">
        <div class="input-group grow">
            <?= icon('search', 18) ?>
            <input type="search" name="search" placeholder="Nom, prénom ou ID…" value="<?= e($search) ?>">
        </div>
        <?= classe_serie_selects($classe, $filiere, ['filter' => true]) ?>
        <button type="submit" class="btn btn-primary"><?= icon('search', 16) ?> Rechercher</button>
        <?php if ($filtres): ?>
            <a href="eleves.php#liste" class="btn btn-secondary">Réinitialiser</a>
        <?php endif; ?>
    </form>

    <?php if (!$eleves): ?>
        <div class="empty-state"><?= icon('search', 40) ?><p>Aucun élève ne correspond à votre recherche.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>Nom</th><th>Prénoms</th><th>Sexe</th><th>Naissance</th><th>Classe</th><th><?= e(serie_label()) ?></th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($eleves as $el): ?>
                <tr>
                    <td><?= (int)$el['id'] ?></td>
                    <td class="fw-bold"><?= e(mb_strtoupper($el['nom'], 'UTF-8')) ?></td>
                    <td><?= e($el['prenoms']) ?></td>
                    <td><?= e($el['sexe']) ?></td>
                    <td><?= e(date_fr($el['date_naissance'])) ?></td>
                    <td><?= e($el['classe']) ?></td>
                    <td><?= $el['filiere'] !== '' ? '<span class="badge badge-primary">' . e($el['filiere']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
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
    <div class="modal-dialog modal-lg" role="dialog" aria-modal="true" aria-labelledby="addStudentTitle">
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
                    <?= classe_serie_selects('', '', ['id_prefix' => 'add_']) ?>
                </div>
                <h3 class="mt-3 mb-2" style="font-size:1rem">Parent / tuteur <span class="text-muted" style="font-weight:400">(facultatif)</span></h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="parent_nom">Nom du parent</label>
                        <input type="text" id="parent_nom" name="parent_nom" maxlength="150">
                    </div>
                    <div class="form-group">
                        <label for="parent_telephone">Téléphone</label>
                        <input type="tel" id="parent_telephone" name="parent_telephone" maxlength="30" pattern="[0-9 +()\-]{6,30}">
                    </div>
                    <div class="form-group">
                        <label for="parent_email">E-mail</label>
                        <input type="email" id="parent_email" name="parent_email" maxlength="150">
                    </div>
                </div>
                <p class="form-help mt-2">Un code secret parent sera généré automatiquement. Avec un e-mail, le parent recevra les notifications (absences, bulletins, paiements).</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
