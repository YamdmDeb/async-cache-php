<?php

/*
 *
 *     _                          ____           _            ____  _   _ ____
 *    / \   ___ _   _ _ __   ___ / ___|__ _  ___| |__   ___  |  _ \| | | |  _ \
 *   / _ \ / __| | | | '_ \ / __| |   / _` |/ __| '_ \ / _ \ | |_) | |_| | |_) |
 *  / ___ \\__ \ |_| | | | | (__| |__| (_| | (__| | | |  __/ |  __/|  _  |  __/
 * /_/   \_\___/\__, |_| |_|\___|\____\__,_|\___|_| |_|\___| |_|   |_| |_|_|
 *              |___/
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author Serhii Cherneha
 * @link https://chernega.eu.org/
 *
 *
 */

namespace Fyennyi\AsyncCache\Serializer;

/**
 * Security decorator that encrypts data after serialization and decrypts before unserialization
 */
class EncryptingSerializer implements SerializerInterface
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * @param  SerializerInterface  $serializer  The inner serializer to wrap
     * @param  string               $key         Secret encryption key (exactly 32 bytes for AES-256)
     *
     * @throws \InvalidArgumentException If the key length is incorrect
     */
    public function __construct(
        private SerializerInterface $serializer,
        private string $key
    ) {
        if (strlen($this->key) !== 32) {
            throw new \InvalidArgumentException("Encryption key must be exactly 32 bytes for AES-256");
        }
    }

    /**
     * Serializes and encrypts data
     *
     * @param  mixed  $data  Data to process
     * @return string        Base64-encoded encrypted package (IV + TAG + Ciphertext)
     *
     * @throws \RuntimeException If encryption fails
     */
    public function serialize(mixed $data) : string
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

    /**
     * Decrypts and unserializes data
     *
     * @param  string  $data  Base64-encoded encrypted package
     * @return mixed          Original reconstructed data
     *
     * @throws \RuntimeException If decryption fails or data is corrupted
     */
    public function unserialize(string $data) : mixed
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
