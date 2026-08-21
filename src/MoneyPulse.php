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
     * AUDIT 20/08/2026 : paramètre $idempotencyKey ajouté — confirmé
     * ABSENT du fichier source original fourni par Yves. Sans lui, un
     * échec réseau cURL suivi d'un nouvel appel (retry manuel côté
     * appelant, ou logique applicative) pouvait produire un paiement ou
     * un RETRAIT EN DOUBLE, le backend n'ayant aucun moyen de reconnaître
     * qu'il s'agissait de la même opération logique. Le middleware
     * backend (middleware/idempotency.ts, confirmé plus tôt cette
     * session) protège déjà /api/v1/payments/initiate et
     * /api/v1/payouts, mais uniquement si le header `Idempotency-Key`
     * est présent — jamais envoyé par ce SDK avant ce correctif.
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
     * AUDIT 20/08/2026 : génère automatiquement une clé d'idempotence via
     * random_bytes() (natif PHP 7+, pas de dépendance ajoutée) si l'appelant
     * n'en fournit pas une dans $params['idempotency_key']. Cette clé est
     * retirée du corps JSON envoyé (le backend ne l'attend qu'en header,
     * pas dans le payload — voir middleware/idempotency.ts) avant l'appel.
     */
    public function create(array $params): array
    {
        $idempotencyKey = $params['idempotency_key'] ?? bin2hex(random_bytes(16));
        unset($params['idempotency_key']);
        return $this->client->request('POST', '/api/v1/payments/initiate', $params, $idempotencyKey);
    }

    public function retrieve(string $id): array
    {
        // FIX (F-056) : le backend n'expose pas GET /api/v1/payments/{id}.
        // La seule route de lecture par identifiant est /:transactionId/status
        // (cf backend/src/routes/payments.ts).
        return $this->client->request('GET', "/api/v1/payments/{$id}/status");
    }

    // FIX (F-056) : verify() et markAsProcessed() sont retirees. Aucune route
    // backend ne les expose ( /api/v1/payments/{id}/verify et
    // /api/v1/payments/{id}/mark-processed sont inexistantes dans
    // backend/src/routes/payments.ts ) — les garder ferait echouer tout appel
    // en 404. Si ces fonctionnalites deviennent necessaires, elles doivent
    // d'abord etre exposees cote backend avant d'etre re-ajoutees ici.
}

class Payout
{
    private MoneyPulseClient $client;
    public function __construct(MoneyPulseClient $client) { $this->client = $client; }

    /**
     * AUDIT 20/08/2026 : même correctif que Payment::create() —
     * PRIORITAIRE ici, un retrait en double déplace réellement des fonds
     * hors du solde marchand (contrairement à un paiement, où le client
     * final subirait au pire un double débit visible et contestable).
     */
    public function create(array $params): array
    {
        // FIX (F-056bis) : le backend expose POST /api/v1/payouts (sans
        // /initiate) — cf backend/src/routes/payouts.ts ligne
        // `router.post('/', PayoutController.createPayout)`.
        $idempotencyKey = $params['idempotency_key'] ?? bin2hex(random_bytes(16));
        unset($params['idempotency_key']);
        return $this->client->request('POST', '/api/v1/payouts', $params, $idempotencyKey);
    }

    // FIX (F-056bis) : retrieve() et verify() sont retirees. Le backend
    // n'expose aucune route GET /api/v1/payouts/{id} ni
    // /api/v1/payouts/{id}/verify (cf backend/src/routes/payouts.ts, qui
    // n'expose que GET '/' pour lister et GET '/balance'). Tout appel a ces
    // methodes echouait systematiquement en 404. A re-ajouter seulement
    // si ces routes sont creees cote backend.
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
