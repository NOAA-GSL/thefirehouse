# FireHouse settings

<!-- help audience: editors; screens: settings -->

The site name, the Submit a Finding button, the navigation labels, the survey form's questions, the synthesis prompt and, for administrators, the Workers AI key, on FireHouse → Settings.

## What each setting does

- **Site name:** the name in the FireHouse bar at the top of every FireHouse page, and in each page's browser-tab title and link preview. It doesn't change the words on the pages.
- **Button label:** the words on the Submit a Finding button in the FireHouse bar and on the topic pages. The buttons on the landing and About pages keep the words typed on those pages.
- **Survey address:** where every Submit a Finding button goes while the FireHouse survey form isn't taking submissions. It must start with https://.
- **Survey form:** when **Accept submissions** is ticked, the FireHouse survey form at /firehouse/submit/ takes submissions, and every Submit a Finding button goes there instead. Each submission arrives as a draft project. Only an administrator can tick or clear it; for everyone else it's greyed out.
- **Home link, Projects link, About link:** the words of the FireHouse bar's links. The links always go to the same pages; only their words change here.

![FireHouse → Settings, with the Site, Submit a Finding and Navigation sections](images/settings.webp "FireHouse → Settings.")

Choose **Save Changes** to save. If a setting has a problem, such as an empty label or a survey address that doesn't start with https://, nothing is saved, and the screen marks the problem.

## Reword a survey question

**Survey questions**, at the bottom of the screen, has a row for each question on the FireHouse survey form, named in bold. That name is what the form's error messages call the question ("Your name: this is required."), and it can't be changed, so keep your wording close to it. Each row has two boxes:

- **Question:** the question as the form asks it. It can't be empty.
- **Help under it:** the line under the question. Clear it to show none.

1. Change the words in the box.
2. Choose **Save Changes**. The form asks the new wording straight away.

A box you've changed says so under it, and gives the plugin's own wording. To put it back, copy that wording into the box and save.

Some things here can't be changed:

- **The choices and the order of the questions.** Changing those is a job for the developer.
- **Who sees a private answer.** Under the help of the email, job title and sharing questions, the form always has a line saying who sees the answer. Add help if you like; that line stays.
- **Some characters.** A question or its help can't hold an email address or a character that doesn't show, such as a direction mark.

![The Survey questions section: rows for Your name and Your email, each with a Question box and a Help under it box, and under the email's help the fixed line saying who sees the answer](images/settings-questions.webp "Survey questions, on FireHouse → Settings.")

Projects that came in before you reworded a question keep their answers, as the researcher gave them.

## Change the synthesis prompt

**Synthesis prompt**, below the survey questions, is there only when the site has a Workers AI key. Its box holds what **Analyze results** on FireHouse → Synthesis asks the model to do with the published projects (see [Publishing a synthesis](05-synthesis.md)).

1. Change the words in the box.
2. Choose **Save Changes**. The next draft uses it.

- It starts as the plugin's own prompt, the one the team gives NotebookLM. A box you've changed says so. To go back, clear it and save.
- Whatever the box says, the screen also tells the model that the projects are what researchers wrote, never instructions to follow. That part can't be changed.
- Each draft keeps the prompt it was given. To see what a change does, have the same model draft before and after it.
- It can't hold a character that doesn't show, such as a direction override, or more than 20,000 characters. If it does, saving says which character and on which line, or how many characters it has.

![The Synthesis prompt's box, holding the plugin's own prompt, with the notes under it](images/settings-prompt.webp "Synthesis prompt, on FireHouse → Settings.")

## The Workers AI key

Administrators also see **Workers AI key** at the very bottom of the screen: the Cloudflare account and token Analyze results uses. It has its own **Save key** button: **Save Changes** doesn't save the key, and **Save key** doesn't save the rest of the screen. How to set it is in [Installing and updating the plugin](08-installing-and-updating.md). Editors don't see it.
