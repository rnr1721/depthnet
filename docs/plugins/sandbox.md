# Sandbox Plugin

The Sandbox plugin lets the agent execute code and shell commands inside an **isolated Docker container**. Unlike the [Shell plugin](shell.md), nothing runs on the host — the container is a clean, contained environment, so the agent can freely run code without any risk to the host system or other presets.

This is the recommended way to give an agent code execution capabilities.

## Prerequisites

The Sandbox plugin requires a Docker sandbox to be **created and assigned to the preset** before it can run any code. Sandboxes are managed in the admin panel — create one, start it, and assign it to the preset you want to enable code execution for. If no sandbox is assigned or the sandbox is stopped, all execution commands will return an error.

> Docker installation is required. Sandbox execution is not available in the Composer or manual installation methods.

## Setup

Enable the **Sandbox** plugin in your preset settings and configure:

| Setting | Description |
|---|---|
| **Enable Shell commands** | Allow `[run shell]` commands |
| **Enable PHP code** | Allow `[run php]` execution |
| **Enable Python code** | Allow `[run python]` execution |
| **Enable Node.js code** | Allow `[run node]` execution |
| **Execution timeout** | Maximum time a single execution is allowed to run, in seconds (5–300). Default: `30`. |

At least one language must be enabled.

## Commands

The plugin uses the `run` tag with the language as the method:

| Command | Description |
|---|---|
| `[run shell]ls -la[/run]` | Run a shell command |
| `[run shell]ps aux \| grep nginx[/run]` | Pipe commands work normally |
| `[run php]echo "Hello!";[/run]` | Execute PHP code (no opening tags needed) |
| `[run php]$result = 15 * 8 + 45; echo $result;[/run]` | PHP with variables |
| `[run python]print("Hello!")[/run]` | Execute Python code |
| `[run python]import json; print(json.dumps({"x": 1}))[/run]` | Python with imports |
| `[run node]console.log(process.version)[/run]` | Execute Node.js / JavaScript |

## How agents use it

With sandbox execution, the agent can go beyond reasoning and actually *do* things — calculate, transform data, test code, interact with the filesystem inside the container, make HTTP requests, and more. Typical patterns:

- Writing and testing a script before presenting it to the user
- Running data processing or analysis tasks
- Verifying that a solution actually works before reporting it
- Using the container's filesystem as a workspace for multi-step tasks

## Sandbox vs Shell

| | Sandbox | Shell |
|---|---|---|
| **Isolation** | Docker container | Host system, no isolation |
| **Risk to host** | None | Full access as PHP user |
| **Requires Docker** | Yes | No |
| **Recommended for** | Code execution, agent tasks | Operational/admin tasks only |

When in doubt, use Sandbox.