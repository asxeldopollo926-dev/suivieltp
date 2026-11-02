<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/frais.php';
require_page_access();

$eleveId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ?');
$stmt->execute([$eleveId]);
$eleve = $stmt->fetch();
if (!$eleve) {
    flash('error', 'Élève introuvable.');
    redirect('paiements.php');
}

// Suppression d'un paiement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_paiement') {
    try {
        $stmt = $pdo->prepare('DELETE FROM paiements WHERE id = ? AND eleve_id = ?');
        $stmt->execute([(int)($_POST['paiement_id'] ?? 0), $eleveId]);
        flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Paiement supprimé.' : 'Paiement introuvable.');
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('paiement_details.php?id=' . $eleveId);
}

$stmt = $pdo->prepare('SELECT * FROM paiements WHERE eleve_id = ? ORDER BY date_paiement DESC, id DESC');
$stmt->execute([$eleveId]);
$paiements = $stmt->fetchAll();

$montantTotal = montant_total($eleve);
$montantPaye = array_sum(array_map(fn($p) => (float)$p['montant'], $paiements));
$reste = max(0, $montantTotal - $montantPaye);
$pourcentage = $montantTotal > 0 ? min(100, round($montantPaye / $montantTotal * 100)) : 100;

$actions = '<a href="paiements.php" class="btn btn-secondary">' . icon('arrow-left', 16) . ' Retour</a>'
    . '<button type="button" class="btn btn-outline" onclick="window.print()">' . icon('printer', 16) . ' Imprimer</button>'
    . '<a href="ajouter_paiement.php?id=' . $eleveId . '" class="btn btn-primary">' . icon('plus', 16) . ' Ajouter un paiement</a>';

layout_start([
    'title'    => 'Paiements de ' . mb_strtoupper($eleve['nom'], 'UTF-8') . ' ' . $eleve['prenoms'],
    'subtitle' => 'Classe ' . $eleve['classe'] . ' ' . $eleve['filiere'] . ' · ID ' . $eleveId,
    'active'   => 'paiements.php',
    'actions'  => $actions,
]);
?>
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= icon('user') ?> Élève</h2>
        <?php if ($reste <= 0): ?>
            <span class="badge badge-success">Soldé</span>
        <?php else: ?>
            <span class="badge badge-warning">En cours</span>
        <?php endif; ?>
    </div>
    <dl class="info-list">
        <div><dt>Nom</dt><dd><?= e(mb_strtoupper($eleve['nom'], 'UTF-8')) ?></dd></div>
        <div><dt>Prénoms</dt><dd><?= e($eleve['prenoms']) ?></dd></div>
        <div><dt>Classe</dt><dd><?= e($eleve['classe']) ?></dd></div>
        <div><dt>Filière</dt><dd><?= e($eleve['filiere']) ?></dd></div>
        <div><dt>Sexe</dt><dd><?= e($eleve['sexe']) ?></dd></div>
    </dl>
</section>

<div class="stats-grid section">
    <div class="stat-card">
        <div class="stat-icon"><?= icon('file', 22) ?></div>
        <div><div class="stat-label">Total dû</div><div class="stat-value"><?= e(fcfa($montantTotal)) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><?= icon('check', 22) ?></div>
        <div><div class="stat-label">Montant payé</div><div class="stat-value"><?= e(fcfa($montantPaye)) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $reste > 0 ? 'danger' : 'success' ?>"><?= icon('wallet', 22) ?></div>
        <div><div class="stat-label">Reste à payer</div><div class="stat-value"><?= e(fcfa($reste)) ?></div></div>
    </div>
</div>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= icon('chart') ?> Progression</h2>
        <span class="fw-bold"><?= $pourcentage ?> %</span>
    </div>
    <div class="progress"><span style="width: <?= $pourcentage ?>%"></span></div>
</section>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= icon('clock') ?> Historique des paiements</h2>
        <?php if ($paiements): ?>
        <form method="POST" action="reinitialiser_paiements.php" class="no-print"
              data-confirm="Supprimer TOUS les paiements de cet élève ? Cette action est irréversible."
              data-confirm-label="Tout supprimer">
            <?= csrf_field() ?>
            <input type="hidden" name="eleve_id" value="<?= $eleveId ?>">
            <button type="submit" class="btn btn-sm btn-danger-soft"><?= icon('refresh', 16) ?> Réinitialiser</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!$paiements): ?>
        <div class="empty-state"><?= icon('wallet', 40) ?><p>Aucun paiement enregistré pour cet élève.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th class="num">Montant</th><th>Mode de paiement</th><th class="no-print">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($paiements as $p): ?>
                <tr>
                    <td><?= e(date_fr($p['date_paiement'])) ?></td>
                    <td class="num fw-bold"><?= e(fcfa($p['montant'])) ?></td>
                    <td><?= e($p['mode_paiement']) ?></td>
                    <td class="no-print actions">
                        <a href="recu.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline"><?= icon('receipt', 16) ?> Reçu</a>
                        <form method="POST" class="inline" data-confirm="Supprimer ce paiement de <?= e(fcfa($p['montant'])) ?> ?" data-confirm-label="Supprimer">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_paiement">
                            <input type="hidden" name="paiement_id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger-soft"><?= icon('trash', 16) ?> Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
