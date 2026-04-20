# Agent Task Plugin

The Agent Task plugin provides task management for **orchestrated agent workflows**. It is designed for multi-preset agents where different presets play different roles: a planner creates and assigns tasks, role presets (executors, writers, analysts, etc.) complete or fail them, and validator presets approve or reject the results. The orchestrator handles all routing between presets automatically — the presets themselves just use simple commands.

Active tasks are always visible in the agent's context via `[[agent_tasks]]`.

> This plugin is for **orchestrated mode**. For free-form multi-agent coordination, see the [Agent plugin](agent.md) handoff mechanism instead.

## Roles

Each preset in an orchestrated agent has a role — `planner`, `executor`, `critic`, `validator`, or any custom name defined in the agent configuration. The task commands are designed around these roles:

- **Planner** creates tasks and assigns them to roles
- **Role presets** (executors etc.) mark tasks done or failed
- **Validators** approve or reject completed results

## Setup

Enable the **Agent Task** plugin in your preset settings. The preset must be part of an active orchestrated agent — the plugin checks this automatically and returns an error if the preset isn't assigned to any agent.

| Setting | Description |
|---|---|
| **Task language** | Optionally force a language for tasks, descriptions, and comments. |

## Placeholder

Add this to the preset's system prompt to keep active tasks visible:

```
[[agent_tasks]]
```

## Commands

**Planner — creating and managing tasks:**

| Command | Description |
|---|---|
| `[task]Write a summary[/task]` | Create an unassigned task |
| `[task]Write a summary \| role: writer \| Summarize the research findings[/task]` | Create and assign to a role |
| `[task list][/task]` | List active tasks |
| `[task list]all[/task]` | List all tasks including completed |
| `[task show]42[/task]` | Show full task details |

**Role presets — completing tasks:**

| Command | Description |
|---|---|
| `[task done]42 \| The summary is ready: ...[/task]` | Mark task #42 as complete with result |
| `[task fail]42 \| Could not access the data source[/task]` | Report task #42 as failed with reason |

**Validators — approving results:**

| Command | Description |
|---|---|
| `[task approve]42 \| Looks good, meets requirements[/task]` | Approve result of task #42 |
| `[task reject]42 \| Missing key sections, please revise[/task]` | Reject with feedback |

## Task lifecycle

Tasks move through statuses automatically as presets act on them:

```
pending → in_progress → validating → done
                ↓                      ↑
              failed ─── (retry) ──────
```

The orchestrator manages transitions — presets only need to call `done`, `fail`, `approve`, or `reject`. If a validator rejects a result, the orchestrator can automatically retry the task (depending on agent configuration).

## How it works in practice

A planner preset creates tasks based on the overall goal:
```
[task]Analyse Q1 data | role: analyst | Focus on revenue and churn metrics[/task]
[task]Write executive summary | role: writer | Based on analyst findings[/task]
```

The orchestrator routes the first task to the analyst preset. The analyst works on it and reports back:
```
[task done]1 | Analysis complete. Revenue up 12%, churn down 3%.[/task]
```

If a validator is configured, it reviews the result and either approves or sends it back for revision. Once approved, the orchestrator moves to the next task.