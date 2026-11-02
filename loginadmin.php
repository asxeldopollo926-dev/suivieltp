<?php
require_once 'auth_functions.php';

if (isLoggedIn()) {
    redirect('admin.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        if (login_is_blocked($pdo, 'admin')) {
            $error = 'Trop de tentatives échouées. Réessayez dans ' . LOGIN_WINDOW_MINUTES . ' minutes.';
        } elseif ($username === '' || $password === '') {
            $error = 'Veuillez remplir tous les champs.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admin WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && isset(ROLES[$user['role']]) && password_verify($password, $user['password'])) {
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE admin SET password = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }
                login_clear_failures($pdo, 'admin');
                login_admin($user);
                redirect('admin.php');
            }
            login_record_failure($pdo, 'admin');
            $error = 'Identifiants incorrects.';
        }
    } catch (Throwable $e) {
        $error = log_error($e, 'Erreur de connexion. Veuillez réessayer.');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion administration - SuiviLTP</title>
    <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=2">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img class="auth-logo" src="assets/logo.svg" alt="SuiviLTP">
        <h1>Administration</h1>
        <p class="auth-subtitle">Connectez-vous à votre espace de gestion</p>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="loginadmin.php" autocomplete="on">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
        </form>
        <a href="bienvenue.html" class="back-link">← Retour à l'accueil</a>
    </main>
</body>
</html>
