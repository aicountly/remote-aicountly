# Icons

The real AICOUNTLY Remote application icon, not a placeholder.

`source.png` (1254×1254, provided directly rather than drawn here) is the
master. Every other file is generated from it with the Tauri CLI's own icon
command, run from `desktop/`:

```
npx tauri icon src-tauri/icons/source.png -o <some staging directory>
```

That command also produces macOS, iOS, Android and Windows-Store assets this
project does not use (Tauri targets Windows only — see `tauri.conf.json`'s
`bundle.targets`); only the five files below were copied out of its output.
Regenerate this way — rather than by hand — if `source.png` is ever replaced,
so every size stays a clean resample of the same master rather than a resize
of a resize.

| File | Used by |
|---|---|
| `icon.ico` | the installer, the executable, the Windows taskbar |
| `32x32.png`, `128x128.png`, `128x128@2x.png` | the Tauri bundle |
| `tray.png` | the system tray. A copy of `32x32.png` — the README this replaced put the tray between 16 and 32px, and 32 is what Tauri's own generator produces at that end of the range. |
