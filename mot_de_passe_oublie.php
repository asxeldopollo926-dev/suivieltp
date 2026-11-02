<?php
/**
 * Demande de réinitialisation du mot de passe (personnel : ?type=admin, professeurs : ?type=prof).
 * Un lien valable 1 heure est envoyé à l'adresse e-mail du compte. Le message affiché est toujours
 * le même, que le compte existe ou non (aucune information n'est divulguée).
 */
require_once 'auth_functions.php';
require_once 'includes/notify.php';

const RESET_TYPES = [
    'admin' => ['titre' => 'Administration',      'login' => 'loginadmin.php', 'champ' => "Nom d'utilisateur ou e-mail"],
    'prof'  => ['titre' => 'Espace professeurs',  'login' => 'loginprof.php',  'champ' => 'Identifiant ou e-mail'],
];

$type = ($_GET['type'] ?? $_POST['type'] ?? '') === 'prof' ? 'prof' : 'admin';
$conf = RESET_TYPES[$type];
$done = false;
$error = '';
$saisie = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saisie = trim($_POST['login'] ?? '');
    try {
        if (login_is_blocked($pdo, 'reset')) {
            $error = 'Trop de demandes. Réessayez dans ' . LOGIN_WINDOW_MINUTES . ' minutes.';
        } elseif ($saisie === '') {
            $error = 'Veuillez saisir votre identifiant ou votre e-mail.';
        } else {
            // Chaque demande compte, qu'elle aboutisse ou non (anti-abus).
            login_record_failure($pdo, 'reset');

            if ($type === 'admin') {
                $stmt = $pdo->prepare('SELECT id, username AS login, email, etablissement_id FROM admin WHERE username = ? OR (email <> \'\' AND email = ?) LIMIT 2');
            } else {
                $stmt = $pdo->prepare('SELECT id, identifiant AS login, email, etablissement_id FROM professeurs WHERE identifiant = ? OR (email <> \'\' AND email = ?) LIMIT 2');
            }
            $stmt->execute([$saisie, $saisie]);
            $accounts = $stmt->fetchAll();

            // Une seule correspondance exigée (un e-mail partagé par deux comptes est ambigu).
            if (count($accounts) === 1 && filter_var($accounts[0]['email'], FILTER_VALIDATE_EMAIL)) {
                $account = $accounts[0];
                $token = bin2hex(random_bytes(32));
                $pdo->prepare('DELETE FROM password_resets WHERE user_type = ? AND user_id = ? AND used_at IS NULL')
                    ->execute([$type, $account['id']]);
                $pdo->prepare('INSERT INTO password_resets (user_type, user_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
                    ->execute([$type, $account['id'], hash('sha256', $token)]);

                $lien = app_url('nouveau_mot_de_passe.php?type=' . $type . '&token=' . $token);
                $etab = $account['etablissement_id'] !== null ? etablissement((int)$account['etablissement_id']) : null;
                $corps = "Bonjour,\n\n"
                    . "Une demande de réinitialisation du mot de passe a été faite pour le compte « {$account['login']} » ({$conf['titre']}).\n\n"
                    . "Pour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure, utilisable une seule fois) :\n$lien\n\n"
                    . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.\n\n"
                    . "—\n" . ($etab['nom'] ?? 'SuiviLTP');
                $id = notif_queue('email', $account['email'], 'Réinitialisation de votre mot de passe', $corps,
                    ['evenement' => 'reset_mdp', 'etablissement_id' => $etab['id'] ?? null]);
                notif_send($id);
            }
            $done = true;
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
    <title>Mot de passe oublié - SuiviLTP</title>
    <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=3">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img class="auth-logo" src="assets/logo.svg" alt="SuiviLTP">
        <h1>Mot de passe oublié</h1>
        <p class="auth-subtitle"><?= e($conf['titre']) ?></p>

        <?php if ($done): ?>
            <div class="alert alert-success" role="status">
                <div>
                    Si un compte correspond à « <?= e($saisie) ?> » et possède une adresse e-mail, un lien de réinitialisation
                    vient d'y être envoyé. Il est valable 1 heure.
                    <?php if (!smtp_config()['enabled']): ?>
                        <br><strong>L'envoi d'e-mails n'est pas encore configuré : contactez l'administrateur.</strong>
                    <?php endif; ?>
                </div>
            </div>
            <a class="btn btn-primary btn-block" href="<?= e($conf['login']) ?>">Retour à la connexion</a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="POST" action="mot_de_passe_oublie.php">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <div class="form-group">
                    <label for="login"><?= e($conf['champ']) ?></label>
                    <input type="text" id="login" name="login" value="<?= e($saisie) ?>" autocomplete="username" required autofocus>
                    <p class="form-help">Vous recevrez un lien par e-mail pour choisir un nouveau mot de passe.</p>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Envoyer le lien</button>
            </form>
            <p class="form-help mt-2" style="text-align:center">Pas d'adresse e-mail enregistrée ? Demandez une réinitialisation <?= $type === 'prof' ? 'au secrétariat' : "à l'administrateur" ?>.</p>
            <a href="<?= e($conf['login']) ?>" class="back-link">← Retour à la connexion</a>
        <?php endif; ?>
    </main>
</body>
</html>
