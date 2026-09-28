# Project docs — start here

All project documentation lives in the repo as Markdown, so it is versioned with the code. `.md` files are blocked
from the web by `.htaccess` and never uploaded by the deploy.

| You are… | Read | Covers |
|---|---|---|
| **Developer** | [`DEVELOPER.md`](DEVELOPER.md) | architecture, flow, files, integrations, payments, tracking, logs, testing, deploy, Git workflow |
| **Designer** | [`DESIGNER.md`](DESIGNER.md) | which files to edit for text, images, colors, fonts, layout; rules that keep the funnel working; your Git branch |
| **Anyone setting up a PC** | [`../README.md`](../README.md) | install XAMPP, clone, `.env`, database, first run |
| **QA** | QA test plan (shared as a PDF / doc outside the repo) | test cases per page |

Reference files (kept where they are):

| File | What it is |
|---|---|
| [`../README.md`](../README.md) | Setup guide + quick reference (configuration, logs, deployment) |
| [`../projects/README.md`](../projects/README.md) | Page JSON and components in detail, legal pages, design roadmap |
| [`../PRODUCT-NEW-MIGRATION.md`](../PRODUCT-NEW-MIGRATION.md) | §0: how the rebuild works, file by file, what was checked; §1–§11: the old live site (reference) |
| [`../NOTES-OPEN-ITEMS.md`](../NOTES-OPEN-ITEMS.md) | Open decisions and temporary values, numbered items |
| [`../database/`](../database/) | SQL files; `003_create_flow_tracking.sql` ends with the drop-off report queries |

**Keeping docs current:** a change that alters behaviour updates the matching doc in the same commit
(new setting → `README.md` + `DEVELOPER.md`; new page / section / CSS rule → `DESIGNER.md` + `projects/README.md`;
a decision still open → `NOTES-OPEN-ITEMS.md`).
