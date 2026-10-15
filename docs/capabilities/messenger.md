# Messenger Capability

The Messenger capability connects a preset to a real messenger account. It is the infrastructure layer: the account, its session, and the delivery of incoming messages. What the agent *does* with the account — reading, sending, searching — is the [Messenger plugin](../plugins/messenger.md).

The two are deliberately separate:

| | Capability | Plugin |
|---|---|---|
| Configured in | Preset → **Capabilities → Messenger** | Preset → **Plugins → Messenger** |
| Holds | Account, credentials, session, inbound rules | Read limit, placeholder options, targeting guidance |
| Direction | Inbound: the world reaches the agent | Outbound: the agent reaches the world |
| Works without the other | Yes — incoming messages arrive even with the plugin off (the agent just can't reply) | No — the plugin uses the capability's account |

Currently available driver: **Telegram (user account)** — a real user account over MTProto via [tgcli](https://github.com/rnr1721/tgcli), not the Bot API. The capability is provider-based like embeddings, vision and speech, so other messengers can be added as drivers without touching the plugin or the inbound pipeline.

## Setup (Telegram)

### 1. API credentials

Get an **API ID** and **API hash** at [my.telegram.org](https://my.telegram.org) → *API development tools*.

One pair of credentials can authorize any number of accounts — it identifies the *application*, not the user. Telegram allows only one application per account, so reuse the pair you already have rather than trying to create a new one.

### 2. Configure

Open the preset → **Capabilities → Messenger**, choose the **Telegram (user account)** driver and fill in:

| Field | Description |
|---|---|
| **API ID** / **API hash** | From my.telegram.org. Leave both empty to keep credentials saved by an earlier setup. |
| **Max media download (MB)** | Voice messages and photos are downloaded for speech recognition and vision; larger files are refused. `0` = no limit. |
| **Incoming messages** | `Off` / `Whitelist` / `Everyone` — see [Incoming messages](#incoming-messages). |
| **Whitelist** | `@usernames` or numeric ids, separated by commas or new lines. |
| **Groups and channels** | `Ignore` / `Only mentions and replies to me` / `All group messages`. |
| **Route to input pool** | Deliver to the input pool instead of the history (pool-mode presets only). |

Save the configuration before logging in.

### 3. Log in

The same card shows the account status and a login form. The steps are driven by the driver:

1. **Phone number** — international format.
2. **Login code** — see the note below.
3. **Two-step verification password** — only if the account has 2FA enabled.

When the status shows the account name, the preset is authorized. The session is stored per preset (`/shared/telegram/preset_<id>/`) and survives restarts and rebuilds. **Log out** removes the session from DepthNet; to also terminate it on Telegram's side, end it in *Settings → Devices* of any Telegram client.

> **Where is the code?** If the account is already logged in anywhere, Telegram sends the code **inside Telegram** — as a message from the official *Telegram* service account (id `777000`) to every active session — not by SMS. It looks like "no code was sent". Check the Telegram app on your phone or desktop. If the account is logged in only in another DepthNet instance, read it there: `tgcli --json read 777000 --limit 1`.

> **Do not copy session files between machines.** Two copies of the same session used from different IPs can be treated as a stolen session, and Telegram may revoke both. Log in separately on each instance instead.

> **Telegram may ask you to log in from a familiar place.** New logins from a new IP or device can be refused or delayed. Logging in from the server where the agent will actually run is the most reliable.

## Incoming messages

With **Incoming messages** on, new messages are delivered to the agent without the agent having to check for them.

### Who gets through

| Mode | Private messages | Group messages |
|---|---|---|
| **Off** | — | — |
| **Whitelist** | From listed people | When the group *or* its author is listed |
| **Everyone** | Anyone | Any group, subject to the *Groups and channels* setting |

*Groups and channels* further filters group traffic: ignore it entirely, pass only mentions of the account and replies to it, or pass everything. Your own outgoing messages are never delivered.

### What wakes the agent

A **private message** or a **mention / reply in a group** acts as a call: if the agent is idle, a thinking cycle starts. Ordinary group chatter is delivered but does not wake the agent — it will see it on its next cycle.

- If the agent's loop is already running, nothing is started; the message is picked up on the next cycle.
- If the agent is busy (a cycle is in progress), the wake is deferred and retried on the next poll.

### Where it lands

**History (default).** One `user` message per batch, marked with the provider and listing each message with its id, time, chat and author:

```
[Telegram (user account)] 2 new messages. Reply with the messenger tool to the address shown, if a reply is needed.

#4215 12:34 [user] Alena (@alena_p):
(voice 0:12) transcript: are you free tonight?

#4216 12:35 [group] Dev chat (-1001820894363) · Bob (@bob) [mentions you]:
@ada what do you think?
```

**Input pool.** With *Route to input pool* on and the preset in pool input mode, each message becomes its own pool item (`telegram Alena #4215`); a waking message flushes the pool to the agent.

### Voice and photos

- **Voice messages** are transcribed through the preset's **STT** capability.
- **Photos** are described through the preset's **Vision** capability.

The result replaces the bare media label in the message. Files are downloaded to a temporary directory and deleted immediately. When the preset has no suitable provider, nothing is downloaded and the agent sees only a label such as `(voice 0:12)` — it can still interpret the media later on demand with [`[messenger media]`](../plugins/messenger.md#media).

### Running the poller

Incoming messages are fetched by `php artisan messenger:poll`. Run it **one** of two ways:

**Supervisor** — conversational latency (recommended when people talk to the agent):

```ini
[program:laravel-messenger-poll]
command=php /var/www/html/artisan messenger:poll --loop --interval=10
directory=/var/www/html
autostart=true
autorestart=true
startsecs=5
stopasgroup=true
killasgroup=true
stopsignal=TERM
stopwaitsecs=130
user=depthnet
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/messenger-poll.log
environment=HOME="/home/depthnet",USER="depthnet"
```

`stopwaitsecs` must exceed the longest tgcli call (downloads and baseline scans get 120 s), so a stop lets the current pass finish instead of cutting it off.

**Scheduler** — no extra process, up to a minute of latency:

```php
// routes/console.php
Schedule::command('messenger:poll')->everyMinute()->withoutOverlapping()->runInBackground();
```

One process serves all presets; the list of presets with inbound on is re-read every pass, so turning inbound on or off needs no restart.

```bash
php artisan messenger:poll                        # one pass over all presets with inbound on
php artisan messenger:poll --preset=1 -v          # one preset, verbose
php artisan messenger:poll --loop --interval=10   # long-running (supervisor)
php artisan messenger:poll --reset                # drop the read cursor; next pass takes a fresh baseline
```

### Delivery guarantees

- **First pass takes a baseline.** Nothing that arrived before inbound was enabled is replayed; delivery starts from that moment.
- **At-least-once.** The read position advances only after delivery succeeded. A crash between the two replays the batch rather than losing it.
- **Independent of unread flags.** The position is tracked per chat by message id, so reading the account from your phone does not hide messages from the agent.
- **Back-off.** On a flood wait the poller pauses for exactly as long as Telegram asks; when the account is not logged in it retries every 5 minutes; other errors pause for 30 s.
- **No double delivery** between a scheduler run and a supervisor worker, given a cache store with locks (redis, database, file).

## Security

Incoming messages are written by people outside the operator's control. For the model they are untrusted input — a prompt injection channel by definition.

What DepthNet does:

- Inbound messages are stored without a user identity.
- Commands inside them are **never executed**, whatever the sender writes.
- Every batch is explicitly marked with its source.

What it cannot do for you:

- An agent with host-level plugins (Shell, Sandbox, Browser, Code) can still *choose* to act on what it was asked in a message.
- For such presets, keep **Incoming messages** on `Whitelist`.
- Tell the agent in its system prompt that messages marked with the messenger name come from external people, not from the operator.

The agent operates a **real user account**, not a bot. Everything it sends is sent as that person. Use a dedicated account for agents where possible.

## Configuration (`config/messenger.php`)

| Env | Default | Description |
|---|---|---|
| `TGCLI_BINARY` | `telegram` | tgcli executable |
| `TELEGRAM_DATA_DIR` | `/shared/telegram` | Base directory for per-preset sessions (`preset_<id>/`) |
| `TGCLI_TIMEOUT` | `60` | Seconds per tgcli call (downloads and baseline scans get 120) |

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Login code never arrives | It was sent inside Telegram (service account `777000`) — see [Log in](#3-log-in). |
| `not_authorized` in the poll log, retries every 5 min | Session missing or revoked — log in again from the capability card. |
| `busy` in the poll log | The agent was using the account at that moment; the poller retries next pass. |
| Messages arrive but the agent doesn't wake | They were group messages without a mention, or the loop was already running — both by design. |
| Poller can't read the session after login | The web server (php-fpm) and the poller run as different users; make sure both can write `preset_<id>/`. |
| Voice arrives only as `(voice 0:12)` | No server-side STT provider configured for the preset (the browser STT provider can't run on the server). |
