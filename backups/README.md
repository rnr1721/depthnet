# Backups

This directory stores database backups created with `make backup`.

```bash
# Create backup
make backup

# Restore from backup
make restore file="backups/depthnet_2026-04-09_15-30.sql"
```

Backup files are excluded from Git — only this README is committed.
