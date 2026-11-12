# Marketing and customer docs

Made 2026-11-11 from the demo workspace (`php artisan projects:demo <slug>`, organization "Hope Water Trust").

| What | Here | Live, editable version |
| --- | --- | --- |
| User guide for customers | `user-guide.md` | https://claude.ai/artifact/GkkpofyfoBZi8xD8jpQjhV |
| Product presentation (16 slides) | `presentation/` | https://claude.ai/artifact/DmSuWUAcKeSgS27rvMuCWL |
| Pitch deck (12 slides) | `pitch-deck/` | https://claude.ai/artifact/RCTRKJg4u8wpUP8weMvHf9 |
| Product presentation, PowerPoint | `Projects-Product-Presentation.pptx` | — |
| Pitch deck, PowerPoint | `Projects-Pitch-Deck.pptx` | — |
| Screenshots (1440×900 at 1.5×) | `screenshots/` | — |

The two `.pptx` files are built by `build-pptx.js` (pptxgenjs) from `screenshots/`, with speaker notes, sections and editable layouts. To rebuild: `npm install pptxgenjs` somewhere, then `NODE_PATH=<that>/node_modules SKILL_DIR=<pptx skill dir> node build-pptx.js docs/marketing` (the skill's `apply_theme.js` writes the colour theme).

The live links are the source of truth: edit there, and export to Word, PDF or PowerPoint from each page's Share → Export menu. The files here are a snapshot.

- `presentation/` and `pitch-deck/` hold the slide sources (`deck.json` plus one HTML file per slide). Their `<img src="/_blob/…">` paths only resolve inside the hosted deck; the same pictures are in `screenshots/`.
- The pitch deck has bracketed placeholders (`[$__]`, `[__]`) for prices, market size, pilot numbers and the ask. Fill them before sharing.
- `screenshots/25-donor.png` is a "Not Found" page (the demo organization has no donor workspace) and can be retaken or deleted.
