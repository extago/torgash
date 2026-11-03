# Backups in Google Drive

The module adds one kind of backup storage, **Google Drive**, to Admin →
System → Backup storages. A storage is a folder in the Drive of the Google
account you connect; a backup plan then keeps its copies there like in any
other storage — the same retention, the same checks, the same restore.

Two things are needed: the shop's own **Google Cloud application** (once per
shop) and a **connected Google account** (per storage).

## 1. The Google Cloud application

Google lets a site into a Drive only on behalf of a registered application.
It takes about ten minutes and costs nothing.

1. Open [console.cloud.google.com](https://console.cloud.google.com) and
   create a project (any name — "My shop backups").
2. **APIs & Services → Library**: find **Google Drive API** and enable it.
3. **Google Auth Platform** (the older consoles call it *OAuth consent
   screen*): **Branding** — the application's name and your e-mail;
   **Audience** — *External*. While the application is in *Testing*, add
   the Google account you will connect under **Test users**.
4. **Data access** (scopes): add
   `https://www.googleapis.com/auth/drive.file`. It is a non-sensitive scope
   — the shop sees only the files it has created itself — so Google asks for
   no verification of the application.
5. **Clients → Create client**: kind *Web application*. Under **Authorised
   redirect URIs** paste the address the module's settings show (it is
   `https://<your shop>/admin/google-drive/callback`).
6. Copy the **Client ID** and the **Client secret** (the secret is shown
   once) into Settings → Google Drive and save.

> **Testing vs. published.** While the application stays in *Testing*,
> Google forgets its keys after seven days and the storage stops working
> until you connect it again. Before relying on it, press **Publish app**
> on the Audience page: with the `drive.file` scope alone no review is
> needed, the button is enough.

## 2. The storage

Admin → System → Backup storages → **New storage**, kind **Google Drive**.
Name it, say which folder under "My Drive" (made when needed; `Backups/Shop`
makes a folder inside a folder), save — and press **Connect a Google
account**. Google asks which account and whether the shop may keep files in
its Drive; you are then brought back, the account's address appears on the
form and a small file is written, read back and deleted to prove the
connection works.

Add the storage to a backup plan (System → Backups) and it takes the plan's
copies from then on. **Disconnect** forgets the key; the backups already in
the Drive stay there.

## What the shop can and cannot see

The connection is granted with the `drive.file` scope: the shop sees the
files and folders **it created** and nothing else in the Drive. So the
folder you name is made by the shop, backups are listed and deleted only
inside it, and a file you drop into that folder by hand is invisible to the
shop. To restore from a backup that was uploaded another way, download it
and restore from the file.

The key Google hands over (a *refresh token*) is kept encrypted with the
shop's `APP_KEY`, beside the storage's other secrets. On another server —
another key — the storage is connected again, by design.

## When it stops working

- *Google no longer accepts the storage's key* — the application was in
  Testing for over a week, or you revoked the shop's access in the Google
  account. Connect again.
- *Google does not know this client ID and secret* — the client was deleted
  in the Cloud Console, or the secret was reset. Paste the current ones.
- *redirect_uri_mismatch* on Google's page — the redirect URI in the Cloud
  Console is not the address the module's settings show. Google compares
  them letter for letter, scheme and trailing slash included.
