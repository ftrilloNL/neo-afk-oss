<?php declare(strict_types=1);

namespace App\Services;

/**
 * Speichert User-Profilbilder unter var/avatars/{user-id}.jpg. Die Photo-Bytes
 * werden vom IdentityProvider beim SSO-Callback geholt (provider-spezifisch)
 * und hier nur persistiert — kein HTTP-Code in dieser Klasse.
 *
 * Best-effort: jeder Caller darf storeBytes() ohne try/catch nutzen, der
 * Login soll nicht scheitern wenn das Schreiben hakt.
 */
final class AvatarService
{
    public function __construct(private readonly string $storagePath)
    {
    }

    public function avatarPath(int $userId): string
    {
        return $this->storagePath . '/' . $userId . '.jpg';
    }

    public function exists(int $userId): bool
    {
        $path = $this->avatarPath($userId);
        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Speichert die Photo-Bytes atomar (tmp + rename) unter var/avatars/{user-id}.jpg.
     * Leere Bytes loeschen eine ggf. vorhandene alte Datei (User hat Photo entfernt).
     */
    public function storeBytes(int $userId, string $bytes): void
    {
        if ($bytes === '') {
            $this->delete($userId);
            return;
        }

        $path = $this->avatarPath($userId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $bytes);
        chmod($tmp, 0644);
        rename($tmp, $path);
    }

    public function delete(int $userId): void
    {
        $path = $this->avatarPath($userId);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
