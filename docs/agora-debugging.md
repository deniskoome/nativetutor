# Agora Session Debugging Checklist

When the live session page still shows **“Please Login First”** or stays stuck on **“Connecting to the live session…”**, collect the following details so we can pinpoint the failure:

## 1. Browser Console Output
* Open the live session page, press <kbd>F12</kbd> (or <kbd>Cmd</kbd>+<kbd>Option</kbd>+<kbd>I</kbd> on macOS), and switch to the **Console** tab.
* Refresh the page and copy every log line that appears **after** the refresh, especially:
  * Errors thrown by `rtm.login` or `client.join`.
  * Warnings about invalid or expired tokens.
  * Any stack traces related to `resources/js/parts/agora/message.js` or `resources/js/parts/agora/stream.js`.

## 2. Network Tab Capture
* In the same developer tools window, switch to the **Network** tab and refresh.
* Filter by “Fetch/XHR” and locate the requests to:
  * `/panel/sessions/{session_id}/agora/token`
  * `/panel/sessions/{session_id}/agora/rtc-token`
* Export or screenshot the response payloads to confirm the `rtcToken`, `rtmToken`, and `rtcUid` values being returned.

## 3. Server Logs
* Tail the Laravel log (`storage/logs/laravel.log`) while reproducing the issue and capture any stack traces or warnings emitted when the tokens are requested.
* Note the exact timestamp and authenticated user ID used during the failing attempt.

## 4. Environment Verification
* Confirm the Agora App ID and App Certificate configured in the admin panel match the credentials from the Agora dashboard.
* Check that the server’s clock is synchronized (token generation is time-sensitive).

Sharing the above information allows us to see whether the failure happens during token generation, transport, or within the client-side join/login calls.
