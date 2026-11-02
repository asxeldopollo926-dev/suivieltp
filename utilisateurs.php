<?php
/** Gestion des comptes du personnel administratif (réservé au rôle Administrateur). */
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

function count_admins(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM admin WHERE role = 'admin'")->fetchColumn();
}

/**
 * Valide e-mail, rôle et établissement saisis. Retourne [email, role, etablissement_id|null, erreurs].
 * Administrateur => tous les établissements (NULL) ; autres rôles => un établissement obligatoire.
 */
function validate_account(array $in): array
{
    $errors = [];
    $email = trim($in['email'] ?? '');
    $role = $in['role'] ?? '';
    $etabId = (int)($in['etablissement_id'] ?? 0);

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse e-mail invalide.';
    }
    if (!isset(ROLES[$role])) {
        $errors[] = 'Rôle invalide.';
    }
    if ($role === 'admin') {
        $etabId = null;
    } elseif (!$etabId || !etablissement($etabId)) {
        $errors[] = 'Choisissez l\'établissement auquel ce compte est rattaché.';
    }
    return [$email, $role, $etabId, $errors];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $me = (int)$_SESSION['admin_id'];

    try {
        $target = null;
        if ($id) {
            $stmt = $pdo->prepare('SELECT id, username, role, etablissement_id FROM admin WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch() ?: null;
        }

        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            [$email, $role, $etabId, $errors] = validate_account($_POST);
            $exists = $pdo->prepare('SELECT COUNT(*) FROM admin WHERE username = ?');
            $exists->execute([$username]);
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                array_unshift($errors, "Le nom d'utilisateur doit faire 3 à 50 caractères (lettres, chiffres, . _ -).");
            } elseif ($exists->fetchColumn() > 0) {
                array_unshift($errors, "Ce nom d'utilisateur existe déjà.");
            }
            if ($errors) {
                flash('error', implode(' ', $errors));
            } else {
                $password = random_password();
                $pdo->prepare('INSERT INTO admin (username, email, password, role, etablissement_id) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $etabId]);
                $_SESSION['one_time_secret'] = ['login' => $username, 'password' => $password];
                flash('success', 'Compte « ' . $username . ' » créé (' . ROLES[$role]['name'] . ').');
            }
        } elseif (!$target) {
            flash('error', 'Compte introuvable.');
        } elseif ($action === 'update') {
            [$email, $role, $etabId, $errors] = validate_account($_POST);
            if (!$errors && $target['role'] === 'admin' && $role !== 'admin' && count_admins($pdo) <= 1) {
                $errors[] = 'Impossible : il doit rester au moins un administrateur.';
            }
            if (!$errors && $id === $me && $role !== 'admin') {
                $errors[] = 'Vous ne pouvez pas retirer votre propre rôle d\'administrateur.';
            }
            if ($errors) {
                flash('error', implode(' ', $errors));
            } else {
                $pdo->prepare('UPDATE admin SET email = ?, role = ?, etablissement_id = ? WHERE id = ?')
                    ->execute([$email, $role, $etabId, $id]);
                flash('success', 'Compte « ' . $target['username'] . ' » mis à jour (' . ROLES[$role]['name'] . ').');
            }
        } elseif ($action === 'reset_password') {
            $password = random_password();
            $pdo->prepare('UPDATE admin SET password = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            $_SESSION['one_time_secret'] = ['login' => $target['username'], 'password' => $password];
            flash('success', 'Mot de passe de « ' . $target['username'] . ' » réinitialisé.');
        } elseif ($action === 'delete') {
            if ($id === $me) {
                flash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            } elseif ($target['role'] === 'admin' && count_admins($pdo) <= 1) {
                flash('error', 'Impossible : il doit rester au moins un administrateur.');
            } else {
                $pdo->prepare('DELETE FROM admin WHERE id = ?')->execute([$id]);
                $pdo->prepare("DELETE FROM password_resets WHERE user_type = 'admin' AND user_id = ?")->execute([$id]);
                flash('success', 'Compte « ' . $target['username'] . ' » supprimé.');
            }
        }
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('utilisateurs.php');
}

$users = $pdo->query("SELECT id, username, email, role, etablissement_id, created_at FROM admin
                      ORDER BY FIELD(role, 'admin', 'censeur', 'comptable', 'secretaire'), username")->fetchAll();
$etabs = etablissements_list();
$secret = $_SESSION['one_time_secret'] ?? null;
unset($_SESSION['one_time_secret']);
$roleBadge = ['admin' => 'badge-danger', 'censeur' => 'badge-primary', 'comptable' => 'badge-warning', 'secretaire' => 'badge-info'];

/** Champs e-mail / rôle / établissement (création et modification). */
function account_fields(string $prefix, array $etabs): void
{ ?>
    <div class="form-group">
        <label for="<?= $prefix ?>email">E-mail <span class="text-muted">(facultatif)</span></label>
        <input type="email" id="<?= $prefix ?>email" name="email" maxlength="150" autocomplete="off">
        <p class="form-help">Permet à la personne d'utiliser « Mot de passe oublié ».</p>
    </div>
    <div class="form-group">
        <label for="<?= $prefix ?>role">Rôle</label>
        <select id="<?= $prefix ?>role" name="role" required data-role-select>
            <?php foreach (ROLES as $key => $r): ?>
                <option value="<?= e($key) ?>" <?= $key === 'secretaire' ? 'selected' : '' ?>><?= e($r['name']) ?> — <?= e($r['description']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" data-etab-group>
        <label for="<?= $prefix ?>etablissement_id">Établissement</label>
        <select id="<?= $prefix ?>etablissement_id" name="etablissement_id">
            <?php foreach ($etabs as $et): ?>
                <option value="<?= (int)$et['id'] ?>" <?= (int)$et['id'] === etab_id() ? 'selected' : '' ?>><?= e(etab_type_name($et['type']) . ' — ' . $et['nom']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="form-help">Le compte ne pourra se connecter qu'à cet établissement. Un administrateur a accès à tous.</p>
    </div>
<?php }

layout_start([
    'title'    => 'Utilisateurs',
    'subtitle' => 'Comptes du personnel, rôles et établissements de rattachement',
    'actions'  => '<button type="button" class="btn btn-primary" data-modal-open="addUser">' . icon('plus', 16) . ' Nouveau compte</button>',
]);
?>
<?php if ($secret): ?>
<div class="alert alert-info" role="status">
    <?= icon('key', 18) ?>
    <div>
        Identifiants de connexion : utilisateur <span class="code"><?= e($secret['login']) ?></span>,
        mot de passe <span class="code"><?= e($secret['password']) ?></span>
        <button type="button" class="btn btn-sm btn-outline" data-copy="<?= e($secret['password']) ?>">Copier</button>
        <div class="form-help">Transmettez-les à la personne concernée : ce mot de passe ne sera plus affiché. Elle pourra le changer dans Paramètres.</div>
    </div>
</div>
<?php endif; ?>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Utilisateur</th><th>E-mail</th><th>Rôle</th><th>Établissement</th><th>Créé le</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u):
                $isMe = (int)$u['id'] === (int)$_SESSION['admin_id'];
                $et = $u['etablissement_id'] !== null ? etablissement((int)$u['etablissement_id']) : null; ?>
                <tr>
                    <td class="fw-bold"><?= e($u['username']) ?><?php if ($isMe): ?> <span class="badge">vous</span><?php endif; ?></td>
                    <td><?= $u['email'] !== '' ? e($u['email']) : '<span class="text-muted">—</span>' ?></td>
                    <td><span class="badge <?= $roleBadge[$u['role']] ?? '' ?>"><?= e(ROLES[$u['role']]['name'] ?? $u['role']) ?></span></td>
                    <td><?= $et ? e(etab_type_name($et['type']) . ' — ' . $et['nom']) : '<span class="badge badge-success">Tous</span>' ?></td>
                    <td><?= e(date_fr($u['created_at'])) ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-sm btn-outline js-edit"
                                data-user="<?= e(json_encode(['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'etablissement_id' => $u['etablissement_id']])) ?>">
                            <?= icon('edit', 16) ?> Modifier
                        </button>
                        <form method="POST" class="inline" data-confirm="Générer un nouveau mot de passe pour « <?= e($u['username']) ?> » ? L'ancien ne fonctionnera plus." data-confirm-label="Réinitialiser">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"><?= icon('key', 16) ?> Mot de passe</button>
                        </form>
                        <?php if (!$isMe): ?>
                        <form method="POST" class="inline" data-confirm="Supprimer définitivement le compte « <?= e($u['username']) ?> » ?" data-confirm-label="Supprimer">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger-soft"><?= icon('trash', 16) ?> Supprimer</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title"><?= icon('shield') ?> Rôles disponibles</h2></div>
    <dl class="info-list">
        <?php foreach (ROLES as $key => $r): ?>
            <div><dt><span class="badge <?= $roleBadge[$key] ?? '' ?>"><?= e($r['name']) ?></span></dt><dd class="text-muted" style="font-weight:400"><?= e($r['description']) ?></dd></div>
        <?php endforeach; ?>
    </dl>
</section>

<div class="modal" id="addUser">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-header"><h2>Nouveau compte</h2><button type="button" class="modal-close" data-modal-close aria-label="Fermer"><?= icon('x') ?></button></div>
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur</label>
                    <input type="text" id="username" name="username" required pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="off">
                    <p class="form-help">3 à 50 caractères : lettres, chiffres, point, tiret ou souligné.</p>
                </div>
                <?php account_fields('add_', $etabs); ?>
                <p class="form-help mb-0">Un mot de passe sécurisé sera généré et affiché une seule fois.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-modal-close>Annuler</button><button type="submit" class="btn btn-primary">Créer le compte</button></div>
        </form>
    </div>
</div>

<div class="modal" id="editUser">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-header"><h2>Modifier « <span id="edit_title"></span> »</h2><button type="button" class="modal-close" data-modal-close aria-label="Fermer"><?= icon('x') ?></button></div>
            <div class="modal-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <?php account_fields('edit_', $etabs); ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-modal-close>Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
</div>
<?php
$currentEtab = (int)etab_id();
layout_end(['scripts' => <<<JS
<script>
// Le choix d'établissement n'a de sens que pour les rôles autres qu'Administrateur.
function syncEtab(form) {
    var role = form.querySelector('[data-role-select]');
    var group = form.querySelector('[data-etab-group]');
    var isAdmin = role.value === 'admin';
    group.style.display = isAdmin ? 'none' : '';
    group.querySelector('select').disabled = isAdmin;
}
document.querySelectorAll('[data-role-select]').forEach(function (sel) {
    sel.addEventListener('change', function () { syncEtab(sel.form); });
    syncEtab(sel.form);
});
document.querySelectorAll('.js-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var u = JSON.parse(btn.getAttribute('data-user'));
        document.getElementById('edit_id').value = u.id;
        document.getElementById('edit_title').textContent = u.username;
        document.getElementById('edit_email').value = u.email || '';
        document.getElementById('edit_role').value = u.role;
        document.getElementById('edit_etablissement_id').value = u.etablissement_id || '{$currentEtab}';
        syncEtab(document.getElementById('edit_role').form);
        App.openModal('editUser');
    });
});
</script>
JS]);
