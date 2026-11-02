<?php
require_once 'config.php';

if (!empty($_SESSION['eleve_id'])) {
    redirect('parents.php');
}

// L'ancien cookie "remember_me" contenait le code secret en clair : on le supprime.
if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 3600, '/');
}

$error = '';
$idEleve = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idEleve = trim($_POST['id_eleve'] ?? '');
    $code = strtoupper(trim($_POST['code_secret'] ?? ''));

    try {
        if (login_is_blocked($pdo, 'parent')) {
            $error = 'Trop de tentatives échouées. Réessayez dans ' . LOGIN_WINDOW_MINUTES . ' minutes.';
        } elseif (!ctype_digit($idEleve) || $code === '') {
            $error = "Veuillez saisir l'ID (numéro) de votre enfant et son code secret.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM eleves WHERE id = ? AND code_secret <> ''");
            $stmt->execute([(int)$idEleve]);
            $eleve = $stmt->fetch();

            if ($eleve && hash_equals(strtoupper($eleve['code_secret']), $code)) {
                login_clear_failures($pdo, 'parent');
                session_regenerate_id(true);
                $_SESSION['eleve_id'] = (int)$eleve['id'];
                $_SESSION['eleve_nom'] = $eleve['nom'];
                $_SESSION['eleve_prenom'] = $eleve['prenoms'];
                redirect('parents.php');
            }
            login_record_failure($pdo, 'parent');
            $error = 'ID ou code secret incorrect.';
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
    <title>Espace parents - SuiviLTP</title>
    <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=2">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img class="auth-logo" src="assets/logo.svg" alt="SuiviLTP">
        <h1>Espace parents</h1>
        <p class="auth-subtitle">Suivez la scolarité de votre enfant</p>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login_parent.php">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="id_eleve">ID de votre enfant</label>
                <input type="text" id="id_eleve" name="id_eleve" value="<?= e($idEleve) ?>" inputmode="numeric" pattern="[0-9]*" required autofocus>
            </div>
            <div class="form-group">
                <label for="code_secret">Code secret</label>
                <input type="password" id="code_secret" name="code_secret" autocomplete="off" maxlength="10" style="text-transform:uppercase" required>
                <p class="form-help">Le code à 6 caractères vous a été remis par l'établissement.</p>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
        </form>
        <a href="bienvenue.html" class="back-link">← Retour à l'accueil</a>
    </main>
</body>
</html>
