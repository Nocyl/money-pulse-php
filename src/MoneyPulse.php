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

    /**
     * @param string|null $idempotencyKey Clé unique identifiant la requête.
     *   Si fournie, une nouvelle tentative avec la même clé ne créera pas
     *   d'opération en double côté serveur.
     */
    public function request(string $method, string $path, array $data = [], ?string $idempotencyKey = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();

        $headers = [
            'X-Api-Key: ' . $this->secretKey,
            'Content-Type: application/json',
            'X-SDK: money-pulse-php/1.0.0',
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
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

    /**
     * Crée un paiement. Une clé d'idempotence est générée automatiquement
     * si vous n'en fournissez pas une via $params['idempotency_key'] —
     * utile pour sécuriser vos propres tentatives de renvoi en cas
     * d'erreur réseau.
     */
    public function create(array $params): array
    {
        $idempotencyKey = $params['idempotency_key'] ?? bin2hex(random_bytes(16));
        unset($params['idempotency_key']);
        return $this->client->request('POST', '/api/v1/payments/initiate', $params, $idempotencyKey);
    }

    public function retrieve(string $id): array
    {
        return $this->client->request('GET', "/api/v1/payments/{$id}/status");
    }
}

class Payout
{
    private MoneyPulseClient $client;
    public function __construct(MoneyPulseClient $client) { $this->client = $client; }

    /**
     * Initie un retrait vers le bénéficiaire indiqué. Une clé
     * d'idempotence est générée automatiquement si vous n'en fournissez
     * pas une via $params['idempotency_key'].
     */
    public function create(array $params): array
    {
        $idempotencyKey = $params['idempotency_key'] ?? bin2hex(random_bytes(16));
        unset($params['idempotency_key']);
        return $this->client->request('POST', '/api/v1/payouts', $params, $idempotencyKey);
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
