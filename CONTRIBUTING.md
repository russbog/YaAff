# Contributing to YaAff

## Version Bumping (REQUIRED on every merge)

YaAff uses a date+time version in `admin/version.txt` (format: `YY.MM.DD.mm`, where `mm` = minutes since midnight UTC).
The auto-update system (`admin/autoupdate.php`) compares this version against the `multipleconfigs` branch on GitHub to determine if an update is available.

**Every PR merged into `multipleconfigs` MUST include an update to `admin/version.txt`**. Without this, deployed instances will not detect the update.

### How to bump the version

Update `admin/version.txt` to the current date+time in `YY.MM.DD.mm` format:

```bash
printf "%s.%d\n" "$(date -u +%y.%m.%d)" "$(( $(date -u +%-H) * 60 + $(date -u +%-M) ))" > admin/version.txt
```

Example: June 24, 2026 at 10:06 UTC → `26.06.24.606`

### Why this matters

- The `AutoUpdater` class converts version strings to timestamps and compares them
- Minutes-level precision allows multiple releases per day to be detected correctly
- If `version.txt` on the remote branch is not newer than the local copy, the "Update" button in the admin panel will report "no updates available"
- Users relying on the built-in auto-update feature will not receive your changes

### Checklist before merging

- [ ] `admin/version.txt` updated (`YY.MM.DD.mm` format, UTC time)
- [ ] Changes tested locally with `php8.2 -S localhost:8080`
- [ ] No secrets or credentials committed
