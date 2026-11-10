# digifoxprosolutions.com (company website pages)

`public_html/` mirrors the website's `public_html` on Hostinger, for the pages kept here
(currently `index.html`, `our-services/index.html`, `quiz/index.html`).

When a change under `website/` is merged into `main`, the **Publish website** GitHub Action
uploads these files (and only these). It backs up each old page to `~/website-backups/<date>/`
on the server first, and refuses to overwrite a page that was edited on the server in the
meantime. `live-base.sha256` holds the fingerprints of the live pages the current files
were made from; update it whenever the files are refreshed from the live site.

Tracking tags in every page's `<head>`: Google Analytics G-JCKWC42TF9, Microsoft Clarity
w8uxl1npqn, Meta Pixel 2137139433844948.
