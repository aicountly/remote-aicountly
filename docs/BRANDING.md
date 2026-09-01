# Brand assets

## The AICOUNTLY logo is not in this repository

Remote must use the real AICOUNTLY logo, and must not redraw, distort or invent
a replacement. This repository ships no brand assets, so no logo artwork was
created for it — drawing one would be inventing it.

**To install the real logo:**

```
web/public/brand/aicountly-logo.svg
```

`AicountlyLogo` loads that path and it appears in the header, the sign-in
screen and the guest join page. Until it exists, the component falls back to the
plain wordmark "AICOUNTLY" set in the interface typeface — a typographic
fallback, not a mark. The fallback is deliberate: a missing asset must not leave
a broken-image icon in the header of every screen.

An SVG around 22px tall in a light-background lockup is what the layout expects.

## The Remote product mark

This one *is* ours: a small screen outline with a signal arc, drawn in
`RemoteMark` inside the same file. It carries no text, uses `currentColor`, and
reads at 16–22px. It appears beside — never instead of — the AICOUNTLY logo.

## Colour

The single brand colour is AICOUNTLY green:

```
#25b003   --aicountly-green          primary actions, active navigation
#1d8d03   --aicountly-green-dark     hover
#166b02   --aicountly-green-darker   text on a soft green ground
#edf9e9   --aicountly-green-soft     selected surfaces, recommended options
```

Everything else is a neutral or a semantic colour. All of it lives in
`web/src/styles/tokens.css`; a component that reaches for a raw hex value is a
component that will drift the first time the brand is adjusted.

**Green is reserved for a session that is genuinely running.** Waiting is
informational blue, paused and reconnecting are amber, failed is red, and
completed is neutral. Using green for every state would make a list of thirty
sessions unreadable — which is the point of a status system.

## The session room is dark

The room (`web/src/styles/room.css`) uses a dark palette. The shared screen is
the content, and a bright interface around it competes with the thing the
viewer is trying to read. Every control in the room is quieter than the video.

## Typography

Inter where available, falling back to the system UI stack. No decorative
faces, no display weights above 650.

## Icons

[Lucide](https://lucide.dev) — clean line icons at a consistent stroke width.
No emoji as production icons. Every toolbar control renders its label
alongside the icon rather than relying on a tooltip.
