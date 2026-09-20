# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum`,
`opcache` → `OPCache`, and `dotenv` → `Env` are existing exceptions the guess
gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks every
  `CLAUDE.md` copy as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. `ez-php/mail`'s Mailpit service is the one other module with published host ports: SMTP `1025` and web UI `8025`, mapped through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above), documented in `modules/mail/.env.example`. It isn't a table column because no other module runs Mailpit, so there is nothing to collide with — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/push

Mobile push notifications (APNS, FCM) as an ez-php/notification channel

> This file is the module-specific half. The coding guidelines above it are
> generated by `sync_guidelines.php` — run `composer guidelines:sync` from the
> monorepo root to fill them in. Never edit that part by hand.

---

## Source Structure

```
src/
├── PushException.php               — Base exception (invalid key, non-2xx APNS/FCM response)
├── PushMessage.php                 — Immutable value object: title, body, data, badge, sound
├── PushDriverInterface.php         — Contract: send(token, PushMessage): void
├── Pusher.php                      — Orchestrates single/many-token delivery via a driver
├── Push.php                        — Static facade over the Pusher singleton
├── PushServiceProvider.php         — Binds PushDriverInterface/Pusher, wires the facade
├── Driver/
│   ├── NullDriver.php              — Discards all notifications (default)
│   ├── ArrayDriver.php             — Records sent messages in-memory, for tests
│   ├── ApnsDriver.php              — Apple Push Notification service, HTTP/2 provider API
│   ├── FcmDriver.php               — Firebase Cloud Messaging, HTTP v1 API
│   └── WebPushDriver.php           — Browser Web Push: VAPID-authenticated, RFC 8291-encrypted POST to the subscription endpoint
├── WebPush/
│   └── WebPushEncryptor.php        — RFC 8291 aes128gcm payload encryption (ECDH P-256 + HKDF + AES-128-GCM)
└── Jwt/
    ├── Es256Signer.php             — ES256 JWT signing for APNS provider tokens and VAPID (kid optional)
    └── Rs256Signer.php             — RS256 JWT signing for FCM's OAuth2 JWT-bearer assertion

tests/
├── TestCase.php                    — Base PHPUnit test case
├── PushMessageTest.php
├── PusherTest.php
├── PushTest.php                    — Static facade
├── PushServiceProviderTest.php
├── Push/ApplicationTestCase.php    — Base class writing a config/push.php fixture from env vars
├── Driver/
│   ├── NullDriverTest.php
│   ├── ArrayDriverTest.php
│   ├── ApnsDriverTest.php          — Uses FakeTransport, a generated throwaway EC key
│   ├── FcmDriverTest.php           — Uses FakeTransport, a generated throwaway RSA key
│   └── WebPushDriverTest.php       — FakeTransport, generated VAPID + subscription keys
├── WebPush/
│   └── WebPushEncryptorTest.php    — Reproduces the RFC 8291 Appendix A vector byte for byte
└── Jwt/
    ├── Es256SignerTest.php         — Round-trips a raw signature back to DER and verifies it
    └── Rs256SignerTest.php
```

---

## Key Classes and Responsibilities

- **`PushMessage`** — the wire-agnostic notification (title, body, custom `data`,
  optional `badge`/`sound`). Each driver maps it onto its own payload format
  (APNS `aps` dictionary, FCM `notification` object).
- **`PushDriverInterface`** — one method, `send(token, message)`, delivering to
  exactly one token. Fan-out lives in `Pusher`, not the driver.
- **`Pusher`** — orchestrates delivery. `sendToMany()` attempts every token
  independently and returns a `token => PushException|null` map instead of
  throwing on the first failure, so a bad token in a batch doesn't block the
  rest.
- **`Push`** — static facade over a `Pusher` singleton, mirroring
  `ez-php/broadcast`'s `Broadcast` facade (`setPusher()`/`resetPusher()`,
  throws `RuntimeException` if used before `setPusher()`).
- **`ApnsDriver`** — POSTs to `api.push.apple.com` (or the sandbox host) with
  a bearer `authorization` header carrying an ES256 provider JWT, `apns-topic`
  set to the app's bundle ID, and `apns-push-type: alert`. The JWT is cached
  and reused for up to 50 minutes (Apple accepts tokens up to 1 hour old).
- **`FcmDriver`** — exchanges an RS256-signed OAuth2 JWT-bearer assertion for
  a Google access token (cached ~55 minutes), then POSTs to
  `fcm.googleapis.com/v1/projects/{id}/messages:send` with that token as a
  bearer credential.
- **`WebPushDriver`** — sends to browser push services. The "device token" is
  the browser's `PushSubscription` serialised as JSON (`endpoint`,
  `keys.p256dh`, `keys.auth`). It encrypts the JSON payload
  (`title`/`body`/`data`, plus `badge`/`sound` when set) with `WebPushEncryptor`
  and POSTs it with `Content-Encoding: aes128gcm`, a `TTL`, and
  `Authorization: vapid t=<jwt>, k=<public key>`. The VAPID JWT (`aud` =
  endpoint origin, `sub`, `exp` = now + 12 h) is signed per send with
  `Es256Signer` (no `kid`). `WebPushDriver::vapidPublicKey($privateKeyPem)`
  derives the base64url public key — also the `applicationServerKey` the browser
  needs to subscribe. Any non-2xx (404/410 = subscription gone) raises a
  `PushException` naming the status.
- **`WebPushEncryptor`** — RFC 8291 message encryption: ephemeral ECDH P-256
  against `p256dh`, HKDF-SHA-256 (`hash_hkdf`) mixed with the `auth` secret,
  AES-128-GCM, single 4096-byte record. `ext-openssl` only (`openssl_pkey_derive`,
  `openssl_encrypt`); raw scalars are wrapped into SEC1/SPKI DER by hand.
- **`Es256Signer` / `Rs256Signer`** — minimal, single-purpose JWT signers built
  on `ext-openssl`, not a general JWT library. `Es256Signer` additionally
  converts `openssl_sign()`'s ASN.1 DER ECDSA output into the raw, fixed-width
  R‖S concatenation JWS requires — `ext-openssl` has no built-in way to
  produce that format directly. `Rs256Signer` needs no such conversion:
  `openssl_sign()` with `OPENSSL_ALGO_SHA256` on an RSA key already produces
  the raw PKCS#1 v1.5 bytes JWS expects.

---

## Design Decisions and Constraints

- **HTTP/2 is not forced.** APNS requires HTTP/2, but this driver relies on
  the underlying transport (`ez-php/http-client`'s `CurlTransport`) to
  negotiate it — no explicit `CURLOPT_HTTP_VERSION` is set here. If cURL/the
  server ever fail to negotiate HTTP/2 automatically, that belongs in
  `ez-php/http-client`, not this package.
- **Web Push is single-record and non-streaming.** Payloads are limited to 4079
  bytes (one record, the size every push service must accept); larger payloads
  raise `PushException` rather than being split. The `$senderPrivateKey`/`$salt`
  parameters of `WebPushEncryptor::encrypt()` exist only to reproduce the RFC
  test vector — production callers must omit them (reusing an ephemeral key or
  salt breaks the scheme's security). Only the current `vapid` Authorization
  scheme is sent, not the legacy `WebPush`/`Crypto-Key` pair, and only
  `aes128gcm` (not the obsolete `aesgcm` coding). No JWT caching: signing ES256
  per send is cheap and keeps the driver stateless.
- **The reserved `kid` argument became optional on `Es256Signer`.** VAPID JWTs
  carry no `kid`; `new Es256Signer($pem)` omits it, `new Es256Signer($pem, $keyId)`
  (APNS) is unchanged.
- **No JWT library dependency.** `Es256Signer`/`Rs256Signer` are scoped to
  exactly the two algorithms APNS and FCM require, not a general-purpose JWT
  implementation — `ez-php/auth`'s `JwtManager` (HS256 only, for session
  tokens) is a different use case and this package does not depend on
  `ez-php/auth`.
- **No device-token storage.** Per the module's scope, storing/pruning
  device tokens and managing FCM topic subscriptions are application-layer
  concerns. `Pusher::sendToMany()` surfaces a `PushException` per failed
  token (e.g. APNS `410 Unregistered`) so the caller can decide what to do
  with it, but this package never persists a token.
- **Wired as an `ez-php/notification` channel via `PushChannel`.** This
  module only ships the transport-level `Pusher`/drivers and the `Push`
  facade — mirroring how `ez-php/broadcast` predates and is independent of
  `notification`'s `BroadcastChannel`. The `ChannelInterface`/
  `QueuableChannelInterface` adapter lives in
  `modules/notification/src/Channel/PushChannel.php` (with
  `Channel/ToPushInterface.php` and `Queue/SendPushNotificationJob.php`
  alongside it), which calls the `Push` facade — `ez-php/notification`
  depends on `ez-php/push`, not the other way around, so this module stays
  usable standalone without pulling in notification orchestration.
- **`modules/push/` is intended to become a git submodule** pointing at
  `git@github.com:ez-php/push.git`, consistent with how every other
  `modules/*` package (and `framework/`, `ez-php/`) is tracked in this
  monorepo — see `.gitmodules`. As of this writing it is still a plain
  scaffolded directory: the push/submodule conversion is a git write
  operation left for a human to run (see the module's creation history for
  the exact commands).

---

## Testing Approach

- Test classes live in the shared `Tests\` namespace but must be uniquely named
  across the whole monorepo — the root `phpunit.xml` loads every package in one
  process, so a duplicate name is a fatal error, not a test failure. This
  package avoids the collision by nesting driver/JWT tests under
  `Tests\Push\...` (mirroring `ez-php/broadcast`'s `Tests\Broadcast\...`)
  rather than renaming classes.
- No MySQL/Redis/Meilisearch infrastructure is required — the module is a
  stateless HTTP client wrapper.
- `ApnsDriverTest`/`FcmDriverTest` never hit the network: they construct
  `HttpClient` directly with `ez-php/http-client`'s `FakeTransport` and assert
  on the recorded requests. `Es256SignerTest`/`Rs256SignerTest` generate a
  throwaway EC/RSA key pair with `openssl_pkey_new()` in `setUp()` (skipping
  if the environment can't generate one) and verify the produced signature
  against the matching public key with `openssl_verify()`.
- `PushServiceProviderTest` extends `Tests\Push\ApplicationTestCase`, which
  writes a `config/push.php` fixture reading `PUSH_*` env vars into a
  temporary application root — the same pattern `ez-php/cache`'s
  `Tests\Cache\ApplicationTestCase` uses for `CACHE_*`.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Device-token storage and lifecycle (register/prune) | Application layer |
| FCM topic subscription management | Application layer |
| The `ChannelInterface`/`QueuableChannelInterface` adapter wiring this into notifications | `ez-php/notification` (`Channel/PushChannel.php`), not this package |
| Rich notification content (images, action buttons, interruption levels) | Application layer — extend `PushMessage`'s `data` map or the driver payload builder if a real need arises |
| A general-purpose JWT library | `Es256Signer`/`Rs256Signer` are intentionally narrow; see Design Decisions |
| Storing `PushSubscription`s, pruning 404/410 subscriptions, service-worker code | Application layer |
| SMS / other channels | Application layer or their own future modules |