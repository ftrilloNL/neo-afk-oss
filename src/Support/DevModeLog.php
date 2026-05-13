<?php declare(strict_types=1);

namespace App\Support;

/**
 * Dev-Mode-File-Log fuer Provider-Operationen. Provider-Implementierungen
 * schreiben hier hin wenn APP_ENV != production — so kann man Flows
 * (Event-IDs, OOO-Settings) inspizieren ohne echte Calendar/Mailbox-Mutationen.
 */
final class DevModeLog
{
    public function __construct(private readonly string $logDir)
    {
    }

    public function write(string $filename, string $line): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        file_put_contents(
            $this->logDir . '/' . $filename,
            sprintf("[%s] %s\n", date('c'), $line),
            FILE_APPEND,
        );
    }
}
