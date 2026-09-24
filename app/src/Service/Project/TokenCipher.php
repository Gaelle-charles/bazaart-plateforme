<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * TokenCipher — chiffrement symétrique des jetons OAuth Google Drive (ADR-0037).
 *
 * Algorithme : XSalsa20-Poly1305 via libsodium (sodium_crypto_secretbox), inclus
 * dans PHP depuis 7.2. C'est du chiffrement AUTHENTIFIÉ : un jeton modifié en base
 * est détecté (decrypt() renvoie null) au lieu de produire n'importe quoi.
 *
 * Clé : dérivée de APP_SECRET (hash BLAKE2b de 32 octets). Conséquence à connaître :
 * si APP_SECRET change, le jeton devient illisible → il suffit de reconnecter le Drive.
 *
 * Format stocké : « v1:<base64(nonce || texte chiffré)> ». Le préfixe de version
 * permettra de changer d'algorithme plus tard sans casser les anciennes valeurs.
 */
final class TokenCipher
{
    private const string PREFIX = 'v1:';

    public function __construct(
        private readonly string $appSecret,
    ) {}

    public function encrypt(string $plainText): string
    {
        // Un nonce (« number used once ») aléatoire à chaque chiffrement : chiffrer
        // deux fois le même jeton donne deux résultats différents.
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plainText, $nonce, $this->key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /** Déchiffre, ou renvoie null si la valeur est illisible / altérée / clé différente. */
    public function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());

        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        // Le « contexte » (préfixe) garantit que cette clé est propre à cet usage :
        // la même APP_SECRET dérivera d'autres clés pour d'autres usages éventuels.
        return sodium_crypto_generichash('bazaart-project-drive|' . $this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
