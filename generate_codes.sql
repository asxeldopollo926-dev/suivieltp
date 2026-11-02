-- Ajouter la colonne code_secret si elle n'existe pas
ALTER TABLE eleves ADD COLUMN IF NOT EXISTS code_secret VARCHAR(10) UNIQUE;

-- Mettre à jour les élèves qui n'ont pas de code secret
UPDATE eleves 
SET code_secret = CONCAT(
    CHAR(65 + FLOOR(RAND() * 26)), -- Première lettre (A-Z)
    CHAR(65 + FLOOR(RAND() * 26)), -- Deuxième lettre (A-Z)
    FLOOR(RAND() * 10),            -- Premier chiffre (0-9)
    FLOOR(RAND() * 10),            -- Deuxième chiffre (0-9)
    CHAR(65 + FLOOR(RAND() * 26)), -- Troisième lettre (A-Z)
    FLOOR(RAND() * 10)             -- Troisième chiffre (0-9)
)
WHERE code_secret IS NULL; 