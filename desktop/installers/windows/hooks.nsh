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
!define AICOUNTLY_APP_EXE "AICOUNTLY Remote.exe"
!define AICOUNTLY_RUN_KEY "Software\Microsoft\Windows\CurrentVersion\Run"

!macro NSIS_HOOK_PREINSTALL
  ; An upgrade must not write over a running service's binary. Stopping it
  ; first turns "file in use, reboot required" into an ordinary install.
  DetailPrint "Stopping the AICOUNTLY Remote service if it is running..."
  nsExec::ExecToLog 'sc.exe stop "${AICOUNTLY_SERVICE_NAME}"'
  Pop $0
  Sleep 1500
!macroend

!macro NSIS_HOOK_POSTINSTALL
  ; ---------------------------------------------------------------------
  ; The machine-wide data directory.
  ;
  ; The ACL is reset and rebuilt rather than inherited: this directory holds
  ; the DPAPI blob containing the device private key, and a directory that
  ; inherited "Users: Modify" from somewhere would be a directory any account
  ; on the machine could replace a device identity in.
  ;
  ;   SYSTEM          full control   the service
  ;   Administrators  full control   installation and support
  ;   Users           read           the tray application reads the config
  ;
  ; DPAPI machine scope plus additional entropy is what protects the key's
  ; contents; this is what protects the file itself.
  ; ---------------------------------------------------------------------
  DetailPrint "Preparing the AICOUNTLY Remote data directory..."
  CreateDirectory "${AICOUNTLY_DATA_DIR}"

  nsExec::ExecToLog 'icacls "${AICOUNTLY_DATA_DIR}" /inheritance:r'
  Pop $0
  nsExec::ExecToLog 'icacls "${AICOUNTLY_DATA_DIR}" /grant:r "*S-1-5-18":(OI)(CI)F'
  Pop $0
  nsExec::ExecToLog 'icacls "${AICOUNTLY_DATA_DIR}" /grant:r "*S-1-5-32-544":(OI)(CI)F'
  Pop $0
  nsExec::ExecToLog 'icacls "${AICOUNTLY_DATA_DIR}" /grant:r "*S-1-5-32-545":(OI)(CI)R'
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
    nsExec::ExecToLog '"$INSTDIR\${AICOUNTLY_SERVICE_EXE}" --install'
    Pop $0

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
  Delete "${AICOUNTLY_DATA_DIR}\config.json"
  Delete "${AICOUNTLY_DATA_DIR}\config.json.new"
  RMDir "${AICOUNTLY_DATA_DIR}"
  RMDir "$COMMONPROGRAMDATA\AICOUNTLY"
!macroend
