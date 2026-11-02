<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_page_access();

const CODE_PATTERN = '/^[A-Za-z0-9éèÉ+_-]{1,20}$/u';

function etab_usage(PDO $pdo, int $etabId): array
{
    $count = function (string $table) use ($pdo, $etabId): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE etablissement_id = ?");
        $stmt->execute([$etabId]);
        return (int)$stmt->fetchColumn();
    };
    return ['eleves' => $count('eleves'), 'professeurs' => $count('professeurs'), 'bulletins' => $count('bulletins')];
}

$selectedId = (int)($_GET['id'] ?? $_POST['etab_id'] ?? etab_id());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back = 'etablissements.php?id=' . $selectedId;
    try {
        switch ($action) {
            case 'create_etab':
                $nom = trim($_POST['nom'] ?? '');
                $type = $_POST['type'] ?? '';
                if (mb_strlen($nom) < 3 || mb_strlen($nom) > 150 || !isset(ETAB_TYPES[$type])) {
                    flash('error', 'Indiquez un nom (3 à 150 caractères) et un type valide.');
                    break;
                }
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO etablissements (nom, type, ville, contact) VALUES (?, ?, ?, ?)')
                    ->execute([$nom, $type, mb_substr(trim($_POST['ville'] ?? ''), 0, 100), mb_substr(trim($_POST['contact'] ?? ''), 0, 150)]);
                $newId = (int)$pdo->lastInsertId();
                etab_seed_structure($pdo, $newId, $type);
                etab_seed_frais($pdo, $newId, $type);
                $pdo->commit();
                flash('success', etab_type_name($type) . ' « ' . $nom . ' » créé avec ses classes et ' . mb_strtolower(ETAB_TYPES[$type]['serie_label_pluriel'], 'UTF-8') . ' par défaut.');
                $back = 'etablissements.php?id=' . $newId;
                break;

            case 'update_etab':
                $etab = etablissement($selectedId);
                $nom = trim($_POST['nom'] ?? '');
                if (!$etab || mb_strlen($nom) < 3 || mb_strlen($nom) > 150) {
                    flash('error', 'Nom invalide (3 à 150 caractères).');
                    break;
                }
                $pdo->prepare('UPDATE etablissements SET nom = ?, ville = ?, contact = ? WHERE id = ?')
                    ->execute([$nom, mb_substr(trim($_POST['ville'] ?? ''), 0, 100), mb_substr(trim($_POST['contact'] ?? ''), 0, 150), $selectedId]);
                flash('success', 'Établissement mis à jour.');
                break;

            case 'delete_etab':
                $usage = etab_usage($pdo, $selectedId);
                if (count(etablissements_list()) <= 1) {
                    flash('error', 'Impossible de supprimer le dernier établissement.');
                } elseif (array_sum($usage) > 0) {
                    flash('error', "Suppression impossible : l'établissement contient encore {$usage['eleves']} élève(s), {$usage['professeurs']} professeur(s) et {$usage['bulletins']} bulletin(s).");
                } else {
                    $pdo->prepare('DELETE FROM frais_scolaires WHERE etablissement_id = ?')->execute([$selectedId]);
                    $pdo->prepare('UPDATE admin SET etablissement_id = NULL WHERE etablissement_id = ?')->execute([$selectedId]);
                    $pdo->prepare('DELETE FROM etablissements WHERE id = ?')->execute([$selectedId]);
                    if (etab_id() === $selectedId) {
                        $_SESSION['etab_id'] = (int)(etablissements_list()[0]['id'] ?? 0);
                    }
                    flash('success', 'Établissement supprimé.');
                    $back = 'etablissements.php';
                }
                break;

            case 'add_classe':
                $code = trim($_POST['code'] ?? '');
                $etab = etablissement($selectedId);
                if (!$etab || !preg_match(CODE_PATTERN, $code)) {
                    flash('error', 'Code de classe invalide (1 à 20 caractères, lettres/chiffres).');
                    break;
                }
                $ordre = (int)$pdo->query('SELECT COALESCE(MAX(ordre), 0) + 1 FROM etab_classes WHERE etablissement_id = ' . $selectedId)->fetchColumn();
                $pdo->prepare('INSERT INTO etab_classes (etablissement_id, code, ordre, avec_serie) VALUES (?, ?, ?, ?)')
                    ->execute([$selectedId, $code, $ordre, !empty($_POST['avec_serie']) ? 1 : 0]);
                etab_cache_clear();
                etab_seed_frais($pdo, $selectedId, $etab['type']);
                flash('success', "Classe « $code » ajoutée (pensez à définir ses frais).");
                break;

            case 'toggle_serie':
            case 'delete_classe':
            case 'move_classe':
                $classeId = (int)($_POST['classe_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM etab_classes WHERE id = ? AND etablissement_id = ?');
                $stmt->execute([$classeId, $selectedId]);
                $classe = $stmt->fetch();
                if (!$classe) {
                    flash('error', 'Classe introuvable.');
                    break;
                }
                $used = $pdo->prepare('SELECT COUNT(*) FROM eleves WHERE etablissement_id = ? AND classe = ?');
                $used->execute([$selectedId, $classe['code']]);
                $nbEleves = (int)$used->fetchColumn();

                if ($action === 'move_classe') {
                    $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
                    $rows = $pdo->prepare('SELECT id FROM etab_classes WHERE etablissement_id = ? ORDER BY ordre, id');
                    $rows->execute([$selectedId]);
                    $ids = $rows->fetchAll(PDO::FETCH_COLUMN);
                    $pos = array_search($classeId, array_map('intval', $ids), true);
                    if ($pos !== false && isset($ids[$pos + $dir])) {
                        [$ids[$pos], $ids[$pos + $dir]] = [$ids[$pos + $dir], $ids[$pos]];
                        $upd = $pdo->prepare('UPDATE etab_classes SET ordre = ? WHERE id = ?');
                        foreach ($ids as $i => $id) {
                            $upd->execute([$i + 1, $id]);
                        }
                    }
                } elseif ($nbEleves > 0) {
                    flash('error', "La classe « {$classe['code']} » compte $nbEleves élève(s) : changez-les de classe avant de la modifier ou de la supprimer.");
                } elseif ($action === 'toggle_serie') {
                    $pdo->prepare('UPDATE etab_classes SET avec_serie = 1 - avec_serie WHERE id = ?')->execute([$classeId]);
                    $pdo->prepare('DELETE FROM frais_scolaires WHERE etablissement_id = ? AND classe = ?')->execute([$selectedId, $classe['code']]);
                    etab_cache_clear();
                    etab_seed_frais($pdo, $selectedId, etablissement($selectedId)['type']);
                    flash('success', "Classe « {$classe['code']} » : " . ($classe['avec_serie'] ? 'sans' : 'avec') . ' série désormais (frais à vérifier).');
                } else {
                    $pdo->prepare('DELETE FROM frais_scolaires WHERE etablissement_id = ? AND classe = ?')->execute([$selectedId, $classe['code']]);
                    $pdo->prepare('DELETE FROM etab_classes WHERE id = ?')->execute([$classeId]);
                    flash('success', "Classe « {$classe['code']} » supprimée.");
                }
                break;

            case 'add_serie':
                $code = trim($_POST['code'] ?? '');
                $etab = etablissement($selectedId);
                if (!$etab || !preg_match(CODE_PATTERN, $code)) {
                    flash('error', 'Code invalide (1 à 20 caractères, lettres/chiffres).');
                    break;
                }
                $ordre = (int)$pdo->query('SELECT COALESCE(MAX(ordre), 0) + 1 FROM etab_series WHERE etablissement_id = ' . $selectedId)->fetchColumn();
                $pdo->prepare('INSERT INTO etab_series (etablissement_id, code, libelle, ordre) VALUES (?, ?, ?, ?)')
                    ->execute([$selectedId, $code, mb_substr(trim($_POST['libelle'] ?? ''), 0, 100), $ordre]);
                etab_cache_clear();
                etab_seed_frais($pdo, $selectedId, $etab['type']);
                flash('success', "« $code » ajouté (pensez à définir ses frais).");
                break;

            case 'delete_serie':
                $serieId = (int)($_POST['serie_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM etab_series WHERE id = ? AND etablissement_id = ?');
                $stmt->execute([$serieId, $selectedId]);
                $serie = $stmt->fetch();
                if (!$serie) {
                    flash('error', 'Introuvable.');
                    break;
                }
                $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM eleves WHERE etablissement_id = ? AND filiere = ?) + (SELECT COUNT(*) FROM professeurs WHERE etablissement_id = ? AND filiere = ?)');
                $used->execute([$selectedId, $serie['code'], $selectedId, $serie['code']]);
                if ((int)$used->fetchColumn() > 0) {
                    flash('error', "« {$serie['code']} » est encore utilisé par des élèves ou des professeurs.");
                } else {
                    $pdo->prepare('DELETE FROM frais_scolaires WHERE etablissement_id = ? AND filiere = ?')->execute([$selectedId, $serie['code']]);
                    $pdo->prepare('DELETE FROM etab_series WHERE id = ?')->execute([$serieId]);
                    flash('success', "« {$serie['code']} » supprimé.");
                }
                break;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getCode() === '23000' ? 'Ce code existe déjà.' : log_error($e));
    }
    redirect($back);
}

$etabs = etablissements_list();
$selected = etablissement($selectedId) ?? ($etabs[0] ?? null);
$selectedId = (int)($selected['id'] ?? 0);
$struct = $selectedId ? etab_structure($selectedId) : ['classes' => [], 'series' => []];
$classesRows = [];
if ($selectedId) {
    $stmt = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM eleves e WHERE e.etablissement_id = c.etablissement_id AND e.classe = c.code) AS nb
                           FROM etab_classes c WHERE c.etablissement_id = ? ORDER BY c.ordre, c.id');
    $stmt->execute([$selectedId]);
    $classesRows = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM eleves e WHERE e.etablissement_id = s.etablissement_id AND e.filiere = s.code) AS nb
                           FROM etab_series s WHERE s.etablissement_id = ? ORDER BY s.ordre, s.id');
    $stmt->execute([$selectedId]);
    $seriesRows = $stmt->fetchAll();
}
$serieWord = $selected ? ETAB_TYPES[$selected['type']]['serie_label_pluriel'] : 'Séries';

layout_start([
    'title'    => 'Établissements',
    'subtitle' => 'Lycées et collèges gérés par SuiviLTP : leurs données sont entièrement séparées.',
    'actions'  => '<button type="button" class="btn btn-primary" data-modal-open="createEtab">' . icon('plus', 18) . ' Nouvel établissement</button>',
]);
?>
<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Type</th><th>Nom</th><th>Ville</th><th class="num">Élèves</th><th class="num">Professeurs</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($etabs as $et): $u = etab_usage($pdo, (int)$et['id']); ?>
                <tr<?= (int)$et['id'] === $selectedId ? ' style="background:var(--primary-soft)"' : '' ?>>
                    <td><span class="badge <?= $et['type'] === 'lycee' ? 'badge-primary' : 'badge-warning' ?>"><?= e(etab_type_name($et['type'])) ?></span></td>
                    <td class="fw-bold"><?= e($et['nom']) ?><?= (int)$et['id'] === etab_id() ? ' <span class="badge badge-success">en cours</span>' : '' ?></td>
                    <td><?= e($et['ville']) ?></td>
                    <td class="num"><?= $u['eleves'] ?></td>
                    <td class="num"><?= $u['professeurs'] ?></td>
                    <td class="actions"><a class="btn btn-sm btn-outline" href="etablissements.php?id=<?= (int)$et['id'] ?>"><?= icon('settings', 16) ?> Configurer</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($selected): ?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('building') ?> <?= e($selected['nom']) ?></h2>
            <p class="card-subtitle"><?= e(etab_type_name($selected['type'])) ?> — le type ne peut pas être modifié après création.</p>
        </div>
        <form method="POST" class="inline" data-confirm="Supprimer définitivement « <?= e($selected['nom']) ?> » ? (possible seulement s'il est vide)" data-confirm-label="Supprimer">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_etab">
            <input type="hidden" name="etab_id" value="<?= $selectedId ?>">
            <button type="submit" class="btn btn-sm btn-danger-soft"><?= icon('trash', 16) ?> Supprimer</button>
        </form>
    </div>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_etab">
        <input type="hidden" name="etab_id" value="<?= $selectedId ?>">
        <div class="form-grid">
            <div class="form-group"><label for="e_nom">Nom</label><input id="e_nom" name="nom" value="<?= e($selected['nom']) ?>" required maxlength="150"></div>
            <div class="form-group"><label for="e_ville">Ville</label><input id="e_ville" name="ville" value="<?= e($selected['ville']) ?>" maxlength="100"></div>
            <div class="form-group"><label for="e_contact">Contact (site, téléphone…)</label><input id="e_contact" name="contact" value="<?= e($selected['contact']) ?>" maxlength="150"></div>
        </div>
        <p class="form-help">Le nom, la ville et le contact apparaissent sur les reçus et dans les e-mails envoyés aux parents.</p>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
    </form>
</section>

<div class="grid grid-2 section">
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title"><?= icon('users') ?> Classes</h2>
                <p class="card-subtitle">Dans l'ordre du cursus. « Sans série » : ex. 6e à 3e.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Classe</th><th><?= e(ETAB_TYPES[$selected['type']]['serie_label']) ?></th><th class="num">Élèves</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($classesRows as $i => $c): ?>
                    <tr>
                        <td class="fw-bold"><?= e($c['code']) ?></td>
                        <td>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_serie">
                                <input type="hidden" name="etab_id" value="<?= $selectedId ?>">
                                <input type="hidden" name="classe_id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $c['avec_serie'] ? 'btn-outline' : 'btn-secondary' ?>" title="Basculer"<?= $c['nb'] ? ' disabled' : '' ?>>
                                    <?= $c['avec_serie'] ? 'Avec ' . e(mb_strtolower(ETAB_TYPES[$selected['type']]['serie_label'], 'UTF-8')) : 'Sans' ?>
                                </button>
                            </form>
                        </td>
                        <td class="num"><?= (int)$c['nb'] ?></td>
                        <td class="actions">
                            <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow):
                                if (($dir === 'up' && $i === 0) || ($dir === 'down' && $i === count($classesRows) - 1)) { continue; } ?>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="move_classe">
                                <input type="hidden" name="etab_id" value="<?= $selectedId ?>">
                                <input type="hidden" name="classe_id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="dir" value="<?= $dir ?>">
                                <button type="submit" class="btn btn-sm btn-secondary" title="Déplacer"><?= $arrow ?></button>
                            </form>
                            <?php endforeach; ?>
                            <?php if (!$c['nb']): ?>
                            <form method="POST" class="inline" data-confirm="Supprimer la classe <?= e($c['code']) ?> ?" data-confirm-label="Supprimer">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_classe">
                                <input type="hidden" name="etab_id" value="<?= $selectedId ?>">
                                <input type="hidden" name="classe_id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger-soft" title="Supprimer"><?= icon('trash', 14) ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" class="filters mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_classe">
            <input type="hidden" name="etab_id" value="<?= $selectedId ?>">
            <input name="code" placeholder="Nouvelle classe (ex. 6e)" required maxlength="20" aria-label="Code de la classe">
            <label class="btn btn-secondary" style="font-weight:500"><input type="checkbox" name="avec_serie" value="1" checked> Avec <?= e(mb_strtolower(ETAB_TYPES[$selected['type']]['serie_label'], 'UTF-8')) ?></label>
            <button type="submit" class="btn btn-outline"><?= icon('plus', 16) ?> Ajouter</button>
        </form>
    </section>

    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title"><?= icon('tag') ?> <?= e($serieWord) ?></h2>
                <p class="card-subtitle">Proposées pour les classes « avec <?= e(mb_strtolower(ETAB_TYPES[$selected['type']]['serie_label'], 'UTF-8')) ?> ».</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Code</th><th>Libellé</th><th class="num">Élèves</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($seriesRows as $s): ?>
                    <tr>
                        <td class="fw-bold"><?= e($s['code']) ?></td>
                        <td><?= e($s['libelle']) ?></td>
                        <td class="num"><?= (int)$s['nb'] ?></td>
                        <td class="actions">
                            <?php if (!$s['nb']): ?>
                            <form method="POST" class="inline" data-confirm="Supprimer « <?= e($s['code']) ?> » ?" data-confirm-label="Supprimer">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_serie">
                                <input type="hidden" name="etab_id" value="<?= $selectedId ?>">
                                <input type="hidden" name="serie_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger-soft" title="Supprimer"><?= icon('trash', 14) ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" class="filters mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_serie">
            <input type="hidden" name="etab_id" value="<?= $selectedId ?>">
            <input name="code" placeholder="Code (ex. E)" required maxlength="20" aria-label="Code" style="flex-basis:110px">
            <input name="libelle" placeholder="Libellé (facultatif)" maxlength="100" aria-label="Libellé" class="grow">
            <button type="submit" class="btn btn-outline"><?= icon('plus', 16) ?> Ajouter</button>
        </form>
    </section>
</div>
<?php endif; ?>

<div class="modal" id="createEtab" role="dialog" aria-modal="true" aria-labelledby="createEtabTitle">
    <div class="modal-dialog">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_etab">
            <div class="modal-header">
                <h2 id="createEtabTitle">Nouvel établissement</h2>
                <button type="button" class="modal-close" data-modal-close aria-label="Fermer"><?= icon('x') ?></button>
            </div>
            <div class="modal-body">
                <div class="segmented" role="radiogroup" aria-label="Type">
                    <input type="radio" name="type" id="n_lycee" value="lycee" checked><label for="n_lycee">Lycée</label>
                    <input type="radio" name="type" id="n_college" value="college"><label for="n_college">Collège</label>
                </div>
                <div class="form-group"><label for="n_nom">Nom</label><input id="n_nom" name="nom" required minlength="3" maxlength="150" placeholder="Ex. CEG de Tchaourou"></div>
                <div class="form-grid">
                    <div class="form-group"><label for="n_ville">Ville</label><input id="n_ville" name="ville" maxlength="100"></div>
                    <div class="form-group"><label for="n_contact">Contact</label><input id="n_contact" name="contact" maxlength="150"></div>
                </div>
                <p class="form-help mt-2">Lycée : 2nde à Tle avec les filières techniques. Collège : 6e à 3e sans série, puis 2nde à Tle en séries A1, A2, B, C, D. Tout reste modifiable ensuite.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary">Créer</button>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
