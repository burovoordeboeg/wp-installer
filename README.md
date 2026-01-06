# BvdB Composer Installer

Composer-first workflow to bootstrap a WordPress project the same way our old `install.sh` did, but locally and repeatable. WordPress core is pulled in via Composer, setup files are fetched from our API, and plugins can be installed from the Satispress repository.

## Prereqs
- PHP >= 8.1 with `ext-phar` enabled (used for extracting `setup.tar.gz`)
- Composer
- Credentials for the setup/ENV endpoints (set `BVDB_SETUP_USER`/`BVDB_SETUP_PASSWORD` or `BVDB_SETUP_BASIC_AUTH`)

## Usage
1) Install dependencies (downloads WordPress to `public/wp`):
   ```
   composer install
   ```
2) Run the installer (downloads the setup bundle, copies config, seeds `.env`):
   ```
   composer run bvdb:setup
   ```
   You’ll be prompted for DB/user/domain values; defaults mirror the old shell script. Salts and license blocks are injected into `.env` using the configured endpoints.

### Configuring the setup bundle
`composer.json` exposes everything under `extra.bvdb`:
- `setup_url`, `salts_url`, `licenses_url`: remote endpoints to pull the archive and placeholder replacements from.
- `setup_map`: source → destination paths inside the extracted setup. Tweak if the archive layout changes (e.g. add entries for extra files you want to copy).
- Basic-auth headers are built from the environment variables mentioned above; they’re sent for all downloads.

### Installing plugins from Satispress
The Satispress repo is already defined in `repositories.satispress`. Add your plugins to `require`, for example:
```
composer require vendor/plugin-one:^1.0 vendor/plugin-two:^2.0
```
Composer will place them into `public/content/plugins/` via the installer paths.

## Notes
- The installer creates and cleans a `.bvdb/` scratch directory. Archives and extracted files are removed when the run completes.
- The installer cleans up temp/setup remnants afterwards: `.bvdb/`, `.security/` (if present), and `.env.bak`.
