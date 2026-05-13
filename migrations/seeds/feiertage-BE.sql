-- Berliner Feiertage 2026 + 2027 (Quelle: Feiertagsgesetz Berlin § 1)
-- Run with: mysql -h <host> -u <user> -p <db> < migrations/seeds/feiertage-BE.sql

INSERT INTO feiertage (datum, name, bundesland) VALUES
('2026-01-01', 'Neujahr', 'BE'),
('2026-03-08', 'Internationaler Frauentag', 'BE'),
('2026-04-03', 'Karfreitag', 'BE'),
('2026-04-06', 'Ostermontag', 'BE'),
('2026-05-01', 'Tag der Arbeit', 'BE'),
('2026-05-14', 'Christi Himmelfahrt', 'BE'),
('2026-05-25', 'Pfingstmontag', 'BE'),
('2026-10-03', 'Tag der Deutschen Einheit', 'BE'),
('2026-12-25', '1. Weihnachtsfeiertag', 'BE'),
('2026-12-26', '2. Weihnachtsfeiertag', 'BE'),
('2027-01-01', 'Neujahr', 'BE'),
('2027-03-08', 'Internationaler Frauentag', 'BE'),
('2027-03-26', 'Karfreitag', 'BE'),
('2027-03-29', 'Ostermontag', 'BE'),
('2027-05-01', 'Tag der Arbeit', 'BE'),
('2027-05-06', 'Christi Himmelfahrt', 'BE'),
('2027-05-17', 'Pfingstmontag', 'BE'),
('2027-10-03', 'Tag der Deutschen Einheit', 'BE'),
('2027-12-25', '1. Weihnachtsfeiertag', 'BE'),
('2027-12-26', '2. Weihnachtsfeiertag', 'BE');
