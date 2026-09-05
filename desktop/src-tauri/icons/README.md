# Icons

**These are placeholders, not AICOUNTLY brand assets.**

They are flat `#25b003` squares — the brand green from
`web/src/styles/tokens.css` — and nothing else. No AICOUNTLY mark was drawn
for them, for the same reason `docs/BRANDING.md` gives for the web app: the
real logo is not in this repository, and inventing one would be inventing it.

The installer and the tray need *some* icon to build, so these exist. Replace
every one of them with the real artwork before a build is given to a customer:

| File | Used by |
|---|---|
| `icon.ico` | the installer, the executable, the Windows taskbar |
| `32x32.png`, `128x128.png`, `128x128@2x.png` | the Tauri bundle |
| `tray.png` | the system tray, at 16–32px |

A release built with these will look unfinished. That is deliberate — it is
more honest than a mark somebody invented, and it is impossible to miss.
