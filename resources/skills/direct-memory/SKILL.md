---
name: direct-memory
description: Direct access to the individual memory stores behind knowledge — the journal, semantic notes, people and the entity graph — for browsing, correcting, merging and removing what is stored. Use when knowledge cannot do it — forgetting anything other than a note, fixing a wrong fact, merging duplicates, managing aliases or domains, reading entries by ID or in time order.
metadata:
  depthnet-tools: "journal, vectormemory, person, ontology"
---

`knowledge` is the front door to memory: it decides where each piece of knowledge lives and searches all of it at once. Behind it are four separate stores. This skill opens them directly. The tools' own descriptions show the exact syntax; this skill is about when to reach past `knowledge` and how to keep memory clean.

## Knowledge first, this for the rest

Keep writing and recalling through `knowledge` — it puts things in the same stores, so nothing is duplicated by using both. Come here for what `knowledge` does not do:

- **remove or correct** — `knowledge` can only forget notes. Episodes, person facts and graph entries are removed or fixed here;
- **tidy** — merge duplicate entities, add or remove aliases, move notes between domains, purge an outdated domain;
- **browse** — the latest entries in time order, a single entry in full by ID, everyone you know, all note domains;
- **look deeper** — an entity's neighbourhood two or three hops out, a precise date window in the journal.

Every store addresses its items by ID. Search or list first, take the ID from the result, then act on that ID. Removing by a text match picks the closest item, which is not always the one you meant.

## The four stores

**journal — what happened.** Entries with a type (action, decision, interaction, error, observation, event), a summary, optional details and an outcome. `recent` and `show` read in time order and in full; `search` takes date filters (ISO dates, ranges, months, years, words like yesterday) and, if enabled, a time-of-day range. Delete removes one entry; clearing removes all of them.

**vectormemory — what you know, recalled by meaning.** Notes live in named domains; the default domain holds anything not filed elsewhere. `domains` lists them with counts (the `[[vector_memory_domains]]` placeholder shows the same list in context, if your system prompt has it). Search takes `domain:`, `time:` and part-of-day filters, combinable. Storing under a domain name files a note there. Purging a domain deletes every note in it; the default domain cannot be purged.

**person — facts about people.** Each fact has its own ID. A person's record holds all their names in one string — "Primary / Alias / Alias" — so they are found by any of them. `find` searches names, `search` searches facts by meaning, `recall` gives everything about one person. Aliases are added or removed through the ID of any fact about that person. `forget` removes the person with all their facts; `delete` removes one fact.

**ontology — what is, and how things relate over time.** Nodes (entities with a class), edges (relations between nodes, optionally dated) and properties. Names follow a contract the tool describes: one lowercase English noun per node, PascalCase class, snake_case relation. `snapshot` shows a node with its neighbourhood, deeper on request. `merge` folds one node into another and deletes the first. `close` ends a node's current edges and properties without erasing them — the graph keeps history, so something that stopped being true is closed, not deleted.

## Keeping memory clean

- Before adding an entity or a person, look for it — under its other names too. A duplicate is cheaper to prevent than to merge.
- When two records turn out to be one, merge (ontology) or move the facts and add an alias (person), then remove the leftover.
- When a fact changes, record the new state. In the graph close the old one; elsewhere correct or remove it, so recall does not return both.
- After removing or merging, check: recall the same thing again and see that the result is what you intended.

## Care with removal

Deleting a fact, forgetting a person, purging a domain and clearing the journal cannot be undone here. Before a removal that touches more than one item, look at what it will take with it. When a memory matters to someone else as well — the user's own facts, things they asked you to keep — removing it is their decision, not a cleanup step.
