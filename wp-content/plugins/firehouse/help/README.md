# The FireHouse guide

How to keep the FireHouse up to date in WordPress, for FireHouse Editors and the
site's administrators. The plugin shows this guide in the dashboard, at FireHouse →
Help, and each FireHouse screen shows its part under the Help tab at the top right.

## For FireHouse Editors

- [Getting started](01-getting-started.md): What the FireHouse part of WordPress holds, who can change what, and where to find help.
- [Reviewing and publishing a project](02-projects.md): How to review a project, add the FireHouse team's words, publish it, and take it off the site.
- [Editing a topic area](03-topics.md): How to change a topic area's short label, description and what it covers.
- [Publishing a synthesis](04-synthesis.md): How to make a new synthesis of the top needs in NotebookLM, check it, and publish it to the topic pages and the landing page.
- [Changing the landing and About pages](05-landing-and-about-pages.md): How to change the words on the landing page and the About page, and the About page's team.
- [FireHouse settings](06-settings.md): The site name, the Submit a Finding button, and the navigation labels, on FireHouse → Settings.

## For administrators

- [Installing and updating the plugin](07-installing-and-updating.md): How to install the FireHouse plugin from its zip, update it, and what deactivating or deleting it does.
- [Importing content](08-importing.md): How to load the FireHouse's content from a seed bundle on FireHouse → Import.
- [People and portraits](09-people-and-portraits.md): How to give someone the FireHouse Editor role, and how to add a portrait for the About page's team.

## Changing this guide

This file is written by `npm run plugin:help` from the pages beside it, which are the
guide's source; so is `help.json`, which the plugin reads. Edit a page, then run
`npm run plugin:help`; CI fails if either file falls behind. `scripts/plugin-help.mjs`
says what Markdown a page may use. `node scripts/help-screenshots.mjs` takes the
screenshots in `images/` again from the local WordPress. A change to a FireHouse
screen changes its page here in the same pull request.
