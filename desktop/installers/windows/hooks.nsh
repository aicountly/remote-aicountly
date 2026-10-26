; AICOUNTLY Remote — NSIS installer hooks.
;
; Tauri generates the installer; these macros are the four points it hands
; control back. Everything here is the part Tauri does not know about: the
; Windows service, the machine-wide data directory, and making sure an
; uninstall leaves nothing privileged behind.
;
; What this deliberately does NOT do
; ---------------------------------
;   * It does not touch UAC, SmartScreen, Windows Defender or any other
;     security setting, and it never asks the person installing to. A product
;     that needs those weakened to work is a product that should be fixed.
;   * It bundles no credential of any kind. The machine gets its identity by
;     generating a keypair after installation and enrolling with an AICOUNTLY
;     account — see docs/desktop/DEVICE_ENROLMENT.md.
;   * It registers no auto-start for the *service* beyond the ordinary delayed
;     automatic start, and no auto-start for the tray application beyond a
;     per-machine Run entry the person can remove.
;
; Everything below runs elevated: the installer is perMachine, which is what
; installing a service requires.

!include "LogicLib.nsh"

; ---------------------------------------------------------------------------
; Where machine-wide state lives. The device key (DPAPI-protected) and the
; configuration both live here, so the LocalSystem service and the signed-in
; user's tray application read the same files.
; ---------------------------------------------------------------------------
!define AICOUNTLY_DATA_DIR "$COMMONPROGRAMDATA\AICOUNTLY\Remote"
!define AICOUNTLY_SERVICE_NAME "AicountlyRemoteService"
!define AICOUNTLY_SERVICE_EXE "AicountlyRemoteService.exe"
; The executable Tauri actually installs, which is the `[[bin]]` name from
; `src-tauri/Cargo.toml` — NOT the product name. Tauri renames the main
; binary only when `mainBinaryName` is set in tauri.conf.json, and it is not;
; the bundler installs, shortcuts and registers `AicountlyRemote.exe`. This
; said "AICOUNTLY Remote.exe", so the sign-in Run entry below pointed at a
; path that does not exist and the tray application never started with
; Windows.
!define AICOUNTLY_APP_EXE "AicountlyRemote.exe"
!define AICOUNTLY_RUN_KEY "Software\Microsoft\Windows\CurrentVersion\Run"

; For the icon-cache refresh at the end of NSIS_HOOK_POSTINSTALL — see the
; comment there for why this exists and what it does not fix.
;
; Guarded rather than a plain !define: Tauri's own installer.nsi unconditionally
; !includes FileAssociation.nsh ahead of this file (to support the unrelated
; fileAssociations config option, which this app does not use), and that header
; already defines SHCNE_ASSOCCHANGED with this same value for its own
; SHChangeNotify call. A plain !define here collides with it —
; `!define: "SHCNE_ASSOCCHANGED" already defined!` — and fails the whole
; build. /ifndef is the same guard utils.nsh (also Tauri's) uses for exactly
; this reason.
!define /ifndef SHCNE_ASSOCCHANGED 0x08000000
!define /ifndef SHCNF_IDLIST       0x0000

!macro NSIS_HOOK_PREINSTALL
  ; An upgrade must not write over a running service's binary. Stopping it
  ; first turns "file in use, reboot required" into an ordinary install.
  ;
  ; Waits for the service to report STOPPED rather than sleeping a fixed
  ; moment: `sc stop` only asks, and a service that takes longer than the
  ; sleep still has its binary open when the files are copied. A machine that
  ; has no service yet (a first install) answers `sc query` with 1060 and
  ; skips all of this.
  DetailPrint "Stopping the AICOUNTLY Remote service if it is running..."
  Push $1
  nsExec::ExecToLog 'sc.exe query "${AICOUNTLY_SERVICE_NAME}"'
  Pop $0
  ${If} $0 == 0
    nsExec::ExecToLog 'sc.exe stop "${AICOUNTLY_SERVICE_NAME}"'
    Pop $0
    StrCpy $1 0
    ${Do}
      nsExec::ExecToLog 'cmd.exe /c sc.exe query "${AICOUNTLY_SERVICE_NAME}" | findstr /C:"STOPPED"'
      Pop $0
      ${IfThen} $0 == 0 ${|} ${ExitDo} ${|}
      IntOp $1 $1 + 1
      ${IfThen} $1 >= 20 ${|} ${ExitDo} ${|}
      Sleep 500
    ${Loop}
  ${EndIf}
  Pop $1
!macroend

!macro NSIS_HOOK_POSTINSTALL
  ; ---------------------------------------------------------------------
  ; The machine-wide data directory.
  ;
  ; The ACL is reset and rebuilt rather than inherited, so whatever
  ; %ProgramData% happens to hand down does not decide who can read a device
  ; identity:
  ;
  ;   SYSTEM          full control   the service, as LocalSystem
  ;   Administrators  full control   installation and support
  ;   Users           modify         the tray application, as whoever signed in
  ;
  ; "Users: modify" and not "Users: read", because every file in here is
  ; WRITTEN by the tray application running as an ordinary, non-elevated
  ; person: `config.json` when they change a setting, `enrolment.json` and
  ; `device-signing-key.key` when they register the computer
  ; (docs/desktop/DEVICE_ENROLMENT.md — enrolling is a signed-in person's act,
  ; not an administrator's). With read-only access every one of those writes
  ; fails with "Access is denied", which is a product that installs and then
  ; cannot be registered.
  ;
  ; What that costs, stated plainly: another account on this machine can
  ; delete or replace this machine's enrolment. It cannot gain access with it
  ; — a key only authenticates against the device row the server already
  ; issued for it, so a replaced key authenticates as nothing — and anyone who
  ; can sign in here can register the machine through the application's own
  ; enrolment screen anyway. The directory is still not world-readable, and
  ; DPAPI machine scope plus additional entropy is what protects the key's
  ; contents rather than the ACL.
  ; ---------------------------------------------------------------------
  DetailPrint "Preparing the AICOUNTLY Remote data directory..."
  CreateDirectory "${AICOUNTLY_DATA_DIR}"

  nsExec::ExecToLog 'icacls "${AICOUNTLY_DATA_DIR}" /inheritance:r'
  Pop $0
  nsExec::ExecToLog 'icacls "${AICOUNTLY_DATA_DIR}" /grant:r "*S-1-5-18":(OI)(CI)F'
  Pop $0
  nsExec::ExecToLog 'icacls "${AICOUNTLY_DATA_DIR}" /grant:r "*S-1-5-32-544":(OI)(CI)F'
  Pop $0
  nsExec::ExecToLog 'icacls "${AICOUNTLY_DATA_DIR}" /grant:r "*S-1-5-32-545":(OI)(CI)M'
  Pop $0

  ; ---------------------------------------------------------------------
  ; The service.
  ;
  ; Registered by the binary itself rather than by an `sc create` line here,
  ; so the service's own definition of how it should be registered — account,
  ; start type, description, recovery — lives in one place and is the same on
  ; an installer install as on a support engineer's manual one.
  ; ---------------------------------------------------------------------
  ${If} ${FileExists} "$INSTDIR\${AICOUNTLY_SERVICE_EXE}"
    DetailPrint "Registering the AICOUNTLY Remote service..."
    nsExec::ExecToLog '"$INSTDIR\${AICOUNTLY_SERVICE_EXE}" --uninstall'
    Pop $0

    ; A reinstall or upgrade deletes the previous registration first. If
    ; anything still holds a handle on it (Services console, an MMC snap-in)
    ; Windows only marks it for deletion, and creating it again fails with
    ; error 1072 until that handle closes. Retry for a few seconds instead of
    ; finishing an install with no service registered at all.
    Push $1
    StrCpy $1 0
    ${Do}
      nsExec::ExecToLog '"$INSTDIR\${AICOUNTLY_SERVICE_EXE}" --install'
      Pop $0
      ${IfThen} $0 == 0 ${|} ${ExitDo} ${|}
      IntOp $1 $1 + 1
      ${IfThen} $1 >= 5 ${|} ${ExitDo} ${|}
      Sleep 2000
    ${Loop}
    Pop $1

    ${If} $0 != 0
      ; Not fatal. The machine still works for attended sessions started from
      ; the tray application; what it loses is being reachable before somebody
      ; signs in. Saying so beats failing the whole installation.
      DetailPrint "The AICOUNTLY Remote service could not be registered (code $0)."
      DetailPrint "Attended sessions will still work. See docs/desktop/WINDOWS_AGENT.md."
    ${EndIf}
  ${Else}
    DetailPrint "The AICOUNTLY Remote service was not included in this build."
  ${EndIf}

  ; ---------------------------------------------------------------------
  ; The tray application at sign-in.
  ;
  ; A Run entry rather than the service launching it: Windows removed
  ; interactive services, and a service pushing a window into somebody's
  ; session is the thing that was removed. This is per-machine so it applies
  ; to whoever signs in, and it is an ordinary Run value that a person can
  ; see in Task Manager's Startup tab and turn off.
  ; ---------------------------------------------------------------------
  WriteRegStr HKLM "${AICOUNTLY_RUN_KEY}" "AICOUNTLY Remote" '"$INSTDIR\${AICOUNTLY_APP_EXE}" --background'

  ; ---------------------------------------------------------------------
  ; Tell Explorer the shortcuts and registry entries it just read are worth
  ; re-reading now, not next reboot.
  ;
  ; Every icon reference Tauri's own NSIS template writes — DisplayIcon in
  ; the uninstall key, the desktop and Start Menu shortcuts — names
  ; "${AICOUNTLY_APP_EXE}" with no baked-in icon index, so Windows is meant
  ; to read the icon out of that binary each time it draws one. In practice
  ; Explorer caches the bitmap per file path and does not always notice a
  ; rebuilt binary at the *same* path has a different one — this nudges it
  ; to. It reliably fixes a first install. It is not guaranteed to fix an
  ; upgrade that overwrote an already-cached path: that is a documented
  ; Explorer limitation (see e.g. tauri-apps/tauri#8453), not something an
  ; installer can force from here. A machine stuck on the old icon needs its
  ; icon cache cleared by hand — see docs/desktop/WINDOWS_AGENT.md.
  ; ---------------------------------------------------------------------
  System::Call 'shell32.dll::SHChangeNotify(i ${SHCNE_ASSOCCHANGED}, i ${SHCNF_IDLIST}, i 0, i 0)'
!macroend

!macro NSIS_HOOK_PREUNINSTALL
  ; Stop and deregister before the files go. A service whose binary has been
  ; deleted but which is still registered is the leftover an administrator
  ; finds months later, and it survives a reboot.
  DetailPrint "Removing the AICOUNTLY Remote service..."
  ${If} ${FileExists} "$INSTDIR\${AICOUNTLY_SERVICE_EXE}"
    nsExec::ExecToLog '"$INSTDIR\${AICOUNTLY_SERVICE_EXE}" --uninstall'
    Pop $0
  ${EndIf}

  ; Belt and braces, in case the binary is already gone or refused.
  nsExec::ExecToLog 'sc.exe stop "${AICOUNTLY_SERVICE_NAME}"'
  Pop $0
  Sleep 1000
  nsExec::ExecToLog 'sc.exe delete "${AICOUNTLY_SERVICE_NAME}"'
  Pop $0

  DeleteRegValue HKLM "${AICOUNTLY_RUN_KEY}" "AICOUNTLY Remote"
!macroend

!macro NSIS_HOOK_POSTUNINSTALL
  ; ---------------------------------------------------------------------
  ; Enrolment data on uninstall — a deliberate decision, documented here and
  ; in docs/desktop/DEVICE_ENROLMENT.md.
  ;
  ; The device key and the machine's configuration are REMOVED. Uninstalling
  ; a remote-support agent is somebody saying "this machine should no longer
  ; be reachable", and leaving a usable device identity behind so that a
  ; reinstall silently restores unattended access would be the opposite of
  ; what they asked for.
  ;
  ; The cost is that reinstalling means enrolling again, which takes an
  ; AICOUNTLY sign-in. That is the right way round.
  ;
  ; The device's *row* is not deleted by this: the server is the authority on
  ; that, and an administrator removes it from the Computers page. What this
  ; guarantees is that the machine can no longer prove possession of the key,
  ; so the row cannot be used from here whether or not anybody removes it.
  ; ---------------------------------------------------------------------
  DetailPrint "Removing this computer's AICOUNTLY Remote identity..."
  ; `device-signing-key.key` is the DPAPI blob — the name comes from
  ; `remote_security::DEVICE_KEY_ENTRY` and the `.key` suffix the Windows
  ; secure-storage provider appends.
  Delete "${AICOUNTLY_DATA_DIR}\device-signing-key.key"
  ; The temporary file the secure-storage provider writes before renaming it
  ; over the key (`path.with_extension("key.new")`). A crash or power loss
  ; mid-write leaves it behind, and it is a second copy of the DPAPI blob.
  Delete "${AICOUNTLY_DATA_DIR}\device-signing-key.key.new"
  Delete "${AICOUNTLY_DATA_DIR}\config.json"
  Delete "${AICOUNTLY_DATA_DIR}\config.json.new"
  ; The enrolment record — device uuid, company, key fingerprint; never the
  ; key itself — persisted so a restart does not forget it. Written by
  ; `remote_security::EnrolmentRecord::save`; same reasoning as the two files
  ; above, and the temporary file its atomic write can leave behind if the
  ; machine lost power mid-write.
  Delete "${AICOUNTLY_DATA_DIR}\enrolment.json"
  Delete "${AICOUNTLY_DATA_DIR}\enrolment.json.new"
  RMDir "${AICOUNTLY_DATA_DIR}"
  RMDir "$COMMONPROGRAMDATA\AICOUNTLY"
!macroend
