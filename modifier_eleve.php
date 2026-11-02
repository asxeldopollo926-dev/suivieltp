<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

const CLASSES  = ['2nde', '1ere', 'Tle'];
const FILIERES = ['DWM', 'EA', 'COM', 'IMI', 'F3', 'PM'];

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ?');
$stmt->execute([$id]);
$eleve = $stmt->fetch();
if (!$eleve) {
    flash('error', 'Élève introuvable.');
    redirect('eleves.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'nom'            => trim($_POST['nom'] ?? ''),
        'prenoms'        => trim($_POST['prenoms'] ?? ''),
        'sexe'           => $_POST['sexe'] ?? '',
        'date_naissance' => $_POST['date_naissance'] ?? '',
        'filiere'        => $_POST['filiere'] ?? '',
        'classe'         => $_POST['classe'] ?? '',
    ];
    if ($d['nom'] === '' || mb_strlen($d['nom']) > 50)          { $errors[] = 'Nom obligatoire (50 caractères max).'; }
    if ($d['prenoms'] === '' || mb_strlen($d['prenoms']) > 100) { $errors[] = 'Prénoms obligatoires (100 caractères max).'; }
    if (!in_array($d['sexe'], ['M', 'F'], true))                { $errors[] = 'Sexe invalide.'; }
    if (!in_array($d['filiere'], FILIERES, true))               { $errors[] = 'Filière invalide.'; }
    if (!in_array($d['classe'], CLASSES, true))                 { $errors[] = 'Classe invalide.'; }
    $dt = DateTime::createFromFormat('Y-m-d', $d['date_naissance']);
    if (!$dt || $dt->format('Y-m-d') !== $d['date_naissance'] || $dt > new DateTime()) {
        $errors[] = 'Date de naissance invalide.';
    }

    if (!$errors) {
        try {
            $pdo->prepare('UPDATE eleves SET nom = ?, prenoms = ?, sexe = ?, date_naissance = ?, filiere = ?, classe = ? WHERE id = ?')
                ->execute([$d['nom'], $d['prenoms'], $d['sexe'], $d['date_naissance'], $d['filiere'], $d['classe'], $id]);
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
            <div class="form-group">
                <label for="filiere">Filière</label>
                <select id="filiere" name="filiere" required>
                    <?php foreach (FILIERES as $f): ?>
                        <option value="<?= e($f) ?>" <?= $eleve['filiere'] === $f ? 'selected' : '' ?>><?= e($f) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="classe">Classe</label>
                <select id="classe" name="classe" required>
                    <?php foreach (CLASSES as $c): ?>
                        <option value="<?= e($c) ?>" <?= $eleve['classe'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <a href="eleve_details.php?id=<?= $id ?>" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?> Enregistrer les modifications</button>
        </div>
    </form>
</section>
<?php layout_end(); ?>
