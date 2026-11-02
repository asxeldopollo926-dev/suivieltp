<?php
/**
 * Migration de la base SuiviLTP (sécurité + rôles).
 * Exécutable uniquement en ligne de commande, et sans risque si relancé :
 *     C:\xampp\php\php.exe install\migrate.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit.');
}

$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../config.php';

function step(string $label, callable $fn): void
{
    try {
        $fn();
        echo "[OK]   $label\n";
    } catch (Throwable $e) {
        echo "[ERR]  $label : " . $e->getMessage() . "\n";
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

// 1. Toutes les tables en InnoDB (nécessaire pour les clés étrangères et les transactions)
foreach (['admin', 'eleves', 'paiements', 'bulletins', 'professeurs'] as $table) {
    step("Moteur InnoDB pour `$table`", function () use ($pdo, $table) {
        $pdo->exec("ALTER TABLE `$table` ENGINE=InnoDB");
    });
}

// 2. Rôles du personnel
step('Colonne `admin.role`', function () use ($pdo) {
    if (!column_exists($pdo, 'admin', 'role')) {
        $pdo->exec("ALTER TABLE admin ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER password");
    }
    // Les comptes créés par l'ancien script avec un mot de passe en clair sont inutilisables : on les signale.
    $plain = $pdo->query("SELECT username FROM admin WHERE password NOT LIKE '$2y$%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($plain) {
        echo "       ! Comptes avec mot de passe non haché (à réinitialiser dans Utilisateurs) : " . implode(', ', $plain) . "\n";
    }
});

// 3. Table des absences (absente de la base, utilisée par l'espace professeur et parents)
step('Table `absences`', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS absences (
        id INT NOT NULL AUTO_INCREMENT,
        eleve_id INT NOT NULL,
        professeur_id INT NOT NULL,
        date_absence DATE NOT NULL,
        statut ENUM('Présent','Présente','Absent','Absente','En retard') NOT NULL,
        matiere VARCHAR(100) DEFAULT NULL,
        heure_debut TIME DEFAULT NULL,
        heure_fin TIME DEFAULT NULL,
        date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        date_modification TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY eleve_id (eleve_id),
        KEY professeur_id (professeur_id),
        CONSTRAINT absences_eleve_fk FOREIGN KEY (eleve_id) REFERENCES eleves (id) ON DELETE CASCADE,
        CONSTRAINT absences_prof_fk FOREIGN KEY (professeur_id) REFERENCES professeurs (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

// 4. Paiements rattachés aux élèves (suppression en cascade)
step('Clé étrangère paiements -> eleves', function () use ($pdo) {
    if (!index_exists($pdo, 'paiements', 'paiements_eleve_fk')) {
        $pdo->exec('DELETE p FROM paiements p LEFT JOIN eleves e ON e.id = p.eleve_id WHERE e.id IS NULL');
        $pdo->exec('ALTER TABLE paiements ADD CONSTRAINT paiements_eleve_fk FOREIGN KEY (eleve_id) REFERENCES eleves (id) ON DELETE CASCADE');
    }
});

// 5. Journal des tentatives de connexion (anti force brute)
step('Table `login_attempts`', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL,
        type VARCHAR(20) NOT NULL,
        attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ip_type_time (ip, type, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

// 6. Codes secrets parents : un code unique et aléatoire pour chaque élève qui n'en a pas
step('Codes secrets des élèves', function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM eleves WHERE code_secret IS NULL OR code_secret = ''")->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare('UPDATE eleves SET code_secret = ? WHERE id = ?');
    foreach ($ids as $id) {
        $update->execute([unique_secret_code($pdo), $id]);
    }
    echo '       ' . count($ids) . " code(s) généré(s)\n";
    if (!index_exists($pdo, 'eleves', 'code_secret_unique')) {
        $pdo->exec('ALTER TABLE eleves ADD UNIQUE KEY code_secret_unique (code_secret)');
    }
});

echo "Migration terminée.\n";
