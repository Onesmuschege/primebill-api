<?php

namespace App\Services\Network;

interface RouterAdapterInterface
{
    public function createUser(array $data): bool;

    public function deleteUser(string $username): bool;

    public function suspendUser(string $username): bool;

    public function unsuspendUser(string $username): bool;

    public function testConnection(): bool;
}
