<?php
namespace MoneyPulse;

class MoneyPulseClient
{
    private string $secretKey;
    private string $baseUrl;
    public Payment $payments;
    public Payout $payouts;
    public Billing $billing;

    public function __construct(string $secretKey, string $baseUrl = 'https://api.money-pulse.org')
    {
        $this->secretKey = $secretKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->payments = new Payment($this);
        $this->payouts = new Payout($this);
        $this->billing = new Billing($this);
    }

    /** UUID v4, sans dépendance externe -- utilisé comme clé d'idempotence par défaut. */
    public function generateIdempotencyKey(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function request(string $method, string $path, array $data = [], ?string $idempotencyKey = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();
        $headers = [
            'X-Api-Key: ' . $this->secretKey,
            'Content-Type: application/json',
            'X-SDK: money-pulse-php/2.1.0',
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

        if ($method === 'POST' || $method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
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
        $idempotencyKey = $params['idempotencyKey'] ?? $this->client->generateIdempotencyKey();
        unset($params['idempotencyKey']);
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

    public function create(array $params): array
    {
        // FIX (F-056bis, corrigé après vérification approfondie du corps
        // attendu) : POST /api/v1/payouts (PayoutController::createPayout)
        // lit destinationDetails, jamais recipient -- envoyer ['recipient'
        // => [...]] comme le fait ce SDK y était silencieusement ignoré
        // (destinataire remplacé par des valeurs vides/"N/A", aucune
        // erreur renvoyée). La route qui lit bien `recipient` est
        // /api/v1/payments/payouts/initiate (PaymentController::initiatePayout).
        $idempotencyKey = $params['idempotencyKey'] ?? $this->client->generateIdempotencyKey();
        unset($params['idempotencyKey']);
        return $this->client->request('POST', '/api/v1/payments/payouts/initiate', $params, $idempotencyKey);
    }

    // FIX (F-056bis) : retrieve() et verify() sont retirees. Le backend
    // n'expose aucune route GET /api/v1/payouts/{id} ni
    // /api/v1/payouts/{id}/verify (cf backend/src/routes/payouts.ts, qui
    // n'expose que GET '/' pour lister et GET '/balance'). Tout appel a ces
    // methodes echouait systematiquement en 404. A re-ajouter seulement
    // si ces routes sont creees cote backend.
}

/**
 * Facturation récurrente : abonnements + usage pour les utilisateurs finaux
 * de votre propre application (ex. les vendeurs qui utilisent votre
 * plateforme) -- pas pour Money-Pulse lui-même.
 */
class Billing
{
    private MoneyPulseClient $client;
    public function __construct(MoneyPulseClient $client) { $this->client = $client; }

    public function createPlan(array $params): array
    {
        return $this->client->request('POST', '/api/v1/billing/plans', $params);
    }

    public function listPlans(bool $includeInactive = false): array
    {
        $suffix = $includeInactive ? '?includeInactive=true' : '';
        return $this->client->request('GET', '/api/v1/billing/plans' . $suffix);
    }

    public function deactivatePlan(string $id): array
    {
        return $this->client->request('DELETE', "/api/v1/billing/plans/{$id}");
    }

    public function upsertCustomer(array $params): array
    {
        return $this->client->request('POST', '/api/v1/billing/customers', $params);
    }

    /** Retourne ['subscription' => ..., 'invoice' => ..., 'checkoutUrl' => ...] -- checkoutUrl est le lien de paiement hébergé à présenter à l'utilisateur final (aucun débit automatique n'existe côté Money-Pulse). */
    public function createSubscription(string $billingCustomerId, string $planCode): array
    {
        return $this->client->request('POST', '/api/v1/billing/subscriptions', [
            'billingCustomerId' => $billingCustomerId,
            'planCode' => $planCode,
        ]);
    }

    public function cancelSubscription(string $id, bool $atPeriodEnd = true, ?string $reason = null): array
    {
        return $this->client->request('POST', "/api/v1/billing/subscriptions/{$id}/cancel", [
            'atPeriodEnd' => $atPeriodEnd,
            'reason' => $reason,
        ]);
    }

    /** Enregistre un relevé d'usage (ex. commission sur une vente), agrégé à la prochaine facture de l'abonnement concerné. */
    public function recordUsage(array $params): array
    {
        return $this->client->request('POST', '/api/v1/billing/usage', $params);
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
