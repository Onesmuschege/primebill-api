<?php

namespace App\Services\Radius;

use App\Models\ClientAccount;
use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * Real RADIUS Dynamic Authorization client (RFC 5176) for MikroTik RouterOS.
 *
 * Sends a wire-format CoA-Request (code 43) carrying the User-Name and the
 * Mikrotik-Rate-Limit VSA (vendor 14988, attribute 8) over UDP to the NAS's
 * CoA port and validates the CoA-ACK (44) / CoA-NAK (45) response,
 * including the Response Authenticator.
 *
 * When the NAS NAKs the CoA (rate-limit attributes commonly cannot be
 * applied to a live session) the client falls back to a Disconnect-Request
 * (code 40) so the customer re-authenticates and picks up the new rate from
 * radreply — RouterOS supports CoA for some attributes but requires a
 * disconnect/reconnect for others (Core ISP Gate — Section 21).
 *
 * Message-Authenticator (type 80) is included and HMAC-MD5 signed as
 * required for Dynamic Authorization messages (RFC 3579 / RFC 5176).
 *
 * The shared secret comes from the Router model (`radius_secret_encrypted`,
 * decrypted transparently by the Encryptable trait). It is never logged.
 */
class RadiusCoaClient
{
    public const CODE_COA_REQUEST        = 43;
    public const CODE_COA_ACK            = 44;
    public const CODE_COA_NAK            = 45;
    public const CODE_DISCONNECT_REQUEST = 40;
    public const CODE_DISCONNECT_ACK     = 41;
    public const CODE_DISCONNECT_NAK     = 42;

    /** Seconds to wait for a response from the NAS. */
    protected int $timeoutSeconds;

    public function __construct(?int $timeoutSeconds = null)
    {
        $this->timeoutSeconds = $timeoutSeconds ?? (int) config('network.coa_timeout_seconds', 5);
    }

    /**
     * Attempt a live rate-limit change on the customer's active session.
     *
     * Returns a structured outcome — callers must surface it, never assume
     * "HTTP 200 = disconnected" (Section 21).
     */
    public function changeRate(ClientAccount $account, string $rate): CoaResult
    {
        $attributes = array_merge(
            $this->userNameAttribute($account->username),
            $this->mikrotikRateLimitAttribute($rate)
        );

        return $this->withFallback(
            $account,
            self::CODE_COA_REQUEST,
            $attributes,
            "coa change-rate to {$rate}"
        );
    }

    /**
     * Disconnect the account's live session via Disconnect-Request.
     */
    public function disconnect(ClientAccount $account): CoaResult
    {
        return $this->send(
            $account,
            self::CODE_DISCONNECT_REQUEST,
            $this->userNameAttribute($account->username),
            'disconnect session'
        );
    }

    /**
     * CoA first; if the NAS NAKs (unsupported attribute on a live session)
     * fall back to a Disconnect so re-auth applies the new rate.
     */
    protected function withFallback(ClientAccount $account, int $code, array $attributes, string $what): CoaResult
    {
        $result = $this->send($account, $code, $attributes, $what);

        if ($result->success) {
            return $result;
        }

        if ($result->responseCode === self::CODE_COA_NAK) {
            Log::info('RadiusCoaClient: CoA NAK, falling back to Disconnect', [
                'username' => $account->username,
            ]);

            $disconnect = $this->send(
                $account,
                self::CODE_DISCONNECT_REQUEST,
                $this->userNameAttribute($account->username),
                "disconnect after CoA NAK ({$what})"
            );

            if ($disconnect->success) {
                return new CoaResult(
                    true,
                    'disconnect',
                    $disconnect->responseCode,
                    $disconnect->error,
                    'coa_nak_fell_back_to_disconnect'
                );
            }

            return new CoaResult(
                false,
                null,
                $disconnect->responseCode,
                $disconnect->error ?: 'CoA NAK and Disconnect failed',
                'coa_nak_disconnect_failed'
            );
        }

        return $result;
    }

    /**
     * Send one Dynamic Authorization packet and await/validate the response.
     */
    public function send(ClientAccount $account, int $code, array $attributes, string $what): CoaResult
    {
        $router = $account->router;

        if (!$router instanceof Router) {
            return new CoaResult(false, null, null, 'No NAS/router configured for account', 'no_nas');
        }

        $secret = $router->radius_secret_encrypted;

        if (empty($secret)) {
            return new CoaResult(false, null, null, 'No RADIUS shared secret configured for router', 'no_secret');
        }

        $host = $router->radius_ip ?: $router->ip_address;
        $port = $router->coa_port ?: 3799;

        if (empty($host)) {
            return new CoaResult(false, null, null, 'Router has no reachable address for CoA', 'no_address');
        }

        $packet = $this->buildPacket($code, $attributes, $secret);

        try {
            $response = $this->transmit($host, $port, $packet, $secret);
        } catch (\Throwable $e) {
            Log::warning('RadiusCoaClient: transport failure', [
                'username' => $account->username,
                'host'     => $host,
                'error'    => $e->getMessage(),
            ]);

            return new CoaResult(false, null, null, 'Transport failure: ' . $e->getMessage(), 'transport_error');
        }

        if ($response === null) {
            return new CoaResult(false, null, null, 'No response from NAS (timeout)', 'timeout');
        }

        [$responseCode, $valid] = $response;

        if (!$valid) {
            return new CoaResult(false, null, $responseCode, 'Invalid Response Authenticator', 'invalid_response');
        }

        $isAck = ($code === self::CODE_COA_REQUEST && $responseCode === self::CODE_COA_ACK)
            || ($code === self::CODE_DISCONNECT_REQUEST && $responseCode === self::CODE_DISCONNECT_ACK);

        if ($isAck) {
            return new CoaResult(true, $code === self::CODE_COA_REQUEST ? 'coa' : 'disconnect', $responseCode, null, null);
        }

        // CoA-NAK / Disconnect-NAK — the NAS refused the request. For CoA the
        // caller (withFallback) decides whether to fall back to Disconnect.
        $reason = ($responseCode === self::CODE_COA_NAK || $responseCode === self::CODE_DISCONNECT_NAK)
            ? 'NAS rejected the request (NAK)'
            : "Unexpected response code {$responseCode} from NAS";

        Log::warning('RadiusCoaClient: request not acknowledged', [
            'username'       => $account->username,
            'what'           => $what,
            'response_code'  => $responseCode,
        ]);

        return new CoaResult(false, null, $responseCode, $reason, 'nak');
    }

    // ─── Packet construction (RFC 2865 / RFC 5176 wire format) ──────────

    /**
     * Build a complete RADIUS Dynamic Authorization packet.
     *
     * Layout: Code(1) Identifier(1) Length(2) Authenticator(16) Attributes...
     * Message-Authenticator (80) is appended and HMAC-MD5 signed over the
     * whole packet with its value zeroed.
     */
    public function buildPacket(int $code, array $attributes, string $secret, ?int $identifier = null): string
    {
        $identifier = $identifier ?? random_int(0, 255);

        // Message-Authenticator placeholder (16 zero bytes) appended last.
        $attributes[] = $this->attribute(80, str_repeat("\x00", 16));

        $body = '';
        foreach ($attributes as $attribute) {
            $body .= $attribute;
        }

        $authenticator = random_bytes(16);
        $length = 4 + 16 + strlen($body);

        $packet = pack('CCn', $code, $identifier, $length) . $authenticator . $body;

        // RFC 3579 §3.2 — HMAC-MD5 over the packet with the Message-
        // Authenticator value zeroed, using the shared secret as key.
        $mac = hash_hmac('md5', $packet, $secret, true);

        // Message-Authenticator is the LAST attribute; its value occupies
        // the final 16 bytes.
        $offset = strlen($packet) - 16;

        return substr($packet, 0, $offset) . $mac;
    }

    /**
     * Validate a response packet against the request authenticator and
     * secret. Returns [responseCode, authenticatorValid].
     *
     * Response Authenticator = MD5(Code + ID + Length + RequestAuth + Attributes + Secret)
     */
    public function parseResponse(string $response, string $requestAuthenticator, string $secret): array
    {
        if (strlen($response) < 20) {
            return [null, false];
        }

        $unpacked = unpack('Ccode/Cid/nlength', substr($response, 0, 4));
        $length   = $unpacked['length'];

        if ($length > strlen($response)) {
            return [$unpacked['code'], false];
        }

        $responseAuth = substr($response, 4, 16);
        $attributes   = substr($response, 20, $length - 20);

        $expected = md5(
            substr($response, 0, 4) . $requestAuthenticator . $attributes . $secret,
            true
        );

        return [$unpacked['code'], hash_equals($expected, $responseAuth)];
    }

    /** Wire one attribute: Type(1) Length(1) Value. */
    protected function attribute(int $type, string $value): string
    {
        return pack('CC', $type, 2 + strlen($value)) . $value;
    }

    /** Vendor-Specific attribute wrapping a MikroTik VSA (vendor 14988). */
    protected function vendorAttribute(int $vendorId, int $vendorType, string $value): string
    {
        $inner = pack('NC', $vendorId, $vendorType) . $value;

        return $this->attribute(26, $inner);
    }

    protected function userNameAttribute(string $username): array
    {
        return [$this->attribute(1, $username)];
    }

    protected function mikrotikRateLimitAttribute(string $rate): array
    {
        // Vendor 14988 (MikroTik), vendor-type 8 = Mikrotik-Rate-Limit.
        return [$this->vendorAttribute(14988, 8, $rate)];
    }

    // ─── Transport ──────────────────────────────────────────────────────

    /**
     * Transmit the packet over UDP and return [responseCode, authenticatorValid]
     * or null on timeout.
     */
    protected function transmit(string $host, int $port, string $packet, string $secret): ?array
    {
        $socket = @fsockopen('udp://' . $host, $port, $errno, $errstr, $this->timeoutSeconds);

        if ($socket === false) {
            throw new \RuntimeException("UDP connect failed: {$errstr} ({$errno})");
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);

            fwrite($socket, $packet);

            $response = fread($socket, 4096);

            $meta = stream_get_meta_data($socket);
            if ($response === false || $response === '' || ($meta['timed_out'] ?? false)) {
                return null;
            }

            // The request authenticator is at bytes 4..20 of the packet.
            return $this->parseResponse($response, substr($packet, 4, 16), $secret);
        } finally {
            fclose($socket);
        }
    }
}
