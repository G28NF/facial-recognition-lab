<?php
declare(strict_types=1);

namespace App\Infrastructure\Security;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class AuthenticatedCipher
{
    private const CIPHER = 'aes-256-gcm';
    private readonly string $encryptionKey;
    private readonly string $lookupKey;

    public function __construct(string $encodedMasterKey, private readonly string $keyId)
    {
        $masterKey = base64_decode($encodedMasterKey, true);
        if (!is_string($masterKey) || strlen($masterKey) !== 32 || trim($keyId) === '') {
            throw new InvalidArgumentException('Chave de dados invalida.');
        }
        $this->encryptionKey = hash_hkdf('sha256', $masterKey, 32, 'rfv2:encryption');
        $this->lookupKey = hash_hkdf('sha256', $masterKey, 32, 'rfv2:lookup');
    }

    /** @return array{ciphertext:string,nonce:string,auth_tag:string,key_id:string} */
    public function encrypt(string $plaintext, string $purpose): array
    {
        if ($plaintext === '' || $purpose === '') {
            throw new InvalidArgumentException('Dado ou finalidade de criptografia vazios.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $nonce, $tag, $purpose, 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Falha ao criptografar dado sensivel.');
        }
        return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'auth_tag' => $tag, 'key_id' => $this->keyId];
    }

    public function decrypt(string $ciphertext, string $nonce, string $tag, string $purpose): string
    {
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $nonce, $tag, $purpose);
        if (!is_string($plaintext)) {
            throw new RuntimeException('Dado sensivel invalido ou adulterado.');
        }
        return $plaintext;
    }

    /** @param array<mixed> $vector
     *  @return array{ciphertext:string,nonce:string,auth_tag:string,key_id:string}
     *  @throws JsonException
     */
    public function encryptEmbedding(array $vector, int $dimensions): array
    {
        if (!array_is_list($vector) || count($vector) !== $dimensions || $dimensions < 1) {
            throw new InvalidArgumentException('Dimensoes do embedding invalidas.');
        }
        $normalized = [];
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new InvalidArgumentException('Embedding contem valor invalido.');
            }
            $normalized[] = (float) $value;
        }
        return $this->encrypt(json_encode($normalized, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'embedding');
    }

    public function lookupHash(string $value): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
        if ($normalized === '') {
            throw new InvalidArgumentException('Valor de busca vazio.');
        }
        return hash_hmac('sha256', $normalized, $this->lookupKey, true);
    }

    /** @return list<float> */
    public function decryptEmbedding(string $ciphertext, string $nonce, string $tag, int $dimensions): array
    {
        try {
            $decoded = json_decode($this->decrypt($ciphertext, $nonce, $tag, 'embedding'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Embedding criptografado invalido.', 0, $error);
        }
        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== $dimensions) {
            throw new RuntimeException('Dimensoes do embedding criptografado invalidas.');
        }
        $vector = [];
        foreach ($decoded as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new RuntimeException('Embedding criptografado contem valor invalido.');
            }
            $vector[] = (float) $value;
        }
        return $vector;
    }
}
