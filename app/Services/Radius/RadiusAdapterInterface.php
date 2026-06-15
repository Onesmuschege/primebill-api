<?php

namespace App\Services\Radius;

interface RadiusAdapterInterface
{
    public function createUser(array $data): bool;

    public function deleteUser(string $username): bool;

    public function syncUsers(): bool;
}
