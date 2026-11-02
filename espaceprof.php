<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
$professeur = require_professeur();

$filiere = $professeur['filiere'];

// Indicateurs de la filière
$stmt = $pdo->prepare('SELECT COUNT(*) FROM eleves WHERE filiere = ?');
$stmt->execute([$filiere]);
$nbEleves = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT
        SUM(a.statut IN ('Absent','Absente')) AS absences,
        SUM(a.statut = 'En retard') AS retards
    FROM absences a JOIN eleves e ON e.id = a.eleve_id
    WHERE e.filiere = ? AND a.date_absence >= (CURDATE() - INTERVAL 30 DAY)");
$stmt->execute([$filiere]);
$recent = $stmt->fetch() ?: ['absences' => 0, 'retards' => 0];

// Élèves fréquemment absents / en retard
$stmt = $pdo->prepare("SELECT e.id, e.nom, e.prenoms, e.classe, COUNT(*) AS nb
    FROM absences a JOIN eleves e ON a.eleve_id = e.id
    WHERE e.filiere = ? AND a.statut IN ('Absent', 'Absente')
    GROUP BY e.id, e.nom, e.prenoms, e.classe ORDER BY nb DESC LIMIT 5");
$stmt->execute([$filiere]);
$elevesAbsents = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT e.id, e.nom, e.prenoms, e.classe, COUNT(*) AS nb
    FROM absences a JOIN eleves e ON a.eleve_id = e.id
    WHERE e.filiere = ? AND a.statut = 'En retard'
    GROUP BY e.id, e.nom, e.prenoms, e.classe ORDER BY nb DESC LIMIT 5");
$stmt->execute([$filiere]);
$elevesRetards = $stmt->fetchAll();

layout_start([
    'title'    => 'Bienvenue, ' . $professeur['prenoms'] . ' ' . $professeur['nom'],
    'subtitle' => 'Filière ' . $filiere,
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
           . '<div class="text-muted" style="font-size:.85rem">' . e($r['classe']) . '</div></td>'
           . '<td class="num"><span class="badge ' . $badgeClass . '">' . (int)$r['nb'] . ' ' . e($unit) . '</span></td></tr>';
    }
    echo '</tbody></table></div>';
}
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><?= icon('users', 22) ?></div>
        <div><div class="stat-label">Élèves de la filière</div><div class="stat-value"><?= $nbEleves ?></div></div>
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

<div class="grid grid-2">
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
