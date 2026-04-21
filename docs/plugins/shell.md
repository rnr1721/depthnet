# Shell Plugin

> ⚠️ **This plugin executes commands directly on the host system as the PHP process user. Enable it only if you fully understand the security implications and trust the agent's prompts. For general code execution, use the [Sandbox plugin](sandbox.md) instead.**

The Shell plugin gives the agent direct access to the host's command line. Unlike the Sandbox plugin — which runs code inside an isolated Docker container — Shell commands run on the real host with no isolation boundary. The agent can read files, check disk usage, look at logs, restart services, and do anything else the PHP process user is permitted to do.

This is intentional: Shell is designed for **operational and administrative tasks** on installations where the operator knowingly accepts this trade-off.

## Setup

Enable the **Shell** plugin in your preset settings. It is disabled by default and must be consciously turned on.

| Setting | Description |
|---|---|
| **Execution user** | Run commands as a specific system user. Leave empty to use the current PHP process user. |
| **Working directory** | Default directory where commands are executed. |
| **Show shell prompt** | Display a `user@host:path $` prefix in command output, like a real terminal. |
| **Timeout (seconds)** | Maximum time a single command is allowed to run (1–600). Default: `60`. |
| **Security checks** | When enabled, commands matching the dangerous-commands blacklist are blocked before execution. |
| **Allowed directories** | Restrict execution to specific directories (one per line). |
| **Dangerous commands** | Custom blacklist of command strings to block. Overrides the built-in defaults if provided. |

### Default blocked commands

The built-in blacklist includes patterns like `rm -rf /`, `sudo`, `shutdown`, `reboot`, `wget`, `curl`, `nc`, `dd if=`, `fdisk`, and similar. You can replace this list entirely with your own via the **Dangerous commands** setting.

## Commands

The plugin exposes a single tag — the content is passed directly to `bash`:

```
[shell]command here[/shell]
```

Examples from the agent's perspective:

| Command | What it does |
|---|---|
| `[shell]ls -la[/shell]` | List files in the current directory |
| `[shell]cat filename.txt[/shell]` | Read a file |
| `[shell]pwd[/shell]` | Show current directory |
| `[shell]df -h && free -h[/shell]` | Check disk and memory usage |
| `[shell]ps aux \| grep nginx[/shell]` | Find running processes |
| `[shell]mkdir new_folder[/shell]` | Create a directory |
| `[shell]find . -name "*.php"[/shell]` | Find files by pattern |

The agent's working directory persists across commands within a session — so a `cd` in one command will be remembered in the next.

## Security considerations

Because Shell runs on the host without isolation, a misconfigured or poorly prompted agent could cause real damage. Before enabling:

- Make sure the PHP process user has **only the permissions it actually needs**
- Configure **allowed directories** to restrict where the agent can operate
- Keep **security checks enabled** and review the dangerous-commands list
- Consider whether [Sandbox plugin](sandbox.md) would be sufficient for your use case — it provides the same code execution capabilities inside an isolated Docker container

If you are running DepthNet in Docker via `manager.sh`, the PHP process user is typically a restricted system user, which limits the blast radius by default.