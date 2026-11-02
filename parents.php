<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/bulletin_file.php';
$eleve = require_parent();
$eleveId = (int)$eleve['id'];

// Montant annuel dû (COM : 53 000 ; autres filières : 120 000 garçons / 80 000 filles)
$montantTotal = $eleve['filiere'] === 'COM' ? 53000 : ($eleve['sexe'] === 'M' ? 120000 : 80000);

$stmt = $pdo->prepare('SELECT * FROM paiements WHERE eleve_id = ? ORDER BY date_paiement DESC, id DESC');
$stmt->execute([$eleveId]);
$paiements = $stmt->fetchAll();
$totalPaye = array_sum(array_map(fn($p) => (float)$p['montant'], $paiements));
$reste = max(0, $montantTotal - $totalPaye);
$pourcentage = $montantTotal > 0 ? min(100, (int)round($totalPaye * 100 / $montantTotal)) : 0;

$stmt = $pdo->prepare("SELECT * FROM absences WHERE eleve_id = ? AND statut IN ('Absent','Absente','En retard') ORDER BY date_absence DESC, id DESC");
$stmt->execute([$eleveId]);
$absences = $stmt->fetchAll();
$nbAbsences = count(array_filter($absences, fn($a) => $a['statut'] !== 'En retard'));
$nbRetards = count($absences) - $nbAbsences;

// Un bulletin publié par semestre pour la classe/filière de l'enfant
$stmt = $pdo->prepare("SELECT b.* FROM bulletins b
    WHERE b.classe = ? AND b.filiere = ? AND b.status = 'oui'
      AND b.id IN (SELECT MIN(id) FROM bulletins WHERE classe = ? AND filiere = ? AND status = 'oui' GROUP BY semestre)
    ORDER BY b.semestre");
$stmt->execute([$eleve['classe'], $eleve['filiere'], $eleve['classe'], $eleve['filiere']]);
$bulletins = $stmt->fetchAll();


layout_start([
    'title'    => $eleve['prenoms'] . ' ' . mb_strtoupper($eleve['nom'], 'UTF-8'),
    'subtitle' => 'Classe ' . $eleve['classe'] . ' · Filière ' . $eleve['filiere'] . ' · ID ' . $eleveId,
    'area'     => 'parent',
]);
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon <?= $reste > 0 ? 'warning' : 'success' ?>"><?= icon('wallet', 22) ?></div>
        <div><div class="stat-label">Reste à payer</div><div class="stat-value"><?= e(fcfa($reste)) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><?= icon('calendar', 22) ?></div>
        <div><div class="stat-label">Absences</div><div class="stat-value"><?= $nbAbsences ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><?= icon('clock', 22) ?></div>
        <div><div class="stat-label">Retards</div><div class="stat-value"><?= $nbRetards ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><?= icon('file', 22) ?></div>
        <div><div class="stat-label">Bulletins disponibles</div><div class="stat-value"><?= count($bulletins) ?></div></div>
    </div>
</div>

<div class="grid grid-2">
    <!-- Paiements -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title"><?= icon('wallet') ?> Frais de scolarité</h2>
            <?php if ($reste <= 0): ?>
                <span class="badge badge-success"><?= icon('check', 14) ?> Soldé</span>
            <?php elseif ($totalPaye > 0): ?>
                <span class="badge badge-warning">Paiement partiel</span>
            <?php else: ?>
                <span class="badge badge-danger">Aucun paiement</span>
            <?php endif; ?>
        </div>

        <dl class="info-list">
            <div><dt>Total dû</dt><dd><?= e(fcfa($montantTotal)) ?></dd></div>
            <div><dt>Déjà payé</dt><dd class="text-success"><?= e(fcfa($totalPaye)) ?></dd></div>
            <div><dt>Reste</dt><dd class="<?= $reste > 0 ? 'text-danger' : 'text-success' ?>"><?= e(fcfa($reste)) ?></dd></div>
        </dl>
        <div class="progress mt-2" role="progressbar" aria-valuenow="<?= $pourcentage ?>" aria-valuemin="0" aria-valuemax="100">
            <span style="width: <?= $pourcentage ?>%"></span>
        </div>
        <p class="form-help"><?= $pourcentage ?> % des frais réglés</p>

        <h3 class="mt-2 mb-2" style="font-size:1rem">Historique des versements</h3>
        <?php if (!$paiements): ?>
            <div class="empty-state"><?= icon('wallet', 36) ?><p class="mb-0">Aucun versement enregistré pour le moment.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Mode</th><th class="num">Montant</th></tr></thead>
                <tbody>
                <?php foreach ($paiements as $p): ?>
                    <tr>
                        <td><?= e(date_fr($p['date_paiement'])) ?></td>
                        <td><?= e($p['mode_paiement']) ?></td>
                        <td class="num fw-bold"><?= e(fcfa($p['montant'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <!-- Bulletins -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title"><?= icon('file') ?> Bulletins scolaires</h2>
        </div>
        <?php if (!$bulletins): ?>
            <div class="empty-state"><?= icon('file', 36) ?><p class="mb-0">Aucun bulletin n'a encore été publié pour la classe de votre enfant.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <tbody>
                <?php foreach ($bulletins as $b):
                    $hasFile = bulletin_pdf_path((int)$b['id'], $eleveId) !== null;
                    $query = 'id=' . (int)$b['id'] . '&eleve_id=' . $eleveId;
                ?>
                    <tr>
                        <td>
                            <span class="fw-bold">Semestre <?= e($b['semestre']) ?></span>
                            <div class="text-muted" style="font-size:.85rem">Publié le <?= e(date_fr($b['date_creation'])) ?></div>
                        </td>
                        <td class="num">
                            <?php if ($hasFile): ?>
                                <div class="btn-group" style="justify-content:flex-end">
                                    <a href="view_bulletin.php?<?= e($query) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><?= icon('eye', 16) ?> Voir</a>
                                    <a href="download_bulletin.php?<?= e($query) ?>" class="btn btn-sm btn-primary"><?= icon('download', 16) ?> Télécharger</a>
                                </div>
                            <?php else: ?>
                                <span class="badge">En préparation</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<!-- Absences et retards -->
<section class="card section">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('calendar') ?> Absences et retards</h2>
            <p class="card-subtitle"><?= $nbAbsences ?> absence(s) · <?= $nbRetards ?> retard(s)</p>
        </div>
    </div>
    <?php if (!$absences): ?>
        <div class="empty-state"><?= icon('check', 36) ?><p class="mb-0">Aucune absence ni aucun retard signalé. Bravo !</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th>Statut</th><th>Matière</th><th>Horaire</th></tr></thead>
            <tbody>
            <?php foreach ($absences as $a): $retard = $a['statut'] === 'En retard'; ?>
                <tr>
                    <td><?= e(date_fr($a['date_absence'])) ?></td>
                    <td><span class="badge <?= $retard ? 'badge-warning' : 'badge-danger' ?>"><?= e($a['statut']) ?></span></td>
                    <td><?= $retard ? '<span class="text-muted">—</span>' : e($a['matiere'] ?: 'Non précisée') ?></td>
                    <td><?= ($a['heure_debut'] && $a['heure_fin']) ? e(substr($a['heure_debut'], 0, 5) . ' – ' . substr($a['heure_fin'], 0, 5)) : '<span class="text-muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<p class="text-muted mt-3" style="font-size:.85rem;text-align:center">
    Une question ? Contactez le secrétariat de l'établissement en indiquant l'ID <?= $eleveId ?> de votre enfant.
</p>
<?php layout_end(); ?>
