# Contributing to YaAff

## Version Bumping (REQUIRED on every merge)

YaAff supports two version formats in `admin/version.txt`:

| Format | Example | Precision |
|--------|---------|-----------|
| `DD.MM.YY` (legacy) | `25.06.26` | Day |
| `YY.MM.DD.mm` (new) | `26.06.24.606` | Minute (`mm` = minutes since midnight UTC) |

The auto-update system (`admin/autoupdate.php`) compares this version against the `multipleconfigs` branch on GitHub.

**Every PR merged into `multipleconfigs` MUST include an update to `admin/version.txt`**. Without this, deployed instances will not detect the update.

### Transition period

Deployed instances running code from before PR #27 only understand the 3-part `DD.MM.YY` format. During the transition, `version.txt` must stay in `DD.MM.YY` format so those instances can detect and pull the update (which includes the new `autoupdate.php` that handles both formats).

**Once all deployed instances have updated past PR #27**, switch to the new 4-part format:

```bash
printf "%s.%d\n" "$(date -u +%y.%m.%d)" "$(( $(date -u +%-H) * 60 + $(date -u +%-M) ))" > admin/version.txt
```

Until then, use the legacy format:

```bash
date -u +"%d.%m.%y" > admin/version.txt
```

### Why this matters

- The `AutoUpdater` class converts version strings to timestamps and compares them
- If `version.txt` on the remote branch is not newer than the local copy, the "Update" button will report "no updates available"
- A 4-part version is **unparseable** by old instances → they silently report "up to date" instead of updating

### Checklist before merging

- [ ] `admin/version.txt` updated (must be newer than previous version)
- [ ] If any deployed instances still run pre-PR#27 code, use `DD.MM.YY` format
- [ ] Changes tested locally with `php8.2 -S localhost:8080`
- [ ] No secrets or credentials committed
