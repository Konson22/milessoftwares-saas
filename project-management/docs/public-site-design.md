# Public site design

## Brand

- The colours come from the Miles logo (`public/images/logo-*.png`), sampled from
  `logo-original.png`:
    - navy `#00245a`;
    - azure `#0187fa`.
- **Public site** (`.marketing` in `resources/css/app.css`):
    - white page, navy text;
    - buttons and links in `#0070e0`, the logo azure deepened so white text on it passes AA
      contrast;
    - the logo azure itself only for marks, rules and highlights;
    - navy bands for the footer and the closing call to action.
- **Product** (`resources/js/ui/theme.css`): the same navy for the sidebar and the same blue as
  primary.
- **Headlines:** Plus Jakarta Sans (bold geometric, close to the logo lettering). Body text is
  Inter.
- **Logos:**
    - header: `logo-icon.png` next to the product name;
    - footer: `logo-with-text-light.png` (white lettering) on navy.
- **No shadows anywhere.** `theme.css` unsets every `shadow-*`, `inset-shadow-*`,
  `drop-shadow-*` and `text-shadow-*` utility, and the explicit shadow classes were removed.
  Focus rings stay, for keyboard users.
- **Colour tokens are declared with `@theme inline`.** That lets a scope that redefines
  `--background` and the other colour variables (the public site) take effect. Before this, the
  public site was silently using the product's grey canvas.

## Photographs

The photographs come from [Unsplash](https://unsplash.com) and are free to use under the
[Unsplash License](https://unsplash.com/license). They're stored in `public/images/photos` and
listed in `resources/js/components/marketing/photo.tsx`.

They are stock photographs. They're never presented as screenshots of the product or as a
customer's work, and the hero photo carries a "Photo: Unsplash" label.

| File                      | Source                                                       |
| ------------------------- | ------------------------------------------------------------ |
| `dashboard-laptop.jpg`    | https://images.unsplash.com/photo-1460925895917-afdab827c52f |
| `dashboard-analytics.jpg` | https://images.unsplash.com/photo-1551288049-bebda4e38f71    |
| `team-review.jpg`         | https://images.unsplash.com/photo-1531482615713-2afd69097998 |
| `field-distribution.jpg`  | https://images.unsplash.com/photo-1593113598332-cd288d649433 |
| `team-planning.jpg`       | https://images.unsplash.com/photo-1552664730-d307ca884978    |

The drawn previews (budget, grant record, approval chain) stay as they were, and are marked
"Illustrative. Sample figures".

The images in `public/images/hero` are not used. They show generic ERP, HR and retail
dashboards, not this product.
