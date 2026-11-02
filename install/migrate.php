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

function constraint_exists(PDO $pdo, string $table, string $name): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $stmt->execute([$table, $name]);
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
    if (!constraint_exists($pdo, 'paiements', 'paiements_eleve_fk')) {
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

// 7. Grille des frais de scolarité
step('Table `frais_scolaires`', function () use ($pdo) {
    if (!column_exists($pdo, 'frais_scolaires', 'montant_garcon')) {
        // L'ancien script create_frais_scolaires.sql créait une table sans distinction garçon/fille.
        $pdo->exec('DROP TABLE IF EXISTS frais_scolaires');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS frais_scolaires (
        id INT NOT NULL AUTO_INCREMENT,
        classe VARCHAR(20) NOT NULL,
        filiere VARCHAR(20) NOT NULL DEFAULT '',
        montant_garcon INT UNSIGNED NOT NULL,
        montant_fille INT UNSIGNED NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

// ===========================================================================
// MULTI-ÉTABLISSEMENTS (lycée / collège)
// ===========================================================================

// 8. Établissements
step('Table `etablissements`', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS etablissements (
        id INT NOT NULL AUTO_INCREMENT,
        nom VARCHAR(150) NOT NULL,
        type ENUM('lycee','college') NOT NULL,
        ville VARCHAR(100) NOT NULL DEFAULT '',
        contact VARCHAR(150) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ((int)$pdo->query('SELECT COUNT(*) FROM etablissements')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO etablissements (id, nom, type, ville, contact) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([1, 'Lycée Technique et Professionnel de Tchaourou', 'lycee', 'Tchaourou', 'www.lyceetchaourou.bj']);
        $ins->execute([2, 'Collège (nom à configurer)', 'college', 'Tchaourou', '']);
        echo "       Établissements créés : lycée (id 1, données existantes) et collège (id 2)\n";
    }
});

// 9. Classes et séries propres à chaque établissement
step('Tables `etab_classes` et `etab_series`', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS etab_classes (
        id INT NOT NULL AUTO_INCREMENT,
        etablissement_id INT NOT NULL,
        code VARCHAR(20) NOT NULL,
        ordre INT NOT NULL DEFAULT 0,
        avec_serie TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY etab_code (etablissement_id, code),
        CONSTRAINT etab_classes_fk FOREIGN KEY (etablissement_id) REFERENCES etablissements (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS etab_series (
        id INT NOT NULL AUTO_INCREMENT,
        etablissement_id INT NOT NULL,
        code VARCHAR(20) NOT NULL,
        libelle VARCHAR(100) NOT NULL DEFAULT '',
        ordre INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY etab_code (etablissement_id, code),
        CONSTRAINT etab_series_fk FOREIGN KEY (etablissement_id) REFERENCES etablissements (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    require_once __DIR__ . '/../includes/etablissement.php';
    foreach ($pdo->query('SELECT id, type FROM etablissements')->fetchAll() as $etab) {
        $has = $pdo->prepare('SELECT COUNT(*) FROM etab_classes WHERE etablissement_id = ?');
        $has->execute([$etab['id']]);
        if ((int)$has->fetchColumn() === 0) {
            etab_seed_structure($pdo, (int)$etab['id'], $etab['type']);
            echo "       Structure par défaut créée pour l'établissement {$etab['id']} ({$etab['type']})\n";
        }
    }
});

// 10. Les classes/filières ne sont plus des listes figées (ENUM) : elles dépendent de l'établissement
step('Colonnes classe/filière en texte libre', function () use ($pdo) {
    foreach (['eleves', 'bulletins', 'frais_scolaires'] as $table) {
        $pdo->exec("ALTER TABLE `$table` MODIFY classe VARCHAR(20) NOT NULL, MODIFY filiere VARCHAR(20) NOT NULL DEFAULT ''");
    }
    $pdo->exec("ALTER TABLE professeurs MODIFY filiere VARCHAR(100) NOT NULL DEFAULT ''");
});

// 11. Rattachement des données à un établissement (les données existantes -> lycée n°1)
step('Colonnes `etablissement_id`', function () use ($pdo) {
    foreach (['eleves', 'professeurs', 'bulletins', 'frais_scolaires'] as $table) {
        if (!column_exists($pdo, $table, 'etablissement_id')) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN etablissement_id INT NOT NULL DEFAULT 1 AFTER id");
            $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT {$table}_etab_fk FOREIGN KEY (etablissement_id) REFERENCES etablissements (id)");
        }
    }
    // Personnel : NULL = accès à tous les établissements (administrateurs)
    if (!column_exists($pdo, 'admin', 'etablissement_id')) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN etablissement_id INT NULL DEFAULT NULL AFTER role');
        $pdo->exec('ALTER TABLE admin ADD CONSTRAINT admin_etab_fk FOREIGN KEY (etablissement_id) REFERENCES etablissements (id) ON DELETE SET NULL');
        $pdo->exec("UPDATE admin SET etablissement_id = 1 WHERE role <> 'admin'");
    }
    if (index_exists($pdo, 'frais_scolaires', 'classe_filiere')) {
        $pdo->exec('ALTER TABLE frais_scolaires DROP INDEX classe_filiere');
    }
    if (!index_exists($pdo, 'frais_scolaires', 'etab_classe_filiere')) {
        $pdo->exec('ALTER TABLE frais_scolaires ADD UNIQUE KEY etab_classe_filiere (etablissement_id, classe, filiere)');
    }
});

// 12. Grille des frais complète pour chaque établissement
step('Grille des frais par établissement', function () use ($pdo) {
    require_once __DIR__ . '/../includes/etablissement.php';
    foreach ($pdo->query('SELECT id, type FROM etablissements')->fetchAll() as $etab) {
        $n = etab_seed_frais($pdo, (int)$etab['id'], $etab['type']);
        if ($n) {
            echo "       $n ligne(s) de frais ajoutée(s) pour l'établissement {$etab['id']}\n";
        }
    }
});

// ===========================================================================
// CONTACTS, NOTIFICATIONS, MOT DE PASSE OUBLIÉ
// ===========================================================================

// 13. Coordonnées des parents et e-mail du personnel
step('Contacts parents et e-mail du personnel', function () use ($pdo) {
    foreach (['parent_nom' => 'VARCHAR(150)', 'parent_telephone' => 'VARCHAR(30)', 'parent_email' => 'VARCHAR(150)'] as $col => $type) {
        if (!column_exists($pdo, 'eleves', $col)) {
            $pdo->exec("ALTER TABLE eleves ADD COLUMN $col $type NOT NULL DEFAULT ''");
        }
    }
    if (!column_exists($pdo, 'admin', 'email')) {
        $pdo->exec("ALTER TABLE admin ADD COLUMN email VARCHAR(150) NOT NULL DEFAULT '' AFTER username");
    }
});

// 14. Réglages de l'application (SMTP, notifications…)
step('Table `app_settings`', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        cle VARCHAR(64) NOT NULL,
        valeur TEXT NOT NULL,
        PRIMARY KEY (cle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

// 15. File d'attente / journal des notifications
step('Table `notifications`', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT NOT NULL AUTO_INCREMENT,
        etablissement_id INT NULL,
        eleve_id INT NULL,
        evenement VARCHAR(30) NOT NULL DEFAULT '',
        canal ENUM('email','sms') NOT NULL DEFAULT 'email',
        destinataire VARCHAR(150) NOT NULL,
        sujet VARCHAR(255) NOT NULL DEFAULT '',
        corps TEXT NOT NULL,
        statut ENUM('en_attente','envoye','echec','journal') NOT NULL DEFAULT 'en_attente',
        erreur VARCHAR(255) NOT NULL DEFAULT '',
        tentatives INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY statut (statut),
        KEY etab (etablissement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

// 16. Jetons de réinitialisation de mot de passe
step('Table `password_resets`', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT NOT NULL AUTO_INCREMENT,
        user_type ENUM('admin','prof') NOT NULL,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY token_hash (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

echo "Migration terminée.\n";
