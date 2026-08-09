# bento-pronto

A self-hostable, **single PHP file** version of the [bento-moodle-tools](https://github.com/lasjoh-toho/bento-moodle-tools) PPTX→Bento converter — same four options (Demo, neue Präsentation, Import, Inhalte auf Folien verteilen), but running as real PHP instead of a static page.

**Why this exists:** the static converter is fully offline and free to host (GitHub Pages), but that means it *structurally cannot* run a server-side component — so its "Inhalte auf Folien verteilen" (paste) feature can only embed an image from copied HTML when the source server happens to allow cross-origin reads (CORS), which most sites don't. bento-pronto solves that by running as PHP: the same file that serves the converter also runs a built-in image proxy (`?proxy=<url>`), fetching images server-side — a browser's CORS restriction only ever applied to a *script's own* cross-origin read, never to server-to-server requests — so images always come through here, no configuration, no separate service to run.

## Use it

1. Download the latest `bento-pronto.php` from [Releases](https://github.com/lasjoh-toho/bento-pronto/releases/latest).
2. Drop it on any PHP-capable web server (shared hosting, a VPS, alongside an existing Moodle install — anything that runs PHP with either the `curl` extension or plain `allow_url_fopen`, both common defaults).
3. Open it in a browser. That's it — no database, no dependencies, no build step on your end.

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
