# Telegram Plugin

The Telegram plugin gives the agent access to a real Telegram account — it can read and send messages, browse dialogs, search chat history, and get user or channel info. Each preset maintains its own independent Telegram session, so multiple agents can be authorized under different accounts simultaneously.

Under the hood the plugin uses [tgcli](https://github.com/rnr1721/tgcli) — a standalone open-source CLI tool for Telegram interaction via MTProto.

## Authorization

Before the agent can use Telegram, the preset must be authorized. Go to the preset's **Telegram** tab in the UI and follow the authorization flow:

1. Enter your Telegram **API ID** and **API Hash** (obtain them at [my.telegram.org](https://my.telegram.org))
2. Enter your phone number
3. Enter the confirmation code sent to your Telegram app
4. If your account has two-factor authentication enabled, enter your password

Once authorized, the session is stored per-preset and persists across restarts. You can revoke it from the same UI tab.

## Setup

Enable the **Telegram** plugin in your preset settings. Available options:

| Setting | Description |
|---|---|
| **Default read limit** | How many messages to fetch when the agent doesn't specify a count. Default: `15`. |
| **Account cache (minutes)** | How long to cache the account info for the `[[telegram_account]]` placeholder. Default: `60` minutes. |

## Placeholder

Add this to the preset's system prompt to let the agent always know which Telegram account it is authorized under:

```
[[telegram_account]]
```

## Commands

| Command | Description |
|---|---|
| `[telegram dialogs][/telegram]` | List all dialogs (default limit: 30) |
| `[telegram dialogs]50 channels[/telegram]` | List channels only (limit 50) |
| `[telegram dialogs]50 groups[/telegram]` | List groups only |
| `[telegram dialogs]50 users[/telegram]` | List private chats only |
| `[telegram read]@username[/telegram]` | Read last messages from a chat |
| `[telegram read]@username 30[/telegram]` | Read last N messages |
| `[telegram read]1820894363 10[/telegram]` | Read by numeric chat ID |
| `[telegram send]@username Hello![/telegram]` | Send a message |
| `[telegram unread][/telegram]` | Show unread dialogs |
| `[telegram search]@groupname keyword[/telegram]` | Search messages in a chat |
| `[telegram info]@username[/telegram]` | Get user or channel info |
| `[telegram mark_read]@username[/telegram]` | Mark a dialog as read |
| `[telegram me][/telegram]` | Show current account info |
| `[telegram]dialogs 20 users[/telegram]` | Raw tgcli command (fallback) |

## How agents use it

With Telegram access, an agent can act as a genuine Telegram user — monitoring conversations, responding to messages, gathering information from channels, or sending notifications. Combined with the agent's autonomous loop, this enables things like:

- Monitoring a group chat and summarising new messages each cycle
- Sending a Telegram message when a task is completed or a condition is met
- Acting as a personal assistant that reads and replies to messages on your behalf

> **Note:** The agent is operating as a real user account, not a bot. Make sure you understand the implications for the account you authorize.