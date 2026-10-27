# PROCTOR brand files

**The mark is the proctor's eye:** two lids with a square gold pupil. It watches the exam and doesn't help.

## Colours
| Role | Colour |
|---|---|
| Lids, tile | Wayne State green `#0C5449` |
| Pupil (the only gold) | Wayne State gold `#FFCC33` |
| Lids on the tile or on dark backgrounds | White `#FFFFFF` |
| Site accent only, never in the mark | Crimson `#9B1C2E` (the red pen) |

Gold never sits behind white text.

## Files
| File | Use |
|---|---|
| `proctor-mark.svg` | The mark on light backgrounds: green lids, gold pupil |
| `proctor-mark-white.svg` | The mark on dark or green backgrounds: white lids, gold pupil |
| `proctor-mark-mono.svg` | One colour (`currentColor`), for print and single-ink use |
| `proctor-tile.svg` | The app icon: a green rounded tile with the white eye |
| `proctor-favicon.svg` | The tile with the eye enlarged, for 32 and 64 px |
| `proctor-favicon-16.svg` | Hand-hinted on the 16 px grid. One unit is one pixel |
| `proctor-avatar-512.png` | The GitLab project avatar and the Open Graph image base |
| `proctor-mark-1024.png`, `proctor-mark-white-1024.png` | Raster marks for slides and documents |
| `favicon-16.png`, `favicon-32.png`, `favicon-64.png`, `favicon.ico` | Browser favicons; the `.ico` holds all three |

## Geometry
The SVGs are drawn from exact geometry, not traced, so the shapes are symmetric to the hundredth of a unit.
- **The top lid:** the band between an outer circle, centre (256, 340) and radius 212, and an inner circle, centre
  (256, 346) and radius 158, on a 512 grid. Both ends are cut square to the band at 44°, so they're blunt and the
  gaps at the corners are open.
- **The bottom lid:** it mirrors the top about y 256.
- **The pupil:** a 74-unit square with a 4-unit corner radius.

## Rendering
The PNGs were rendered with Inkscape 1.4, for example
`inkscape proctor-tile.svg --export-type=png --export-filename=proctor-avatar-512.png -w 512 -h 512`. The `.ico`
was built with ImageMagick 7: `magick favicon-16.png favicon-32.png favicon-64.png favicon.ico`.
