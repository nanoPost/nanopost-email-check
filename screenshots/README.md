# Screenshots

Repeatable screenshots of the plugin screens, taken from the wp-env site.

- `spec.json`: one entry per image (see below).
- `scenes.php`: puts the site in a known state with fake data (`main`, `failed`, `stale`,
  `reset`). Run by the spec through WP-CLI. The `alert` scene changes nothing; it prints the
  webhook text for a sample failure and for the recovery as JSON.
- `alert.html`: a neutral chat card (not any real chat app) that shows the webhook text from
  the `alert` scene. The times on it are made up.
- `shoot.cjs`: logs in once, then for each entry runs its setup, opens the page, hides
  clutter, turns off animations, waits for fonts, crops and saves.

Outputs:

| Folder              | Use                                                                   | Format                   |
|---------------------|-----------------------------------------------------------------------|--------------------------|
| `.wordpress-org/`   | WordPress.org listing (`screenshot-N.png`, deployed to SVN `assets/`) | PNG, 1280 px window      |
| `screenshots/help/` | Help pages on nanopo.st (content column 740 px)                       | WebP q82, 1000 px window |
| `screenshots/help/` | `overview-*`: overview section of the plugin page on nanopo.st        | WebP q82, 1280 px window |

All images are taken at device scale 2. Neither folder is in the release zip.

## Run

```sh
npm run env:start
npm run screenshots                    # all
npm run screenshots -- help-log        # one or more ids
```

Environment: `SHOT_BASE` (default `http://localhost:8888`), `SHOT_USER` (`admin`),
`SHOT_PASSWORD` (`password`). Needs ImageMagick (`convert`) for WebP.

Captions in `readme.txt` under `== Screenshots ==` must match the `wporg-N` entries.

## Spec entry

| Key              | Meaning                                                                    |
|------------------|----------------------------------------------------------------------------|
| `id`             | Name, used to pick shots on the command line                               |
| `out`            | Output file, relative to the repo                                          |
| `path`           | Admin URL path                                                             |
| `html`           | Local page to open instead of `path`, relative to the repo                 |
| `data`           | WP-CLI command that prints JSON, given to the `html` page as `shotData`    |
| `width`          | Window width in CSS px                                                     |
| `setup`          | WP-CLI commands run before the shot (`wp ...`, no spaces inside arguments) |
| `teardown`       | WP-CLI commands run after the shot, also when it fails                     |
| `full`           | Whole page                                                                 |
| `top`            | From the top-left of the page down to `to`, full window width              |
| `from`, `to`     | Crop from the top of `from` to the bottom of `to`, plus `pad`              |
| `hide`           | Selectors to hide (added to the defaults)                                  |
| `replace`        | `{ selector: text }` to put in place of private values                     |
| `mask`           | Selectors to cover with a grey box                                         |
| `caption`, `alt` | Text for the listing or the help page                                      |

Times in the scenes are relative to the moment of the run, so "x ago" texts stay the same,
but dates change from day to day. Use fake data only: this repo is public.

## Live Preview blueprint

`.wordpress-org/blueprints/blueprint.json` is the WordPress Playground blueprint for the
"Live Preview" button on WordPress.org (SVN `assets/blueprints/blueprint.json`). It installs
the plugin from WordPress.org, logs in, and lands on Tools > Email Check with fake results:
the same story as the `failed` scene in `scenes.php`. Keep the two in step.

Test it locally (any free port except 8888):

```sh
npx @wp-playground/cli@3.1.63 server --port=9412 --blueprint=.wordpress-org/blueprints/blueprint.json
```

The blueprint is published by the "Update WordPress.org listing" workflow or by a release.
The button shows only to committers until the preview is set to public in the plugin's
Advanced view on WordPress.org.
