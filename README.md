# bento-pronto

A self-hostable, **single PHP file** version of the [bento-moodle-tools](https://github.com/lasjoh-toho/bento-moodle-tools) PPTX→Bento converter — same four options (Demo, neue Präsentation, Import, Inhalte auf Folien verteilen), but running as real PHP instead of a static page.

**Why this exists:** the static converter is fully offline and free to host (GitHub Pages), but that means it *structurally cannot* run a server-side component — so its "Inhalte auf Folien verteilen" (paste) feature can only embed an image from copied HTML when the source server happens to allow cross-origin reads (CORS), which most sites don't. bento-pronto solves that by running as PHP: the same file that serves the converter also runs a built-in image proxy (`?proxy=<url>`), fetching images server-side — a browser's CORS restriction only ever applied to a *script's own* cross-origin read, never to server-to-server requests — so images always come through here, no configuration, no separate service to run.

## Use it

1. Download the latest `bento-pronto.php` from [Releases](https://github.com/lasjoh-toho/bento-pronto/releases/latest).
2. Drop it on any PHP-capable web server (shared hosting, a VPS, alongside an existing Moodle install — anything that runs PHP with either the `curl` extension or plain `allow_url_fopen`, both common defaults).
3. Open it in a browser. That's it — no database, no dependencies, no build step on your end.

## The hub — a one-person presentation control center (`hub/`)

`template.php` alone is a one-shot converter — open it, get one file out, done.
`hub/` is a small standalone PHP app (no database, plain files) sitting on top
of that idea: it organizes any number of **presentations**, each built from one
or more **modules** — ordinary self-contained `.bento.html` files (saved
straight from the Bento editor, or produced by `template.php`'s converter).

Each module carries its own visibility, mirroring the exact three-state model
[`mod_bento`](https://github.com/lasjoh-toho/moodle-mod_bento)'s own
`bento_decks` table already uses: **hidden** (draft, admin preview only),
**public**, or **moderator-only** (e.g. slides with answers/speaker notes that
only the presenter should see). A presentation then exposes three kinds of
link:

- **one public link** — plays through every *public* module, for the audience;
- **one moderator link** — public *and* moderator-only modules, for whoever is
  presenting;
- **one direct link per module** — for sharing a single slide deck on its own.

### Setup

1. Copy `hub/config.example.php` to `hub/config.php` and set an admin password:
   ```
   php -r "echo password_hash('DEIN-PASSWORT', PASSWORD_DEFAULT), \"\n\";"
   ```
2. Open `hub/index.php`, log in, create a presentation, upload modules.
3. Optionally add a `public_password_hash`/`moderator_password_hash` per
   presentation slug in `hub/config.php` to password-gate that presentation's
   public/moderator links for outside visitors (the admin never needs these —
   logging into the hub already unlocks everything).

`hub/config.php` and `hub/data/` (where presentations/modules actually live)
are gitignored — never commit either.

**Known limitation:** each module is its own fully standalone HTML document,
so reaching the last slide of one module does **not** auto-advance into the
next one the way `mod_bento`'s own Moodle-hosted decks can (that relies on a
present-mode playlist hook that only activates inside a real Moodle URL). The
public/moderator link is itself the navigation between modules for now —
presenters click back to it between decks.

## Security notes on the built-in proxy

An "fetch any URL for me" endpoint is an abuse target if left wide open, so `?proxy=` includes:

- **SSRF protection** — resolves the target hostname and refuses anything that isn't a plain public address (blocks `127.0.0.1`, cloud metadata endpoints like `169.254.169.254`, and internal `10.x`/`192.168.x`/`172.16.x` ranges) — otherwise the proxy could be used to probe *your own server's* internal network from the outside.
- **Content-type enforcement** — only ever returns something if the target actually responds `image/*`; refuses to become a general-purpose URL fetcher.
- **Size cap** (15 MB) and a request timeout.
- **http/https only.**

There's no login system here (unlike the [mod_bento](https://github.com/lasjoh-toho/moodle-mod_bento) Moodle plugin's own copy of this proxy, which requires a logged-in session) — anyone who can reach the page can use the proxy. If you need it gated behind authentication, put it behind your web server's own access control (an `.htaccess` login, a reverse-proxy auth layer, etc.) rather than relying on this file alone.

## Building it yourself

```
npm install --no-audit --no-fund   # only needed by the bento fork build.mjs clones — see below
node build.mjs
```

`build.mjs` clones the current [bento](https://github.com/lasjoh-toho/bento) fork, builds the actual slide editor/present-mode app, extracts the demo deck, and splices both into `template.php` — producing `dist/bento-pronto.php`. The GitHub Action here (`workflow_dispatch`, manual trigger) does exactly this and attaches the result to a new [Release](https://github.com/lasjoh-toho/bento-pronto/releases).

## License

MIT — see [LICENSE](LICENSE).
