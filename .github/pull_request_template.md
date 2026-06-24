## Summary

<!-- Brief description of the changes -->

## Version Bump

> **REQUIRED**: Every merge into `multipleconfigs` must update the version so auto-updates work.
> Run: `printf "%s.%d\n" "$(date -u +%y.%m.%d)" "$(( $(date -u +%-H) * 60 + $(date -u +%-M) ))" > admin/version.txt`

- [ ] `admin/version.txt` updated (`YY.MM.DD.mm` format, UTC time)

## Testing

- [ ] Tested locally with `php8.2 -S localhost:8080`
