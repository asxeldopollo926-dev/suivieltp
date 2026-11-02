<?php
require_once 'auth_functions.php';
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accès non autorisé - SuiviLTP</title>
    <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=2">
</head>
<body class="auth-page">
    <main class="auth-card" style="text-align:center">
        <img class="auth-logo" src="assets/logo.svg" alt="SuiviLTP">
        <h1>Accès non autorisé</h1>
        <p class="auth-subtitle">
            <?php if (isLoggedIn()): ?>
                Votre rôle (<strong><?= e(getCurrentRoleInfo()['name'] ?? '') ?></strong>) ne permet pas d'ouvrir cette page.
            <?php else: ?>
                Vous devez être connecté pour accéder à cette page.
            <?php endif; ?>
        </p>
        <div class="btn-group" style="justify-content:center">
            <?php if (isLoggedIn()): ?>
                <a href="admin.php" class="btn btn-primary">Tableau de bord</a>
                <a href="logout.php" class="btn btn-secondary">Changer de compte</a>
            <?php else: ?>
                <a href="loginadmin.php" class="btn btn-primary">Se connecter</a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
