<?php

namespace App\Services\Odoo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OdooClient
{
    private ?int $uid = null;

    public function __construct(
        private readonly string $url,
        private readonly string $db,
        private readonly string $login,
        private readonly string $apiKey,
        private readonly int $timeout = 30,
    ) {}

    public function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $result = $this->jsonRpc('common', 'authenticate', [
            $this->db,
            $this->login,
            $this->apiKey,
            new \stdClass(),
        ]);

        if (! is_int($result)) {
            throw new RuntimeException(
                'Odoo authentication failed: credentials rejected (db/login/api_key mismatch).'
            );
        }

        return $this->uid = $result;
    }

    public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid = $this->authenticate();

        return $this->jsonRpc('object', 'execute_kw', [
            $this->db,
            $uid,
            $this->apiKey,
            $model,
            $method,
            $args,
            (object) $kwargs,
        ]);
    }

    private function jsonRpc(string $service, string $method, array $args): mixed
    {
        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($this->url, '/').'/jsonrpc', [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => $service,
                    'method' => $method,
                    'args' => $args,
                ],
                'id' => random_int(1, PHP_INT_MAX),
            ]);

        $response->throw();

        $body = $response->json();

        if (isset($body['error'])) {
            $msg = $body['error']['data']['message'] ?? $body['error']['message'] ?? 'unknown error';
            throw new RuntimeException("Odoo error ({$service}.{$method}): {$msg}");
        }

        return $body['result'] ?? null;
    }
}
