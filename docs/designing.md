# Designing pages

A page is not code here. It is a settings object, checked against what the site
actually has before any of it is written.

The loop is the same for both builders:

```
ask the site what exists  →  compose  →  dry run  →  fix what it names  →  apply
```

The dry run is the part that matters. A builder drops a setting it does not
recognise without saying so, and the element renders blank. Asking first, and
being told which keys are wrong before anything lands, is the whole design.

## Elementor

### Ask first

```bash
niranzwp run niranzwp/elementor-widgets                          # what widgets exist
niranzwp run niranzwp/elementor-widget --input '{"name":"heading"}'
niranzwp run niranzwp/elementor-widget --input '{"name":"common"}'
```

`common` is the Advanced tab — 264 controls that every widget shares: margin,
padding, ID, class, z-index, background, border, shadow, transform, motion
effects, entrance animation, custom attributes, custom CSS. A widget's own
catalogue leaves them out and says so, because listing them on all 253 widgets
would be the same 40 KB over and over.

Anything a theme or add-on adds to that tab appears too. The catalogue is read
from the live registry, not from a list written once.

### Write

```json
{
  "id": 12,
  "mode": "replace-page",
  "dry_run": true,
  "elements": [{
    "elType": "container",
    "settings": { "padding": {"unit":"px","top":"90","right":"24","bottom":"90","left":"24","isLinked":false} },
    "elements": [{
      "elType": "widget",
      "widgetType": "heading",
      "settings": {
        "title": "Advanced tab, every panel",
        "typography_typography": "custom",
        "typography_font_size": {"unit":"px","size":46}
      }
    }]
  }]
}
```

The reply names anything it did not recognise:

```json
{"unknown_settings": ["heading._transform_rotateZ"]}
```

Look that key up, fix it, write again with `dry_run: false`. Ids are assigned
for you — never invent them.

Modes: `append`, `prepend`, `after`, `before`, `replace-element`,
`replace-page`, `delete`. The middle four act on `target`.

Two things worth knowing. Typography needs `typography_typography: "custom"`
before its other keys do anything. And a container names its spacing `padding`,
while a widget names the same thing `_padding`.

### Beyond one page

| Ability | Reaches |
| --- | --- |
| `elementor-settings-read/write` `scope: page` | Page layout, page background, page-level custom CSS |
| `elementor-settings-read/write` `scope: site` | The kit: global colours, global fonts, site-wide defaults |
| `elementor-templates`, `elementor-template-write` | Headers, footers, single and archive layouts, popups, and the conditions that place them |

Bind a widget to a global colour rather than hardcoding it, and one change to
the kit moves the whole site:

```json
{"__globals__": {"title_color": "globals/colors?id=primary"}}
```

`template: "elementor_canvas"` in a page's settings is what removes the theme's
header and footer from that page.

## Gutenberg

A block has no id, so it is addressed by where it sits.

```
0      core/heading
1      core/group
 1.0   core/paragraph
 1.1   core/paragraph
```

`block-read` reports that path for every block. `block-find` locates blocks by
type, by an attribute, or by the text they show.

```bash
niranzwp run niranzwp/block-find --input '{"id":12,"name":"core/heading"}'
niranzwp run niranzwp/block-update --yes \
  --input '{"id":12,"path":"1.1","attributes":{"align":"center"},"dry_run":false}'
```

`block-update` merges into what the block already has. `block-move` takes a
block before, after or inside another. `block-write` has the same targeted
modes as Elementor, plus `replace` for the whole body.

Attributes are checked against the registry and a block carrying one its type
does not declare is **refused**, not saved. `expected_sha256` from `block-read`
makes an edit refuse to land if the post moved underneath it.

## Which builder

| | Elementor | Gutenberg |
| --- | --- | --- |
| Ready-made widgets | 253 | 116 core, half of them for post and comment templates |
| A price table, a counter, an icon box | one widget | assembled from group, heading and paragraph |
| Attribute checking | reports what it did not recognise | refuses the write |
| Concurrent-edit guard | — | `expected_sha256` |

Both write real pages. Elementor is further along because it ships more parts.

## Keeping a long page consistent

Decide the values once and reuse them, rather than picking them per section:

```
ink #070B14   panel #0E1524   accent #E9B44C
section padding 110/24   h1 60/700/-1.6   body 16/28
```

Put colours in the kit and bind to them. Then a change is one call, not fifty.
