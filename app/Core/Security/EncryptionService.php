<?php

namespace App\Core\Security;

final readonly class EncryptionService
{
    private const ALGORITHM = 'xchacha20poly1305';
    private const VERSION = 'v1';

    private string $key;

    public function __construct(
        #[\SensitiveParameter]
        string $base64Key
    ) {
        if (!extension_loaded('sodium')) {
            throw new EncryptionException(
                'La extensión Sodium no está disponible en PHP.'
            );
        }

        $decodedKey = base64_decode(
            trim($base64Key),
            true
        );

        if ($decodedKey === false) {
            throw new EncryptionException(
                'La llave de cifrado no contiene un Base64 válido.'
            );
        }

        if (
            strlen($decodedKey) !==
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
        ) {
            throw new EncryptionException(
                sprintf(
                    'La llave de cifrado debe contener exactamente %d bytes.',
                    SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
                )
            );
        }

        $this->key = $decodedKey;
    }

    public function encryptString(
        #[\SensitiveParameter]
        string $plainText,
        string $context = ''
    ): string {
        try {
            $nonce = random_bytes(
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            );

            $cipherText =
                sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                    $plainText,
                    $context,
                    $nonce,
                    $this->key
                );

            $payload = base64_encode(
                $nonce . $cipherText
            );

            return sprintf(
                '%s:%s:%s',
                self::ALGORITHM,
                self::VERSION,
                $payload
            );
        } catch (\Throwable $exception) {
            throw new EncryptionException(
                'No fue posible cifrar la información.',
                previous: $exception
            );
        }
    }

    public function decryptString(
        #[\SensitiveParameter]
        string $encryptedValue,
        string $context = ''
    ): string {
        $parts = explode(':', $encryptedValue, 3);

        if (count($parts) !== 3) {
            throw new EncryptionException(
                'El valor cifrado no contiene un formato válido.'
            );
        }

        [$algorithm, $version, $encodedPayload] = $parts;

        if ($algorithm !== self::ALGORITHM) {
            throw new EncryptionException(
                "El algoritmo de cifrado '{$algorithm}' no está soportado."
            );
        }

        if ($version !== self::VERSION) {
            throw new EncryptionException(
                "La versión de cifrado '{$version}' no está soportada."
            );
        }

        $payload = base64_decode(
            $encodedPayload,
            true
        );

        if ($payload === false) {
            throw new EncryptionException(
                'El contenido cifrado no contiene un Base64 válido.'
            );
        }

        $nonceLength =
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        $minimumLength =
            $nonceLength +
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

        if (strlen($payload) < $minimumLength) {
            throw new EncryptionException(
                'El contenido cifrado está incompleto o dañado.'
            );
        }

        $nonce = substr(
            $payload,
            0,
            $nonceLength
        );

        $cipherText = substr(
            $payload,
            $nonceLength
        );

        try {
            $plainText =
                sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $cipherText,
                    $context,
                    $nonce,
                    $this->key
                );
        } catch (\Throwable $exception) {
            throw new EncryptionException(
                'No fue posible descifrar la información.',
                previous: $exception
            );
        }

        if ($plainText === false) {
            throw new EncryptionException(
                'No fue posible descifrar la información. La llave, el contexto o los datos no son válidos.'
            );
        }

        return $plainText;
    }
}