-- Structure de la table `frais_scolaires`
DROP TABLE IF EXISTS `frais_scolaires`;
CREATE TABLE IF NOT EXISTS `frais_scolaires` (
  `id` int NOT NULL AUTO_INCREMENT,
  `classe` enum('2nde','1ere','Tle') NOT NULL,
  `filiere` enum('DWM','EA','COM','IMI','F3','PM') NOT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `classe_filiere` (`classe`,`filiere`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Insertion des données de base
INSERT INTO `frais_scolaires` (`classe`, `filiere`, `montant_total`) VALUES
('2nde', 'DWM', 80000.00),
('2nde', 'EA', 80000.00),
('2nde', 'COM', 80000.00),
('2nde', 'IMI', 80000.00),
('2nde', 'F3', 80000.00),
('2nde', 'PM', 80000.00),
('1ere', 'DWM', 80000.00),
('1ere', 'EA', 80000.00),
('1ere', 'COM', 80000.00),
('1ere', 'IMI', 80000.00),
('1ere', 'F3', 80000.00),
('1ere', 'PM', 80000.00),
('Tle', 'DWM', 80000.00),
('Tle', 'EA', 80000.00),
('Tle', 'COM', 80000.00),
('Tle', 'IMI', 80000.00),
('Tle', 'F3', 80000.00),
('Tle', 'PM', 80000.00); 