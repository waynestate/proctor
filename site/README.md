# PROCTOR front page

A static page: `index.html`, `assets/proctor.css`, `assets/proctor.js`. No build step. Serve the `site/` folder from
anywhere (Citadel, any web server, or `python -m http.server` inside `site/` for a look).

- type: **Young Serif** (display, SIL OFL 1.1, self-hosted in `assets/fonts/`, licence in `OFL-young-serif.txt`) and
  **Caveat** (the pencil and the red pen, SIL OFL 1.1, licence in `OFL-caveat.txt`); Lato from WayneUI for body text;
  the system monospace for commands and report lines.
- base: WayneUI 2.0.8, copied from its static path into `assets/wayne-ui/` (tokens, Lato, the skip link, sr-only),
  then re-papered: ruled exam paper with a red margin line, exam-book cardstock, a Scantron-style sheet in drop-out
  green.
- world: an exam. The hero is the examination book on a desk, with the mark as its seal. Page 1 is the instructions to
  candidates, Part A is the nine checks set as numbered questions with a real report line each, Part B is next term
  (what is coming, with no commands), then the marking, four carbon copies (the report formats), a note from home
  (overrides), the exam halls (CI), "you may turn over your paper" (quick start per OS) and a seating chart of the
  WELD parts.
- magic: the answer sheet marks itself. Scroll through five steps and the pencil fills the bubbles, the red pen
  circles question 3 (`unbounded-constraints`) and writes the fix in the margin, the result is written as a fail,
  then the resit erases the F, fills the P, strikes the fail and the PASS stamp lands with a thunk. On the cover the
  seal turns its head toward the pointer and now and then glances down the rows. Type "cheat" anywhere.
- data: every report line on the page is real output from `bin/proctor` run on the fixtures in `tests/fixtures/apps`
  with `--today=2026-10-28` (trimmed where marked). Refresh them when a message changes.
- marks: `brand/` is PROCTOR's (generated with ChatGPT, never redrawn or given effects; only the whole tile is moved).
  `assets/marks/` holds the other parts' marks, copied from CREED's and WELD's sites; WayneUI uses its own reversed
  mark on green.
- reduced motion (or no JavaScript): the marked paper is handed over finished: every bubble filled, the red circle and
  the fix in the margin, the struck fail, the pass and the stamp, all present at first paint. Nothing moves.
- jokes: exam and invigilator puns. No lyrics, no quotes.

Screenshots are in `screenshots/` (desktop, mobile, reduced motion, a motion preview, and mobile crops per section in
`screenshots/sections/`).

Pencils down.
