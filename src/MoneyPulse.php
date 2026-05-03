<?php
namespace MoneyPulse;

class MoneyPulseClient
{
    private string $secretKey;
    private string $baseUrl;
    public Payment $payments;
    public Payout $payouts;

    public function __construct(string $secretKey, string $baseUrl = 'https://api.money-pulse.org')
    {
        $this->secretKey = $secretKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->payments = new Payment($this);
        $this->payouts = new Payout($this);
    }

    public function request(string $method, string $path, array $data = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->secretKey,
                'Content-Type: application/json',
                'X-SDK: money-pulse-php/1.0.0',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new MoneyPulseException("cURL error: {$error}", 0);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = $decoded['error']['message'] ?? $decoded['error'] ?? 'Request failed';
            $code = $decoded['error']['code'] ?? 'unknown';
            throw new MoneyPulseException($msg, $httpCode, $code);
        }

        return $decoded['data'] ?? $decoded;
    }
}

class Payment
{
    private MoneyPulseClient $client;
    public function __construct(MoneyPulseClient $client) { $this->client = $client; }

    public function create(array $params): array
    {
        return $this->client->request('POST', '/api/v1/payments/initiate', $params);
    }

    public function retrieve(string $id): array
    {
        return $this->client->request('GET', "/api/v1/payments/{$id}");
    }

    public function verify(string $id): array
    {
        return $this->client->request('GET', "/api/v1/payments/{$id}/verify");
    }

    public function markAsProcessed(string $id): array
    {
        return $this->client->request('POST', "/api/v1/payments/{$id}/mark-processed");
    }
}

class Payout
{
    private MoneyPulseClient $client;
    public function __construct(MoneyPulseClient $client) { $this->client = $client; }

    public function create(array $params): array
    {
        return $this->client->request('POST', '/api/v1/payouts/initiate', $params);
    }

    public function retrieve(string $id): array
    {
        return $this->client->request('GET', "/api/v1/payouts/{$id}");
    }

    public function verify(string $id): array
    {
        return $this->client->request('GET', "/api/v1/payouts/{$id}/verify");
    }
}

class MoneyPulseException extends \Exception
{
    private string $errorCode;

    public function __construct(string $message, int $httpCode = 0, string $errorCode = 'unknown')
    {
        parent::__construct($message, $httpCode);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string { return $this->errorCode; }
    public function getHttpCode(): int { return $this->getCode(); }
}
