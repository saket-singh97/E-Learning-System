# Local Demo Logins

Seeded local-dev users, URLs, and passwords — for demoing the app on `localhost`
without re-deriving them from the seeders every time. See `SETUP.md` for the
full environment setup.

Start the server with `php artisan serve` alone (port 8000). `composer dev`'s
bundled `pail` (log tail) needs `pcntl`, which is faked on Windows — it crashes
and its `--kill-others` flag takes the whole group down with it. Skip
queue/vite/pail for a demo; run them separately only if you actually need them.

All seeded users share the same password (Laravel factory default,
`Hash::make('password')`).

| Role | URL | Email | Password |
|---|---|---|---|
| Super admin | `http://lms.test:8000/super` | `super@lms.test` | `password` |
| Institute admin | `http://physics.lms.test:8000/admin` | `admin@physics.test` | `password` |
| Instructor | `http://physics.lms.test:8000/teach` | `teacher@physics.test` | `password` |
| Student | `http://physics.lms.test:8000/` | `student@physics.test` | `password` |
| Parent | `http://physics.lms.test:8000/parent/dashboard` | `parent@physics.test` | `password` |

`test@example.com` (id 1) also exists but is unusable — no role assigned, and
its tenant (`graham`, id 3) isn't in the Windows hosts file (only `lms.test`,
`physics.lms.test`, `chemistry.lms.test` are mapped — see `SETUP.md` §5).

## Gotchas

**Shared session cookie across panels.** `SESSION_DOMAIN=.lms.test` is
deliberate (every tenant subdomain shares one cookie — see `CLAUDE.md`'s
tenant-context section). Logging into one panel, then visiting a *different*
panel's `/login` in the same browser, redirects straight into that panel and
403s on the role mismatch (`EnsureRole`) instead of showing a login form —
not a bug. Use a separate incognito window per role, or log out first.

**Device limit (409).** `DeviceLimitExceededException` fires once a user has
`security.device_limit` (default 2) active rows in `device_sessions` — easy to
hit after repeated local login testing. Fix:

```php
DeviceSession::withoutGlobalScope('tenant')
    ->where('user_id', $id)
    ->whereNull('revoked_at')
    ->update(['revoked_at' => now()]);
```
