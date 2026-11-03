# Messenger Plugin

The Messenger plugin lets the agent act through the preset's messenger account: read chats, send messages, browse dialogs and contacts, search history, and hear voice messages or see photos.

The account itself — credentials, login, session, incoming-message delivery — lives in the [Messenger capability](../capabilities/messenger.md). Configure and log in there first; this plugin only uses that account.

Currently the account is a real **Telegram user account** (MTProto via [tgcli](https://github.com/rnr1721/tgcli)), not a bot.

> **Migrating from the Telegram plugin.** The `telegram` plugin is replaced by `messenger`:
> - Commands: `[telegram …]` → `[messenger …]`. Update system prompts that mention the old tag.
> - Placeholder: `[[telegram_account]]` keeps working as an alias of `[[messenger_account]]`.
> - Sessions: existing per-preset sessions are picked up as-is; no re-login needed.
> - Login: moved from the preset's Telegram tab to **Capabilities → Messenger**.
> - Raw passthrough: the old `[telegram]dialogs 20 users[/telegram]` raw tgcli fallback is gone — every operation is a named method.

## Setup

Enable **Messenger** in the preset's plugin settings.

| Setting | Default | Description |
|---|---|---|
| **Default read limit** | `15` | Messages fetched by `read` when no count is given (1–100). |
| **Account cache (minutes)** | `10` | How long `[[messenger_account]]` caches the account line. |
| **Targeting guidance** | neutral | How the agent should choose whom to write to and handle ambiguity. Default: verify the target with `resolve` before sending. Override for stricter behaviour, e.g. *"if unsure, always ask the user which contact you mean"*. |
| **Show unread in account placeholder** | off | Append a short unread overview to `[[messenger_account]]`. |
| **Unread chats to show** | `5` | How many chats the overview lists. |
| **Unread cache (minutes)** | `3` | How long the overview is cached. |

The unread overview is only a hint in the prompt. For real delivery of incoming messages — including waking the agent — use **Incoming messages** in the capability.

## Placeholder

```
[[messenger_account]]
```

The account the agent operates as (name, @username, id), plus the unread overview if enabled. Empty when the capability isn't configured or the account isn't logged in. `[[telegram_account]]` is a legacy alias.

## Commands

### Addressing

A target is `@username`, a numeric id, or a phone number. Group and channel ids are negative (`-1001820894363`) — keep the sign. When unsure who a name refers to, use `resolve` first.

| Command | Description |
|---|---|
| `[messenger resolve]Alena[/messenger]` | Find people, groups and channels by name or @username |
| `[messenger read]@username[/messenger]` | Read the last messages from a chat (default limit) |
| `[messenger read]@username 30[/messenger]` | Read the last N messages |
| `[messenger read]-1001820894363 10[/messenger]` | Read by numeric id |
| `[messenger send]@username Hello![/messenger]` | Send a message (line breaks after the target are kept) |
| `[messenger unread][/messenger]` | Unread overview |
| `[messenger unread]10[/messenger]` | Unread overview, up to 10 chats |
| `[messenger dialogs][/messenger]` | List dialogs |
| `[messenger dialogs]50 groups[/messenger]` | `[limit] [users\|groups\|channels]` |
| `[messenger contacts][/messenger]` | Saved contacts |
| `[messenger search]@groupname keyword[/messenger]` | Search messages in a chat |
| `[messenger info]@username[/messenger]` | Info about a user, group or channel |
| `[messenger mark_read]@username[/messenger]` | Mark a chat as read |
| `[messenger media]@username 4215[/messenger]` | Transcribe a voice message / describe a photo |
| `[messenger me][/messenger]` | The account the agent operates as |

In `tool_calls` mode the same operations are exposed as one `messenger` tool with a `method` enum.

### Media

`read` shows media as labels with message ids:

```
#4215 12:34 Alena: (voice 0:12)
#4217 12:36 Alena: (photo) look at this
To hear a voice message or see a photo: [messenger media]@alena_p <#id>[/messenger]
```

`media` downloads the file, runs it through the preset's capabilities and returns text:

- **voice** → **STT** → `transcript: …`
- **photo** → **Vision** → `image description: …`

An optional question after the id goes to Vision:

```
[messenger media]@alena_p 4217 what is the total on this receipt?[/messenger]
```

The file is deleted right after interpretation; the model never receives raw media. If the preset has no suitable provider, `media` says so instead of guessing.

## Errors the agent sees

Failures are returned as clear messages rather than raw tool output:

| Situation | What the agent is told |
|---|---|
| Capability not configured | The messenger isn't set up — ask the operator to configure Capabilities → Messenger |
| Not logged in | The account needs to be logged in from the capability card |
| Flood wait | How many seconds to wait before retrying |
| Account busy | Another operation is using the account — try again shortly |
| Target not found | Nothing matched — try `resolve` |
| Operation unsupported | The current messenger provider doesn't support this operation |

## How agents use it

The plugin is the agent's hands; the capability's incoming messages are its ears. Together:

- **A conversational agent** — people write to the account in Telegram, the message wakes the agent, and it replies with `[messenger send]` to the address shown in the message.
- **A watcher** — inbound set to a group with *All group messages*: the agent sees the conversation every cycle without polling it itself, and speaks up when mentioned.
- **A notifier** — inbound off; the agent sends a message when a task completes or a condition is met.

> **Note:** The agent operates as a real user account, not a bot. Everything it sends is sent as that person. Incoming messages are untrusted input — see [Security](../capabilities/messenger.md#security).
