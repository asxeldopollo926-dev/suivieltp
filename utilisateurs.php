<?php
/** Gestion des comptes du personnel administratif (réservé au rôle Administrateur). */
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

function count_admins(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM admin WHERE role = 'admin'")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $me = (int)$_SESSION['admin_id'];

    try {
        $target = null;
        if ($id) {
            $stmt = $pdo->prepare('SELECT id, username, role FROM admin WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch() ?: null;
        }

        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $role = $_POST['role'] ?? '';
            $exists = $pdo->prepare('SELECT COUNT(*) FROM admin WHERE username = ?');
            $exists->execute([$username]);
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                flash('error', "Le nom d'utilisateur doit faire 3 à 50 caractères (lettres, chiffres, . _ -).");
            } elseif (!isset(ROLES[$role])) {
                flash('error', 'Rôle invalide.');
            } elseif ($exists->fetchColumn() > 0) {
                flash('error', "Ce nom d'utilisateur existe déjà.");
            } else {
                $password = random_password();
                $pdo->prepare('INSERT INTO admin (username, password, role) VALUES (?, ?, ?)')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
                $_SESSION['one_time_secret'] = ['login' => $username, 'password' => $password];
                flash('success', 'Compte « ' . $username . ' » créé (' . ROLES[$role]['name'] . ').');
            }
        } elseif (!$target) {
            flash('error', 'Compte introuvable.');
        } elseif ($action === 'change_role') {
            $role = $_POST['role'] ?? '';
            if (!isset(ROLES[$role])) {
                flash('error', 'Rôle invalide.');
            } elseif ($target['role'] === 'admin' && $role !== 'admin' && count_admins($pdo) <= 1) {
                flash('error', 'Impossible : il doit rester au moins un administrateur.');
            } else {
                $pdo->prepare('UPDATE admin SET role = ? WHERE id = ?')->execute([$role, $id]);
                flash('success', 'Rôle de « ' . $target['username'] . ' » : ' . ROLES[$role]['name'] . '.');
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
                flash('success', 'Compte « ' . $target['username'] . ' » supprimé.');
            }
        }
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('utilisateurs.php');
}

$users = $pdo->query("SELECT id, username, role, created_at FROM admin ORDER BY FIELD(role, 'admin', 'censeur', 'comptable', 'secretaire'), username")->fetchAll();
$secret = $_SESSION['one_time_secret'] ?? null;
unset($_SESSION['one_time_secret']);
$roleBadge = ['admin' => 'badge-danger', 'censeur' => 'badge-primary', 'comptable' => 'badge-warning', 'secretaire' => 'badge-info'];

layout_start([
    'title'    => 'Utilisateurs',
    'subtitle' => 'Comptes du personnel et rôles d\'accès',
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
            <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Créé le</th><th>Changer le rôle</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $isMe = (int)$u['id'] === (int)$_SESSION['admin_id']; ?>
                <tr>
                    <td class="fw-bold"><?= e($u['username']) ?><?php if ($isMe): ?> <span class="badge">vous</span><?php endif; ?></td>
                    <td><span class="badge <?= $roleBadge[$u['role']] ?? '' ?>"><?= e(ROLES[$u['role']]['name'] ?? $u['role']) ?></span></td>
                    <td><?= e(date_fr($u['created_at'])) ?></td>
                    <td>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="change_role">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <select name="role" class="input" data-autosubmit aria-label="Rôle de <?= e($u['username']) ?>" style="width:auto;padding:6px 10px">
                                <?php foreach (ROLES as $key => $r): ?>
                                    <option value="<?= e($key) ?>" <?= $u['role'] === $key ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td class="actions">
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
                <div class="form-group">
                    <label for="role">Rôle</label>
                    <select id="role" name="role" required>
                        <?php foreach (ROLES as $key => $r): ?>
                            <option value="<?= e($key) ?>" <?= $key === 'secretaire' ? 'selected' : '' ?>><?= e($r['name']) ?> — <?= e($r['description']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="form-help mb-0">Un mot de passe sécurisé sera généré et affiché une seule fois.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-modal-close>Annuler</button><button type="submit" class="btn btn-primary">Créer le compte</button></div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
