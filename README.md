# Money-Pulse PHP SDK

Official PHP SDK for [Money-Pulse](https://money-pulse.org). Compatible with **PHP 7.4+**, Laravel, Symfony, WordPress / WooCommerce.

## Installation

```bash
composer require money-pulse/money-pulse-php
```

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use MoneyPulse\MoneyPulseClient;

$client = new MoneyPulseClient('mp_live_votre_cle_api');

// Créer un paiement
$payment = $client->payments->create([
    'amount'       => 10000,
    'currency'     => 'XOF',
    'country'      => 'CI',
    'customer'     => ['email' => 'client@email.com', 'phone' => '+22507000000'],
    'callback_url' => 'https://votre-site.com/webhook',
]);

// Rediriger vers le checkout hosted
header('Location: ' . $payment['checkout_url']);
```

## Payouts (transferts sortants)

```php
$payout = $client->payouts->create([
    'amount'    => 50000,
    'currency'  => 'XOF',
    'country'   => 'CI',
    'recipient' => [
        'type'  => 'mobile_money',
        'phone' => '+22507000000',
        'name'  => 'Jean Kouassi',
    ],
    'description' => 'Retrait marchand #4521',
]);

// AUDIT 20/08/2026 : `payout_id` n'a pas pu être confirmé contre le
// backend réel cette session — champ conservé tel quel de la version
// précédente mais non vérifié. Inspectez vous-même la réponse brute
// (var_dump($payout)) avant de vous y fier en production.
echo $payout['payout_id'] ?? $payout;
```

## ⚠️ Mode Simulation — NON VÉRIFIÉ

> Cette section documentait `metadata.simulate` et un header
> `X-MP-Simulate` via une méthode `$client->setDefaultHeader(...)`.
> **AUDIT 20/08/2026 : cette méthode n'existe PAS dans le code source
> réel de `MoneyPulseClient`** (vérifié directement — la classe
> n'expose que `payments`, `payouts` et `request()`). Je n'ai par
> ailleurs trouvé aucune trace, côté backend, d'une logique de
> simulation basée sur le dernier chiffre du montant. Cette section a
> été retirée plutôt que de la corriger à l'aveugle — si ce mode existe
> réellement côté backend, il faut d'abord l'implémenter dans
> `MoneyPulseClient::request()` (ajout d'un header configurable) avant
> de le redocumenter ici.

## Webhooks — vérification de signature (HMAC SHA-256)

Money-Pulse signe chaque webhook avec votre `webhook_secret` (visible dans le dashboard).
**Toujours vérifier la signature** avant de traiter le payload.

⚠️ **AUDIT 20/08/2026** : la structure du payload ci-dessous a été corrigée. Confirmé
directement dans le code source du backend (`services/OutgoingWebhookService.ts`,
`services/orchestrator/WebhookProcessor.ts`) — la forme réelle est :
```json
{
  "event": "payment.succeeded",
  "created": 1755000000000,
  "data": {
    "transactionId": "tx_xxx",
    "status": "completed",
    "amount": 10000,
    "currency": "XOF",
    "netAmount": 9800,
    "fee": 200
  }
}
```
Pas de champ `type` (c'est `event`), pas de valeur `payment.success` (c'est
`payment.succeeded`), et **pas de `metadata`** — `order_id` n'est jamais transmis
dans le webhook. Le seul identifiant disponible est `data.transactionId` : stockez-le
vous-même au moment de la création du paiement pour pouvoir retrouver votre commande.

```php
<?php
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MONEYPULSE_SIGNATURE'] ?? '';
$secret    = getenv('MP_WEBHOOK_SECRET');

$expected = hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Signature invalide');
}

$event = json_decode($payload, true);

// CORRIGÉ : $event['event'], pas $event['type'].
switch ($event['event']) {
    case 'payment.succeeded':
        // CORRIGÉ : le seul identifiant transmis est transactionId,
        // pas de metadata.order_id (voir avertissement ci-dessus).
        $transactionId = $event['data']['transactionId'];
        // → marquer la commande comme payée, retrouvée par $transactionId
        break;
    case 'payment.failed':
        // → notifier le client
        break;
    // ⚠️ 'payout.completed' retiré : non confirmé contre le backend
    // cette session (aucun webhook payout examiné). À revérifier avant
    // de vous y fier pour un flux de retrait.
}
http_response_code(200);
echo 'OK';
```

## Exemple Laravel (route + middleware + controller)

**`routes/api.php`**

```php
use App\Http\Controllers\MoneyPulseWebhookController;

Route::post('/webhooks/money-pulse', [MoneyPulseWebhookController::class, 'handle'])
     ->middleware('moneypulse.signature');
```

**`app/Http/Middleware/VerifyMoneyPulseSignature.php`**

```php
<?php
namespace App\Http\Middleware;

use Closure;

class VerifyMoneyPulseSignature
{
    public function handle($request, Closure $next)
    {
        $expected = hash_hmac(
            'sha256',
            $request->getContent(),
            config('services.moneypulse.webhook_secret')
        );

        if (!hash_equals($expected, $request->header('X-MoneyPulse-Signature', ''))) {
            abort(401, 'Invalid Money-Pulse signature');
        }
        return $next($request);
    }
}
```

**`app/Http/Controllers/MoneyPulseWebhookController.php`**

```php
<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;

class MoneyPulseWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $event = $request->json()->all();

        // CORRIGÉ : 'event' (pas 'type'), 'payment.succeeded' (pas
        // 'payment.success'), recherche par transaction_id STOCKÉ PAR
        // VOUS à la création (pas metadata.order_id, inexistant).
        if ($event['event'] === 'payment.succeeded') {
            $transactionId = $event['data']['transactionId'];
            Order::where('money_pulse_transaction_id', $transactionId)->update([
                'status'      => 'paid',
                'paid_amount' => $event['data']['amount'],
                'paid_at'     => now(),
            ]);
        }

        return response()->json(['received' => true]);
    }
}
```

**Initier un paiement depuis un controller Laravel :**

```php
use MoneyPulse\MoneyPulseClient;

public function checkout(Order $order)
{
    $client = new MoneyPulseClient(config('services.moneypulse.api_key'));
    $payment = $client->payments->create([
        'amount'       => $order->total,
        'currency'     => 'XOF',
        'country'      => 'CI',
        'customer'     => ['email' => $order->customer_email, 'phone' => $order->customer_phone],
        'callback_url' => route('webhooks.moneypulse'),
        'return_url'   => route('orders.success', $order),
    ]);

    // CORRIGÉ : on stocke NOUS-MÊMES le transactionId (metadata.order_id
    // ne sera jamais renvoyé par le webhook, voir avertissement ci-dessus).
    $order->update(['money_pulse_transaction_id' => $payment['transaction_id'] ?? $payment['id']]);

    return redirect($payment['checkout_url']);
}
```

## Exemple WordPress / WooCommerce

Créez `wp-content/plugins/money-pulse/money-pulse.php` :

```php
<?php
/*
Plugin Name: Money-Pulse for WooCommerce
*/
defined('ABSPATH') || exit;

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_MoneyPulse_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'moneypulse';
            $this->method_title = 'Money-Pulse';
            $this->title = 'Mobile Money & Cartes';
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled'        => ['title' => 'Activer', 'type' => 'checkbox', 'default' => 'yes'],
                'api_key'        => ['title' => 'Clé API', 'type' => 'text'],
                'webhook_secret' => ['title' => 'Webhook secret', 'type' => 'password'],
            ];
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            require_once __DIR__ . '/vendor/autoload.php';
            $client = new \MoneyPulse\MoneyPulseClient($this->get_option('api_key'));
            $payment = $client->payments->create([
                'amount'       => $order->get_total(),
                'currency'     => $order->get_currency(),
                'country'      => $order->get_billing_country(),
                'customer'     => [
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone(),
                ],
                'callback_url' => home_url('/?moneypulse_webhook=1'),
                'return_url'   => $this->get_return_url($order),
            ]);
            // CORRIGÉ : stockage direct du transactionId sur la commande —
            // 'metadata' => ['order_id' => ...] (version précédente) n'est
            // jamais renvoyé par le webhook, inutile de le passer à create().
            $order->update_meta_data('_moneypulse_payment_id', $payment['transaction_id'] ?? $payment['id']);
            $order->save();
            return ['result' => 'success', 'redirect' => $payment['checkout_url']];
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gw) {
        $gw[] = 'WC_MoneyPulse_Gateway';
        return $gw;
    });
});

// Webhook handler
add_action('init', function () {
    if (!isset($_GET['moneypulse_webhook'])) return;
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_MONEYPULSE_SIGNATURE'] ?? '';
    $settings = get_option('woocommerce_moneypulse_settings');
    $expected = hash_hmac('sha256', $payload, $settings['webhook_secret']);
    if (!hash_equals($expected, $signature)) {
        status_header(401); exit('bad signature');
    }
    $event = json_decode($payload, true);
    // CORRIGÉ : 'event' (pas 'type'), 'payment.succeeded' (pas
    // 'payment.success'), recherche par _moneypulse_payment_id (métadonnée
    // stockée à la création, pas metadata.order_id — inexistant dans le
    // vrai payload).
    if ($event['event'] === 'payment.succeeded') {
        $transactionId = $event['data']['transactionId'];
        $orders = wc_get_orders([
            'meta_key'   => '_moneypulse_payment_id',
            'meta_value' => $transactionId,
            'limit'      => 1,
        ]);
        if (!empty($orders)) {
            $orders[0]->payment_complete($transactionId);
        }
    }
    status_header(200); echo 'ok'; exit;
});
```

## Erreurs courantes

```php
try {
    $payment = $client->payments->create([...]);
} catch (\MoneyPulse\MoneyPulseException $e) {
    echo $e->getMessage();      // description lisible
    echo $e->getErrorCode();    // ex: 'invalid_amount', 'insufficient_balance'
    echo $e->getHttpCode();     // 400, 401, 422...
}
```

| Code | Cause | Action |
|---|---|---|
| `invalid_api_key` | Clé absente ou révoquée | Régénérer dans le dashboard |
| `invalid_signature` | Webhook mal signé | Vérifier `webhook_secret` |
| `insufficient_balance` | Solde marchand insuffisant pour payout | Recharger compte |
| `gateway_unavailable` | Aucune passerelle dispo | Réessayer (failover auto côté MP) |
| `validation_error` | Champ manquant ou invalide | Vérifier le détail dans `error.fields` |

## Liens

- Docs complètes : <https://money-pulse.org/documentation>
- Dashboard : <https://app.money-pulse.org>
- Support : <support@money-pulse.org>

## License

MIT © NOCYL-PULSE
