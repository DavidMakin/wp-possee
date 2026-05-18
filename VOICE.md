# Writing Voice

Reference for AI agents writing or editing blog posts for this site.

## Who this is

A developer writing for other developers (and himself). Not a content marketer. Not a technical writer. Someone who solved a problem, wrote down how, and published it.

## Published posts to read first

Before writing anything, read these for calibration. Fetch via WP-CLI:

- Post 36 — Silex REST part 1 (tutorial, explains from scratch)
- Post 42 — Composer/Phing/Jenkins (troubleshooting narrative)
- Post 44 — Munin RRD migration (problem → solution, no fluff)
- Post 38 — Joining PDFs (two sentences and a code block — the minimum viable post)

## Voice markers

**Jumps straight in.** No "In this post I will show you how to...". No "Here's how X works." No scene-setting. The first sentence is the content.

**Short declarative sentences.** Especially for conclusions and gotchas. "Port forwarding is pointless." "Go for solution 2." "`--user 65532` is not optional."

**Honest about limitations.** "It's a little rough and ready but it does the job." "I have skipped a stage here, I am sure you can do this without my help." "That title makes this sound a lot grander than it is."

**First person, present tense.** "I run this site through..." not "This site runs through...". Owns the setup.

**Opinions stated plainly.** Not hedged. "It's more complexity than it's worth." "Don't skip it and proxy directly to PHP-FPM."

**Casual discovery language.** "Turns out they were not being stored." "Worth knowing before you convince yourself the change is broken."

**Dry understatement.** "Half the internet was also down at the time, so I didn't worry about it."

**Fragments are fine.** "Nothing listening for inbound traffic, no cert to renew." Complete sentences not required when a fragment is sharper.

## What to avoid

**"Here's how..." openers.** He doesn't use them. Cut them. Go straight to the heading or the first fact.

**Connector paragraphs.** "Here's the complete picture." / "Here's how the whole chain fits together." / "Let me walk you through the setup." — all cut.

**AI vocabulary.** Additionally, crucial, pivotal, seamless, vibrant, landscape, testament, underscore, highlight, showcase. If one of these appears, rewrite the sentence.

**Rule of three.** Lists of exactly three things for rhetorical effect. Use two, or four, or just say the thing once.

**Inline-header bold pattern.** Bullet lists where each item starts with `**Word.**` followed by prose. Use proper subheadings (`###`) or write it as prose.

**Vague attributions.** "Experts say", "Many developers find", "It is generally considered". Just say the thing directly.

**Generic positive conclusions.** "It works well and has served me well." / "The result is a clean and maintainable setup." End on something specific: what it actually does, or an honest caveat.

## Structure

- Headings are sentence case: "Reading files inside the container" not "Reading Files Inside The Container"
- Headings are functional, not promotional. Describe the section, don't sell it
- Code blocks for anything you'd type or copy
- No "Further reading" sections unless there's a specific reason to link out
- Cross-links between related posts (use `/?p=ID` format — permanent regardless of publish date)

## Post types on this site

**Setup/infrastructure posts** (293, 294): Walk through what was done and why. Gotchas in their own paragraphs. Honest about tradeoffs.

**IndieWeb how-it-works posts** (295, 296, 297): Explain the concept briefly, then the mechanics. How things connect. What breaks. Honest about the rough edges.

**Tutorial posts** (36, 40, 46): Step by step. Show the code. Explain what it does. Give an opinion on which approach to use.

## Excerpts

Write excerpts as a single sentence or two that say what the post actually contains. Not a tease, not a summary — the specific thing the reader will learn or recognise. Example: "My ISP puts me behind CGNAT, so port forwarding was never an option. Here's how I run this site on home hardware with no open ports, using a Cloudflare Tunnel as the only way in."

## What a good draft looks like

Post 293 and 294 after editing are the best reference for the current style. Post 38 is the best example of the minimum: say the thing, show the code, stop.
