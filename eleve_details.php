<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/bulletin_file.php';
require_page_access();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ? AND etablissement_id = ?');
$stmt->execute([$id, etab_id()]);
$eleve = $stmt->fetch();
if (!$eleve) {
    flash('error', 'Élève introuvable.');
    redirect('eleves.php');
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM eleves WHERE id = ? AND etablissement_id = ?')->execute([$id, etab_id()]);
            flash('success', 'L\'élève ' . $eleve['prenoms'] . ' ' . $eleve['nom'] . ' a été supprimé.');
            redirect('eleves.php');
        }
        if ($action === 'regenerate_code') {
            $code = unique_secret_code($pdo);
            $pdo->prepare('UPDATE eleves SET code_secret = ? WHERE id = ? AND etablissement_id = ?')->execute([$code, $id, etab_id()]);
            flash('success', "Nouveau code secret : $code. L'ancien code ne fonctionne plus.");
        }
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('eleve_details.php?id=' . $id);
}

// Bulletins publiés de sa classe / filière (un par semestre : le plus récent)
$stmt = $pdo->prepare("SELECT * FROM bulletins WHERE etablissement_id = ? AND classe = ? AND filiere = ? AND status = 'oui' ORDER BY semestre, date_creation DESC");
$stmt->execute([etab_id(), $eleve['classe'], $eleve['filiere']]);
$bulletins = [];
foreach ($stmt->fetchAll() as $b) {
    if (!isset($bulletins[$b['semestre']])) {
        $b['has_file'] = bulletin_pdf_path((int)$b['id'], $id) !== null;
        $bulletins[$b['semestre']] = $b;
    }
}

$actions = '<a href="eleves.php" class="btn btn-secondary">' . icon('arrow-left', 16) . ' Retour</a>'
         . '<a href="modifier_eleve.php?id=' . $id . '" class="btn btn-outline">' . icon('edit', 16) . ' Modifier</a>';
if (canAccessPage('paiement_details.php')) {
    $actions .= '<a href="paiement_details.php?id=' . $id . '" class="btn btn-outline">' . icon('wallet', 16) . ' Paiements</a>';
}

layout_start([
    'title'    => $eleve['prenoms'] . ' ' . mb_strtoupper($eleve['nom'], 'UTF-8'),
    'subtitle' => 'Élève n° ' . $id . ' — ' . classe_label($eleve['classe'], $eleve['filiere']),
    'active'   => 'eleves.php',
    'actions'  => $actions,
]);
?>
<div class="grid grid-2">
    <section class="card">
        <div class="card-header"><h2 class="card-title"><?= icon('user') ?> Informations</h2></div>
        <dl class="info-list">
            <div><dt>ID élève</dt><dd><?= $id ?></dd></div>
            <div><dt>Nom</dt><dd><?= e(mb_strtoupper($eleve['nom'], 'UTF-8')) ?></dd></div>
            <div><dt>Prénoms</dt><dd><?= e($eleve['prenoms']) ?></dd></div>
            <div><dt>Sexe</dt><dd><?= $eleve['sexe'] === 'F' ? 'Féminin' : 'Masculin' ?></dd></div>
            <div><dt>Date de naissance</dt><dd><?= e(date_fr($eleve['date_naissance'])) ?></dd></div>
            <div><dt>Classe</dt><dd><?= e($eleve['classe']) ?></dd></div>
            <div><dt><?= e(serie_label()) ?></dt><dd><?= $eleve['filiere'] !== '' ? '<span class="badge badge-primary">' . e($eleve['filiere']) . '</span>' : '<span class="text-muted">—</span>' ?></dd></div>
        </dl>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="card-title"><?= icon('key') ?> Accès parent</h2></div>
        <p class="text-muted">Les parents se connectent à l'espace parents avec l'ID de l'élève et ce code.</p>
        <dl class="info-list">
            <div><dt>ID</dt><dd class="code"><?= $id ?></dd></div>
            <div><dt>Code secret</dt>
                <dd>
                    <?php if (!empty($eleve['code_secret'])): ?>
                        <span class="code"><?= e($eleve['code_secret']) ?></span>
                        <button type="button" class="btn btn-sm btn-secondary" data-copy="<?= e($eleve['code_secret']) ?>">Copier</button>
                    <?php else: ?>
                        <span class="badge badge-warning">Non attribué</span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
        <h3 class="mt-3 mb-2" style="font-size:1rem">Parent / tuteur</h3>
        <dl class="info-list">
            <div><dt>Nom</dt><dd><?= $eleve['parent_nom'] !== '' ? e($eleve['parent_nom']) : '<span class="text-muted">Non renseigné</span>' ?></dd></div>
            <div><dt>Téléphone</dt><dd><?= $eleve['parent_telephone'] !== '' ? e($eleve['parent_telephone']) : '<span class="text-muted">Non renseigné</span>' ?></dd></div>
            <div><dt>E-mail</dt><dd><?= $eleve['parent_email'] !== '' ? e($eleve['parent_email']) : '<span class="text-muted">Non renseigné</span>' ?></dd></div>
        </dl>
        <?php if ($eleve['parent_email'] !== ''): ?>
            <p class="mt-2 mb-0"><span class="badge badge-success"><?= icon('mail', 14) ?> Notifications activées</span> <span class="text-muted">Le parent reçoit les e-mails (absences, bulletins, paiements).</span></p>
        <?php else: ?>
            <p class="mt-2 mb-0"><span class="badge badge-warning"><?= icon('mail', 14) ?> Pas de notification</span> <span class="text-muted">Ajoutez un e-mail du parent (Modifier) pour qu'il soit prévenu.</span></p>
        <?php endif; ?>
        <form method="POST" class="mt-2" data-confirm="Générer un nouveau code secret ? L'ancien code ne permettra plus aux parents de se connecter." data-confirm-label="Régénérer">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="regenerate_code">
            <button type="submit" class="btn btn-outline"><?= icon('refresh', 16) ?> <?= empty($eleve['code_secret']) ? 'Générer le code' : 'Régénérer le code' ?></button>
        </form>
    </section>
</div>

<section class="card section">
    <div class="card-header"><h2 class="card-title"><?= icon('file') ?> Bulletins</h2></div>
    <?php if (!$bulletins): ?>
        <div class="empty-state"><?= icon('file', 40) ?><p>Aucun bulletin publié pour la classe <?= e(classe_label($eleve['classe'], $eleve['filiere'])) ?>.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Semestre</th><th>Publié le</th><th>Fichier</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($bulletins as $b): ?>
                <tr>
                    <td class="fw-bold">Semestre <?= e($b['semestre']) ?></td>
                    <td><?= e(date_fr($b['date_creation'])) ?></td>
                    <td><?= $b['has_file'] ? '<span class="badge badge-success">Disponible</span>' : '<span class="badge badge-warning">PDF non déposé</span>' ?></td>
                    <td class="actions">
                        <?php if ($b['has_file']): ?>
                            <a href="view_bulletin.php?id=<?= (int)$b['id'] ?>&amp;eleve_id=<?= $id ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><?= icon('eye', 16) ?> Afficher</a>
                            <a href="download_bulletin.php?id=<?= (int)$b['id'] ?>&amp;eleve_id=<?= $id ?>" class="btn btn-sm btn-primary"><?= icon('download', 16) ?> Télécharger</a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card section">
    <div class="card-header">
        <div>
            <h2 class="card-title text-danger"><?= icon('trash') ?> Supprimer l'élève</h2>
            <p class="card-subtitle">Supprime définitivement l'élève ainsi que ses paiements et absences.</p>
        </div>
        <form method="POST" data-confirm="Supprimer définitivement <?= e($eleve['prenoms'] . ' ' . $eleve['nom']) ?> ? Ses paiements et absences seront aussi supprimés." data-confirm-label="Supprimer">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-danger"><?= icon('trash', 16) ?> Supprimer</button>
        </form>
    </div>
</section>
<?php layout_end(); ?>
