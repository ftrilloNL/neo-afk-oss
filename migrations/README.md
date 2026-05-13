# Database setup

Two SQL files:

```bash
# 1. Schema — alle Tabellen (users, absences, approval_tokens, feiertage,
#    audit_log, user_master_data).
mysql -h <host> -u <user> -p <db> < migrations/schema.sql

# 2. Feiertage-Seed — passend zu ORG_FEIERTAGE_BUNDESLAND aus eurer .env.
mysql -h <host> -u <user> -p <db> < migrations/seeds/feiertage-BE.sql
```

Der Browser-Wizard unter `/setup` erledigt beide Schritte automatisch nach
DB-Connection-Test.

## Verfügbare Feiertage-Seeds

| Datei | Bundesland | Jahre |
|---|---|---|
| `seeds/feiertage-BE.sql` | Berlin | 2026, 2027 |
| `seeds/feiertage-BY.sql` | Bayern | 2026, 2027 |

Weitere Bundesländer / Länder: Datei nach gleichem Pattern anlegen
(`seeds/feiertage-<ISO-Code>.sql`), Bundesland-Code passend zur `.env`-Variable
`ORG_FEIERTAGE_BUNDESLAND`. PRs willkommen.

## Schema-Änderungen

Aktuell gibt es keine Migration-Historie — `schema.sql` ist der End-State.
Bei zukünftigen Schema-Änderungen kommt entweder eine Migration-Chain dazu
(`001_*.sql`, `002_*.sql`, ...) oder `schema.sql` wird direkt erweitert,
je nach Umfang.
