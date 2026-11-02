<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/frais.php';
require_once 'includes/notify.php';
require_page_access();

const MODES_PAIEMENT = ['Espèces', 'Reçu CLCAM', 'Chèque', 'Mobile Money'];

/** Prévient le parent du paiement. Ne bloque jamais la saisie en cas d'erreur d'envoi. */
function notifier_paiement(array $eleve, int $paiementId, int $montant, string $date, string $mode, float $dejaPaye, int $du): void
{
    try {
        $totalPaye = (int)round($dejaPaye) + $montant;
        $numero = 'REC-' . date('Y', strtotime($date)) . '-' . str_pad((string)$paiementId, 6, '0', STR_PAD_LEFT);
        $resteTxt = $du > 0 ? ($du - $totalPaye <= 0 ? 'Soldé' : fcfa($du - $totalPaye)) : 'non défini';
        $body = 'Nous vous confirmons la réception d\'un paiement de ' . fcfa($montant)
              . ' pour ' . mb_strtoupper($eleve['nom'], 'UTF-8') . ' ' . $eleve['prenoms']
              . ' (' . classe_label($eleve['classe'], $eleve['filiere']) . ").\n\n"
              . 'Date : ' . date_fr($date) . "\n"
              . 'Mode de paiement : ' . $mode . "\n"
              . 'N° de reçu : ' . $numero . "\n"
              . 'Total versé à ce jour : ' . fcfa($totalPaye) . "\n"
              . 'Reste à payer : ' . $resteTxt . "\n\n"
              . 'Le reçu est consultable dans votre espace parents.';
        notify_parent($eleve, 'paiement', 'Paiement reçu', $body, true);
    } catch (Throwable $e) {
        log_error($e);
    }
}

$eleveId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ? AND etablissement_id = ?');
$stmt->execute([$eleveId, etab_id()]);
$eleve = $stmt->fetch();
if (!$eleve) {
    flash('error', 'Élève introuvable.');
    redirect('paiements.php');
}

$stmt = $pdo->prepare('SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE eleve_id = ?');
$stmt->execute([$eleveId]);
$dejaPaye = (float)$stmt->fetchColumn();
$du = montant_total($eleve);
$reste = max(0, $du - $dejaPaye);

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
            $paiementId = (int)$pdo->lastInsertId();
            notifier_paiement($eleve, $paiementId, (int)$form['montant'], $form['date_paiement'], $form['mode_paiement'], $dejaPaye, $du);
            // On ouvre directement le reçu, prêt à être imprimé et remis au parent.
            redirect('recu.php?id=' . $paiementId . '&nouveau=1');
        } catch (Throwable $e) {
            $errors[] = log_error($e);
        }
    }
}

layout_start([
    'title'    => 'Ajouter un paiement',
    'subtitle' => mb_strtoupper($eleve['nom'], 'UTF-8') . ' ' . $eleve['prenoms'] . ' · ' . classe_label($eleve['classe'], $eleve['filiere']),
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
            <div><dt><?= e(serie_label()) ?></dt><dd><?= e($eleve['filiere'] !== '' ? $eleve['filiere'] : '—') ?></dd></div>
            <div><dt>Total dû</dt><dd><?= $du > 0 ? e(fcfa($du)) : 'Non défini' ?></dd></div>
            <div><dt>Déjà payé</dt><dd><?= e(fcfa($dejaPaye)) ?></dd></div>
        </dl>
    </section>
</div>
<?php layout_end(); ?>
