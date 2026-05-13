<?php declare(strict_types=1);

namespace App\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Generischer RS256-JWT-Verifier gegen einen JWKS-Endpoint. Provider-agnostisch:
 * JWKS-URL, akzeptierte Issuer und erwartete Audience werden beim Konstruktor
 * mitgegeben. Nutzt openssl_verify + einen minimalen ASN.1-DER-Encoder
 * (JWK -> PEM), um eine harte firebase/php-jwt-Dependency zu vermeiden.
 *
 * JWKS wird in-memory pro Request gecached. Cross-Request-Caching koennte via
 * APCu nachgezogen werden — fuer wenige Logins pro Minute ist der extra
 * Roundtrip akzeptabel.
 */
final class JwksVerifier
{
    /** @var array<string, mixed>|null */
    private ?array $cachedJwks = null;

    /**
     * @param list<string> $acceptedIssuers Mehrere erlaubt fuer Provider wie
     *   Google, die sowohl `accounts.google.com` als auch `https://accounts.google.com`
     *   als gueltigen iss-Claim zurueckgeben.
     */
    public function __construct(
        private readonly string $jwksUrl,
        private readonly array $acceptedIssuers,
        private readonly string $expectedAudience,
        private readonly ClientInterface $http = new Client(['timeout' => 5]),
    ) {
    }

    /**
     * Verifiziert Signatur + Standard-Claims (iss, aud, exp, nbf) und gibt
     * die dekodierten Claims zurueck. Wirft \RuntimeException bei jeglichem
     * Fehler — der Aufrufer darf das Token dann nicht trusten.
     *
     * @return array<string, mixed>
     */
    public function verify(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new \RuntimeException('id_token: ungueltiges Format (kein JWT)');
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = $this->decodeJsonSegment($headerB64, 'header');
        $payload = $this->decodeJsonSegment($payloadB64, 'payload');
        $signature = $this->base64UrlDecode($signatureB64);

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new \RuntimeException('id_token: unerwarteter alg, erwartet RS256');
        }
        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '') {
            throw new \RuntimeException('id_token: header ohne kid');
        }

        $jwk = $this->findKey($kid);
        $pem = $this->jwkToPem($jwk);

        $signingInput = $headerB64 . '.' . $payloadB64;
        $result = openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new \RuntimeException('id_token: Signatur ungueltig');
        }

        $this->assertClaims($payload);
        return $payload;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertClaims(array $claims): void
    {
        $iss = (string) ($claims['iss'] ?? '');
        if (!in_array($iss, $this->acceptedIssuers, true)) {
            throw new \RuntimeException('id_token: iss-Claim stimmt nicht');
        }
        if (($claims['aud'] ?? '') !== $this->expectedAudience) {
            throw new \RuntimeException('id_token: aud-Claim stimmt nicht');
        }
        $now = time();
        $skew = 60; // 1 min clock skew tolerance
        if (!isset($claims['exp']) || (int) $claims['exp'] + $skew < $now) {
            throw new \RuntimeException('id_token: abgelaufen');
        }
        if (isset($claims['nbf']) && (int) $claims['nbf'] - $skew > $now) {
            throw new \RuntimeException('id_token: noch nicht gueltig (nbf)');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findKey(string $kid): array
    {
        $jwks = $this->fetchJwks();
        foreach ($jwks['keys'] ?? [] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }
        // Key-Rotation auf Provider-Seite, JWKS neu holen.
        $this->cachedJwks = null;
        $jwks = $this->fetchJwks();
        foreach ($jwks['keys'] ?? [] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }
        throw new \RuntimeException("id_token: kid {$kid} nicht in JWKS gefunden");
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(): array
    {
        if ($this->cachedJwks !== null) {
            return $this->cachedJwks;
        }
        $response = $this->http->request('GET', $this->jwksUrl);
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['keys']) || !is_array($data['keys'])) {
            throw new \RuntimeException('JWKS-Response: unerwartetes Format');
        }
        $this->cachedJwks = $data;
        return $data;
    }

    /**
     * Konvertiert ein JWK (RSA-Public-Key mit n + e) in ein PEM-encoded
     * SubjectPublicKeyInfo, das openssl_verify versteht. ASN.1-DER von Hand,
     * weil keine ext-asn1-Dependency in PHP-Standardbuild und phpseclib
     * waere fuer diesen einen Zweck overkill.
     *
     * @param array<string, mixed> $jwk
     */
    private function jwkToPem(array $jwk): string
    {
        $n = $this->base64UrlDecode((string) ($jwk['n'] ?? ''));
        $e = $this->base64UrlDecode((string) ($jwk['e'] ?? ''));
        if ($n === '' || $e === '') {
            throw new \RuntimeException('JWK ohne n oder e');
        }

        $modulus = $this->asn1Integer($n);
        $publicExponent = $this->asn1Integer($e);
        $rsaPublicKey = $this->asn1Sequence($modulus . $publicExponent);

        // rsaEncryption OID = 1.2.840.113549.1.1.1, DER-encoded
        $algId = $this->asn1Sequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"
            . "\x05\x00",
        );

        $bitString = "\x03" . $this->asn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $spki = $this->asn1Sequence($algId . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function asn1Integer(string $bytes): string
    {
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $this->asn1Length(strlen($bytes)) . $bytes;
    }

    private function asn1Sequence(string $content): string
    {
        return "\x30" . $this->asn1Length(strlen($content)) . $content;
    }

    private function asn1Length(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xff) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonSegment(string $b64, string $label): array
    {
        $raw = $this->base64UrlDecode($b64);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("id_token: {$label} kein gueltiges JSON");
        }
        return $decoded;
    }

    private function base64UrlDecode(string $input): string
    {
        $padded = $input . str_repeat('=', (4 - strlen($input) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('base64url-decode fehlgeschlagen');
        }
        return $decoded;
    }
}
