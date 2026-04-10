# Manual Test Plan (5 steps)

1. Visit `/qr-redirect/admin/login.php` and log in with the current admin credentials.
2. In `/qr-redirect/admin/index.php`, add a counselor or edit an existing one, then confirm duplicate codes are rejected and the QR + redirect link appear in the table.
3. Use the clear-test-data action and verify all counselor `unique` and `raw` counters return to `0`.
4. Visit `/qr-redirect/r.php?c=LVS` twice from the same browser session and verify `LVS` shows `raw = 2` and `unique = 1`.
5. Disable `LVS` in the admin, revisit `/qr-redirect/r.php?c=LVS`, and confirm it no longer redirects or increments counts; re-enable it and verify the link works again.
