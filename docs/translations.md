# Translations

neo:afk is built with a Translation-Catalog-first approach: all
user-facing strings live in `translations/messages.<locale>.po`. The
code references them by stable, dot-namespaced keys. Adding a new
language never requires touching code.

This file documents how to:

- add a new language
- find and translate new strings
- understand which strings are NOT translatable on purpose
- run the extraction / verification tooling

## Stack

- `symfony/translation` is the translator. `PoFileLoader` loads `.po`
  files directly at runtime — `.mo` compilation is not used. **`.po`
  files are the source of truth and are committed to the repo.**
  No build step is required for translations.
- `symfony/twig-bridge` adds the `|trans` filter to Twig.
- `willdurand/negotiation` resolves the active locale from the
  browser's `Accept-Language` header.
- `App\I18n\LocalizedDate` (`src/I18n/LocalizedDate.php`) is a tiny
  wrapper that reads date-format patterns and month names from the
  catalog so we can format locale-aware dates without `ext-intl`
  (which is not available on Hetzner Webhosting).

## Catalogs

Each supported locale has a single PO file:

```
translations/
  messages.de.po
  messages.en.po
```

Keys are flat, dot-namespaced, all-lowercase identifiers. Examples:

```
home.greeting
nav.antrag
flash.antrag.required_fields
mail.approval_request.subject
status.beantragt
month.5
```

Variable interpolation uses `%name%` placeholders:

```
msgid "home.greeting"
msgstr "Hallo %name% 👋"
```

In code:

```php
$this->translator->trans('home.greeting', ['%name%' => $user->display_name]);
```

```twig
{{ 'home.greeting'|trans({'%name%': user.display_name}) }}
```

## Adding a new language

To add e.g. French:

1. Create `translations/messages.fr.po` — copy `messages.en.po` as a
   starting point so the gettext header is present:

   ```
   cp translations/messages.en.po translations/messages.fr.po
   ```

   Then edit the header so `Language: fr`.

2. Add `'fr'` to `Config::SUPPORTED_LOCALES` (`src/Config.php`).

3. (Optional) Set the repo default to `fr` via `DEFAULT_LOCALE=fr` in
   `.env`, or leave the existing default. Browser `Accept-Language: fr`
   will pick the new locale either way.

4. Translate every `msgstr` in `messages.fr.po`. The easiest workflow
   is [Poedit](https://poedit.net/) (Mac + Windows + Linux); it
   understands `.po` natively and shows the German + English values as
   reference rows.

5. Pay attention to keys with dot-suffix variants — they're called
   dynamically from the code and must all be filled in:

   - `month.1` ... `month.12`
   - `status.beantragt`, `status.aktiv.urlaub`, `status.aktiv.krank`,
     `status.abgelehnt`, `status.storniert`
   - `audit.action.<action_name>` for every action in the audit log
   - `audit.field.<field_name>` for every field surfaced in the audit
     diff view

That's the entire flow. **No code change other than the one-line
`Config::SUPPORTED_LOCALES` edit is required.**

## Finding new strings to translate

After adding or editing user-facing strings in PHP / Twig, run:

```
composer i18n:extract
```

This scans `src/` and `cron/` for `->trans('...')` calls and
`src/Templates/` for `'...'|trans` patterns, then compares against
every locale's PO file. Output:

- `[de] OK -- 362 keys, none missing` — every static key is present.
- `[en] 3 missing key(s) ... + login.welcome_back` — the locale is
  missing keys.

To append the missing keys with empty `msgstr` values (one entry per
missing key in each locale's PO):

```
composer i18n:extract:write
```

Then open the affected `.po` files (in Poedit or any editor) and fill
in the empty strings.

### CI / pre-commit

```
composer i18n:extract:check
```

Exit code 1 if any locale is missing keys, otherwise 0. Suitable for
pre-commit hook or GitHub-Actions step.

### Cleaning up dead keys

```
composer i18n:extract:orphans
```

Lists PO entries that are not referenced from code (excluding the
dynamic-key prefixes `status.*`, `month.*`, `audit.action.*`,
`audit.field.*`, which the extractor knows are called via string
concatenation). Use sparingly — orphans are not always bugs (e.g. a
key might be used by a template that imports a partial).

### Dynamic keys

Some sites build keys at runtime, e.g.
`$translator->trans('status.' . $absence['status'])` or
`('audit.action.' ~ e.action)|trans`. The extractor cannot follow
those statically; it flags the call sites as warnings and lists them
under "dynamic" in the report. The prefix families above
(`status.*`, `month.*`, `audit.action.*`, `audit.field.*`) are
maintained by hand — when you add a new action / status / etc., add
the corresponding catalog entries yourself.

## What is NOT translated (by design)

Per the Epic AFK-1 "UI-Layer only" scope, the following stay German
in DB even when the UI runs in English:

- DB column names: `startdatum`, `art`, `genehmiger_id`,
  `resturlaub_aktuell`, etc.
- Internal enum values: `status='beantragt'`, `'aktiv'`, `'abgelehnt'`,
  `'storniert'`; `art='urlaub'`, `'krank'`; `halbtag_start='ganztag'`
  etc. (display labels for these enums ARE translated via
  `status.<value>` / `common.art.<value>`)
- Audit-event-name values: `absence.approval_requested`,
  `user.stammdaten_updated`, etc. (display labels via
  `audit.action.<value>`)
- OOO marker constant: `<!-- neo-afk:auto-ooo -->`
- Internal assertion / invariant-violation exception messages in
  English (`Absence {N} not found` etc.) — these only fire on bugs and
  surface via 500 pages
- DB-stored auto-rejection reason
  (`absences.begruendung_ablehnung`): written in the active locale at
  write-time, not re-translated on display. Same pragma as audit-log
  payloads.

## Common keys / reuse conventions

Cross-cutting strings live under short namespaces:

- `common.*` — shared utility strings (`common.days`, `common.or`,
  `common.back_to_home`, `common.role.hr`, etc.)
- `action.*` — verb buttons (`action.save`, `action.cancel`,
  `action.edit`, `action.storno`)
- `nav.*` — sidebar nav labels
- `status.*` — DB-status display labels
- `month.*` — month names (catalog-driven date formatter)
- `date.format.*` — `DateTime::format()` patterns per locale

Feature areas: `home.*`, `login.*`, `antrag.*`, `krank.*`, `hr.*`,
`audit.*`, `genehmigungen.*`, `approval.*`, `profil.*`, `team.*`,
`layout.*`, `flash.*`, `service.*`, `mail.*`, `calendar.*`,
`coming_soon.*`.

When adding a string, search for an existing key that fits before
creating a new one — `Stornieren` is `action.storno`, `Tage` is
`common.days`, the period label "Zeitraum / Period" is
`antrag.form.zeitraum`.
