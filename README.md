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

echo $payout['payout_id']; // → po_xxxxx
```

## Mode Simulation

Testez vos flux **sans débiter de fonds réels** ni déclencher de webhooks sortants. Activable
par requête, fonctionne aussi en environnement `live`.

```php
// Option 1 : flag dans le payload
$test = $client->payments->create([
    'amount'   => 5000,
    'currency' => 'XOF',
    'country'  => 'CI',
    'customer' => ['phone' => '+22507000000'],
    'metadata' => ['simulate' => true],
]);

// Option 2 : header HTTP global pour toutes les requêtes
$client->setDefaultHeader('X-MP-Simulate', 'true');
```

| Dernier chiffre du montant | Résultat simulé |
|---|---|
| `00`–`49` | ✅ `completed` immédiat |
| `50`–`89` | ⏳ `pending` puis résolu |
| `90`–`99` | ❌ `failed` |

## Webhooks — vérification de signature (HMAC SHA-256)

Money-Pulse signe chaque webhook avec votre `webhook_secret` (visible dans le dashboard).
**Toujours vérifier la signature** avant de traiter le payload.

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
switch ($event['type']) {
    case 'payment.success':
        // → marquer la commande comme payée
        break;
    case 'payment.failed':
        // → notifier le client
        break;
    case 'payout.completed':
        // → confirmer le retrait
        break;
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
        $orderId = $event['data']['metadata']['order_id'] ?? null;

        if ($event['type'] === 'payment.success' && $orderId) {
            Order::where('id', $orderId)->update([
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
        'metadata'     => ['order_id' => $order->id],
    ]);
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
                'metadata'     => ['order_id' => $order_id],
            ]);
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
    if ($event['type'] === 'payment.success') {
        $order = wc_get_order($event['data']['metadata']['order_id']);
        if ($order) $order->payment_complete($event['data']['transaction_id']);
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
