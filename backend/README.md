# AICOUNTLY Remote — API

CodeIgniter 4 + PostgreSQL. Deployed to `<document root>/api/`.

This is one of three pieces; see the [repository README](../README.md) and
[docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md) for how they fit together.

```bash
composer install
cp .env.example .env         # then fill in the database and the two secrets
php spark migrate
php spark db:seed RemotePlatformDefaultsSeeder
php spark serve --port 8080

vendor/bin/phpunit           # needs the aicountly_remote_test database
php spark routes             # every endpoint and its filters
```

## Where things live

```
app/Controllers/    input, output, status codes — no decisions
app/Domain/
  Auth/             portal, identity projection, launch context, guests
  Policy/           the permission hierarchy (§9)
  Session/          lifecycle, participants, invitations, joining, chat
  Support/          the AICOUNTLY Support queue, and shared helpers
  Signalling/       token issuance, ICE configuration
  Audit/            session events and the security record
  Directory/        the AICOUNTLY platform projection
app/Database/       migrations and seeds
app/Filters/        auth, CORS, security headers, rate limits, launch context
```

Controllers validate input, call a service, and format the answer. They do not
decide permissions, open transactions or write tables — that lives in the
services, so it can be tested without HTTP and cannot be forgotten on one route
out of twenty.

`App\Domain\Policy\EffectivePolicyResolver` is the one class to read first: it
owns the permission hierarchy, and the ordering inside `resolve()` is what makes
"the most restrictive rule wins" true rather than aspirational.
