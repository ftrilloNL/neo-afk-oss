# Future Features — HR-Erweiterungen

> **Hinweis:** Dieser Ideen-Katalog entstand ursprünglich intern. Im
> OSS-Kontext: Liste dient als Inspirationsquelle für Forks/Contributions,
> nicht als verbindliche Roadmap. Eine offizielle Roadmap gibt es nicht —
> Direction folgt den Issues + PRs im Repo.

Ideen-Katalog über die Initial-Phase (Urlaub + Krank + Kalender + HR-Auswertung)
hinaus. Priorisiert nach Relevanz für eine ~10-MA-Legaltech-Firma. **Nicht alles
wird gebraucht** — Liste dient als Auswahlmenü, nicht als Roadmap-Verpflichtung.

## 1. Personnel Master Data (Stammdatenpflege)

**Erweiterung der `users`-Tabelle um zusätzliche Pflicht- und Optional-Felder, durchgängig HR-pflegbar.**

| Feld | Zweck | Priorität |
|---|---|---|
| Geburtsdatum | Geburtstags-Erinnerungen, Steuerberechnung | hoch |
| Anschrift (Straße, PLZ, Ort) | Postversand, Steuerberatung | hoch |
| Bankverbindung (IBAN, BIC) | Gehaltszahlungen, Spesenrückerstattung | hoch |
| Sozialversicherungsnummer | Steuerberater-Übergabe | hoch |
| Steueridentifikationsnummer | Steuerberater-Übergabe | hoch |
| Krankenkasse | Lohnabrechnung | hoch |
| Notfallkontakt (Name + Telefon) | Bei Unfällen/Notfällen | hoch |
| Familienstand | Steuerklasse-Berechnung | mittel |
| Kinder (Anzahl, Geburtsdaten) | Kinderfreibeträge | mittel |
| Foto / Avatar | Personalisierung in der App | niedrig |
| Bevorzugte Pronomen | Inklusivität | niedrig |

**Implementierung:** neue Tabelle `user_master_data` mit 1:1-Beziehung zu `users`, Sichtbarkeit nur HR + User selbst, Audit-Log für jede Änderung.

## 2. Vertragsverwaltung

**Dokumenten-Ablage mit Versionierung, Änderungs-Historie und Ablauf-Warnungen.**

- **Vertrags-Upload** (PDF/DOCX), pro MA mehrere Verträge möglich (Hauptvertrag, Zusatz, NDA)
- **Vertrags-Typen** — Arbeitsvertrag, Änderung, NDA, Wettbewerbsverbot, Datenschutz-Zusatz, Geheimhaltung mit Mandanten
- **Versionsverlauf** mit Datum, Verantwortlichem und kurzer Änderungsbeschreibung
- **Befristete Verträge** mit automatischen Erinnerungen 3 Monate vor Ablauf
- **Probezeit-Tracking** mit Erinnerung 2 Wochen vor Ende, Übernahme-Entscheidung
- **Gehaltsentwicklung** als Zeitreihe (Datum, Gehalt, Grund: Beförderung / Inflation / etc.)
- **Sicherheits-Klassifizierung:** Verträge nur für MA selbst + HR sichtbar; alle Zugriffe loggen
- **DSGVO Art. 15:** Auf Anfrage Export aller MA-Dokumente in einem ZIP

**Implementierung:** Tabellen `contracts`, `contract_versions`, Files via S3-kompatibler Storage (Hetzner Storage Box) mit verschlüsselten Dateinamen.

## 3. Personalgespräche & Performance

**1:1-Notizen, Jahresgespräche, Zielvereinbarungen — strukturiert und nachvollziehbar.**

- **1:1-Notizen** zwischen MA und Vorgesetzte:r, mit Datum, Themen, Action Items, Sichtbarkeit (privat / HR-sichtbar)
- **Jahresgespräche** mit strukturiertem Template: Rückblick (was lief gut/schlecht), Entwicklung (Stärken, Lernfelder), Ziele (Q1–Q4), Gehaltsdiskussion-Notiz
- **Zielvereinbarungen** (light, kein OKR-Tool — nur 3–5 Ziele pro Jahr/Quartal mit Status: erreicht / teilweise / verfehlt)
- **360°-Feedback** (optional) — Kollegen-Feedback einsammeln, anonymisiert
- **Probezeit-Beurteilung** mit Pflicht-Template
- **Talent-Tags** — z.B. „High Potential", „Mentor", „Spezialist X" — für interne Personalentwicklung

**DSGVO-Hinweis:** Personalgesprächs-Notizen sind hochsensibel. Strikte Sichtbarkeit (nur Vorgesetzte:r + MA bei Notizen, MA + HR bei Beurteilungen). Audit-Log für jeden Lese-Zugriff.

## 4. Onboarding & Offboarding

**Checkliste-basierte Workflows für Ein- und Austritte — kein vergessener Punkt.**

- **Onboarding-Template** als Liste konfigurierbar von HR (z.B. 30 Punkte: IT-Account, Hardware-Bestellung, Buddy-Zuweisung, Schulungen, Vertrags-Unterschrift, Sozialversicherungs-Anmeldung, Schlüssel/Zugang, Willkommens-Gespräch, NDA, Datenschutz-Briefing, …)
- **Per neuem MA wird das Template instanziiert**, Verantwortliche pro Punkt zugewiesen, Status getrackt
- **Offboarding-Template** analog (Hardware-Rückgabe, Account-Deaktivierung, Wissens-Transfer, Resturlaubs-Auszahlung, Arbeitszeugnis, NDA-Erinnerung, Abmeldung Sozialversicherung, …)
- **Email-Reminder** an Verantwortliche bei überfälligen Punkten

**Implementierung:** Tabellen `onboarding_templates`, `onboarding_instances`, `onboarding_tasks`. Verbindung zu Microsoft Graph für Account-Anlage/Deaktivierung wäre fancy, aber Phase 2.

## 5. Equipment & Inventar

**Wer hat welche Firmen-Hardware? — wichtig in Tech-Firmen, oft vergessen.**

- **Asset-Inventar:** Geräte (Laptops, Monitore, Headsets, Mobile, YubiKeys), Lizenzen (Adobe, JetBrains)
- **Zuweisung an MA** mit Übergabe-Datum + Übergabe-Protokoll (Foto/PDF als Beleg)
- **Rückgabe** beim Offboarding mit Quittung
- **Kosten-Tracking** pro Gerät (Anschaffung, Garantie-Ablauf)
- **Wartung-Reminder** (Akku-Tausch nach X Jahren, AppleCare-Verlängerung, …)

**Implementierung:** Tabellen `assets`, `asset_assignments`. Eher schmaler Funktionsumfang, kein vollständiges ITAM.

## 6. Erweiterte Abwesenheits-Typen

**Über Urlaub/Krank hinaus — Sonderfälle, die in DE üblich sind.**

- **Sonderurlaub** mit eigenen Kontingenten (Hochzeit: 2 Tage, Umzug: 1 Tag, Trauerfall: 2–4 Tage, Geburt eines Kindes: 1 Tag — konfigurierbar)
- **Mutterschutz** (6 Wochen vor + 8 Wochen nach Geburt) mit automatischem Resturlaub-Übertrag
- **Elternzeit** (bis zu 3 Jahre, mehrfach teilbar) mit Resturlaub-Anteilberechnung
- **Sabbatical** (mehrwöchige unbezahlte Auszeit) — Antrag, Genehmigung, Auswirkung auf Resturlaub
- **Bildungsurlaub** (gesetzlicher Anspruch in DE: 5 Tage/Jahr in den meisten Bundesländern) — separates Kontingent
- **Pflegezeit** (Pflege Angehöriger) — gesetzlich, separat trackbar
- **Homeoffice-Tage** — wenn ihr Hybrid-Policy habt mit X Tagen pro Monat verpflichtend im Office
- **Brückentag-Empfehlungen** vom System: „2026 hast du mit 5 Urlaubstagen den Zeitraum 30.04.–10.05. komplett frei (Tag der Arbeit + Wochenenden)"

**Implementierung:** Tabelle `absence_types` mit Eigenschaften (Genehmigungs-Workflow ja/nein, eigenes Kontingent oder Standard-Resturlaub, Standard-Tage, etc.). `absences.art` wird Foreign Key statt ENUM.

## 7. HR-Analytics & Reports

**Datengetriebene Sicht für HR — über die einfache Liste hinaus.**

- **Dashboard mit KPIs:** Krankenstand-Quote, durchschnittliche Krankheitsdauer, Resturlaub-Verteilung, Genehmigungs-Durchlaufzeit
- **Trends pro Quartal/Jahr** mit Vergleich Vorjahr
- **Per-MA-Reports** — Resturlaub, genommen, krank — als PDF exportierbar
- **Engpass-Warnung:** „In KW 32/2026 sind 5 von 10 MAs gleichzeitig im Urlaub — kritisch?"
- **Abwesenheits-Forecast:** Liste alle bekannten Abwesenheiten der nächsten 6 Monate
- **Geburtstags-/Jubiläums-Liste** mit Erinnerungen
- **CSV / Excel-Export** aller Daten für externe Auswertung
- **Power BI-Anbindung** (oder Metabase) als Data Source — für komplexere Auswertungen

## 8. DSGVO-Compliance-Funktionen

**Gesetzlich gefordert, kein Nice-to-have.**

- **Datenexport-Anfrage (Art. 15)** — User kann eigenen Daten-Dump als ZIP runterladen (alle Abwesenheiten, Stammdaten, Verträge — als PDF/CSV)
- **Löschungs-Anfrage (Art. 17)** — User kann Account-Anonymisierung beantragen, HR genehmigt; bestimmte Daten bleiben aus Aufbewahrungsgründen (Lohn 6 Jahre, etc.) als anonymisierte Pseudonyme
- **Auskunfts-Audit** — Wer hat wann was gesehen? — automatisch via Audit-Log
- **Datenverarbeitungs-Verzeichnis** (Art. 30) — automatisch generiert aus den Tabellen-Schemas
- **Datenschutz-Einwilligungen** — z.B. Foto im Intranet, Geburtstags-Veröffentlichung — opt-in pro MA

## 9. Quality-of-Life-Features

**Kleine Features mit hohem Wirkungsgrad.**

- **Geburtstags- und Jubiläums-Reminder** — automatische Mail an alle MAs am Tag X
- **Vertretungs-Workflow** — beim Urlaubsantrag pflicht zu pflegen, wer übernimmt; bei Genehmigung erhält Vertretung automatisch Notiz
- **Approval direkt aus Outlook via Actionable Messages / Adaptive Cards** — Genehmiger:innen klicken Approve/Reject-Buttons direkt im Mail-Body in Outlook, ohne dass ein Browser-Tab öffnet. Setup: Adaptive-Card-JSON im Mail-Body, App-Endpoint mit Microsoft-Bearer-Token-Verifikation, Microsoft-Registrierung als „Actionable Email Provider" (Approval-Prozess mehrere Tage). Funktioniert nur in Outlook (Web/Desktop/Mobile), andere Mail-Clients sehen den bisherigen Magic-Link-Fallback. ~4–5h Code + Microsoft-Wartezeit.
- **iCal-Feed pro User** — eigene Abwesenheiten und Team-Abwesenheiten als Calendar-Subscribe
- **Slack/Teams-Integration** — Anträge, Genehmigungen, Krankmeldungen als Channel-Notifications
- **Mobile-optimierte Ansicht** — wenn MAs unterwegs Krank-Mails schicken wollen
- **2FA für sensitive HR-Aktionen** — Stammdatenänderung, Vertragsupload
- **Bulk-Antrag** — z.B. Brückentag für ganzes Team auf einen Klick (mit Einzel-Genehmigungen)

## 10. Startup-spezifisch (LegalTech-Software-Firma)

**Funktionen mit besonderer Relevanz für ein wachsendes Tech-Startup, weniger für traditionelle Firmen.**

- **Equity / ESOP / VSOP-Tracking** — pro MA: Anzahl Optionen, Vesting-Schedule, Cliff-Datum, Strike Price, Exit-Wert. Self-Service-View „Wieviele Optionen habe ich, wann vested das nächste Stück?". Sensible Daten — strikte Berechtigung (MA sieht eigene, HR/CFO alle, sonst niemand).
- **OKR / Goal-Tracking light** — 3–5 Quartalsziele pro Person/Team mit Status (erreicht / teilweise / verfehlt). Verzichtet auf vollwertige OKR-Tools wie Workpath/Mooncamp.
- **Tech-Onboarding-Checkliste** (Variante von § 4) — explizit für Software-Firmen: GitHub-Zugang, AWS-Rollen, Notion, Slack-Channels, 1Password-Vault, Sentry, Linear, Production-DB-Read-Only-Access etc.
- **Remote-/Hybrid-Vereinbarungen** — Home-Office-Anteil pro User, Equipment-Pauschale, Internet-Zuschuss, ergonomische Möbel-Budget.
- **Referral-Bonus-Tracking** — wer hat XY empfohlen, Einstellungs-Datum, Bonus-Auszahlungsdatum mit Reminder.
- **Peer-Feedback-Zyklus** — quartalsweise gegenseitiges schriftliches Feedback (offen oder anonym). Wichtig bei flacher Hierarchie und schnellem Wachstum.
- **Work-Anniversary-Reminder** — Slack-Posting oder Mail an Team am Jahrestag des Eintritts (1, 2, 5 Jahre).
- **Lunch-Roulette / Random-Coffee-Pairing** — wöchentlich zufällige 1:1-Lunches via Cron, Donut-Style aber intern.
- **Glossary / Onboarding-Wiki-Sync** — Stack/Tooling-Doku, Notion/Confluence-Verlinkung mit pro-MA Onboarding-Path.

## 11. Universelle Compliance-Themen (alle dt. Firmen, nicht nur LegalTech)

- **Arbeitszeiterfassung** — BAG-Urteil 2022 macht objektive Zeit-Erfassung verpflichtend, auch bei Vertrauensarbeitszeit. Aktuell oft mit Excel/extern erledigt; eigene Lösung lohnt sich wenn 10+ MAs.
- **Pflichtschulungs-Tracking (Datenschutz, IT-Sicherheit)** — Datenschutz-Sensibilisierung ist via DSGVO Art. 32 vorgegeben. Bei B2B-Tech-Firma oft auch von Kunden gefordert (ISO 27001 / SOC 2).
- **Probezeit-Workflow** — 6 Monate vor Ende: Beurteilungs-Template + Übernahme-Entscheidung mit Reminder.
- **Mental Health Day / Family Care Day** — eigene Abwesenheitstypen mit Begrenzung pro Jahr (siehe § 6 Sonderurlaub).
- **Whistleblowing-System (HinSchG)** — gesetzlich erst ab 50 MA. Bei kleinen Teams nicht zwingend, oft besser via externes Tool (EQS Integrity Line, Whistlelink).

## 12. Optional / Längere Sicht

**Funktionen, die wahrscheinlich besser bei spezialisierten Tools bleiben — nur falls Eigenbau gewünscht.**

- **Spesenabrechnung** — eher Pleo, Spendesk, oder N26 Business
- **Gehaltsabrechnung** — eher Steuerberater oder DATEV / Lexware
- **Schulungs-Plattform** — eigene LMS-Lösung selten sinnvoll
- **Bewerber-Management** — eigene ATS-Tool wie Personio, Workable
- **Recruitment-Funnel-Tracking** — separate Tools

---

## Priorisierungs-Vorschlag (Phasen-Plan)

**Status 2026-05-12:** Phase 2 teilweise gestartet — Stammdatenpflege (Adresse/Telefon/Geburtsdatum) ist live, Avatar-Sync mit M365 ebenfalls. Auto-OOO + Org-Chart in Arbeit.

**Phase 2 — laufend:**
- ✅ Stammdatenpflege (§ 1) — Adresse, Telefon, Geburtsdatum live
- ✅ User-Avatar aus Microsoft Graph (§ 1) — live in Sidebar
- 🔄 Auto-OOO in Outlook (§ 9) — in Arbeit
- 🔄 Org-Chart / Team-Übersicht (§ 9) — in Arbeit
- DSGVO-Datenexport (§ 8) — offen, gesetzlich relevant + Showcase für eigene Kunden
- Probezeit-Workflow (§ 11) — offen, niedriger Aufwand, hoher Wirkungsgrad
- Work-Anniversary-Reminder (§ 10) — offen, trivialer Aufwand

**Phase 3 — wenn akut nötig:**
- Sonderurlaub-Typen (§ 6) — Mental Health Day, Family Care Day, Hochzeit/Umzug/Trauerfall
- Vertretungs-Workflow im Antrag (§ 9)
- iCal-Feed pro User (§ 9)
- Tech-Onboarding-Checkliste (§ 10) — variante von § 4 mit GitHub/AWS/Notion-Fokus
- OKR-Tracking light (§ 10)

**Phase 4 — strategisch, größere Stücke:**
- Arbeitszeiterfassung (§ 11) — BAG-Pflicht. Nicht doppeln falls extern schon gelöst.
- Equity-/ESOP-Tracking (§ 10) — sensible Daten, eigenes Berechtigungs-Modell
- Vertragsverwaltung (§ 2)
- HR-Analytics-Dashboard (§ 7)
- Mutterschutz / Elternzeit (§ 6)

**Vermutlich nie / nur wenn explizit nachgefragt:**
- 360°-Feedback (§ 3)
- Sabbatical-Workflows (§ 6)
- Whistleblowing-System (§ 11) — erst ab 50 MA gesetzlich, dann lieber extern
- Conflict-of-Interest (war anwaltsspezifisch, irrelevant für Tech-Startup)
- Anwaltsrechtliche Themen (Anwaltszulassung, FAO-Fortbildung, GwG-Schulung) — irrelevant

---

## Wichtig: bei jedem Feature, vor Bau

1. **Existiert ein besseres externes Tool?** — Spesen / Recruiting / LMS gehören häufig nicht in eine eigene HR-App.
2. **Lohnt sich der Bau für 10 MAs?** — Manche Features brauchen kritische Masse, um sich zu rechnen.
3. **DSGVO-Risiko geprüft?** — Personenbezogene Daten erweitern die Compliance-Last.
4. **Wer ist Owner?** — HR (Flavio?) oder ein dedizierter Maintainer? Ohne Owner kein Feature.
