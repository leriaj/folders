# Storage

Storage is a PHP and MariaDB app for XAMPP. Accounts, usernames, friendships,
folder permissions, reset-token hashes, and activity reports live in MariaDB.
Uploaded files are saved in `private_uploads` and are served through the
permission-checked `download.php` endpoint.

## Set up with XAMPP

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin/`, select **Import**, and import
   `schema.sql`. It creates the `storage_portal` database and application tables.
3. Open a command prompt in `C:\xampp\htdocs\folders` and run
   `C:\xampp\php\php.exe migrate.php`. The migration adds missing account
   columns to an existing Storage database and assigns unique usernames to
   accounts created before usernames were introduced.
4. Open `http://localhost/folders/`. Do not open the page as a `file://` URL.
5. Database connection settings are at the top of `config.php`; the defaults
   match a standard local XAMPP installation (`root` with an empty password).

## Username and friend requests

Choose a unique username (3–30 letters, numbers, dots, underscores, or
hyphens) when signing up. You can sign in and send friend requests using either
the username or registered email address.

## Configure password-reset email

Password reset email uses PHP's built-in `mail()` function and XAMPP Sendmail.
Configure Gmail once in `C:\xampp\sendmail\sendmail.ini` (Gmail SMTP server,
port 587, TLS, Gmail username, and a Gmail App Password). In
`C:\xampp\php\php.ini`, set `sendmail_from` to the Gmail sender address and
`sendmail_path` to `"C:\xampp\sendmail\sendmail.exe" -t`. Restart Apache after
changing `php.ini`. Check `C:\xampp\sendmail\error.log` if sending fails.

The email contains a six-digit code that expires after 10 minutes and allows
five verification attempts. Codes are password-hashed in the database and can
only be used once. The reset page verifies the code first, then reveals the
new-password form. At most three reset emails per account are sent per hour.
Reset requests return a generic response so they do not reveal which email
addresses have accounts.

## First administrator

1. Create a regular Storage account.
2. In phpMyAdmin's SQL tab (or the MySQL command line), promote that account:

   ```sql
   UPDATE storage_portal.users SET role = 'admin' WHERE username = 'your_username';
   SELECT username, role FROM storage_portal.users WHERE username = 'your_username';
   ```

   Alternatively, from `C:\xampp\htdocs\folders`, run
   `C:\xampp\php\php.exe promote-admin.php <username-or-email>`.
3. Sign out and back in. The **Administration** page shows account and storage
   usage, and lets administrators permanently remove other accounts.

The promotion script is command-line only; there is no built-in default admin
password. The last admin cannot be deleted, and an admin cannot remove their
own account from the admin page.

## Other features

Images and videos can be previewed in a folder. Documents download as
attachments. The sun/moon button switches between light and dark mode, and its
preference is saved in this browser.

Workspace sections and folders have bookmarkable URL routes (for example
`#friends`, `#activity`, `#admin`, and `#folder/123`). Browser Back and Forward
navigate between pages, and refreshing a page keeps the current page open.

PHP sessions use an HTTP-only cookie and expire after 30 minutes of inactivity.
For uploads larger than PHP's defaults, update `upload_max_filesize` and
`post_max_size` in `C:\xampp\php\php.ini` (for example, `100M` and `105M`), then
restart Apache. Storage accepts files up to 100 MB.
