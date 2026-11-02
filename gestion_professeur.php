<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

/** Valeurs autorisées pour la filière/série enseignée ('' = toutes les classes). */
function prof_filieres(): array
{
    return array_merge([''], serie_codes());
}

/** Le professeur appartient-il à l'établissement courant ? */
function prof_in_etab(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM professeurs WHERE id = ? AND etablissement_id = ?');
    $stmt->execute([$id, etab_id()]);
    return $stmt->fetch() ?: null;
}

/** Valide les champs d'un professeur ; retourne [données, erreurs]. */
function validate_professeur(PDO $pdo, array $in, int $id = 0): array
{
    $data = [
        'nom'         => trim($in['nom'] ?? ''),
        'prenoms'     => trim($in['prenoms'] ?? ''),
        'filiere'     => trim($in['filiere'] ?? ''),
        'email'       => trim($in['email'] ?? ''),
        'telephone'   => trim($in['telephone'] ?? ''),
        'identifiant' => trim($in['identifiant'] ?? ''),
    ];
    $errors = [];
    if ($data['nom'] === '' || $data['prenoms'] === '') {
        $errors[] = 'Le nom et les prénoms sont obligatoires.';
    }
    if (!in_array($data['filiere'], prof_filieres(), true)) {
        $errors[] = serie_label() . ' invalide.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse email invalide.';
    }
    if (!preg_match('/^[0-9 +().-]{6,20}$/', $data['telephone'])) {
        $errors[] = 'Numéro de téléphone invalide.';
    }
    // 3 caractères minimum pour les nouveaux identifiants (les anciens plus courts restent modifiables)
    if (!preg_match($id ? '/^[A-Za-z0-9._-]{1,50}$/' : '/^[A-Za-z0-9._-]{3,50}$/', $data['identifiant'])) {
        $errors[] = "L'identifiant doit faire 3 à 50 caractères (lettres, chiffres, . _ -).";
    }
    if (!$errors) {
        $stmt = $pdo->prepare('SELECT email, identifiant FROM professeurs WHERE (email = ? OR identifiant = ?) AND id <> ?');
        $stmt->execute([$data['email'], $data['identifiant'], $id]);
        foreach ($stmt->fetchAll() as $dup) {
            if (strcasecmp($dup['identifiant'], $data['identifiant']) === 0) {
                $errors[] = 'Cet identifiant est déjà utilisé.';
            }
            if (strcasecmp($dup['email'], $data['email']) === 0) {
                $errors[] = 'Cette adresse email est déjà utilisée.';
            }
        }
    }
    return [$data, array_unique($errors)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'create') {
            [$data, $errors] = validate_professeur($pdo, $_POST);
            if ($errors) {
                flash('error', implode(' ', $errors));
            } else {
                $password = random_password();
                $pdo->prepare('INSERT INTO professeurs (etablissement_id, nom, prenoms, filiere, email, telephone, identifiant, mot_de_passe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([etab_id(), $data['nom'], $data['prenoms'], $data['filiere'], $data['email'], $data['telephone'], $data['identifiant'], password_hash($password, PASSWORD_DEFAULT)]);
                $_SESSION['one_time_secret'] = ['who' => $data['prenoms'] . ' ' . $data['nom'], 'login' => $data['identifiant'], 'password' => $password];
                flash('success', 'Professeur ajouté avec succès.');
            }
        } elseif ($action === 'update') {
            [$data, $errors] = validate_professeur($pdo, $_POST, $id);
            if (!prof_in_etab($pdo, $id)) {
                flash('error', 'Professeur introuvable dans cet établissement.');
            } elseif ($errors) {
                flash('error', implode(' ', $errors));
            } else {
                $stmt = $pdo->prepare('UPDATE professeurs SET nom = ?, prenoms = ?, filiere = ?, email = ?, telephone = ?, identifiant = ? WHERE id = ? AND etablissement_id = ?');
                $stmt->execute([$data['nom'], $data['prenoms'], $data['filiere'], $data['email'], $data['telephone'], $data['identifiant'], $id, etab_id()]);
                flash('success', 'Professeur modifié avec succès.');
            }
        } elseif ($action === 'reset_password') {
            if ($prof = prof_in_etab($pdo, $id)) {
                $password = random_password();
                $pdo->prepare('UPDATE professeurs SET mot_de_passe = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                $_SESSION['one_time_secret'] = ['who' => $prof['prenoms'] . ' ' . $prof['nom'], 'login' => $prof['identifiant'], 'password' => $password];
                flash('success', 'Mot de passe réinitialisé.');
            } else {
                flash('error', 'Professeur introuvable.');
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM professeurs WHERE id = ? AND etablissement_id = ?');
            $stmt->execute([$id, etab_id()]);
            $stmt->rowCount() ? flash('success', 'Professeur supprimé.') : flash('error', 'Professeur introuvable dans cet établissement.');
        }
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('gestion_professeur.php');
}

$stmt = $pdo->prepare('SELECT id, nom, prenoms, filiere, email, telephone, identifiant FROM professeurs WHERE etablissement_id = ? ORDER BY nom, prenoms');
$stmt->execute([etab_id()]);
$professeurs = $stmt->fetchAll();
$secret = $_SESSION['one_time_secret'] ?? null;
unset($_SESSION['one_time_secret']);

/** Champs du formulaire (ajout ou modification). */
function prof_fields(string $prefix): void
{ ?>
    <div class="form-grid">
        <div class="form-group"><label for="<?= $prefix ?>nom">Nom</label><input type="text" id="<?= $prefix ?>nom" name="nom" required maxlength="100"></div>
        <div class="form-group"><label for="<?= $prefix ?>prenoms">Prénoms</label><input type="text" id="<?= $prefix ?>prenoms" name="prenoms" required maxlength="100"></div>
        <div class="form-group">
            <label for="<?= $prefix ?>filiere"><?= e(serie_label()) ?> enseignée</label>
            <select id="<?= $prefix ?>filiere" name="filiere">
                <option value="">Toutes les classes</option>
                <?php foreach (series_libelles() as $f => $lib): ?><option value="<?= e($f) ?>" title="<?= e($lib) ?>"><?= e($f) ?></option><?php endforeach; ?>
            </select>
            <p class="form-help">« Toutes les classes » : le professeur peut faire l'appel dans toutes les classes de l'établissement.</p>
        </div>
        <div class="form-group"><label for="<?= $prefix ?>identifiant">Identifiant de connexion</label><input type="text" id="<?= $prefix ?>identifiant" name="identifiant" required pattern="[A-Za-z0-9._\-]{1,50}"></div>
        <div class="form-group"><label for="<?= $prefix ?>email">Email</label><input type="email" id="<?= $prefix ?>email" name="email" required maxlength="100"></div>
        <div class="form-group"><label for="<?= $prefix ?>telephone">Téléphone</label><input type="tel" id="<?= $prefix ?>telephone" name="telephone" required maxlength="20"></div>
    </div>
<?php }

layout_start([
    'title'    => 'Professeurs',
    'subtitle' => count($professeurs) . ' professeur(s) enregistré(s)',
    'actions'  => '<button type="button" class="btn btn-primary" data-modal-open="addModal">' . icon('plus', 16) . ' Ajouter un professeur</button>',
]);
?>
<?php if ($secret): ?>
<div class="alert alert-info" role="status">
    <?= icon('key', 18) ?>
    <div>
        Identifiants de <strong><?= e($secret['who']) ?></strong> — identifiant <span class="code"><?= e($secret['login']) ?></span>,
        mot de passe <span class="code"><?= e($secret['password']) ?></span>
        <button type="button" class="btn btn-sm btn-outline" data-copy="<?= e($secret['password']) ?>">Copier</button>
        <div class="form-help">Notez-le maintenant et transmettez-le au professeur : il ne sera plus affiché.</div>
    </div>
</div>
<?php endif; ?>

<section class="card">
    <?php if (!$professeurs): ?>
        <div class="empty-state"><?= icon('teacher', 40) ?><p>Aucun professeur enregistré pour l'instant.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nom</th><th>Prénoms</th><th><?= e(serie_label()) ?></th><th>Identifiant</th><th>Email</th><th>Téléphone</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($professeurs as $prof): ?>
                <tr>
                    <td class="fw-bold"><?= e(mb_strtoupper($prof['nom'], 'UTF-8')) ?></td>
                    <td><?= e($prof['prenoms']) ?></td>
                    <td><?= $prof['filiere'] !== '' ? '<span class="badge badge-primary">' . e($prof['filiere']) . '</span>' : '<span class="text-muted">Toutes</span>' ?></td>
                    <td class="code"><?= e($prof['identifiant']) ?></td>
                    <td><?= e($prof['email']) ?></td>
                    <td><?= e($prof['telephone']) ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-sm btn-outline js-edit" data-prof="<?= e(json_encode($prof)) ?>"><?= icon('edit', 16) ?> Modifier</button>
                        <form method="POST" class="inline" data-confirm="Générer un nouveau mot de passe pour <?= e($prof['prenoms'] . ' ' . $prof['nom']) ?> ? L'ancien ne fonctionnera plus." data-confirm-label="Réinitialiser">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="id" value="<?= (int)$prof['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"><?= icon('key', 16) ?> Mot de passe</button>
                        </form>
                        <form method="POST" class="inline" data-confirm="Supprimer définitivement <?= e($prof['prenoms'] . ' ' . $prof['nom']) ?> ? Ses saisies d'absences seront aussi supprimées." data-confirm-label="Supprimer">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$prof['id'] ?>">
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

<div class="modal" id="addModal">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-header"><h2>Ajouter un professeur</h2><button type="button" class="modal-close" data-modal-close aria-label="Fermer"><?= icon('x') ?></button></div>
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <?php prof_fields('add_'); ?>
                <p class="form-help mt-2">Un mot de passe sécurisé sera généré automatiquement et affiché une seule fois.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-modal-close>Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
</div>

<div class="modal" id="editModal">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-header"><h2>Modifier le professeur</h2><button type="button" class="modal-close" data-modal-close aria-label="Fermer"><?= icon('x') ?></button></div>
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <?php prof_fields('edit_'); ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-modal-close>Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
</div>
<?php
layout_end(['scripts' => <<<'JS'
<script>
document.querySelectorAll('.js-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var p = JSON.parse(btn.getAttribute('data-prof'));
        ['id', 'nom', 'prenoms', 'filiere', 'email', 'telephone', 'identifiant'].forEach(function (k) {
            document.getElementById('edit_' + k).value = p[k] || '';
        });
        App.openModal('editModal');
    });
});
</script>
JS]);
