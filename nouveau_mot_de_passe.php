<?php
/** Choix d'un nouveau mot de passe à partir du lien reçu par e-mail (mot_de_passe_oublie.php). */
require_once 'auth_functions.php';

$type = ($_GET['type'] ?? $_POST['type'] ?? '') === 'prof' ? 'prof' : 'admin';
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$loginPage = $type === 'prof' ? 'loginprof.php' : 'loginadmin.php';
$error = '';
$success = false;

/** Jeton valide (non utilisé, non expiré) ou null. */
function find_reset(PDO $pdo, string $type, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token_hash = ? AND user_type = ? AND used_at IS NULL AND expires_at > NOW()');
    $stmt->execute([hash('sha256', $token), $type]);
    return $stmt->fetch() ?: null;
}

try {
    $reset = find_reset($pdo, $type, $token);

    if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (mb_strlen($new) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($new !== $confirm) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            $sql = $type === 'prof'
                ? 'UPDATE professeurs SET mot_de_passe = ? WHERE id = ?'
                : 'UPDATE admin SET password = ? WHERE id = ?';
            $pdo->prepare($sql)->execute([$hash, $reset['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_type = ? AND user_id = ? AND id <> ?')
                ->execute([$type, $reset['user_id'], $reset['id']]);
            $pdo->commit();
            login_clear_failures($pdo, $type === 'prof' ? 'prof' : 'admin');
            $success = true;
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $reset = $reset ?? null;
    $error = log_error($e, 'Erreur système, veuillez réessayer.');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Nouveau mot de passe - SuiviLTP</title>
    <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=3">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img class="auth-logo" src="assets/logo.svg" alt="SuiviLTP">
        <h1>Nouveau mot de passe</h1>
        <p class="auth-subtitle"><?= $type === 'prof' ? 'Espace professeurs' : 'Administration' ?></p>

        <?php if ($success): ?>
            <div class="alert alert-success" role="status">Votre mot de passe a été modifié. Vous pouvez maintenant vous connecter.</div>
            <a class="btn btn-primary btn-block" href="<?= e($loginPage) ?>">Se connecter</a>
        <?php elseif (!$reset): ?>
            <div class="alert alert-error" role="alert">Ce lien n'est pas valide : il a expiré (1 heure) ou a déjà été utilisé.</div>
            <a class="btn btn-primary btn-block" href="mot_de_passe_oublie.php?type=<?= e($type) ?>">Faire une nouvelle demande</a>
            <a href="<?= e($loginPage) ?>" class="back-link">← Retour à la connexion</a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="POST" action="nouveau_mot_de_passe.php" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="form-group">
                    <label for="new_password">Nouveau mot de passe</label>
                    <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required autofocus>
                    <p class="form-help">8 caractères minimum. Mélangez lettres, chiffres et symboles.</p>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirmer le mot de passe</label>
                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Enregistrer</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
