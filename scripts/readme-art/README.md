# README art kit

Generates everything under `docs/readme/` from PROCTOR's own front page (`site/`) and brand files
(`site/brand/`): the hero and marking screenshots, a section divider cut from the real ruled paper,
the animated report SVG (nine real report lines, read from the page at build time), the brand board,
the badges and the cast strip. Every image is a crop, resize or local render of something the page
already shows. The proctor never draws anything it did not sit the exam on.

## Run

The same three commands in either shell:

| PowerShell | sh (macOS, Linux) |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The build is deterministic: same inputs, same images. It opens the page as a `file://` URL, so no
server is needed, and it refuses to ship any image whose text mentions an internal host.
