<?php

namespace App\Services\Network;

use App\Models\Router;
use RouterOS\Client;
use RouterOS\Query;
use Exception;

class MikroTikService
{
    protected ?Client $client = null;
    protected Router $router;

    public function connect(Router $router): bool
    {
        try {
            $this->router = $router;
            $this->client = new Client([
                'host' => $router->ip_address,
                'user' => $router->username,
                'pass' => $router->password,
                'port' => $router->port ?? 8728,
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getRouterResources(): array
    {
        try {
            $query    = new Query('/system/resource/print');
            $response = $this->client->query($query)->read();
            return $response[0] ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    public function getActiveSessions(): array
    {
        try {
            $query    = new Query('/ppp/active/print');
            $response = $this->client->query($query)->read();
            return $response ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    public function getTrafficStats(string $interface = 'ether1'): array
    {
        try {
            $query = new Query('/interface/print');
            $query->where('name', $interface);
            $response = $this->client->query($query)->read();
            return $response[0] ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Return traffic counters for every configured interface on the router.
     *
     * @return array<int, array{name: string, 'rx-byte': int, 'tx-byte': int, 'rx-packet': int, 'tx-packet': int}>
     */
    public function getAllInterfacesTraffic(): array
    {
        try {
            $query    = new Query('/interface/print');
            $response = $this->client->query($query)->read();

            if (!is_array($response)) {
                return [];
            }

            $result = [];
            foreach ($response as $iface) {
                if (!is_array($iface) || empty($iface['name'])) {
                    continue;
                }

                $result[] = [
                    'name'       => (string) $iface['name'],
                    'rx-byte'    => (int) ($iface['rx-byte'] ?? 0),
                    'tx-byte'    => (int) ($iface['tx-byte'] ?? 0),
                    'rx-packet'  => (int) ($iface['rx-packet'] ?? 0),
                    'tx-packet'  => (int) ($iface['tx-packet'] ?? 0),
                ];
            }

            return $result;
        } catch (Exception $e) {
            return [];
        }
    }

    public function addPPPoEUser(string $username, string $password, string $profile = 'default'): bool
    {
        try {
            $query = new Query('/ppp/secret/add');
            $query->equal('name', $username);
            $query->equal('password', $password);
            $query->equal('service', 'pppoe');
            $query->equal('profile', $profile);
            $this->client->query($query)->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function removePPPoEUser(string $username): bool
    {
        try {
            $query = new Query('/ppp/secret/remove');
            $query->equal('.id', $this->getPPPoEUserId($username));
            $this->client->query($query)->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function disablePPPoEUser(string $username): bool
    {
        try {
            $query = new Query('/ppp/secret/disable');
            $query->equal('.id', $this->getPPPoEUserId($username));
            $this->client->query($query)->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function enablePPPoEUser(string $username): bool
    {
        try {
            $query = new Query('/ppp/secret/enable');
            $query->equal('.id', $this->getPPPoEUserId($username));
            $this->client->query($query)->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function addHotspotUser(string $username, string $password, string $profile = 'default'): bool
    {
        try {
            $query = new Query('/ip/hotspot/user/add');
            $query->equal('name', $username);
            $query->equal('password', $password);
            $query->equal('profile', $profile);
            $this->client->query($query)->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function removeHotspotUser(string $username): bool
    {
        try {
            $query = new Query('/ip/hotspot/user/remove');
            $query->equal('.id', $this->getHotspotUserId($username));
            $this->client->query($query)->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function disableHotspotUser(string $username): bool
    {
        try {
            $query = new Query('/ip/hotspot/user/disable');
            $query->equal('.id', $this->getHotspotUserId($username));
            $this->client->query($query)->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function enableHotspotUser(string $username): bool
    {
        try {
            $query = new Query('/ip/hotspot/user/enable');
            $query->equal('.id', $this->getHotspotUserId($username));
            $this->client->query($query)->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getPPPoEUserId(string $username): string
    {
        $query = new Query('/ppp/secret/print');
        $query->where('name', $username);
        $response = $this->client->query($query)->read();
        return $response[0]['.id'] ?? '';
    }

    private function getHotspotUserId(string $username): string
    {
        $query = new Query('/ip/hotspot/user/print');
        $query->where('name', $username);
        $response = $this->client->query($query)->read();
        return $response[0]['.id'] ?? '';
    }

    public function testConnection(): bool
    {
        try {
            $resources = $this->getRouterResources();
            return !empty($resources);
        } catch (Exception $e) {
            return false;
        }
    }
}
