<?php

namespace App\Models\Traits;

use Illuminate\Support\Facades\Crypt;

/**
 * Encryptable trait — transparently encrypts designated attributes at rest
 * and decrypts them on read.
 *
 * Usage:
 *
 *   class Router extends Model
 *   {
 *       use Encryptable;
 *
 *       protected $encryptable = ['password'];
 *   }
 *
 * The attribute is stored as an encrypted string in the DB, so any column
 * it maps to should be TEXT (not VARCHAR(255)) to avoid truncation.
 * Values written before the trait was applied (plaintext) are detected on
 * read and returned as-is so nothing breaks during rollout; they are only
 * written back encrypted on the next save.
 */
trait Encryptable
{
    /**
     * Get the list of attributes that should be encrypted at rest.
     * Override in the consuming model.
     */
    public function getEncryptableAttributes(): array
    {
        return property_exists($this, 'encryptable') ? (array) $this->encryptable : [];
    }

    /**
     * Decrypt an attribute value on read if it was encrypted.
     */
    protected function decryptValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Values that are not valid encrypted payloads (e.g. legacy plaintext,
        // or already-decrypted test fixtures) are returned unchanged so the
        // rollout doesn't corrupt existing data.
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /**
     * Encrypt an attribute value on write.
     */
    protected function encryptValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Detect plaintext (not starting with the encrypt prefix) and encrypt it.
        if (!str_starts_with($value, 'eyJ')) {
            try {
                return Crypt::encryptString($value);
            } catch (\Throwable $e) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * Get an attribute — decrypt on read.
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (in_array($key, $this->getEncryptableAttributes(), true) && is_string($value)) {
            return $this->decryptValue($value);
        }

        return $value;
    }

    /**
     * Set an attribute — encrypt on write.
     */
    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->getEncryptableAttributes(), true) && $value !== null && $value !== '') {
            $value = $this->encryptValue((string) $value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Override toArray so encrypted attributes are decrypted when the model
     * is serialized to JSON — matches the getAttribute behaviour.
     */
    public function toArray()
    {
        $array = parent::toArray();

        foreach ($this->getEncryptableAttributes() as $key) {
            if (array_key_exists($key, $array) && is_string($array[$key])) {
                $array[$key] = $this->decryptValue($array[$key]);
            }
        }

        return $array;
    }
}

