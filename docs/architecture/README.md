# neo-afk — Architektur

Per-Repo-Architektur-Dokumentation für die Abwesenheits-App (Urlaubsanträge,
Krankmeldungen, HR-Stammdaten). Beschreibt die internen Subsysteme,
Datenflüsse und Designentscheidungen, die aus Code allein nicht hervorgehen.

> **Hinweis für OSS-Leser:** Die Beispiele in diesen Docs nutzen Platzhalter
> wie `afk.firma.example` und `urlaub@firma.example`. Die beschriebenen
> Subsysteme und Datenflüsse sind deployment-unabhängig — die Org-spezifischen
> Werte werden via `.env` / DB-Seeds konfiguriert. Wo du im Code z.B. den
> Shared-Mailbox-Wert siehst, kommt der zur Laufzeit aus `GRAPH_CALENDAR_USER`.

Alle Citations sind ohne Repo-Prefix (z.B. `src/Services/ApprovalService.php:137`),
weil immer auf neo-afk bezogen.

## Inhalt

| Doc | Inhalt |
|---|---|
| [overview.md](overview.md) | Stack, Komponenten-Übersicht, externe Integrationen, Request-Lifecycle |
| [auth-and-integrations.md](auth-and-integrations.md) | SSO (Entra ID + JWKS), CSRF, SMTP-OAuth (XOAUTH2), Microsoft Graph (Calendar + MailboxSettings) |
| [absence-workflow.md](absence-workflow.md) | Antrag → Approve → aktiv-Status mit Calendar-Event und Auto-OOO; Storno-Rückbuchung; Werktage-Berechnung mit Halbtagen + Berliner Feiertagen |
| [hr-and-audit.md](hr-and-audit.md) | HR-Stammdaten-Pflege, MA-Pre-Create vor erstem SSO, anteilige Berechnung Jahresanspruch, Avatar-Sync, Audit-Log-Modell |
| [data-model.md](data-model.md) | DB-Schema-Übersicht: users, user_master_data, absences, approval_tokens, audit_log, feiertage; Migrations-History |
| [tensions.md](tensions.md) | Beobachtete Inkonsistenzen, bewusste Vereinfachungen, offene Designfragen — observed conditions ohne Recommendations |

## Konventionen (für alle Docs)

- **File:line-Citations sind point-in-time.** Vor jeder code-relevanten Entscheidung gegen den aktuellen Source verifizieren.
- **Pfade in Backticks.** Beispiel: `src/Controllers/AntragController.php:87`. Repo-Prefix entfällt, weil Single-Repo.
- **DB-Inhalt lebt in der DB, nicht in der Doku.** Migrations-Files zeigen Schema, nicht Daten. Aktuelle User/Antrags-Zahlen über die App oder direkte DB-Query, nicht hier.
- **Cross-doc-References als Prose**, nicht als relative Markdown-Links — Beispiel: *„siehe `absence-workflow.md` § Storno"*. Robust beim Lesen über Filesystem oder Confluence.
- **Tensions-Sections** in jedem Doc: list-and-cite. Beobachtete Bedingungen mit Code-Citation. Keine Lösungs-Vorschläge — die kommen in PR-Beschreibungen oder `future-features.md`.
- **Mail-/Twig-Templates werden nicht inhaltlich zitiert.** Nur Struktur-Hinweise (welche Variablen die View braucht, welcher Action sie aus dem Controller bekommt).

## Verwandte externe Docs

- `README.md` (Root) — Lokales Setup + Quick-Start
- `spec.md` (Root) — Funktionale Spezifikation, Datenmodell-Beschreibung
- `architecture.md` (Root) — Mermaid-Sequenzdiagramme + Werktage-Algorithmus (vor docs/architecture/ entstanden, bleibt als Diagramm-Referenz)
- `future-features.md` (Root) — Roadmap-Backlog mit Priorisierung
- `docs/entra-id-setup.md` — Tenant-Admin-Setup für SSO + Graph
- `docs/smtp-setup.md` — XOAUTH2-Refresh-Token-Setup

## Warum diese Docs existieren

Drei Lesergruppen:

1. **Künftige Devs / OSS-Contributors** — von 0 → Verständnis ohne mündliche Übergabe.
2. **Self-Hoster** — Operator einer eigenen Instanz, der verstehen will was unter der Haube passiert.
3. **Claude/AI-Assistenten** in zukünftigen Iterationen — Doku ist Context-Source, Drift bedeutet falsche Mental-Models. Citation-genau halten.
