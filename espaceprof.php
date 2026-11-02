<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
$professeur = require_professeur();

$etabId    = (int)$professeur['etablissement_id'];
$profSerie = (string)$professeur['filiere'];   // '' = toutes les classes de l'établissement

// Périmètre du professeur : son établissement, et sa filière si elle est fixée
$scopeSql = 'e.etablissement_id = ?' . ($profSerie !== '' ? ' AND e.filiere = ?' : '');
$scopeParams = $profSerie !== '' ? [$etabId, $profSerie] : [$etabId];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM eleves e WHERE $scopeSql");
$stmt->execute($scopeParams);
$nbEleves = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT
        SUM(a.statut IN ('Absent','Absente')) AS absences,
        SUM(a.statut = 'En retard') AS retards
    FROM absences a JOIN eleves e ON e.id = a.eleve_id
    WHERE $scopeSql AND a.date_absence >= (CURDATE() - INTERVAL 30 DAY)");
$stmt->execute($scopeParams);
$recent = $stmt->fetch() ?: ['absences' => 0, 'retards' => 0];

// Élèves fréquemment absents / en retard
$top = function (string $condition) use ($pdo, $scopeSql, $scopeParams): array {
    $stmt = $pdo->prepare("SELECT e.id, e.nom, e.prenoms, e.classe, e.filiere, COUNT(*) AS nb
        FROM absences a JOIN eleves e ON a.eleve_id = e.id
        WHERE $scopeSql AND $condition
        GROUP BY e.id, e.nom, e.prenoms, e.classe, e.filiere ORDER BY nb DESC LIMIT 5");
    $stmt->execute($scopeParams);
    return $stmt->fetchAll();
};
$elevesAbsents = $top("a.statut IN ('Absent', 'Absente')");
$elevesRetards = $top("a.statut = 'En retard'");

$etabNom = current_etab()['nom'] ?? '';
layout_start([
    'title'    => 'Bienvenue, ' . $professeur['prenoms'] . ' ' . $professeur['nom'],
    'subtitle' => $etabNom . ' — ' . ($profSerie !== '' ? serie_label() . ' ' . $profSerie : 'toutes les classes'),
    'area'     => 'prof',
    'actions'  => '<a href="absences.php" class="btn btn-primary">' . icon('calendar', 16) . ' Faire l\'appel</a>',
]);

function top_list(array $rows, string $badgeClass, string $unit, string $empty): void
{
    if (!$rows) {
        echo '<div class="empty-state">' . icon('check', 36) . '<p class="mb-0">' . e($empty) . '</p></div>';
        return;
    }
    echo '<div class="table-wrap"><table class="table"><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><span class="fw-bold">' . e(mb_strtoupper($r['nom'], 'UTF-8')) . '</span> ' . e($r['prenoms'])
           . '<div class="text-muted" style="font-size:.85rem">' . e(classe_label($r['classe'], $r['filiere'])) . '</div></td>'
           . '<td class="num"><span class="badge ' . $badgeClass . '">' . (int)$r['nb'] . ' ' . e($unit) . '</span></td></tr>';
    }
    echo '</tbody></table></div>';
}
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><?= icon('users', 22) ?></div>
        <div><div class="stat-label"><?= $profSerie !== '' ? 'Élèves de la ' . e(mb_strtolower(serie_label(), 'UTF-8')) : "Élèves de l'établissement" ?></div><div class="stat-value"><?= $nbEleves ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><?= icon('calendar', 22) ?></div>
        <div><div class="stat-label">Absences (30 jours)</div><div class="stat-value"><?= (int)$recent['absences'] ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><?= icon('clock', 22) ?></div>
        <div><div class="stat-label">Retards (30 jours)</div><div class="stat-value"><?= (int)$recent['retards'] ?></div></div>
    </div>
</div>

<section class="card mb-2">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('calendar') ?> Appel du jour</h2>
            <p class="card-subtitle">Choisissez une classe : tous les élèves sont présents par défaut, il suffit de signaler les absents et retards, puis d'enregistrer en un clic.</p>
        </div>
        <a href="absences.php" class="btn btn-primary"><?= icon('check', 16) ?> Faire l'appel</a>
    </div>
</section>

<div class="grid grid-2 section">
    <section class="card">
        <div class="card-header">
            <h2 class="card-title"><?= icon('alert') ?> Élèves fréquemment absents</h2>
        </div>
        <?php top_list($elevesAbsents, 'badge-danger', 'absence(s)', 'Aucun élève fréquemment absent.'); ?>
    </section>
    <section class="card">
        <div class="card-header">
            <h2 class="card-title"><?= icon('clock') ?> Élèves fréquemment en retard</h2>
        </div>
        <?php top_list($elevesRetards, 'badge-warning', 'retard(s)', 'Aucun élève fréquemment en retard.'); ?>
    </section>
</div>
<?php layout_end(); ?>
