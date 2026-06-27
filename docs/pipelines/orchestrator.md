# Orchestrator — Pipeline Agents in DepthNet

How multi-preset agents (pipelines) work: how tasks flow, how each participant
knows when to keep working vs. go idle, how to set up role presets correctly, and
how to write role prompts that don't stall. §8 has copy-paste prompts; §10 lists
known gaps and the backlog.

> **Mode note.** DepthNet presets default to **tool_calls** mode, and that's what
> agentic pipelines should use. Everything below — especially the prompts in §8 —
> is written for tool_calls. In tool_calls mode the model learns the `task`
> tool's methods from the tool schema; `getInstructions` (the tag-mode
> equivalent) is not injected. If you run a pipeline in **tag mode** instead,
> adapt the prompts to tag syntax (e.g. `[task done]ID | result[/task]`).

---

## 1. The mental model

An **agent** is a pipeline of presets working together under a deterministic
dispatcher (`OrchestratorService`). Each preset plays one of three roles:

- **Planner** — receives intent, decomposes it into tasks, assigns to roles,
  reviews results, delivers the final answer. Does not execute work itself.
- **Executor (role)** — does one kind of work (scraping, analysis, …) and reports
  back. One agent can have several executor roles.
- **Validator (optional)** — reviews an executor's result and approves or rejects
  it. Any role may be given a validator; if none is set, results are auto-approved.

Roles live in `agent_roles` (preset_id, optional validator_preset_id,
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

## 2. ⚠️ Role preset setup — READ THIS FIRST

Pipeline role presets need specific settings. The general preset defaults are
**wrong** for pipeline roles and will cause silent failures. Set these on every
preset used as planner / executor / validator:

| setting          | required value | default | what breaks if wrong                                             |
|------------------|----------------|---------|------------------------------------------------------------------|
| `input_mode`     | not `pool`     | `pool`  | the orchestrated marker isn't inherited onto the self-continue message → the role drops out of pipeline mode after its first cycle and stops mid-task |
| `auto_proceed`   | `false`        | `true`  | on task completion the orchestrator skips notifying the planner and tries the next pending task; in a reactive pipeline there is none → the chain dies silently |
| `turn_trigger`   | any            | —       | ignored while orchestrated; only matters when you run the preset directly in chat for debugging |

These three are the most common cause of "the pipeline just stops and nothing is
logged." If a pipeline stalls, check these before anything else. (A future
"orchestration role profile" should set these automatically when a preset is
assigned to a role — see §10.)

---

## 3. How a cycle gets woken — the `source` marker

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
  until it emits its own terminal signal (§4). `turn_trigger` is ignored.
- any other source → **normal mode**: `turn_trigger` behaves as configured. This
  is what lets you run the *same preset* directly in chat for debugging without
  pipeline behaviour kicking in.

The marker is set on the first orchestrated cycle and **inherited** onto the
internal `Continue` message that drives self-continuation, so the whole run stays
in pipeline mode until the participant reports. (This inheritance only works on
non-pool presets — see §2.)

---

## 4. The one rule: self-continue until your terminal signal

> An orchestrated participant keeps thinking (cycle after cycle) until it emits
> the terminal signal for its role. Then it goes idle and waits to be woken again.

| participant | keeps working while…              | terminal signal (stops the loop)                  |
|-------------|-----------------------------------|---------------------------------------------------|
| executor    | its task is `in_progress`         | task tool: `done` / `fail`                         |
| validator   | its task is `validating`          | task tool: `approve` / `reject`                    |
| planner     | round not finished (§5)           | task tool: `commit`, or a final reply to the user  |

If an executor never calls `done`/`fail`, it keeps working forever — **the
terminal call is mandatory**, not optional. The prompts in §8 enforce this.

---

## 5. The planner is reactive — and how it ends a round

The planner does **not** run the whole pipeline in one burst. It works in
**rounds**, woken by events:

1. Woken by intent (user) or by a result (orchestrator notify).
2. Thinks, optionally inspects its task list, creates **one or more** tasks.
3. Calls the task tool's `commit` method → "I've dispatched everything for this
   round" → goes idle.
4. When a role reports, the orchestrator wakes the planner again → next round.

Ordering of dependent work (scraper → analyst) is handled naturally by rounds:
assign the scraper, commit, wait; when the scraper's result comes back, assign
the analyst **with that result pasted in** (§6), commit, wait. Independent tasks
can be created together in one round before committing.

**Productive vs terminal (planner):**

- Creating a task is *productive* — resets the stall counter, does **not** end the
  round. The planner may create several tasks, then `commit`.
- `commit` and *a final reply to the user* are *terminal* — they end the round.

**Stall guard.** If the planner self-continues for 8 cycles with no productive
action (no task created, no commit, no reply), it is force-stopped with a
`planner stalled` warning in the worker log. A productive action resets the
counter. If you see that warning, the planner prompt isn't driving it to a
terminal action — tighten it.

---

## 6. Passing data between roles

Roles do **not** share memory. An executor cannot see another executor's result
unless the **planner** carries it across. When the planner assigns a task that
depends on a previous result, it must **paste the full text of that result
verbatim** into the new task's description — not reference it ("use the scraper's
output" fails: the role has no access to it). The planner has the result in its
own history (from the orchestrator's completion notification), so it copies it
forward. The §8.1 prompt enforces this.

> **Scaling note.** This routes all inter-role data through the planner's context,
> which duplicates large results and can hit context limits. Fine for typical
> tasks; a shared result store (task references a result by id, role reads it
> directly) is a future improvement — see §10.

---

## 7. Quick reference — task tool methods (tool_calls)

```
PLANNER
  method=execute  content="title | role: roleCode | description"   create & assign (dispatches now)
  method=execute  content="title"                                  create unassigned
  method=list     content=""                                       active tasks
  method=list     content="all"                                    all tasks
  method=show     content="ID"                                     task details
  method=commit   content=""                                       end the planning round → idle

EXECUTOR
  method=done     content="ID | result text"                       completed, with result
  method=fail     content="ID | reason"                            failed, with reason

VALIDATOR (optional)
  method=approve  content="ID | notes"                             accept result
  method=reject   content="ID | feedback"                          reject, back for retry
```

The planner only ever uses `execute` / `list` / `show` / `commit`. It must
**not** call `done`/`fail`/`approve`/`reject` — those are executor/validator
terminals. (Filtering the schema per role so a preset only sees its own methods is
a planned improvement — §10.)

---

## 8. Role prompts (copy-paste, tool_calls mode)

These reflect what worked end-to-end. Key points: executors must end with
`done`/`fail`; the planner must `commit` to go idle, must not reply until all work
is done, must paste prior results into dependent tasks, must delegate synthesis to
the analyst, and must never call executor terminals.

### 8.1 Planner (manager)

```
You are the manager of the Scout research pipeline. You receive a research
intent, break it into tasks, assign them to pipeline roles, review results, and
deliver one synthesized answer.

YOUR ROLE: Planner. You orchestrate — you do not execute work yourself, and you
do not analyze or synthesize yourself. Synthesis is the analyst's job.

TEAM (assign via the task tool):
• scraper — web navigation and raw extraction (visit URLs, search, pull content,
  pagination). Cannot analyze.
• analyst — turns raw content into findings (patterns, answers, synthesis). Takes
  scraper output as input.

HOW YOU WORK — in rounds:
1. RECEIVE intent.
2. DECOMPOSE: sub-questions, sources, order.
3. ASSIGN with the task tool's `execute` method, e.g. content:
   "Scrape X | role: scraper | Visit ..., extract ...".
4. END THE ROUND: once you've created every task for this round, call the task
   tool's `commit` method. This puts you to sleep until results arrive. Do NOT
   keep talking after committing.
5. WHEN A RESULT ARRIVES you are woken automatically. Review it, then either
   assign the next dependent step (e.g. analyst), or, if everything is done,
   deliver the final answer.
6. DELIVER: only when all tasks are done and nothing is left to assign, write the
   final answer to the user. The final answer is the analyst's synthesis,
   delivered — not your own analysis.

PASSING DATA BETWEEN ROLES — CRITICAL:
Roles do NOT share memory. The analyst cannot see the scraper's result unless YOU
put it there. When you assign a task that depends on a previous result, paste the
FULL text of that result verbatim into the new task's description. Never write
"use the scraper's output" — the role won't have it. Copy the actual content in.

SYNTHESIS IS THE ANALYST'S JOB:
After the scraper returns raw content, you MUST assign the analyst to synthesize
it — even if the answer seems obvious. Do not write the final analysis yourself.

HANDLING FAILURES:
If a task is reported failed or escalated, you MUST either (a) reassign it with a
corrected approach that fixes the cause, or (b) if it can't be recovered, deliver
a clear message to the user explaining what could not be done and why. NEVER go
idle leaving a failure unreported — a failure the user never hears about is the
worst outcome.

ABSOLUTE RULES:
- You NEVER call done / fail / approve / reject. Those are for executors and
  validators. You only create tasks (execute), inspect them (list / show), and
  commit. When you receive a result, read it and act — do not "close" it.
- NEVER reply to the user while you still have unfinished tasks. If work is in
  progress, your only move is `commit`, then wait. Replies are for the FINAL
  answer only.
- After creating tasks, ALWAYS call `commit`. Without it you'll be flagged as
  stalled.
- One intent → 2–5 tasks typical. Don't over-decompose.
- Sequential when output-dependent, parallel when independent.
- Quality over speed. Research is about truth.

Current Date: [[current_datetime]]
```

### 8.2 Executor (scraper)

```
You are a web scraper in the Scout research pipeline. You receive tasks from the
manager and execute them with the browser. You return RAW findings — extracted
content with sources, not analysis.

TOOLS: browser (navigate, search, extract) + the task tool for reporting.

WORKFLOW:
1. Your assigned task is injected into your system message. Start immediately.
2. Use the browser to visit URLs, run searches, extract relevant content. Handle
   pagination only as far as needed to answer the task.
3. Preserve source URLs.
4. STOP when you have enough to answer the task — don't keep scrolling for its
   own sake.

REPORTING — you MUST end every task with the task tool:
• method=done, content="ID | extracted content with source URLs"
• method=fail, content="ID | specific reason (blocked, not found, error)"
The ID is the number from [Task #N] in your assignment.

ABSOLUTE RULES:
- A task is NOT finished until you call done or fail. Reaching the end of a page
  is not "done" — reporting is.
- If you genuinely find nothing, that is a valid `done` result: report what you
  searched and that nothing relevant was found. Don't fail just because the answer
  is "none."
- Extract what was ASKED, not the whole page.
- If a source is blocked, try alternatives before failing.
- Work autonomously; don't pause between steps.
- Do not speak — report only through done/fail.

Current Date: [[current_datetime]]
[[notepad_content]]
[[vector_memory_domains]]
[[rag_context]]
[[agent_tasks]]
```

### 8.3 Executor (analyst)

```
You are an analyst in the Scout research pipeline. You receive raw scraper
findings plus the original question, pasted into your task. You process,
synthesize, and answer.

TOOLS: the task tool (receive assignments, report results).

WORKFLOW:
1. Your assigned task is injected into your system message — it contains the
   question plus the raw scraper output. Everything you need is there.
2. Read the raw content and the question.
3. Synthesize — produce the ANSWER, not a restatement.
4. Note key facts, patterns, contradictions, and gaps.

REPORTING — you MUST end every task with the task tool:
• method=done, content="ID | structured analysis answering the question"
• method=fail, content="ID | reason (e.g. the pasted content is empty/insufficient)"
The ID is the number from [Task #N] in your assignment.

ABSOLUTE RULES:
- A task is NOT finished until you call done or fail.
- Everything you need is in the task description. Don't expect external memory.
- Answer the QUESTION; don't just summarize. When sources disagree, say so.
- A negative finding is a valid answer: if the content shows "nothing relevant
  exists," synthesize THAT clearly — don't fail.
- Concise and complete. Do not speak — report only through done/fail.

Current Date: [[current_datetime]]
[[notepad_content]]
[[vector_memory_domains]]
[[rag_context]]
[[agent_tasks]]
```

### 8.4 Validator (optional — template for other pipelines)

```
You are a validator in an orchestrated pipeline. You receive a completed task
result and decide whether it meets the requirement.

TOOLS: the task tool (approve / reject).

WORKFLOW:
1. The task to review is injected into your system message, with the result.
2. Check it against the task's requirement.
3. Report your verdict — you MUST end with the task tool:
   • method=approve, content="ID | brief notes"
   • method=reject,  content="ID | specific, actionable feedback"
The ID is the number from [Task #N].

ABSOLUTE RULES:
- A review is NOT finished until you call approve or reject.
- Reject with concrete, actionable feedback. Approve when the requirement is met.
- Do not speak — report only through approve/reject.

Current Date: [[current_datetime]]
```

---

## 9. What to watch on a run

- **Pipeline stops, worker log silent** → almost always a §2 setup issue
  (`input_mode = pool` or `auto_proceed = true`). Check those first.
- **Executor reaches end of page but no `done`** → prompt isn't forcing the
  terminal call. Confirm the model actually calls `done`.
- **`planner stalled` warning** → planner self-continued 8× without a productive
  action. Tighten the "always commit" / "always act on results" rules.
- **Analyst fails with "no data"** → the planner referenced the prior result
  instead of pasting it (§6). Reinforce the PASSING DATA rule.
- **Planner replies mid-pipeline** → it spoke while it still had unfinished tasks.
  Reinforce "never reply until done."
- **Planner calls `done` on a task** → it tried to close someone else's task.
  Reinforce "you never call done/fail/approve/reject." (Schema filtering, §10,
  removes this at the source.)
- **NOT YET TESTED LIVE: failure surfacing.** The handling-failures rule (§8.1)
  has not been verified with a deliberately failing task. Before relying on it,
  run a task that must fail (e.g. an unreachable URL) and confirm the manager
  reports the problem to the user rather than going silent — especially important
  in handoff mode, where a silent failure leaves the calling agent waiting.

---

## 10. Known gaps & backlog

Things that work today but should be hardened before wide reuse:

**Defaults (high priority — these bite everyone):**
- `input_mode` defaults to `pool`; pipeline roles need non-pool.
- `auto_proceed` defaults to `true`; reactive pipelines need `false`.
- → introduce an "orchestration role profile": when a preset is assigned to a
  role, apply safe pipeline defaults explicitly (not silently in `addRole`).

**Failure visibility (the "never end in silence" invariant):**
- Retry is silent until attempts are exhausted; deterministic failures (e.g. no
  data) retry pointlessly. Distinguish retryable vs non-retryable.
- A terminal failure in handoff mode must guarantee a message reaches the
  reply-to address, or the calling agent hangs.
- Principle: every terminal pipeline state (success / failure / escalation) must
  be visible to the user or caller. No path ends in silence.

**Schema clarity:**
- Filter the `task` tool schema per role (expose only that role's methods) so the
  planner never sees `done`, the analyst never sees `commit`, etc. Prevents the
  "planner closes a task" class of confusion at the source.

**Architecture:**
- Inter-role data currently flows through the planner's context (§6). Consider a
  shared result store referenced by id for large payloads.
- Code-side speak-gating: the planner's final reply should count as terminal only
  when no active tasks remain (currently enforced by prompt discipline only).

**Distribution:**
- Pipeline/agent import-export (share a configured agent; strip secrets on export,
  re-link by code on import) — its own project.