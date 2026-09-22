<?php
/**
 * CHIFFREMENT RÉVERSIBLE — pour les secrets qu'il faut RELIRE.
 *
 * À NE PAS CONFONDRE AVEC LE HACHAGE DES MOTS DE PASSE.
 * ======================================================
 * Un mot de passe d'utilisateur se HACHE (bcrypt) : on ne le relit
 * jamais, on compare. C'est ce que fait `password_hash()` partout
 * ailleurs dans le produit, et rien ici ne le remplace.
 *
 * Un mot de passe de SERVEUR SMTP, lui, doit être relu en clair au
 * moment de la connexion : le protocole l'exige. On ne peut donc pas
 * le hacher. Le stocker en clair en base est exclu — une sauvegarde
 * qui fuit livrerait le compte d'envoi de chaque école, c'est-à-dire
 * le droit d'écrire aux familles au nom de l'établissement.
 *
 * Il est donc CHIFFRÉ, avec une clé qui ne vit PAS en base :
 * `security.encryption_key`, dans `config.local.php`, hors du dépôt.
 * Une fuite de la base seule ne donne rien.
 *
 *   > Une clé rangée à côté de ce qu'elle protège ne protège rien.
 *
 * PAS DE REPLI EN CLAIR. Si la clé manque ou est invalide, les
 * fonctions LÈVENT. Un produit qui, faute de clé, se rabattrait
 * silencieusement sur du texte brut serait pire qu'un produit sans
 * chiffrement : il en donnerait l'illusion.
 *
 * Algorithme : `sodium_crypto_secretbox` (XSalsa20-Poly1305), fourni
 * avec PHP 8.2+ sans extension à installer — condition nécessaire sur
 * un hébergement mutualisé. Le format stocké est
 * `v1:<base64(nonce . chiffré)>`, préfixé pour qu'une rotation
 * d'algorithme reste possible sans deviner.
 */

declare(strict_types=1);

/** Préfixe de version — permet de changer d'algorithme sans ambiguïté. */
const CRYPTO_VERSION = 'v1';

/**
 * La clé de chiffrement, dérivée de la configuration.
 *
 * @throws RuntimeException si elle est absente ou mal formée.
 */
function crypto_key(): string
{
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    $raw = (string) config('security.encryption_key', '');

    if (trim($raw) === '') {
        throw new RuntimeException(
            'Aucune clé de chiffrement configurée. Renseignez '
            . 'security.encryption_key dans app/config/config.local.php '
            . '(32 octets en base64). Générez-la avec : '
            . 'php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'
        );
    }

    $decoded = base64_decode($raw, true);

    if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException(sprintf(
            'La clé security.encryption_key doit être %d octets encodés en base64. '
            . 'Générez-la avec : php -r "echo base64_encode(random_bytes(%d)), PHP_EOL;"',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        ));
    }

    return $key = $decoded;
}

/** Une clé de chiffrement est-elle utilisable ? Pour le DIRE à l'écran. */
function crypto_available(): bool
{
    try {
        crypto_key();

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Chiffre une chaîne. Rend `v1:<base64>`.
 *
 * Un nonce neuf à chaque appel : chiffrer deux fois le même secret ne
 * doit pas produire deux fois le même texte, sinon la base révèle que
 * deux écoles partagent un mot de passe.
 */
function crypto_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $cipher = sodium_crypto_secretbox($plain, $nonce, crypto_key());

    return CRYPTO_VERSION . ':' . base64_encode($nonce . $cipher);
}

/**
 * Déchiffre. Rend `null` si le texte est illisible — clé changée,
 * donnée corrompue, format inconnu.
 *
 * `null` plutôt qu'une exception : l'appelant doit pouvoir afficher
 * « configuration illisible, ressaisissez le mot de passe » au lieu
 * de planter une page entière.
 */
function crypto_decrypt(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }

    $parts = explode(':', $stored, 2);

    if (count($parts) !== 2 || $parts[0] !== CRYPTO_VERSION) {
        return null;
    }

    $raw = base64_decode($parts[1], true);

    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }

    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    try {
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, crypto_key());
    } catch (Throwable) {
        return null;
    }

    return $plain === false ? null : $plain;
}
