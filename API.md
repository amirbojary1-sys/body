# FitBot PHP API

Base endpoint: `public/api.php?action=...` (relative to the served `public/` folder).
All responses are JSON. No wildcard CORS is enabled. Production requires HTTPS.

## Browser session

Load `index.php` or `GET ?action=status` first to establish a session. The `FITBOTSESSID` cookie is HttpOnly. The boot payload / status response contains `csrf`, but never password hashes or the provider key.

Mutations require:

```http
Content-Type: application/json
X-CSRF-Token: <current session token>
```

Authenticated state/agent/account requests also require:

```http
X-Fitbot-Account: <current authenticated user's numeric id>
```

The account header is a mismatch guard, **not** authorization. The server always derives the actual user from the session and ignores user IDs supplied in state bodies or query strings. Tokens rotate on login, registration and logout. Use the new `csrf` returned by those operations.

## Routes

| Action | Method | Login | Purpose |
|---|---|---|---|
| `status` | GET | No | Engine, configured model, current public user and CSRF |
| `register` | POST | No | Create an account and rotate session |
| `login` | POST | No | Verify password and return owned state |
| `logout` | POST | No | Invalidate identity and rotate session |
| `account` | DELETE | Yes | Delete account/state after password verification |
| `state` | GET | Yes | Get owned snapshot, revision and updatedAt |
| `state` | PUT | Yes | Validate and replace owned snapshot at expected revision |
| `calculate` | POST | No | PHP calorie estimates and workout-plan generation |
| `agent` | POST | Yes | Server-side model response and pending tool proposals |

### Registration

```json
{
  "name": "نام نمایشی",
  "email": "you@example.com",
  "password": "your-own-long-password",
  "consent": true
}
```

A new account has `state: null`, `revision: 0`. Guest-state transfer is an independent, explicit client choice followed by `PUT state`.

### Login

```json
{"email":"you@example.com","password":"your-own-long-password"}
```

Returns `user`, `csrf`, `state`, `revision`, `updatedAt`. Passwords are 10+ characters for registration, capped at 72 bytes for bcrypt safety. Email verification and email password recovery are not implemented. A successful login also updates `last_login_at`; an account suspended from the admin panel is rejected with **403** `account_suspended`.

### Save snapshot

```json
{
  "revision": 3,
  "state": {
    "version": 2,
    "profile": null,
    "water": {},
    "weights": [],
    "sessions": [],
    "favorites": [],
    "meals": {},
    "mealChoices": [0,0,0,0],
    "diet": "regular",
    "events": [],
    "chat": {"coordinator":[],"trainer":[],"nutrition":[],"recovery":[]},
    "settings": {"theme":"dark","reduced":false,"waterGoal":8},
    "activeSession": null
  }
}
```

Successful response: `{"saved":true,"revision":4,"updatedAt":...}`. A stale revision returns **409** `state_conflict` without writing. The UI offers explicit pull-server or replace-with-local choices. Unknown fields are discarded; record counts, numeric ranges and text lengths are bounded by `StateValidator.php`.

### Calculate / plan

```json
{
  "profile": {
    "name":"",
    "gender":"male",
    "age":25,
    "height":175,
    "weight":75,
    "activity":1.55,
    "goal":"maintain",
    "days":3,
    "equipment":"home",
    "level":"beginner",
    "duration":35
  }
}
```

Returns `engine: "php"`, validated `profile`, `estimates`, `plan`, and a general-advice disclaimer. It does **not** write a guest's health profile to the database. Age must be 18–85. Values are estimates, not clinical recommendations. An underweight profile is not assigned a calorie-deficit target.

### Model request

```json
{
  "role":"trainer",
  "consent":true,
  "profile":{"age":25,"height":175,"weight":75,"sample":true},
  "context":{"waterGlasses":1,"waterGoal":8,"completedThisWeek":0},
  "messages":[{"role":"user","content":"برای من برنامه بساز"}]
}
```

The model and base URL come only from server environment configuration. Profile names are omitted from model context. The last 12 user/assistant messages may be sent; their contents can include personal information the user previously wrote. Only user/assistant roles from the client are accepted. Validated PHP estimates replace client-supplied calorie estimates in the model context.

Response:

```json
{
  "message":"پیشنهاد آماده است؛ هنوز تغییری انجام نشده.",
  "actions":[{"type":"log_water","args":{"glasses":1}}],
  "mode":"cloud",
  "engine":"php"
}
```

Possible action types: `planner`, `calculator`, `nutrition`, `progress`, `log_water`, `start_timer`, `update_goal`. Tools are proposals only. `log_water` allows 1–2 glasses; `start_timer` allows 30–180 seconds. Unknown tools and malformed arguments are discarded. Every data-changing proposal requires an explicit UI approval before the normal state-save path.

## Errors

Errors have `error` (user-facing message) and `code`.

- **401**: authentication required or invalid credentials.
- **403**: origin rejected, or the account is suspended (`account_suspended`).
- **405**: incorrect HTTP method.
- **409**: state revision conflict / browser account mismatch.
- **413**: body or snapshot too large.
- **415**: non-JSON mutation.
- **419**: missing or expired CSRF; cookie restrictions can cause this in previews.
- **422**: invalid profile, snapshot, consent or request fields.
- **429**: request quota exceeded.
- **502**: provider timeout, invalid response or upstream error; raw provider bodies are not exposed.
- **503**: AI not configured or production authentication without recognized HTTPS.

Rate limits count requests, not monetary spend. Provider-side billing limits remain necessary. The built-in PHP server is for development only; use PHP-FPM or an appropriately configured Apache deployment for production.
