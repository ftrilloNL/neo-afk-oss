-- Bayerische Feiertage 2026 + 2027.
-- Quelle: Bayerisches Feiertagsgesetz (FTG).
-- Run mit: mysql -h <host> -u <user> -p <db> < migrations/seeds/feiertage-BY.sql

INSERT INTO feiertage (datum, name, bundesland) VALUES
('2026-01-01', 'Neujahr', 'BY'),
('2026-01-06', 'Heilige Drei Könige', 'BY'),
('2026-04-03', 'Karfreitag', 'BY'),
('2026-04-06', 'Ostermontag', 'BY'),
('2026-05-01', 'Tag der Arbeit', 'BY'),
('2026-05-14', 'Christi Himmelfahrt', 'BY'),
('2026-05-25', 'Pfingstmontag', 'BY'),
('2026-06-04', 'Fronleichnam', 'BY'),
('2026-08-15', 'Mariä Himmelfahrt', 'BY'),
('2026-10-03', 'Tag der Deutschen Einheit', 'BY'),
('2026-11-01', 'Allerheiligen', 'BY'),
('2026-12-25', '1. Weihnachtsfeiertag', 'BY'),
('2026-12-26', '2. Weihnachtsfeiertag', 'BY'),
('2027-01-01', 'Neujahr', 'BY'),
('2027-01-06', 'Heilige Drei Könige', 'BY'),
('2027-03-26', 'Karfreitag', 'BY'),
('2027-03-29', 'Ostermontag', 'BY'),
('2027-05-01', 'Tag der Arbeit', 'BY'),
('2027-05-06', 'Christi Himmelfahrt', 'BY'),
('2027-05-17', 'Pfingstmontag', 'BY'),
('2027-05-27', 'Fronleichnam', 'BY'),
('2027-08-15', 'Mariä Himmelfahrt', 'BY'),
('2027-10-03', 'Tag der Deutschen Einheit', 'BY'),
('2027-11-01', 'Allerheiligen', 'BY'),
('2027-12-25', '1. Weihnachtsfeiertag', 'BY'),
('2027-12-26', '2. Weihnachtsfeiertag', 'BY');
