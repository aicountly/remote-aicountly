# Evidence for the audit of 14 November 2026

What `../PUBLIC_READINESS_AUDIT.md` measured, as it came out of the tools. Everything was produced on one Linux
machine (four cores, 16 GB); nothing here was taken on a real Windows PC or Mac.

## Pictures

Each pair is the same screen in the same state, before this pass and after it. The desktop pictures are the app's
window drawn in a browser at 960×720 with a stand-in for the app behind it; the web pictures are the built web app
with a stand-in for the server. The grey bracketed label in a desktop picture marks where the app draws the web site;
it is not part of the app.

| Before | After | What to look at |
|---|---|---|
| `desktop-consent-on-remote-tab-before.png` | `desktop-consent-on-remote-tab-after.png` | The request for control on the tab the window opens on. Before, the dialog runs off the bottom of a window that cannot scroll. |
| `desktop-being-controlled-before.png` | `desktop-being-controlled-after.png` | The strip at the top while somebody has the mouse and keyboard. |
| — | `desktop-unattended-banner-after.png` | The amber strip of an unattended connection. |
| `web-request-for-control-before.png` | `web-request-for-control-after.png` | Somebody asks to control your computer. Before, the whole notification is the "1" on the Control button. |
| `web-phone-room-before.png` | `web-phone-room-after.png` | The session room on a phone (390 px wide). |
| `web-phone-dashboard-before.png` | `web-phone-dashboard-after.png` | The dashboard on a phone, which cannot share a screen. |

## Measurements

| File | What it is |
|---|---|
| `desktop-encoder.txt` | Time to convert and encode one frame, for three screen sizes (audit §8.2). |
| `relay-before-the-fixes.txt` | The load test of the signalling relay as it is on `main`, with the six defects it reproduced (§9.3). |
| `relay-after-the-fixes.txt` | The same scenarios against the branch. |
| `server-request-model.txt` | What one session asks of the server, where it would give way first, and what could not be told from the code (§9.1, §9.5, §9.6). Written before the fixes in §9.4: its "small safe fixes" 1–5 and 7 are done. |
| `web-load-before.json`, `web-load-after.json` | Load times of the built web app with 150 ms added to every answer from the server (§8.1). |
| `web-contrast-and-keyboard-before.json`, `…-after.json` | Contrast of every text style, focus rings, tab order, what a screen reader is told (§7.5). |
| `desktop-contrast-before.txt`, `desktop-contrast-after.txt` | Contrast of every text style in the desktop app's window. |

File names inside the reports that begin `relay-load/`, `web-ui/`, `bench/` or `pg/` point into the working area of the
session that produced them, which no longer exists.
