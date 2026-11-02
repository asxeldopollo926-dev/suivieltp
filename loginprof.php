<?php
require_once 'config.php';

if (!empty($_SESSION['professeur_id'])) {
    redirect('espaceprof.php');
}

$error = '';
$identifiant = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = trim($_POST['identifiant'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        if (login_is_blocked($pdo, 'prof')) {
            $error = 'Trop de tentatives échouées. Réessayez dans ' . LOGIN_WINDOW_MINUTES . ' minutes.';
        } elseif ($identifiant === '' || $password === '') {
            $error = 'Veuillez remplir tous les champs.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM professeurs WHERE identifiant = ?');
            $stmt->execute([$identifiant]);
            $prof = $stmt->fetch();

            if ($prof && password_verify($password, $prof['mot_de_passe'])) {
                login_clear_failures($pdo, 'prof');
                session_regenerate_id(true);
                $_SESSION['professeur_id'] = (int)$prof['id'];
                $_SESSION['professeur_nom'] = $prof['nom'];
                $_SESSION['professeur_prenom'] = $prof['prenoms'];
                redirect('espaceprof.php');
            }
            login_record_failure($pdo, 'prof');
            $error = 'Identifiant ou mot de passe incorrect.';
        }
    } catch (Throwable $e) {
        $error = log_error($e, 'Erreur système, veuillez réessayer.');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace professeurs - SuiviLTP</title>
    <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=2">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img class="auth-logo" src="assets/logo.svg" alt="SuiviLTP">
        <h1>Espace professeurs</h1>
        <p class="auth-subtitle">Gérez les absences de vos élèves</p>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="loginprof.php">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="identifiant">Identifiant</label>
                <input type="text" id="identifiant" name="identifiant" value="<?= e($identifiant) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
        </form>
        <p class="form-help text-center mt-2" style="text-align:center">Mot de passe oublié ? Adressez-vous au secrétariat.</p>
        <a href="bienvenue.html" class="back-link">← Retour à l'accueil</a>
    </main>
</body>
</html>
