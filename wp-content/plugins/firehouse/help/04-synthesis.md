# Publishing a synthesis

<!-- help audience: editors; screens: synthesis, topic-edit -->

How to make a new synthesis of the top needs in NotebookLM, check it, and publish it to the topic pages and the landing page.

## What the synthesis is

The synthesis reads every published project together and lists up to five needs in each topic area, each with how many projects raise it and which ones. It's the one place the site puts researchers' findings in other words, so nothing reaches the site until someone on the FireHouse team has reviewed it.

Make a new one when projects have been published or taken off the site since the last.

## 1. Download the source

Go to **FireHouse → Synthesis** and choose **Download NotebookLM source**. You get firehouse-findings.md: every published project, word for word, and the four topic areas.

![FireHouse → Synthesis, with the Download NotebookLM source button and the box for the synthesis](images/synthesis-screen.webp "FireHouse → Synthesis.")

## 2. Run the prompt in NotebookLM

1. In the FireHouse notebook, delete the old firehouse-findings.md source and upload the one you downloaded. Keep it the notebook's only source, so the synthesis can't draw on anything the site doesn't show.
2. In the notebook's chat, run the team's prompt. The current prompt is in the [FireHouse repository's NotebookLM notes](https://github.com/NOAA-GSL/thefirehouse/blob/main/notebooklm/README.md#prompt).

## 3. Review it

NotebookLM answers with the needs and their citations, then a block of JSON that starts with `[` and ends with `]`. Check each need against the projects it came from, with NotebookLM's citations, and edit the wording: the published words are the team's, not the model's.

Edit the JSON in the box on FireHouse → Synthesis, or in a plain-text editor such as Notepad. Don't use Word or Google Docs: they change the quotation marks, and the screen can't read it then. A need's words are its `"text"`, its count is its `"mentions"` number, and its projects are its `"projects"` list.

- In each area, put the needs in order of their project count, highest first. The prompt lists the most important need first, so you'll usually reorder them: the check refuses any other order. For a tie, put first the need that applies across more agencies or regions.
- Each need's count must equal the number of projects it lists. If you take a project out of a need, lower its count.

Keep a copy of your edited version until it's published.

## 4. Check it

1. Copy the block, from its first `[` to its last `]`.
2. Paste it into **The JSON the prompt gave**, and choose **Check the synthesis**.
3. If the screen says "Not ready", it lists each problem. Fix them in the box and check again. Nothing is written until the check finds nothing wrong.

## 5. Mark it reviewed

When the screen says "Ready to mark reviewed", read the needs it shows. **Reviewed by** starts with the name your account shows, and the topic pages show it to visitors: type the names as the team wants them published. **Made with** starts with NotebookLM. Then choose **Mark reviewed and publish**.

The topic pages and the landing page show the new synthesis at once, with today's date.

> Mark it reviewed within thirty minutes of the check. If you wait longer, or open FireHouse → Synthesis again in between, the check is dropped, and you check it again.

If it says "Not published: the projects changed since the check", a project was published or taken off the site in between. The screen has checked it again: review the needs, then mark it reviewed. If it says "Not published: this check isn't waiting any more", check it again.

## Undo it

Each topic area keeps the synthesis it had before as a revision. To go back, open each of the four areas under **FireHouse → Topics**, choose **Browse** next to Revisions, move the slider one step to the left, and choose **Restore This Revision**. Do all four. A restore also puts back the area's label, description and what it covers as they were then.
