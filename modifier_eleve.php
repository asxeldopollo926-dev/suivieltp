<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ? AND etablissement_id = ?');
$stmt->execute([$id, etab_id()]);
$eleve = $stmt->fetch();
if (!$eleve) {
    flash('error', 'Élève introuvable.');
    redirect('eleves.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if (!$errors) {
        try {
            $pdo->prepare('UPDATE eleves SET nom = ?, prenoms = ?, sexe = ?, date_naissance = ?, filiere = ?, classe = ?,
                                  parent_nom = ?, parent_telephone = ?, parent_email = ?
                           WHERE id = ? AND etablissement_id = ?')
                ->execute([$d['nom'], $d['prenoms'], $d['sexe'], $d['date_naissance'], $d['filiere'], $d['classe'],
                           $d['parent_nom'], $d['parent_telephone'], $d['parent_email'], $id, etab_id()]);
            flash('success', 'Les informations de l\'élève ont été mises à jour.');
            redirect('eleve_details.php?id=' . $id);
        } catch (Throwable $e) {
            $errors[] = log_error($e);
        }
    }
    $eleve = array_merge($eleve, $d); // réafficher la saisie
}

layout_start([
    'title'    => 'Modifier l\'élève',
    'subtitle' => $eleve['prenoms'] . ' ' . mb_strtoupper($eleve['nom'], 'UTF-8') . ' — n° ' . $id,
    'active'   => 'eleves.php',
    'actions'  => '<a href="eleve_details.php?id=' . $id . '" class="btn btn-secondary">' . icon('arrow-left', 16) . ' Retour à la fiche</a>',
]);
?>
<?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert', 18) ?><div><?= e(implode(' ', $errors)) ?></div></div>
<?php endif; ?>

<section class="card">
    <form method="POST" action="modifier_eleve.php?id=<?= $id ?>">
        <?= csrf_field() ?>
        <h2 class="card-title mb-2"><?= icon('user') ?> Élève</h2>
        <div class="form-grid">
            <div class="form-group">
                <label for="nom">Nom</label>
                <input type="text" id="nom" name="nom" maxlength="50" value="<?= e($eleve['nom']) ?>" required>
            </div>
            <div class="form-group">
                <label for="prenoms">Prénoms</label>
                <input type="text" id="prenoms" name="prenoms" maxlength="100" value="<?= e($eleve['prenoms']) ?>" required>
            </div>
            <div class="form-group">
                <label for="sexe">Sexe</label>
                <select id="sexe" name="sexe" required>
                    <option value="M" <?= $eleve['sexe'] === 'M' ? 'selected' : '' ?>>Masculin</option>
                    <option value="F" <?= $eleve['sexe'] === 'F' ? 'selected' : '' ?>>Féminin</option>
                </select>
            </div>
            <div class="form-group">
                <label for="date_naissance">Date de naissance</label>
                <input type="date" id="date_naissance" name="date_naissance" max="<?= date('Y-m-d') ?>" value="<?= e($eleve['date_naissance']) ?>" required>
            </div>
            <?= classe_serie_selects((string)$eleve['classe'], (string)$eleve['filiere']) ?>
        </div>

        <h2 class="card-title mt-3 mb-2"><?= icon('users') ?> Parent / tuteur</h2>
        <div class="form-grid">
            <div class="form-group">
                <label for="parent_nom">Nom du parent</label>
                <input type="text" id="parent_nom" name="parent_nom" maxlength="150" value="<?= e($eleve['parent_nom']) ?>">
            </div>
            <div class="form-group">
                <label for="parent_telephone">Téléphone</label>
                <input type="tel" id="parent_telephone" name="parent_telephone" maxlength="30" pattern="[0-9 +()\-]{6,30}" value="<?= e($eleve['parent_telephone']) ?>">
            </div>
            <div class="form-group">
                <label for="parent_email">E-mail</label>
                <input type="email" id="parent_email" name="parent_email" maxlength="150" value="<?= e($eleve['parent_email']) ?>">
                <p class="form-help">Renseigné, il permet au parent de recevoir les notifications par e-mail.</p>
            </div>
        </div>
        <div class="form-actions">
            <a href="eleve_details.php?id=<?= $id ?>" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?> Enregistrer les modifications</button>
        </div>
    </form>
</section>
<?php layout_end(); ?>
