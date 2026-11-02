<?php
/**
 * Codes parents : génération des codes manquants et liste imprimable à distribuer.
 */
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

const CLASSES  = ['2nde', '1ere', 'Tle'];
const FILIERES = ['DWM', 'EA', 'COM', 'IMI', 'F3', 'PM'];

$filiere = in_array($_GET['filiere'] ?? '', FILIERES, true) ? $_GET['filiere'] : '';
$classe  = in_array($_GET['classe'] ?? '', CLASSES, true) ? $_GET['classe'] : '';
$query   = http_build_query(array_filter(['classe' => $classe, 'filiere' => $filiere]));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_missing') {
    try {
        $ids = $pdo->query("SELECT id FROM eleves WHERE code_secret IS NULL OR code_secret = ''")->fetchAll(PDO::FETCH_COLUMN);
        $update = $pdo->prepare('UPDATE eleves SET code_secret = ? WHERE id = ?');
        foreach ($ids as $eleveId) {
            $update->execute([unique_secret_code($pdo), $eleveId]);
        }
        flash('success', $ids ? count($ids) . ' code(s) secret(s) généré(s).' : 'Tous les élèves ont déjà un code secret.');
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('add_code_secret.php' . ($query ? "?$query" : ''));
}

$where = ' WHERE 1=1';
$params = [];
if ($classe !== '')  { $where .= ' AND classe = ?';  $params[] = $classe; }
if ($filiere !== '') { $where .= ' AND filiere = ?'; $params[] = $filiere; }
$stmt = $pdo->prepare("SELECT id, nom, prenoms, classe, filiere, code_secret FROM eleves $where ORDER BY FIELD(classe, '2nde', '1ere', 'Tle'), filiere, nom, prenoms");
$stmt->execute($params);
$eleves = $stmt->fetchAll();
$manquants = (int)$pdo->query("SELECT COUNT(*) FROM eleves WHERE code_secret IS NULL OR code_secret = ''")->fetchColumn();

$titreListe = 'Codes d\'accès parents' . ($classe || $filiere ? ' — ' . trim($classe . ' ' . $filiere) : '');

layout_start([
    'title'    => 'Codes parents',
    'subtitle' => 'Identifiants à remettre aux parents pour l\'espace parents',
    'active'   => 'eleves.php',
    'actions'  => '<a href="eleves.php" class="btn btn-secondary">' . icon('arrow-left', 16) . ' Élèves</a>'
                . '<button type="button" class="btn btn-primary" onclick="window.print()">' . icon('printer', 16) . ' Imprimer la liste</button>',
    'head'     => '<style>.print-title{display:none}@media print{.print-title{display:block;margin-bottom:12px}.card-header,.filters{display:none!important}.table th,.table td{padding:6px 8px}}</style>',
]);
?>
<?php if ($manquants > 0): ?>
<div class="alert alert-warning no-print"><?= icon('alert', 18) ?>
    <div><?= $manquants ?> élève(s) n'ont pas encore de code secret.</div>
</div>
<?php endif; ?>

<section class="card">
    <div class="card-header no-print">
        <div>
            <h2 class="card-title"><?= icon('key') ?> Liste des codes</h2>
            <p class="card-subtitle"><?= count($eleves) ?> élève(s)</p>
        </div>
        <form method="POST" data-confirm="Générer un code secret pour tous les élèves qui n'en ont pas ?" data-confirm-label="Générer">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="generate_missing">
            <button type="submit" class="btn btn-success" <?= $manquants ? '' : 'disabled' ?>><?= icon('key', 16) ?> Générer les codes manquants</button>
        </form>
    </div>

    <form method="GET" class="filters no-print">
        <select name="classe" data-autosubmit>
            <option value="">Toutes les classes</option>
            <?php foreach (CLASSES as $c): ?><option value="<?= e($c) ?>" <?= $classe === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
        </select>
        <select name="filiere" data-autosubmit>
            <option value="">Toutes les filières</option>
            <?php foreach (FILIERES as $f): ?><option value="<?= e($f) ?>" <?= $filiere === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-primary">Filtrer</button></noscript>
    </form>

    <div class="print-title">
        <h2><?= e($titreListe) ?></h2>
        <p class="text-muted">Connexion sur l'espace parents SuiviLTP avec l'ID de l'élève et son code secret. Document confidentiel — imprimé le <?= date('d/m/Y') ?>.</p>
    </div>

    <?php if (!$eleves): ?>
        <div class="empty-state"><?= icon('users', 40) ?><p>Aucun élève pour ce filtre.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>Nom</th><th>Prénoms</th><th>Classe</th><th>Filière</th><th>Code secret</th></tr></thead>
            <tbody>
            <?php foreach ($eleves as $el): ?>
                <tr>
                    <td class="code"><?= (int)$el['id'] ?></td>
                    <td class="fw-bold"><?= e(mb_strtoupper($el['nom'], 'UTF-8')) ?></td>
                    <td><?= e($el['prenoms']) ?></td>
                    <td><?= e($el['classe']) ?></td>
                    <td><?= e($el['filiere']) ?></td>
                    <td>
                        <?php if (!empty($el['code_secret'])): ?>
                            <span class="code"><?= e($el['code_secret']) ?></span>
                        <?php else: ?>
                            <span class="badge badge-warning">Non attribué</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
