# MT Content Assistant

WordPress plugin for mt-law.co.il. Turns a court ruling or a press article (PDF or public link) into a **draft** post with its ACF fields filled, using the Claude API. A chat on each draft handles corrections. Publishing is always manual.

## Setup

1. Install and activate the plugin. Requires ACF and the Tomashin theme 1.61 or later.
2. In `wp-config.php`:
   ```php
   define( 'MTCA_CLAUDE_API_KEY', '...' ); // the firm's own key; never commit it
   ```
3. **עוזר תוכן AI > הגדרות**: firm style guide, the fixed closing line for success stories, monthly token cap, authorised users. The same screen shows the field check and the image pool coverage.
4. Add approved mood images to the pool from the media library (field "כלי ה-AI" on an image).

Optional constants: `MTCA_CLAUDE_MODEL`, `MTCA_API_VERSION`, `MTCA_MAX_FILES` (10), `MTCA_MAX_PDF_BYTES` (20 MB).

## How it works

| Part | File |
| --- | --- |
| Content types and site lists | `includes/class-mtca-types.php` |
| Prompts, schema, API calls | `includes/class-mtca-claude.php` |
| Jobs: queue, draft, chat, warnings | `includes/class-mtca-jobs.php` |
| Draft post + ACF fields | `includes/class-mtca-writer.php` |
| Image pool | `includes/class-mtca-images.php` |
| Private source files | `includes/class-mtca-storage.php` |
| Field check, `athor` -> `author` merge | `includes/class-mtca-check.php` |
| REST API (`mtca/v1`) | `includes/class-mtca-rest.php` |
| Admin screens | `includes/class-mtca-admin.php`, `assets/` |

- One job per source. Several PDFs can be uploaded at once; drafts are generated one at a time, driven by the open admin screen.
- Source PDFs are kept in `uploads/mtca-private/` under random names, not in the media library. On nginx, deny direct access to that directory in the server config (`.htaccess` only covers Apache).
- Rules enforced in the prompt and re-checked in code: no client names or identifying details, facts only with a quote from the source, gag order / in camera / minor flags must be acknowledged, press articles are summarised and checked for copied passages.
- Posts created by the plugin are marked "טיוטת AI" until someone publishes them; the publisher and time are recorded.

## Status

0.1.0. Tested with stubs (request building, parsing, cleaning, warnings) and a mocked admin screen. **Not yet run on a WordPress site or against the live API.** Test on staging first.
