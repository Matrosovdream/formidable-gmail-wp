# Gmail Parser for Formidable (WordPress)

Minimal admin tool to connect Gmail via OAuth and sync email-derived statuses into Formidable entries.

## Install
1. Upload to `wp-content/plugins/frm-gmail-parser/`.
2. From the plugin root, install deps:
   ```bash
   composer install
   composer update
   ```
3. Activate in **WP Admin → Plugins**.

## Configure
- Go to **Formidable → Gmail parser**.
- Add the shown **Redirect URI** to your Google OAuth client.
- Add an account: paste credentials JSON, set **Statuses**, optional **Mask** (e.g. `store+{entry_id}`), optional **Status Field Id**, and set global **Start date**.
- **Connect** → **Test email list** (auto-saves before running).

## Notes
- Masks aren’t used in the Gmail search; they’re applied after fetching messages. `{entry_id}` captures digits only.
- The updater writes the parsed status to the configured Formidable field when an `entry_id` is found.
