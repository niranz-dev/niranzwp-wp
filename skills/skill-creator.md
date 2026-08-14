---
name: Skill creator
description: How to write a good skill for this site. Load when creating or revising a skill.
---

A skill is the brief you would give a new editor on their first day: what to
do, what never to do, and the reasoning where it is not obvious.

## Shape

Start with the frontmatter block. `name` is what a person sees; `description`
is what a client reads to decide whether this skill applies, so write it as
"how to X" or "what to do when Y", not "this skill is about X".

Then the instructions. Short lines. Imperative. One rule per line.

## What makes a skill work

- Be specific enough to act on. "Write good headlines" is not a rule.
  "Under 70 characters, sentence case, no colon splices" is.
- Give the reason when the rule looks arbitrary, and only then. A rule with a
  reason survives an edge case; a rule without one gets applied literally in a
  situation it was never meant for.
- Include the failure you actually keep seeing. That is usually the whole
  reason the skill exists.
- Leave out anything already true everywhere. General writing advice wastes
  the reader's attention and buries the parts that are specific to this site.

## What does not belong

- Passwords, keys, private data. Skills are handed to every client that asks.
- One-off instructions. Those go in the request, not in a standing rule.
- Site-wide facts. Those belong in Context, which is always loaded; a skill is
  only fetched when its subject comes up.
