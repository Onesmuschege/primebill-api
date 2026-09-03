<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Services\Radius\RadiusCoaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test as Test;
use Tests\TestCase;

class RadiusCoaClientTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);
    }

    protected function tearDown(): void
    {
        Tenant::setCurrent(null);
        parent::tearDown();
    }

    #[Test]
    public function build_packet_has_correct_wire_format(): void
    {
        $client = new RadiusCoaClient();
        $secret = 'testing123';
        $identifier = 7;

        $userName = 'testuser';
        $userNameAttr = pack('CC', 1, 2 + strlen($userName)) . $userName; // User-Name (type 1)
        $inner = pack('NC', 14988, 8) . '30m/30m'; // VSA vendor=14988 type=8
        $vsaAttr = pack('CC', 26, 2 + strlen($inner)) . $inner;          // Vendor-Specific (type 26)
        $packet = $client->buildPacket(43, [$userNameAttr, $vsaAttr], $secret, $identifier);

        $this->assertGreaterThanOrEqual(20, strlen($packet));
        $this->assertSame(43, ord($packet[0]));
        $this->assertSame($identifier, ord($packet[1]));
        $this->assertSame(strlen($packet), (ord($packet[2]) << 8) | ord($packet[3]));

        $payload = substr($packet, 20);
        $this->assertSame(1, ord($payload[0]));
        $this->assertSame(2 + strlen($userName), ord($payload[1]));
        $this->assertSame($userName, substr($payload, 2, strlen($userName)));

                $lastAttr = substr($payload, -18);
        $this->assertSame(80, ord($lastAttr[0]));
        $this->assertSame(18, ord($lastAttr[1])); // Message-Authenticator = type 80, len 18
    }

    #[Test]
    public function build_packet_message_authenticator_is_hmac_md5_signed(): void
    {
        $client = new RadiusCoaClient();
        $secret = 'shared-secret';
        $userNameAttr = pack('CC', 1, 2 + 4) . 'user';
        $packet = $client->buildPacket(43, [$userNameAttr], $secret, 41);

        $maValue = substr($packet, -16);
        $zeroed = substr($packet, 0, -16) . str_repeat("\x00", 16);
        $this->assertSame(hash_hmac('md5', $zeroed, $secret, true), $maValue);
    }

    #[Test]
    public function parse_response_validates_a_correctly_signed_ack(): void
    {
        $client = new RadiusCoaClient();
        $secret = 'mysecret';
        $userNameAttr = pack('CC', 1, 2 + 4) . 'user';
        $request = $client->buildPacket(RadiusCoaClient::CODE_COA_REQUEST, [$userNameAttr], $secret, 9);
        $reqAuth = substr($request, 4, 16);

        // CoA-ACK: Code(1) ID(1) Length(2 BE) + RequestAuth(16) + no attrs.
        $respCode = chr(RadiusCoaClient::CODE_COA_ACK);
        $respLen = pack('n', 20);
        $respAuth = md5($respCode . $request[1] . $respLen . $reqAuth . '' . $secret, true);
        $response = $respCode . $request[1] . $respLen . $respAuth;

        [$code, $valid] = $client->parseResponse($response, $reqAuth, $secret);
        $this->assertSame(44, $code);
        $this->assertTrue($valid);
    }

    #[Test]
    public function parse_response_rejects_a_tampered_authenticator(): void
    {
        $client = new RadiusCoaClient();
        $secret = 'mysecret';
        $userNameAttr = pack('CC', 1, 2 + 4) . 'user';
        $request = $client->buildPacket(RadiusCoaClient::CODE_COA_REQUEST, [$userNameAttr], $secret, 3);
        $reqAuth = substr($request, 4, 16);

                $respCode = chr(RadiusCoaClient::CODE_COA_ACK);
        $respLen = pack('n', 20);
        $respAuth = md5($respCode . $request[1] . $respLen . $reqAuth . '' . $secret, true);
        // Flip one byte of the authenticator (length-preserving mutation).
        $tamperedAuth = $respAuth;
        $tamperedAuth[5] = chr((ord($respAuth[5]) + 1) % 256);
        $response = $respCode . $request[1] . $respLen . $tamperedAuth;

        [$code, $valid] = $client->parseResponse($response, $reqAuth, $secret);
        $this->assertSame(44, $code);
        $this->assertFalse($valid);
    }

    #[Test]
    public function parse_response_rejects_a_wrong_shared_secret(): void
    {
        $client = new RadiusCoaClient();
        $secret = 'mysecret';
        $userNameAttr = pack('CC', 1, 2 + 4) . 'user';
        $request = $client->buildPacket(RadiusCoaClient::CODE_COA_REQUEST, [$userNameAttr], $secret, 2);
        $reqAuth = substr($request, 4, 16);

        $respCode = chr(RadiusCoaClient::CODE_COA_ACK);
        $respLen = pack('n', 20);
        $respAuth = md5($respCode . $request[1] . $respLen . $reqAuth . '' . $secret, true);
        $response = $respCode . $request[1] . $respLen . $respAuth;

        [, $valid] = $client->parseResponse($response, $reqAuth, 'wrong-secret');
        $this->assertFalse($valid);
    }

    #[Test]
    public function parse_response_rejects_truncated_packet(): void
    {
        $client = new RadiusCoaClient();
        [$code, $valid] = $client->parseResponse('short', '1234567890123456', 'secret');
        $this->assertNull($code);
        $this->assertFalse($valid);
    }
}
