# Contributing to YaAff

## Version Bumping (REQUIRED on every merge)

YaAff uses a date-based version in `admin/version.txt` (format: `DD.MM.YY`).
The auto-update system (`admin/autoupdate.php`) compares this version against the `multipleconfigs` branch on GitHub to determine if an update is available.

**Every PR merged into `multipleconfigs` MUST include an update to `admin/version.txt`** with the current date. Without this, deployed instances will not detect the update.

### How to bump the version

Update `admin/version.txt` to the current date in `DD.MM.YY` format:

```bash
date +"%d.%m.%y" > admin/version.txt
```

### Why this matters

- The `AutoUpdater` class converts version strings to timestamps and compares them
- If `version.txt` on the remote branch is not newer than the local copy, the "Update" button in the admin panel will report "no updates available"
- Users relying on the built-in auto-update feature will not receive your changes

### Checklist before merging

- [ ] `admin/version.txt` updated to today's date (`DD.MM.YY`)
- [ ] Changes tested locally with `php8.2 -S localhost:8080`
- [ ] No secrets or credentials committed
