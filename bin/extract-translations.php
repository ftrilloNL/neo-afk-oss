<?php declare(strict_types=1);

/**
 * Translation-Key-Extractor.
 *
 * Scant PHP- und Twig-Quellen nach Uebersetzungs-Schluesseln und vergleicht
 * mit den vorhandenen `translations/messages.<locale>.po`-Dateien.
 *
 * Usage:
 *   php bin/extract-translations.php             # listet fehlende Keys
 *   php bin/extract-translations.php --write     # haengt fehlende Keys leer
 *                                                  an alle .po-Files an
 *   php bin/extract-translations.php --check     # exit-code 1 wenn etwas fehlt
 *                                                  (fuer CI / pre-commit)
 *   php bin/extract-translations.php --orphans   # listet Keys, die in den
 *                                                  .po-Files stehen aber im
 *                                                  Code nicht (mehr) referenziert
 *                                                  werden
 *
 * Statisch extrahierbar:
 *   PHP   ->trans('key.name')
 *   Twig  'key.name'|trans
 *
 * Nicht statisch extrahierbar (werden als Warnungen gemeldet, nicht als Keys):
 *   ->trans('prefix.' . $var)
 *   ('prefix.' ~ var)|trans
 *
 * Solche dynamischen Aufrufe muessen die Schluessel-Familie selbst pflegen
 * (z.B. status.*, audit.action.*, audit.field.*) -- siehe docs/translations.md.
 */

$rootPath = dirname(__DIR__);
$translationsDir = $rootPath . '/translations';
$sources = [
    'php' => [
        $rootPath . '/src',
        $rootPath . '/cron',
    ],
    'twig' => [
        $rootPath . '/src/Templates',
    ],
];

$args = array_slice($argv, 1);
$mode = 'list';
if (in_array('--write', $args, true)) {
    $mode = 'write';
} elseif (in_array('--check', $args, true)) {
    $mode = 'check';
} elseif (in_array('--orphans', $args, true)) {
    $mode = 'orphans';
}

$staticKeys = [];
$dynamicCalls = [];

/**
 * @return iterable<\SplFileInfo>
 */
function walk(string $dir, string $ext): iterable
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === $ext) {
            yield $file;
        }
    }
}

// ---- PHP: ->trans('key') ----
foreach ($sources['php'] as $dir) {
    foreach (walk($dir, 'php') as $file) {
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }
        // Static: ->trans('key.name') or ->trans("key.name")
        if (preg_match_all('/->\s*trans\s*\(\s*([\'"])([a-z_][a-z0-9._]*[a-z0-9_])\1/i', $content, $m)) {
            foreach ($m[2] as $k) {
                $staticKeys[$k] = true;
            }
        }
        // Dynamic: ->trans('prefix.' . $expr) or ->trans('prefix.' . expr)
        if (preg_match_all('/->\s*trans\s*\(\s*[\'"][a-z_][a-z0-9._]*[a-z0-9_]\.[\'"]\s*\.\s*/i', $content, $m)) {
            foreach ($m[0] as $hit) {
                $dynamicCalls[] = $file->getPathname() . ': ' . trim($hit);
            }
        }
    }
}

// ---- Twig: 'key'|trans ----
foreach ($sources['twig'] as $dir) {
    foreach (walk($dir, 'twig') as $file) {
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }
        // Static: 'key.name'|trans or "key.name"|trans
        if (preg_match_all('/([\'"])([a-z_][a-z0-9._]*[a-z0-9_])\1\s*\|\s*trans/i', $content, $m)) {
            foreach ($m[2] as $k) {
                $staticKeys[$k] = true;
            }
        }
        // Dynamic: ('prefix.' ~ x)|trans
        if (preg_match_all('/\(\s*[\'"][a-z_][a-z0-9._]*[a-z0-9_]\.[\'"]\s*~\s*/i', $content, $m)) {
            foreach ($m[0] as $hit) {
                $dynamicCalls[] = $file->getPathname() . ': ' . trim($hit);
            }
        }
    }
}

$usedKeys = array_keys($staticKeys);
sort($usedKeys);

// Read locales from filesystem
$locales = [];
foreach (glob($translationsDir . '/messages.*.po') ?: [] as $poFile) {
    if (preg_match('/messages\.([a-z]+)\.po$/', $poFile, $m)) {
        $locales[$m[1]] = $poFile;
    }
}

if ($locales === []) {
    fwrite(STDERR, "No translations/messages.<locale>.po files found.\n");
    exit(1);
}

/**
 * @return array{keys: list<string>, content: string}
 */
function parsePo(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        return ['keys' => [], 'content' => ''];
    }
    $keys = [];
    if (preg_match_all('/^msgid\s+"((?:[^"\\\\]|\\\\.)*)"$/m', $content, $m)) {
        foreach ($m[1] as $k) {
            if ($k === '') {
                continue; // empty msgid is the PO header
            }
            $keys[] = $k;
        }
    }
    return ['keys' => $keys, 'content' => $content];
}

$report = [];
foreach ($locales as $loc => $path) {
    $po = parsePo($path);
    $poKeys = array_flip($po['keys']);
    $missing = [];
    foreach ($usedKeys as $k) {
        if (! isset($poKeys[$k])) {
            $missing[] = $k;
        }
    }
    $orphans = [];
    $usedSet = array_flip($usedKeys);
    foreach ($po['keys'] as $k) {
        // Skip status.*, audit.action.*, audit.field.*, month.* — these are
        // referenced via dynamic keys and known to live in the catalog.
        if (
            str_starts_with($k, 'status.')
            || str_starts_with($k, 'audit.action.')
            || str_starts_with($k, 'audit.field.')
            || str_starts_with($k, 'month.')
        ) {
            continue;
        }
        if (! isset($usedSet[$k])) {
            $orphans[] = $k;
        }
    }
    $report[$loc] = ['path' => $path, 'missing' => $missing, 'orphans' => $orphans];
}

// ---- Output ----
$totalMissing = 0;
$totalOrphans = 0;

if ($mode === 'orphans') {
    foreach ($report as $loc => $r) {
        $totalOrphans += count($r['orphans']);
        echo "[{$loc}] " . count($r['orphans']) . " orphan key(s)\n";
        foreach ($r['orphans'] as $k) {
            echo "  · {$k}\n";
        }
    }
    exit($totalOrphans > 0 ? 1 : 0);
}

// list / check / write all start with the same missing-key report
foreach ($report as $loc => $r) {
    $totalMissing += count($r['missing']);
    if (count($r['missing']) === 0) {
        echo "[{$loc}] OK -- " . count($usedKeys) . " keys, none missing\n";
        continue;
    }
    echo "[{$loc}] " . count($r['missing']) . " missing key(s) in {$r['path']}\n";
    foreach ($r['missing'] as $k) {
        echo "  + {$k}\n";
    }
}

if ($dynamicCalls !== []) {
    echo "\nNote: " . count($dynamicCalls) . " dynamic ->trans() / |trans call(s) skipped (not statically extractable):\n";
    foreach (array_slice($dynamicCalls, 0, 5) as $hit) {
        echo "  ! {$hit}\n";
    }
    if (count($dynamicCalls) > 5) {
        echo "  ... (" . (count($dynamicCalls) - 5) . " more)\n";
    }
}

if ($mode === 'write' && $totalMissing > 0) {
    foreach ($report as $loc => $r) {
        if ($r['missing'] === []) {
            continue;
        }
        $append = "\n";
        foreach ($r['missing'] as $k) {
            $append .= "msgid \"{$k}\"\nmsgstr \"\"\n\n";
        }
        // strip the trailing extra newline we added
        $append = rtrim($append, "\n") . "\n";
        file_put_contents($r['path'], $append, FILE_APPEND);
        echo "[{$loc}] appended " . count($r['missing']) . " key(s) with empty msgstr.\n";
    }
    echo "\nFill in the empty msgstr values in each .po file (Poedit recommended -- see docs/translations.md).\n";
    exit(0);
}

if ($mode === 'check' && $totalMissing > 0) {
    exit(1);
}

if ($totalMissing === 0) {
    echo "\nAll " . count($usedKeys) . " statically-extractable keys are present in every locale.\n";
}
exit(0);
