<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    try {
        $stmt = $pdo->prepare('SELECT password FROM admin WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($current, $hash)) {
            flash('error', 'Le mot de passe actuel est incorrect.');
        } elseif (mb_strlen($new) < 8) {
            flash('error', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
        } elseif ($new !== $confirm) {
            flash('error', 'Les deux nouveaux mots de passe ne correspondent pas.');
        } elseif ($new === $current) {
            flash('error', "Le nouveau mot de passe doit être différent de l'actuel.");
        } else {
            $pdo->prepare('UPDATE admin SET password = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
            session_regenerate_id(true);
            flash('success', 'Votre mot de passe a été modifié.');
        }
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('parametres.php');
}

$stmt = $pdo->prepare('SELECT username, role, created_at FROM admin WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$account = $stmt->fetch();
$roleInfo = getCurrentRoleInfo();

layout_start(['title' => 'Paramètres', 'subtitle' => 'Votre compte et votre mot de passe']);
?>
<div class="grid grid-2">
    <section class="card">
        <div class="card-header"><h2 class="card-title"><?= icon('user') ?> Mon compte</h2></div>
        <dl class="info-list">
            <div><dt>Nom d'utilisateur</dt><dd><?= e($account['username']) ?></dd></div>
            <div><dt>Rôle</dt><dd><span class="badge badge-primary"><?= e($roleInfo['name'] ?? '') ?></span></dd></div>
            <div><dt>Compte créé le</dt><dd><?= e(date_fr($account['created_at'])) ?></dd></div>
        </dl>
        <p class="text-muted mt-2 mb-0"><?= e($roleInfo['description'] ?? '') ?></p>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="card-title"><?= icon('lock') ?> Changer le mot de passe</h2></div>
        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label for="current_password">Mot de passe actuel</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label for="new_password">Nouveau mot de passe</label>
                <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required>
                <p class="form-help">8 caractères minimum. Mélangez lettres, chiffres et symboles.</p>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirmer le nouveau mot de passe</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </section>
</div>
<?php layout_end(); ?>
