<?php
require_once 'auth_functions.php';

if (isLoggedIn()) {
    redirect('admin.php');
}

$etabs = etablissements_list();
$byType = ['lycee' => [], 'college' => []];
foreach ($etabs as $et) {
    $byType[$et['type']][] = $et;
}

$error = '';
$username = '';
$type = ($_POST['type'] ?? $_GET['type'] ?? '') === 'college' ? 'college' : 'lycee';
if (!$byType[$type] && $etabs) {
    $type = $etabs[0]['type'];
}
$etabId = (int)($_POST['etab_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    // Si un seul établissement de ce type, il est choisi d'office.
    if (!$etabId && count($byType[$type]) === 1) {
        $etabId = (int)$byType[$type][0]['id'];
    }
    $etab = $etabId ? etablissement($etabId) : null;

    try {
        if (login_is_blocked($pdo, 'admin')) {
            $error = 'Trop de tentatives échouées. Réessayez dans ' . LOGIN_WINDOW_MINUTES . ' minutes.';
        } elseif ($username === '' || $password === '') {
            $error = 'Veuillez remplir tous les champs.';
        } elseif (!$etab || $etab['type'] !== $type) {
            $error = "Veuillez choisir l'établissement.";
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admin WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && isset(ROLES[$user['role']]) && password_verify($password, $user['password'])) {
                if (!user_can_use_etab($user, $etabId)) {
                    $own = etablissement((int)$user['etablissement_id']);
                    $error = 'Ce compte est rattaché à : ' . ($own ? etab_type_name($own['type']) . ' — ' . $own['nom'] : 'un autre établissement') . '.';
                } else {
                    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                        $pdo->prepare('UPDATE admin SET password = ? WHERE id = ?')
                            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                    }
                    login_clear_failures($pdo, 'admin');
                    login_admin($user, $etabId);
                    redirect('admin.php');
                }
            } else {
                login_record_failure($pdo, 'admin');
                $error = 'Identifiants incorrects.';
            }
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
    <link rel="stylesheet" href="assets/css/app.css?v=3">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img class="auth-logo" src="assets/logo.svg" alt="SuiviLTP">
        <h1>Administration</h1>
        <p class="auth-subtitle">Connectez-vous à votre espace de gestion</p>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!$etabs): ?>
            <div class="alert alert-warning">Aucun établissement n'est configuré. Lancez <span class="code">php install/migrate.php</span>.</div>
        <?php else: ?>
        <form method="POST" action="loginadmin.php" autocomplete="on">
            <?= csrf_field() ?>
            <div class="segmented" role="radiogroup" aria-label="Type d'établissement">
                <?php foreach (['lycee' => 'Lycée', 'college' => 'Collège'] as $t => $label): ?>
                    <input type="radio" name="type" id="type_<?= $t ?>" value="<?= $t ?>" <?= $type === $t ? 'checked' : '' ?> <?= $byType[$t] ? '' : 'disabled' ?>>
                    <label for="type_<?= $t ?>"><?= $label ?></label>
                <?php endforeach; ?>
            </div>

            <div class="form-group" id="etabGroup">
                <label for="etab_id">Établissement</label>
                <select name="etab_id" id="etab_id">
                    <?php foreach ($etabs as $et): ?>
                        <option value="<?= (int)$et['id'] ?>" data-type="<?= e($et['type']) ?>" <?= (int)$et['id'] === $etabId ? 'selected' : '' ?>><?= e($et['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

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
        <p class="form-help mt-2" style="text-align:center"><a href="mot_de_passe_oublie.php?type=admin">Mot de passe oublié ?</a></p>
        <?php endif; ?>
        <a href="bienvenue.html" class="back-link">← Retour à l'accueil</a>
    </main>
    <script>
    // N'affiche que les établissements du type choisi ; masque la liste s'il n'y en a qu'un.
    (function () {
        var select = document.getElementById('etab_id');
        if (!select) { return; }
        var group = document.getElementById('etabGroup');
        function refresh() {
            var type = document.querySelector('input[name="type"]:checked').value;
            var visible = [];
            Array.prototype.forEach.call(select.options, function (o) {
                var ok = o.getAttribute('data-type') === type;
                o.hidden = !ok; o.disabled = !ok;
                if (ok) { visible.push(o); }
            });
            if (visible.length && (select.selectedOptions[0] || {}).disabled !== false) { visible[0].selected = true; }
            group.style.display = visible.length > 1 ? '' : 'none';
        }
        document.querySelectorAll('input[name="type"]').forEach(function (r) { r.addEventListener('change', refresh); });
        refresh();
    })();
    </script>
</body>
</html>
