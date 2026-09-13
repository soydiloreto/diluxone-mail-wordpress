# Where the listing artwork comes from

The four images wp.org shows next to the plugin are generated from the two SVGs
here, and the six screenshots are captured from a real site rather than mocked
up — a mockup drifts from the plugin the first time a screen changes, and
nobody notices until somebody in the directory does.

    icon.svg    -> icon-256x256.png, icon-128x128.png
    banner.svg  -> banner-1544x500.png, banner-772x250.png

Rendering them needs no local toolchain:

    docker run --rm -u 0:0 -v "$PWD:/w" -w /w alpine sh -c '
      apk add --no-cache rsvg-convert optipng >/dev/null
      rsvg-convert -w 256  -h 256 src/icon.svg   -o icon-256x256.png
      rsvg-convert -w 128  -h 128 src/icon.svg   -o icon-128x128.png
      rsvg-convert -w 1544 -h 500 src/banner.svg -o banner-1544x500.png
      rsvg-convert -w 772  -h 250 src/banner.svg -o banner-772x250.png
      optipng -quiet -o5 icon-*.png banner-*.png'

The icon is the same flat, black-outlined, blue-and-amber shape as DiluxOne
Offload's, so the two read as one family in a plugin list. The banner follows
the same layout: the site on the left, what it produces on the right, one small
patch of real colour in the middle, and everything else in pale blue-grey.

## Screenshots

`screenshots.js` drives a headless Chromium over the wp-env dev site and
captures the plugin's own screens — the WordPress sidebar and admin bar are
stripped, because they say nothing about this plugin and eat a third of the
frame.

It runs in two phases because the settings and status screens show the
transport, and a local Mailpit host tells a reader nothing. Phase A captures
the screens that need log data in the database; phase B captures the
configuration screens after switching the site to a real provider profile.

To regenerate: bring up wp-env, activate the plugin, point it at a Mailpit
container on the same Docker network, send a handful of messages to two or
three demo users, then

    docker run --rm --network host -e PHASE=a \
      -v "$PWD/src:/app" -v "$PWD:/out" -w /app \
      mcr.microsoft.com/playwright:v1.56.0-noble \
      sh -c 'npm i -s playwright@1.56.0 && node screenshots.js'

and again with `PHASE=b` once the site is switched to the provider profile.
The numbering is deliberate and matches readme.txt: the two things no other
plugin does come first.

The whole `.wordpress-org/` directory is excluded from the plugin zip by
`.distignore`; the deploy workflow uploads it to SVN `assets/` on its own. This
`src/` folder is the exception on both counts — it is the source material, not a
listing asset, so `deploy.yml` removes it from its checkout before the upload.