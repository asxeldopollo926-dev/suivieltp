<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/frais.php';
require_page_access();

const MODES_PAIEMENT = ['Espèces', 'Reçu CLCAM', 'Chèque', 'Mobile Money'];

$eleveId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ?');
$stmt->execute([$eleveId]);
$eleve = $stmt->fetch();
if (!$eleve) {
    flash('error', 'Élève introuvable.');
    redirect('paiements.php');
}

$stmt = $pdo->prepare('SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE eleve_id = ?');
$stmt->execute([$eleveId]);
$dejaPaye = (float)$stmt->fetchColumn();
$reste = max(0, montant_total($eleve) - $dejaPaye);

$errors = [];
$form = ['montant' => '', 'mode_paiement' => '', 'date_paiement' => date('Y-m-d')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'montant'       => trim($_POST['montant'] ?? ''),
        'mode_paiement' => $_POST['mode_paiement'] ?? '',
        'date_paiement' => $_POST['date_paiement'] ?? '',
    ];

    if (!ctype_digit($form['montant']) || (int)$form['montant'] <= 0) {
        $errors[] = 'Le montant doit être un nombre entier supérieur à 0.';
    } elseif ((int)$form['montant'] > 10000000) {
        $errors[] = 'Le montant semble trop élevé.';
    }
    if (!in_array($form['mode_paiement'], MODES_PAIEMENT, true)) {
        $errors[] = 'Veuillez choisir un mode de paiement valide.';
    }
    $date = DateTime::createFromFormat('Y-m-d', $form['date_paiement']);
    if (!$date || $date->format('Y-m-d') !== $form['date_paiement']) {
        $errors[] = 'La date de paiement est invalide.';
    } elseif ($date > new DateTime('+1 day')) {
        $errors[] = 'La date de paiement ne peut pas être dans le futur.';
    } elseif ($date < new DateTime('-5 years')) {
        $errors[] = 'La date de paiement est trop ancienne.';
    }

    if (!$errors) {
        try {
            $pdo->prepare('INSERT INTO paiements (eleve_id, montant, mode_paiement, date_paiement) VALUES (?, ?, ?, ?)')
                ->execute([$eleveId, (int)$form['montant'], $form['mode_paiement'], $form['date_paiement']]);
            // On ouvre directement le reçu, prêt à être imprimé et remis au parent.
            redirect('recu.php?id=' . (int)$pdo->lastInsertId() . '&nouveau=1');
        } catch (Throwable $e) {
            $errors[] = log_error($e);
        }
    }
}

layout_start([
    'title'    => 'Ajouter un paiement',
    'subtitle' => mb_strtoupper($eleve['nom'], 'UTF-8') . ' ' . $eleve['prenoms'] . ' · ' . $eleve['classe'] . ' ' . $eleve['filiere'],
    'active'   => 'paiements.php',
    'actions'  => '<a href="paiement_details.php?id=' . $eleveId . '" class="btn btn-secondary">' . icon('arrow-left', 16) . ' Retour</a>',
]);
?>
<div class="grid grid-2">
    <section class="card">
        <h2 class="card-title mb-2"><?= icon('wallet') ?> Nouveau paiement</h2>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><?= icon('alert', 18) ?><div><?= e($err) ?></div></div>
        <?php endforeach; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="montant">Montant (FCFA)</label>
                <input type="number" id="montant" name="montant" min="1" step="1" required value="<?= e($form['montant']) ?>" autofocus>
                <?php if ($reste > 0): ?>
                    <p class="form-help">Reste à payer : <?= e(fcfa($reste)) ?></p>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="mode_paiement">Mode de paiement</label>
                <select id="mode_paiement" name="mode_paiement" required>
                    <option value="">Sélectionner…</option>
                    <?php foreach (MODES_PAIEMENT as $mode): ?>
                        <option value="<?= e($mode) ?>" <?= $form['mode_paiement'] === $mode ? 'selected' : '' ?>><?= e($mode) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="date_paiement">Date du paiement</label>
                <input type="date" id="date_paiement" name="date_paiement" required max="<?= date('Y-m-d') ?>" value="<?= e($form['date_paiement']) ?>">
            </div>
            <div class="form-actions">
                <a href="paiement_details.php?id=<?= $eleveId ?>" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?> Enregistrer le paiement</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2 class="card-title mb-2"><?= icon('user') ?> Élève</h2>
        <dl class="info-list">
            <div><dt>Nom</dt><dd><?= e(mb_strtoupper($eleve['nom'], 'UTF-8')) ?></dd></div>
            <div><dt>Prénoms</dt><dd><?= e($eleve['prenoms']) ?></dd></div>
            <div><dt>Classe</dt><dd><?= e($eleve['classe']) ?></dd></div>
            <div><dt>Filière</dt><dd><?= e($eleve['filiere']) ?></dd></div>
            <div><dt>Total dû</dt><dd><?= e(fcfa(montant_total($eleve))) ?></dd></div>
            <div><dt>Déjà payé</dt><dd><?= e(fcfa($dejaPaye)) ?></dd></div>
        </dl>
    </section>
</div>
<?php layout_end(); ?>
