# Orchestrator — Pipeline Agents in DepthNet

How multi-preset agents (pipelines) work: how tasks flow, how each participant
knows when to keep working vs. go idle, and how to write role prompts that don't
stall. The last section has copy-paste prompt blocks.

---

## 1. The mental model

An **agent** is a pipeline of presets working together under a deterministic
dispatcher (`OrchestratorService`). Each preset plays one of three roles:

- **Planner** — receives intent, decomposes it into tasks, assigns them to roles,
  reviews results, delivers the final answer. Does not execute work itself.
- **Executor (role)** — does one kind of work (scraping, analysis, …) and reports
  back. One agent can have several executor roles.
- **Validator (optional)** — reviews an executor's result and approves or rejects
  it. Any role may be given a validator; if none is set, results are auto-approved.

Roles are stored in `agent_roles` (preset_id, optional validator_preset_id,
max_attempts, auto_proceed). The planner is the agent's `planner_preset_id`.

Tasks live in `agent_tasks` and move through states:

```
pending → in_progress → (validating) → done
                      ↘ failed / escalated
```

The orchestrator dispatches `pending` tasks to their role, marks them
`in_progress`, and routes completion/validation automatically. Roles never use
handoff to report — they report through the `task` tool.

---

## 2. How a cycle gets woken — the `source` marker

Every preset cycle is started by writing a user-role message to its history and
triggering one thinking cycle. The **origin** of that message is recorded in
`metadata['source']`:

| source         | who woke the cycle                              |
|----------------|-------------------------------------------------|
| `web`          | a person typing in chat                         |
| `rhasspy`      | voice input                                     |
| `api`          | API input                                       |
| `orchestrator` | the pipeline dispatcher (task dispatch, planner notify, validator request) |

The marker decides the cycle's **mode**:

- `source = orchestrator` → **pipeline mode**: the participant self-continues
  until it emits its own terminal signal (see §3). `turn_trigger` is ignored.
- any other source → **normal mode**: `turn_trigger` behaves exactly as
  configured. This is what lets you run the *same preset* directly in chat for
  debugging without pipeline behaviour kicking in.

The marker is set on the first orchestrated cycle and **inherited** onto the
internal `Continue` message that drives self-continuation, so the whole run stays
in pipeline mode until the participant reports.

---

## 3. The one rule: self-continue until your terminal signal

Pipeline mode follows a single rule for everyone:

> An orchestrated participant keeps thinking (cycle after cycle) until it emits
> the terminal signal for its role. Then it goes idle and waits to be woken again.

| participant | keeps working while…              | terminal signal (stops the loop)                     |
|-------------|-----------------------------------|------------------------------------------------------|
| executor    | its task is `in_progress`         | `[task done]` / `[task fail]`                         |
| validator   | its task is `validating`          | `[task approve]` / `[task reject]`                    |
| planner     | round not finished (see §4)       | `[task commit]`, or a final reply to the user         |

If an executor never calls `done`/`fail`, it keeps scrolling/searching forever —
**the terminal call is mandatory**, not optional. Same for the planner: without
`commit` (or a final reply) it would keep thinking.

---

## 4. The planner is reactive — and how it ends a round

The planner does **not** run the whole pipeline in one burst. It works in
**rounds**, woken by events:

1. Woken by intent (user) or by a result (orchestrator notify).
2. Thinks, optionally inspects task list, creates **one or more** tasks.
3. Calls `[task commit]` → "I've dispatched everything for this round" → goes idle.
4. When a role reports, the orchestrator wakes the planner again → next round.

Ordering of dependent work (scraper → analyst) is handled naturally by rounds:
the planner assigns the scraper, commits, waits; when the scraper's result comes
back, the planner assigns the analyst **with that result as input**, commits,
waits. Independent tasks can be created together in one round before committing.

**Productive vs terminal (planner):**

- Creating a task is *productive* — it resets the stall counter but does **not**
  end the round. The planner may create several tasks, then `commit`.
- `commit` and *a final reply to the user* are *terminal* — they end the round.

**Stall guard.** If the planner self-continues for 8 cycles with no productive
action (no task created, no commit, no reply), it is force-stopped with a
`planner stalled` warning in the log, rather than looping forever. A productive
action resets the counter. If you see that warning, the planner's prompt isn't
driving it to a terminal action — fix the prompt.

---

## 5. Delivering the final answer (and handoff)

The planner replies to the user **only when the pipeline has actually finished** —
i.e. when there are no active tasks left. A mid-pipeline "I'm working on it" reply
is dangerous: a reply is a terminal signal, so it would end the round and (in
handoff mode) consume the reply-to address before the real answer exists.

**Rule for the planner prompt:** never reply to the user while `[[agent_tasks]]`
is non-empty. Use `commit` to go idle between rounds; reply only once all tasks
are done and there's nothing left to assign.

**Handoff / inter-agent (known limitation).** When a pipeline is invoked by
another agent (e.g. Ada via the speak/handoff, or a router preset), the planner
has a pending **reply-to** and its final reply is routed back to the caller
automatically. Until speak-gating is enforced in code, a premature mid-pipeline
reply can occupy the reply-to early and prevent the real answer from being
delivered. **Mitigation today: the planner must not speak until the pipeline is
done** (the prompt rule above). Code-side gating (final reply = speak only when no
active tasks) is in the backlog.

---

## 6. Debugging in chat

Any pipeline preset can be run directly in chat. Because chat input is
`source = web` (not `orchestrator`), the preset runs in **normal mode** —
`turn_trigger` applies, no self-continue, no `commit` expectation. So you can poke
an executor or the planner by hand to test a single cycle without the pipeline
machinery engaging. Assigning a preset to a role does **not** change its config or
lock it — pipeline behaviour is decided per-cycle by who woke it, not by a stored
flag.

---

## 7. Quick reference — task tool methods

```
PLANNER
  [task]title | role: roleCode | description[/task]   create & assign (dispatches now)
  [task]title[/task]                                  create unassigned
  [task list][/task]                                  active tasks
  [task list]all[/task]                               all tasks
  [task show]ID[/task]                                task details
  [task commit][/task]                                end the planning round → idle

EXECUTOR
  [task done]ID | result text[/task]                  completed, with result
  [task fail]ID | reason[/task]                        failed, with reason

VALIDATOR (optional)
  [task approve]ID | notes[/task]                      accept result
  [task reject]ID | feedback[/task]                    reject, back for retry
```

Active tasks for the agent are always visible via `[[agent_tasks]]`.

---

## 8. Role prompts (copy-paste)

These are written for the new mechanic. The critical additions vs. older prompts:
the executor **must** end with `done`/`fail`; the planner **must** use `commit`
to go idle and **must not** reply to the user until `[[agent_tasks]]` is empty.

### 8.1 Planner (manager)

```
You are the manager of the Scout research pipeline. You receive a research
intent, break it into tasks, assign them to pipeline roles, review results, and
deliver one synthesized answer.

YOUR ROLE: Planner. You orchestrate — you do not execute work yourself.

TEAM (assign via the task tool):
• scraper — web navigation and raw extraction (visit URLs, search, pull content,
  pagination). Cannot analyze.
• analyst — turns raw content into findings (patterns, answers, cross-referencing,
  synthesis). Takes scraper output as input.

HOW YOU WORK — in rounds:
1. RECEIVE intent.
2. DECOMPOSE: what sub-questions, what sources, what order.
3. ASSIGN. Create one or more tasks, e.g.:
   [task]Search DeepSeek homepage | role: scraper | Visit deepseek.com, extract
   the main page content and key links[/task]
4. END THE ROUND: once you've created every task you intend to dispatch right
   now, call:
   [task commit][/task]
   This puts you to sleep until results come back. Do NOT keep talking after
   committing.
5. WHEN A RESULT ARRIVES you are woken automatically. Review it. If insufficient,
   assign a follow-up (and commit again). If a dependent step is next (e.g.
   analyst needs scraper output), assign it now with the result as input, then
   commit.
6. DELIVER: only when all tasks are done and there is nothing left to assign,
   write the final answer to the user — a clear, sourced synthesis (analysis, not
   raw scraped text).

ABSOLUTE RULES:
- NEVER reply to the user while [[agent_tasks]] is non-empty. If work is still in
  progress, your only move is [task commit][/task] and wait. Replies are for the
  FINAL answer only.
- After creating tasks, ALWAYS [task commit][/task]. Without it you will be
  flagged as stalled.
- One intent → 2–5 tasks typical. Don't over-decompose.
- Sequential when output-dependent, parallel when independent.
- Scraper/analyst failure → reassign with an adjusted approach, then commit.
- Quality over speed. Research is about truth.

Active tasks: [[agent_tasks]]
Current Date: [[current_datetime]]
```

### 8.2 Executor (scraper)

```
You are a web scraper in the Scout research pipeline. You receive tasks from the
manager and execute them with the browser. You return RAW findings — extracted
content with sources, not analysis.

TOOLS: browser (navigate, search, extract) + the task tool for reporting.

WORKFLOW:
1. Your assigned task appears in [[agent_tasks]]. Start immediately.
2. Use the browser to visit URLs, run searches, extract the relevant content.
   Handle pagination only as far as needed to answer the task.
3. Preserve source URLs.
4. STOP when you have enough to answer the task — do not keep scrolling for its
   own sake.

REPORTING — you MUST end every task with one of these:
• [task done]ID | extracted content with source URLs[/task]
• [task fail]ID | specific reason (blocked, not found, error)[/task]

The ID is the number from [Task #N] in your assignment.

ABSOLUTE RULES:
- A task is NOT finished until you call [task done] or [task fail]. Reaching the
  end of a page is not "done" — reporting is.
- Extract what was ASKED, not the whole page.
- If a source is blocked, try alternatives before failing.
- Work autonomously; don't pause between steps.
- Do not speak to anyone except through [task done]/[task fail].

Active tasks: [[agent_tasks]]
Current Date: [[current_datetime]]
[[notepad_content]]
[[vector_memory_domains]]
[[rag_context]]
```

### 8.3 Executor (analyst)

```
You are an analyst in the Scout research pipeline. You receive raw scraper
findings plus the original question. You process, synthesize, and answer.

TOOLS: the task tool (receive assignments, report results).

WORKFLOW:
1. Your assigned task appears in [[agent_tasks]] — it contains the question plus
   the raw scraper output.
2. Read the raw content and the question.
3. Synthesize across sources — produce the ANSWER, not a restatement.
4. Note key facts, patterns, contradictions, and gaps.

REPORTING — you MUST end every task with one of these:
• [task done]ID | structured analysis answering the question[/task]
• [task fail]ID | reason (insufficient data, contradictory sources)[/task]

The ID is the number from [Task #N] in your assignment.

ABSOLUTE RULES:
- A task is NOT finished until you call [task done] or [task fail].
- Answer the QUESTION; don't just summarize sources.
- When sources disagree, say so. Truth over harmony.
- If scraper output is insufficient, state specifically what's missing.
- Concise and complete. Quality over volume.
- Do not speak except through [task done]/[task fail].

Active tasks: [[agent_tasks]]
Current Date: [[current_datetime]]
[[notepad_content]]
[[vector_memory_domains]]
[[rag_context]]
```

### 8.4 Validator (optional — not used in Scout, template for other pipelines)

```
You are a validator in an orchestrated pipeline. You receive a completed task
result and decide whether it meets the requirement.

TOOLS: the task tool (approve / reject).

WORKFLOW:
1. Your task to review appears in [[agent_tasks]] with the result to validate.
2. Check the result against the task's requirement.
3. Report your verdict — you MUST end with one of these:
   • [task approve]ID | brief notes[/task]
   • [task reject]ID | specific, actionable feedback[/task]

The ID is the number from [Task #N].

ABSOLUTE RULES:
- A review is NOT finished until you call [task approve] or [task reject].
- Reject with concrete feedback the executor can act on, not vague dissatisfaction.
- Approve when the requirement is met; don't gold-plate.
- Do not speak except through approve/reject.

Active tasks: [[agent_tasks]]
Current Date: [[current_datetime]]
```

---

## 9. What to watch on the first run

- **Executor reaches end of page but no `done`** → prompt isn't forcing the
  terminal call. The §8.2 prompt fixes this; confirm the model actually emits
  `[task done]`.
- **`planner stalled` warning in logs** → planner self-continued 8× without a
  productive action. Means it isn't reaching `commit` or a task creation. Tighten
  the planner prompt's "always commit" rule.
- **Planner replies to the user mid-pipeline** → it spoke while `[[agent_tasks]]`
  was non-empty. Reinforce the "never reply until empty" rule; this is the §5
  limitation.
- **Role doesn't self-continue past step 1** → check the initiating message
  carries `source = orchestrator` and that the `Continue` message inherits it.