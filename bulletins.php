<?php
require_once 'auth_functions.php';
require_once 'includes/bulletin_file.php';
require_once 'includes/layout.php';
require_page_access();

const CLASSES  = ['2nde', '1ere', 'Tle'];
const FILIERES = ['DWM', 'EA', 'COM', 'IMI', 'F3', 'PM'];
const ZIP_MAX_BYTES = 50 * 1024 * 1024;   // 50 Mo par archive
const PDF_MAX_BYTES = 10 * 1024 * 1024;   // 10 Mo par bulletin
const ZIP_MAX_ENTRIES = 1000;

/** Supprime un dossier et son contenu. */
function deleteDirectory(string $dir): bool
{
    if (!file_exists($dir)) {
        return true;
    }
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    foreach (scandir($dir) as $item) {
        if ($item !== '.' && $item !== '..' && !deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    return rmdir($dir);
}

function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Le fichier est trop volumineux (maximum autorisé par le serveur : ' . ini_get('upload_max_filesize') . ').';
        case UPLOAD_ERR_PARTIAL:
            return "Le fichier n'a été que partiellement envoyé.";
        case UPLOAD_ERR_NO_FILE:
            return "Aucun fichier n'a été sélectionné.";
        default:
            return "Erreur serveur lors de l'envoi du fichier (code $code).";
    }
}

/**
 * Traite une archive ZIP de bulletins : seuls les PDF valides sont conservés,
 * rangés sous uploads/bulletins/<bulletin_id>/<eleve_id>.pdf.
 * Le nom de chaque PDF doit contenir l'ID de l'élève (ex. "55.pdf", "bulletin_55.pdf")
 * ou être de la forme NOM_Prenoms.pdf.
 */
function process_bulletin_zip(PDO $pdo, array $bulletin, string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException("L'archive ZIP est illisible ou corrompue.");
    }
    if ($zip->numFiles > ZIP_MAX_ENTRIES) {
        $zip->close();
        throw new RuntimeException('Archive trop volumineuse (plus de ' . ZIP_MAX_ENTRIES . ' fichiers).');
    }

    $stmt = $pdo->prepare('SELECT id, nom, prenoms FROM eleves WHERE classe = ? AND filiere = ?');
    $stmt->execute([$bulletin['classe'], $bulletin['filiere']]);
    $byId = [];
    $byName = [];
    foreach ($stmt->fetchAll() as $eleve) {
        $byId[(int)$eleve['id']] = $eleve;
        $byName[mb_strtoupper($eleve['nom'] . '_' . $eleve['prenoms'], 'UTF-8')] = $eleve;
    }

    $targetDir = BULLETINS_DIR . '/' . (int)$bulletin['id'];
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        $zip->close();
        throw new RuntimeException('Impossible de créer le dossier de stockage des bulletins.');
    }

    $report = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $entry = str_replace('\\', '/', $stat['name']);
        $base = basename($entry);
        if (substr($entry, -1) === '/' || $base === '' || $base[0] === '.' || strpos($entry, '__MACOSX/') !== false) {
            continue; // dossiers et fichiers système
        }
        $fileName = pathinfo($base, PATHINFO_FILENAME);

        if (strtolower(pathinfo($base, PATHINFO_EXTENSION)) !== 'pdf') {
            $report[] = ['nom' => $base, 'prenoms' => '', 'id' => '', 'status' => 'Ignoré (pas un PDF)'];
            continue;
        }
        if ($stat['size'] > PDF_MAX_BYTES) {
            $report[] = ['nom' => $base, 'prenoms' => '', 'id' => '', 'status' => 'Ignoré (fichier trop lourd)'];
            continue;
        }

        $eleve = null;
        $how = '';
        if (preg_match('/\d+/', $fileName, $m) && isset($byId[(int)$m[0]])) {
            $eleve = $byId[(int)$m[0]];
            $how = 'par ID';
        } else {
            $key = mb_strtoupper(str_replace(' ', '_', $fileName), 'UTF-8');
            foreach ($byName as $nameKey => $candidate) {
                if (str_replace(' ', '_', $nameKey) === $key) {
                    $eleve = $candidate;
                    $how = 'par nom';
                    break;
                }
            }
        }
        if ($eleve === null) {
            $report[] = ['nom' => $fileName, 'prenoms' => '', 'id' => $m[0] ?? '', 'status' => 'Élève non trouvé dans cette classe'];
            continue;
        }

        $content = $zip->getFromIndex($i);
        if ($content === false || strncmp($content, '%PDF', 4) !== 0) {
            $report[] = ['nom' => $eleve['nom'], 'prenoms' => $eleve['prenoms'], 'id' => $eleve['id'], 'status' => 'Ignoré (PDF invalide)'];
            continue;
        }

        $eleveId = (int)$eleve['id'];
        file_put_contents("$targetDir/$eleveId.pdf", $content);
        // Ancien format de rangement (sous-dossier par élève) : on le supprime pour éviter les doublons.
        if (is_dir("$targetDir/$eleveId")) {
            deleteDirectory("$targetDir/$eleveId");
        }
        $report[] = ['nom' => $eleve['nom'], 'prenoms' => $eleve['prenoms'], 'id' => $eleveId, 'status' => "Traité ($how)"];
    }
    $zip->close();
    return $report;
}

// ---------------------------------------------------------------------------
// Traitement des formulaires (POST -> redirection)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $bulletinId = (int)($_POST['bulletin_id'] ?? 0);

    try {
        if ($action === 'create_bulletin') {
            $classe = $_POST['classe'] ?? '';
            $filiere = $_POST['filiere'] ?? '';
            $semestre = $_POST['semestre'] ?? '';
            if (!in_array($classe, CLASSES, true) || !in_array($filiere, FILIERES, true) || !in_array($semestre, ['1', '2'], true)) {
                flash('error', 'Veuillez choisir une classe, une filière et un semestre valides.');
            } else {
                $check = $pdo->prepare('SELECT COUNT(*) FROM bulletins WHERE classe = ? AND filiere = ? AND semestre = ?');
                $check->execute([$classe, $filiere, $semestre]);
                if ($check->fetchColumn() > 0) {
                    flash('error', "Un bulletin existe déjà pour la classe $classe - $filiere (semestre $semestre).");
                } else {
                    $pdo->prepare('INSERT INTO bulletins (classe, filiere, semestre) VALUES (?, ?, ?)')->execute([$classe, $filiere, $semestre]);
                    flash('success', 'Bulletin créé avec succès.');
                }
            }
        } elseif ($action === 'update_status') {
            $status = ($_POST['status'] ?? '') === 'oui' ? 'oui' : 'non';
            $pdo->prepare('UPDATE bulletins SET status = ? WHERE id = ?')->execute([$status, $bulletinId]);
            flash('success', $status === 'oui' ? 'Bulletin publié : les parents peuvent le consulter.' : 'Bulletin masqué aux parents.');
        } elseif ($action === 'upload_zip') {
            $stmt = $pdo->prepare('SELECT * FROM bulletins WHERE id = ?');
            $stmt->execute([$bulletinId]);
            $bulletin = $stmt->fetch();
            $file = $_FILES['bulletin_zip'] ?? null;

            if (!$bulletin) {
                flash('error', 'Bulletin introuvable.');
            } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                flash('error', upload_error_message($file['error'] ?? UPLOAD_ERR_NO_FILE));
            } elseif ($file['size'] > ZIP_MAX_BYTES) {
                flash('error', 'Archive trop volumineuse (50 Mo maximum).');
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'zip' || !in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
                    flash('error', 'Seules les archives .zip contenant des bulletins PDF sont acceptées.');
                } else {
                    $report = process_bulletin_zip($pdo, $bulletin, $file['tmp_name']);
                    $ok = count(array_filter($report, fn($r) => strpos($r['status'], 'Traité') === 0));
                    $pdo->prepare('UPDATE bulletins SET file_path = ? WHERE id = ?')
                        ->execute([mb_substr(basename($file['name']), 0, 255), $bulletinId]);
                    $_SESSION['processed_files'] = $report;
                    flash($ok > 0 ? 'success' : 'error', "$ok bulletin(s) enregistré(s) sur " . count($report) . ' fichier(s) analysé(s).');
                }
            }
        } elseif ($action === 'delete_bulletin') {
            deleteDirectory(BULLETINS_DIR . '/' . $bulletinId);
            $pdo->prepare('DELETE FROM bulletins WHERE id = ?')->execute([$bulletinId]);
            flash('success', 'Bulletin et fichiers associés supprimés.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('bulletins.php');
}

// Récupération des bulletins
$bulletins = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM eleves e WHERE e.classe = b.classe AND e.filiere = b.filiere) AS nb_eleves
                          FROM bulletins b ORDER BY b.date_creation DESC")->fetchAll();

/** Nombre de PDF effectivement déposés pour un bulletin. */
function count_bulletin_pdfs(int $bulletinId): int
{
    $dir = BULLETINS_DIR . '/' . $bulletinId;
    if (!is_dir($dir)) {
        return 0;
    }
    $count = count(glob($dir . '/*.pdf') ?: []);
    $count += count(glob($dir . '/*/*.pdf') ?: []); // ancien format
    return $count;
}

$processed = $_SESSION['processed_files'] ?? [];
unset($_SESSION['processed_files']);

layout_start([
    'title'    => 'Bulletins',
    'subtitle' => 'Créez les bulletins par classe, déposez les PDF puis publiez-les pour les parents.',
    'actions'  => '<button type="button" class="btn btn-primary" data-modal-open="createModal">' . icon('plus', 18) . ' Nouveau bulletin</button>',
]);
?>
<div class="alert alert-info">
    <?= icon('info', 18) ?>
    <div>
        <strong>Comment déposer les bulletins ?</strong> Préparez une archive <strong>.zip</strong> contenant un PDF par élève,
        nommé avec son <strong>ID</strong> (ex. <span class="code">55.pdf</span>) ou sous la forme <span class="code">NOM_Prenoms.pdf</span>.
        Seuls les PDF des élèves de la classe concernée sont conservés.
    </div>
</div>

<?php if (!$bulletins): ?>
    <div class="card empty-state">
        <?= icon('file', 44) ?>
        <p>Aucun bulletin n'a encore été créé.</p>
        <button type="button" class="btn btn-primary" data-modal-open="createModal"><?= icon('plus', 18) ?> Créer le premier bulletin</button>
    </div>
<?php else: ?>
<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Classe</th>
                    <th>Semestre</th>
                    <th>Créé le</th>
                    <th>PDF déposés</th>
                    <th>Visibilité parents</th>
                    <th>Déposer les PDF (.zip)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bulletins as $b):
                $pdfs = count_bulletin_pdfs((int)$b['id']);
                $label = $b['classe'] . ' ' . $b['filiere'] . ' — semestre ' . $b['semestre']; ?>
                <tr>
                    <td class="fw-bold"><?= e($b['classe']) ?> <span class="badge badge-primary"><?= e($b['filiere']) ?></span></td>
                    <td>Semestre <?= e($b['semestre']) ?></td>
                    <td><?= e(date_fr($b['date_creation'])) ?></td>
                    <td>
                        <span class="badge <?= $pdfs === 0 ? 'badge-warning' : ($pdfs >= (int)$b['nb_eleves'] ? 'badge-success' : 'badge-info') ?>">
                            <?= $pdfs ?> / <?= (int)$b['nb_eleves'] ?> élève(s)
                        </span>
                        <?php if ($b['file_path']): ?>
                            <div class="form-help">Dernier envoi : <?= e($b['file_path']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="bulletin_id" value="<?= (int)$b['id'] ?>">
                            <select name="status" class="input" data-autosubmit aria-label="Visibilité du bulletin <?= e($label) ?>" style="min-width:150px">
                                <option value="non" <?= $b['status'] === 'non' ? 'selected' : '' ?>>Masqué</option>
                                <option value="oui" <?= $b['status'] === 'oui' ? 'selected' : '' ?>>Publié</option>
                            </select>
                        </form>
                    </td>
                    <td>
                        <form method="POST" enctype="multipart/form-data" class="btn-group" style="flex-wrap:nowrap">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="upload_zip">
                            <input type="hidden" name="bulletin_id" value="<?= (int)$b['id'] ?>">
                            <input type="file" name="bulletin_zip" accept=".zip,application/zip" required class="input" style="max-width:230px;padding:6px">
                            <button type="submit" class="btn btn-sm btn-outline"><?= icon('upload', 16) ?> Envoyer</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" class="inline" data-confirm="Supprimer le bulletin <?= e($label) ?> et tous ses PDF ? Cette action est définitive." data-confirm-label="Supprimer">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_bulletin">
                            <input type="hidden" name="bulletin_id" value="<?= (int)$b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger-soft" title="Supprimer"><?= icon('trash', 16) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- Création d'un bulletin -->
<div class="modal" id="createModal" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
    <div class="modal-dialog">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_bulletin">
            <div class="modal-header">
                <h2 id="createModalTitle">Nouveau bulletin</h2>
                <button type="button" class="modal-close" data-modal-close aria-label="Fermer"><?= icon('x') ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="classe">Classe</label>
                        <select name="classe" id="classe" required>
                            <option value="">Choisir…</option>
                            <?php foreach (CLASSES as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filiere">Filière</label>
                        <select name="filiere" id="filiere" required>
                            <option value="">Choisir…</option>
                            <?php foreach (FILIERES as $f): ?><option value="<?= e($f) ?>"><?= e($f) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="semestre">Semestre</label>
                        <select name="semestre" id="semestre" required>
                            <option value="">Choisir…</option>
                            <option value="1">1er semestre</option>
                            <option value="2">2nd semestre</option>
                        </select>
                    </div>
                </div>
                <p class="form-help mt-2">Le bulletin est créé « masqué » : publiez-le une fois les PDF déposés.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary">Créer</button>
            </div>
        </form>
    </div>
</div>

<?php if ($processed): ?>
<!-- Rapport du dernier envoi -->
<div class="modal open" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h2 id="reportTitle">Résultat de l'envoi</h2>
            <button type="button" class="modal-close" data-modal-close aria-label="Fermer"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Fichier / élève</th><th>Prénoms</th><th>ID</th><th>Statut</th></tr></thead>
                    <tbody>
                    <?php foreach ($processed as $row):
                        $ok = strpos($row['status'], 'Traité') === 0; ?>
                        <tr>
                            <td><?= e($row['nom']) ?></td>
                            <td><?= e($row['prenoms']) ?></td>
                            <td><?= e($row['id']) ?></td>
                            <td><span class="badge <?= $ok ? 'badge-success' : 'badge-danger' ?>"><?= e($row['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-modal-close>Fermer</button></div>
    </div>
</div>
<?php endif; ?>
<?php layout_end(); ?>
