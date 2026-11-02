<?php
/**
 * Gabarit commun des pages connectées.
 *
 *   layout_start([
 *       'title'    => 'Élèves',                 // titre de la page (obligatoire)
 *       'subtitle' => 'Texte sous le titre',    // facultatif
 *       'area'     => 'admin',                  // 'admin' | 'prof' | 'parent'
 *       'active'   => 'eleves.php',             // entrée de menu active (défaut : page courante)
 *       'actions'  => '<a class="btn">…</a>',   // boutons en haut à droite (HTML)
 *       'head'     => '<style>…</style>',       // ajouts dans <head> (rare)
 *   ]);
 *   … contenu …
 *   layout_end(['scripts' => '<script>…</script>']);
 */
require_once __DIR__ . '/icons.php';

const PROF_MENU = [
    ['href' => 'espaceprof.php', 'label' => 'Tableau de bord', 'icon' => 'home'],
    ['href' => 'absences.php',   'label' => 'Absences',        'icon' => 'calendar'],
];

function render_flash_messages(): string
{
    $html = '';
    foreach (flash_pull() as $flash) {
        $type = in_array($flash['type'], ['success', 'error', 'warning', 'info'], true) ? $flash['type'] : 'info';
        $iconName = ['success' => 'check', 'error' => 'alert', 'warning' => 'alert', 'info' => 'info'][$type];
        $html .= '<div class="alert alert-' . $type . '" role="status">' . icon($iconName, 18)
               . '<div>' . e($flash['message']) . '</div>'
               . '<button type="button" class="alert-close" aria-label="Fermer">&times;</button></div>';
    }
    return $html;
}

function layout_start(array $opt): void
{
    $title    = $opt['title'] ?? 'SuiviLTP';
    $area     = $opt['area'] ?? 'admin';
    $active   = $opt['active'] ?? basename($_SERVER['SCRIPT_NAME']);
    $subtitle = $opt['subtitle'] ?? '';
    $actions  = $opt['actions'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> - SuiviLTP</title>
    <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=3">
    <?= $opt['head'] ?? '' ?>
</head>
<body class="area-<?= e($area) ?>">
<?php if ($area === 'parent'): ?>
    <header class="header-bar">
        <div class="header-bar-inner">
            <a class="sidebar-brand" href="parents.php">
                <img src="assets/logo.svg" alt="">
                <span>SuiviLTP<small>Espace parents · <?= e(current_etab()['nom'] ?? '') ?></small></span>
            </a>
            <a class="btn btn-sm btn-outline" href="logout.php"><?= icon('logout', 16) ?> Déconnexion</a>
        </div>
    </header>
    <div class="app app-simple">
        <main class="main">
<?php else: ?>
    <div class="app">
        <aside class="sidebar" aria-label="Menu principal">
            <a class="sidebar-brand" href="<?= $area === 'prof' ? 'espaceprof.php' : 'admin.php' ?>">
                <img src="assets/logo.svg" alt="">
                <span>SuiviLTP<small><?= $area === 'prof' ? 'Espace professeurs' : 'Administration' ?></small></span>
            </a>
            <?php $etab = current_etab(); if ($etab): ?>
            <div class="sidebar-etab">
                <span class="etab-type"><?= icon('building', 14) ?> <?= e(etab_type_name($etab['type'])) ?></span>
                <?php if ($area === 'admin' && can_switch_etab() && count($allEtabs = etablissements_list()) > 1): ?>
                <form method="POST" action="changer_etablissement.php">
                    <?= csrf_field() ?>
                    <select name="etab_id" data-autosubmit aria-label="Changer d'établissement">
                        <?php foreach ($allEtabs as $et): ?>
                            <option value="<?= (int)$et['id'] ?>" <?= (int)$et['id'] === (int)$etab['id'] ? 'selected' : '' ?>>
                                <?= e(etab_type_name($et['type']) . ' — ' . $et['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php else: ?>
                <strong><?= e($etab['nom']) ?></strong>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <ul class="nav">
                <?php
                $menu = $area === 'prof' ? PROF_MENU : array_filter(ADMIN_MENU, fn($item) => canAccessPage($item['href']));
                foreach ($menu as $item): ?>
                <li>
                    <a href="<?= e($item['href']) ?>" class="nav-link<?= $item['href'] === $active ? ' active' : '' ?>">
                        <?= icon($item['icon']) ?> <?= e($item['label']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="sidebar-user">
                <?php if ($area === 'prof'): ?>
                    <strong><?= e(($_SESSION['professeur_prenom'] ?? '') . ' ' . ($_SESSION['professeur_nom'] ?? '')) ?></strong>
                    <span class="role">Professeur</span>
                <?php else: ?>
                    <strong><?= e($_SESSION['admin_username'] ?? '') ?></strong>
                    <span class="role"><?= e(getCurrentRoleInfo()['name'] ?? '') ?></span>
                <?php endif; ?>
                <a class="nav-link" href="logout.php"><?= icon('logout', 18) ?> Déconnexion</a>
            </div>
        </aside>
        <div class="sidebar-backdrop"></div>
        <main class="main">
<?php endif; ?>
            <div class="topbar">
                <div class="topbar-title">
                    <?php if ($area !== 'parent'): ?>
                    <button type="button" class="menu-toggle" aria-label="Ouvrir le menu"><?= icon('menu') ?></button>
                    <?php endif; ?>
                    <div>
                        <h1><?= e($title) ?></h1>
                        <?php if ($subtitle !== ''): ?><p class="subtitle"><?= e($subtitle) ?></p><?php endif; ?>
                    </div>
                </div>
                <?php if ($actions !== ''): ?><div class="topbar-actions"><?= $actions ?></div><?php endif; ?>
            </div>
            <?= render_flash_messages() ?>
    <?php
}

function layout_end(array $opt = []): void
{
    ?>
        </main>
    </div>
    <script src="assets/js/app.js?v=3"></script>
    <?= $opt['scripts'] ?? '' ?>
</body>
</html>
    <?php
}

/** Petit composant de pagination conservant les filtres de la requête courante. */
function pagination(int $page, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }
    $query = $_GET;
    $html = '<nav class="pagination" aria-label="Pagination">';
    $window = 2;
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i !== 1 && $i !== $totalPages && abs($i - $page) > $window) {
            if ($i === 2 || $i === $totalPages - 1) {
                $html .= '<span>…</span>';
            }
            continue;
        }
        $query['page'] = $i;
        $html .= $i === $page
            ? '<span class="active">' . $i . '</span>'
            : '<a href="?' . e(http_build_query($query)) . '">' . $i . '</a>';
    }
    return $html . '</nav>';
}

/** Montant formaté en FCFA. */
function fcfa($amount): string
{
    return number_format((float)$amount, 0, ',', ' ') . ' FCFA';
}

/** Date au format jj/mm/aaaa. */
function date_fr(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '';
}
