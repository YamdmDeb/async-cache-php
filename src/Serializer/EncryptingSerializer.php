<?php

namespace Fyennyi\AsyncCache\Serializer;

/**
 * Decorator that encrypts data after serialization and decrypts before unserialization
 */
class EncryptingSerializer implements SerializerInterface
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * @param SerializerInterface $serializer The inner serializer to wrap
     * @param string $key Secret encryption key (must be 32 bytes for aes-256)
     */
    public function __construct(
        private SerializerInterface $serializer,
        private string $key
    ) {
        if (strlen($this->key) !== 32) {
            throw new \InvalidArgumentException("Encryption key must be exactly 32 bytes for AES-256");
        }
    }

    public function serialize(mixed $data): string
    {
        $plaintext = $this->serializer->serialize($data);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER));

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException("Encryption failed: " . openssl_error_string());
        }

        // We store IV + Tag + Ciphertext as a single string
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function unserialize(string $data): mixed
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new \RuntimeException("Failed to decode base64 data");
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $tagLen = 16; // Standard tag length for GCM

        $iv = substr($decoded, 0, $ivLen);
        $tag = substr($decoded, $ivLen, $tagLen);
        $ciphertext = substr($decoded, $ivLen + $tagLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException("Decryption failed. Data might be corrupted or key is invalid.");
        }

        return $this->serializer->unserialize($plaintext);
    }
}
