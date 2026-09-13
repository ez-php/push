# ez-php/push

Mobile push notifications for ez-php applications. Delivers a `PushMessage` to a
device token through a pluggable driver — APNS (Apple, HTTP/2 provider API with
token-based `.p8` authentication) or FCM (Firebase Cloud Messaging, HTTP v1 API
with service-account OAuth2) — plus Null and Array drivers for local development
and testing.

Device-token storage, invalid-token pruning, and topic/channel subscription
management are application-layer concerns — this package only delivers a message
to a token you already have.

---

## Installation

```bash
composer require ez-php/push
```

Requires `ez-php/http-client` to be registered first — the `apns` and `fcm`
drivers resolve `HttpClient` from the container.

---

## Quick Start

Register the providers in `provider/modules.php`:

```php
use EzPhp\HttpClient\HttpClientServiceProvider;
use EzPhp\Push\PushServiceProvider;

$app->register(HttpClientServiceProvider::class);
$app->register(PushServiceProvider::class);
```

Add configuration to `config/push.php`:

```php
return [
    'driver' => env('PUSH_DRIVER', 'null'),
    'apns' => [
        'key_id' => env('PUSH_APNS_KEY_ID', ''),
        'team_id' => env('PUSH_APNS_TEAM_ID', ''),
        'bundle_id' => env('PUSH_APNS_BUNDLE_ID', ''),
        'private_key' => env('PUSH_APNS_PRIVATE_KEY', ''),
        'sandbox' => env('PUSH_APNS_SANDBOX', false),
    ],
    'fcm' => [
        'project_id' => env('PUSH_FCM_PROJECT_ID', ''),
        'client_email' => env('PUSH_FCM_CLIENT_EMAIL', ''),
        'private_key' => env('PUSH_FCM_PRIVATE_KEY', ''),
    ],
];
```

Send a push notification from anywhere:

```php
use EzPhp\Push\Push;
use EzPhp\Push\PushMessage;

Push::send('device-token', new PushMessage(
    title: 'Order shipped',
    body: 'Your order #42 is on its way.',
    data: ['order_id' => '42'],
    badge: 1,
    sound: 'default',
));
```

Or fan out to many tokens without one failure blocking the rest:

```php
$results = Push::sendToMany(['token-a', 'token-b'], $message);

foreach ($results as $token => $error) {
    if ($error !== null) {
        // e.g. drop $token from storage if $error indicates it is unregistered
    }
}
```

---

## Drivers

| Driver | `PUSH_DRIVER` | Description |
|--------|---------------|-------------|
| Null   | `null`        | Silently discards all notifications (default) |
| Array  | `array`       | Stores notifications in memory — designed for testing |
| APNS   | `apns`        | Apple Push Notification service, HTTP/2 provider API |
| FCM    | `fcm`         | Firebase Cloud Messaging, HTTP v1 API |

### APNS Driver

```dotenv
PUSH_DRIVER=apns
PUSH_APNS_KEY_ID=ABC123DEFG
PUSH_APNS_TEAM_ID=DEF456GHIJ
PUSH_APNS_BUNDLE_ID=com.example.app
PUSH_APNS_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
PUSH_APNS_SANDBOX=false
```

`PUSH_APNS_PRIVATE_KEY` is the PEM contents of the `.p8` authentication key
downloaded from the Apple Developer portal. The driver signs an ES256 provider
JWT from it and reuses that JWT across requests for up to 50 minutes, per
Apple's guidance. Set `PUSH_APNS_SANDBOX=true` to target the sandbox APNS host
during development.

### FCM Driver

```dotenv
PUSH_DRIVER=fcm
PUSH_FCM_PROJECT_ID=my-firebase-project
PUSH_FCM_CLIENT_EMAIL=push@my-firebase-project.iam.gserviceaccount.com
PUSH_FCM_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
```

`PUSH_FCM_CLIENT_EMAIL` and `PUSH_FCM_PRIVATE_KEY` come from a Firebase
service-account JSON key (Project Settings → Service Accounts → Generate new
private key). The driver exchanges an RS256-signed JWT-bearer assertion for an
OAuth2 access token and caches it for its ~1-hour lifetime.

### Array Driver (for Testing)

```php
use EzPhp\Push\Driver\ArrayDriver;
use EzPhp\Push\Push;
use EzPhp\Push\Pusher;

$driver = new ArrayDriver();
Push::setPusher(new Pusher($driver));

// ... exercise code under test ...

$messages = $driver->sentTo('device-token');
assert(count($messages) === 1);

Push::resetPusher();
```

---

## Static Facade

`Push` is a static facade backed by a `Pusher` singleton:

| Method | Description |
|--------|-------------|
| `Push::send(string $token, PushMessage $message)` | Deliver to one device token |
| `Push::sendToMany(list<string> $tokens, PushMessage $message)` | Deliver to many tokens, returning a `token => PushException\|null` map |
| `Push::setPusher(Pusher)` | Wire the singleton (done by `PushServiceProvider`) |
| `Push::resetPusher()` | Reset to null — call in test `tearDown()` |

Throws `RuntimeException` if called before `setPusher()`.

---

## Custom Driver

Implement `PushDriverInterface` to add a custom backend:

```php
use EzPhp\Push\PushDriverInterface;
use EzPhp\Push\PushMessage;

final class WebPushDriver implements PushDriverInterface
{
    public function send(string $token, PushMessage $message): void
    {
        // deliver via a Web Push provider
    }
}
```

Bind it in a service provider:

```php
$app->bind(PushDriverInterface::class, fn () => new WebPushDriver());
```

---

## Exceptions

`PushException` (extends `RuntimeException`) is the base exception for this
package — thrown on an invalid signing key or a non-2xx response from APNS/FCM.
`Push::send()` / `Push::sendToMany()` throw plain `RuntimeException` if called
before the pusher is set.

---

## License

MIT
