<?php declare(strict_types=1);

namespace App\Services;

/**
 * Liest und aktualisiert die .env-Datei vom Setup-Wizard aus.
 *
 * Atomares Pattern: Ziel-File wird in eine .tmp-Datei geschrieben und
 * dann via rename() umgesetzt. flock() um konkurrierende Setup-Klicks
 * zu serialisieren.
 *
 * Nur fuer Setup gedacht. Im Normalbetrieb wird .env nicht geschrieben.
 */
final class EnvWriter
{
    public function __construct(private readonly string $envPath)
    {
    }

    /**
     * Liest die .env als assoziatives Array. Fehlende Datei => [].
     * Bewahrt Kommentare und Leerzeilen NICHT — Output ist nur Key=>Value.
     *
     * @return array<string,string>
     */
    public function readAll(): array
    {
        if (!is_file($this->envPath)) {
            return [];
        }
        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $raw = trim(substr($line, $eq + 1));
            // Entfernt umschliessende single- oder double-quotes (wie phpdotenv).
            if (
                (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
                || (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
            ) {
                $raw = substr($raw, 1, -1);
            }
            $out[$key] = $raw;
        }
        return $out;
    }

    /**
     * Schreibt/aktualisiert mehrere Keys. Existierende Zeilen werden ueberschrieben,
     * neue werden ans Ende angehaengt. Kommentare und Reihenfolge bestehender Zeilen
     * bleiben erhalten.
     *
     * @param array<string,string> $updates
     */
    public function update(array $updates): void
    {
        $fh = @fopen($this->envPath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("Kann .env nicht oeffnen: {$this->envPath}");
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException('Konnte .env nicht locken');
            }
            $existing = stream_get_contents($fh);
            $lines = $existing === '' ? [] : explode("\n", rtrim($existing, "\n"));

            $seen = [];
            foreach ($lines as $i => $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $eq));
                if (array_key_exists($key, $updates)) {
                    $lines[$i] = $this->formatLine($key, $updates[$key]);
                    $seen[$key] = true;
                }
            }

            foreach ($updates as $key => $value) {
                if (!isset($seen[$key])) {
                    $lines[] = $this->formatLine($key, $value);
                }
            }

            $new = implode("\n", $lines) . "\n";
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $new);
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function formatLine(string $key, string $value): string
    {
        // Single-quote wenn Whitespace oder Sonderzeichen ($, #, =) im Wert.
        $needsQuote = preg_match('/[\s#$=]/', $value) === 1
            || $value === ''
            || str_starts_with($value, '"');
        if ($needsQuote) {
            $escaped = str_replace("'", "'\\''", $value);
            return sprintf("%s='%s'", $key, $escaped);
        }
        return sprintf('%s=%s', $key, $value);
    }
}
