# Platform operations runbook

## Private configuration

Production requirements: PHP 8.4+, MySQL/MariaDB with transactional InnoDB tables, OpenSSL, ZipArchive/libzip with AES support, HTTPS, and a scheduler. Match the lockfile and test a new runtime before deployment. Application code and storage must be outside the served public directory.

Configure privately, then regenerate Laravel's configuration cache:

```dotenv
APP_ENV=production
APP_DEBUG=false
PLATFORM_MFA_REQUIRED=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
PLATFORM_BACKUP_PATH=/PRIVATE/PERSISTENT/DIRECTORY
PLATFORM_BACKUP_PASSWORD="a randomly generated secret of at least 24 characters"
```

Use a persistent private backup directory shared across releases. Never place it under public_html or a public storage symlink. Directories use mode 0700 and archives 0600. Keep APP_KEY and the backup password in a separate secure credential store; archives intentionally omit .env and application code. Preserve the release source/Composer lock and a deployable source artifact separately. Do not put keys into Git or support messages.

## Scheduling and checks

Run the active release using the correct PHP 8.4 CLI every minute:

```sh
/opt/alt/php84/usr/bin/php /PRIVATE/ACTIVE_APP/artisan schedule:run
```

Laravel schedules subscription processing hourly and backup at 02:15 in the configured application timezone (UTC unless APP_TIMEZONE is explicitly configured). Both have overlap locks. Change the scheduler command when switching release directories; remove the former pharmacy job after the new job is verified. Preserve unrelated websites' jobs.

Manual creation and verification:

```sh
php artisan platform:backup
php artisan platform:backup --verify=/PRIVATE/BACKUPS/backup-DATE-RANDOM.zip
php artisan schedule:list
```

Check the command exit status, private application log, provider cron output and **Platform overview → Encrypted backups**. A successful run decrypts and hashes every archived entry before atomically updating its success metadata. Backups older than 14 days are pruned only after a successful new run. Copy verified encrypted archives to a separately controlled offsite destination according to your operational policy; no remote credentials or billable storage are provisioned automatically.

Database rows are captured in one transaction. Media is collected afterwards, so a busy site's media can change during capture. Use a maintenance window for the final recovery/migration snapshot when exact database/media alignment is required. The archive includes business media under storage/app/businesses or storage/app/private/businesses, private billing receipts under storage/app/billing, and public avatars/receipt assets under public/storage. PHP code, environment files, cache, logs and prior backups are excluded.

## Restore rehearsal and recovery

Rehearse on an isolated installation with a new database. Never import a full platform backup into a running business database or use an owner export as a platform restore.

1. Select a verified archive and matching release. Make an additional backup of the affected environment before changing it. Record the incident, snapshot time and affected businesses.
2. Decrypt the ZIP in a private working directory using an AES-compatible archiver such as 7-Zip. Enter its password interactively, never as a visible command argument. Validate the manifest's SHA-256 checksums.
3. Create a **new empty database** and a least-privilege user through the hosting control panel. Import `database.sql` there using a client that prompts for its password. The dump contains DROP/CREATE statements; selecting the correct new database is essential. Keep the original database intact.
4. Configure an isolated release with the original APP_KEY and the new database credentials. Restore `media/businesses` to storage/app/businesses, `media/private/businesses` to storage/app/private/businesses, `media/billing` to storage/app/billing, and `media/public` to public/storage. Preserve private permissions. Confirm the configured public disk points to the real public/storage directory.
5. Reconcile business, account, branch, product, inventory, sale, purchase, invoice and payment counts; check sample totals, media checksums and business IDs against the snapshot. Test two businesses to verify isolation. Run a newer migration only when deliberately upgrading the restored release.
6. Invalidate restored Sanctum tokens and remember tokens; clear cached sessions before exposing the restore. If database sessions are configured, clear the sessions table too. Do this only in the new restore database. Existing session files are not part of the archive and should not be copied. Clear configuration/view caches, rebuild production caches and verify MFA enrollment using normal office login.
7. For live cutover, pause writes, reconcile the final snapshot, switch the front controller/database configuration atomically and verify owner, office and cashier login plus operational pages. Repoint the pharmacy scheduler. Keep rollback files and the old database until recovery is accepted.
8. Record actual restore timing and any transactions entered after the snapshot that require reconciliation. Do not claim zero data loss without that reconciliation.

The Phase 3 development rehearsal restored an encrypted snapshot into a newly created disposable database and matched five central/operational table counts. It did not modify the source or live database. Repeat rehearsals as the schema and operational volume change.

## Office access and incidents

Office staff enroll an authenticator at their next login. Recovery codes are single use. A trusted server operator can reset lost enrollment using the audited `platform:admin --reset-mfa --force` command documented in PHASE_3.md. Recheck the intended office role before running it. Never grant a business membership to an office identity.

For a support incident, require an owner ticket and explicit expiring diagnostics approval. The current diagnostic scope is counts only. Full business data access or an assisted export needs a separately authorized operator procedure; no implicit platform impersonation exists.

If a backup fails, retain prior archives, inspect private logs and disk space, verify PHP AES support and private environment keys, then rerun and verify. If keys are exposed, rotate them through a reviewed procedure and retain the keys needed for historical archive decryption in secure escrow. Do not rotate APP_KEY casually: encrypted MFA enrollment depends on it.

The production mail transport remains separately configured; no reminder email or password-reset delivery is claimed while it uses the log driver. Physical receipt hardware remains an onsite verification task.
