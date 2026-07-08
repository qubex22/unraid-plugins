# Claude Code for Unraid

[![Unraid Forum](https://img.shields.io/badge/Unraid-Forum-orange?logo=unraid)](https://forums.unraid.net)
[![Min Unraid Version](https://img.shields.io/badge/Unraid-%E2%89%A5%206.10.0-blue)](https://unraid.net)

Run [Claude Code](https://docs.anthropic.com/en/docs/claude-code) — Anthropic's official terminal AI coding agent — on your Unraid server, **persistent across reboots**. The plugin installs Claude Code via the official installer, then copies the binary and all runtime files to `/boot` (your USB flash drive) so nothing is lost when the server restarts.

> **How it works**: Unraid boots into RAM. `/root` is a ramdisk — everything outside `/boot` vanishes on reboot. This plugin persists Claude Code's binary, versions, config (`~/.claude.json`), and Claude data (`~/.claude`) onto `/boot/config/plugins/claude-code/persist/`, then symlinks them back into place at boot time via `/boot/config/go`.

## Features

- **One-click install** from the Unraid WebGUI (Settings → Utilities → Claude Code)
- **Survives reboots** — binary, shared runtime, and user config persist on your USB flash drive
- **Automatic restore** at boot via `/boot/config/go` integration
- **Live progress** panel with cancel support for long-running operations
- **Settings editor** — edit `~/.claude/settings.json` directly from the WebGUI
- **MCP server editor** — configure `mcp.json` from the WebGUI
- **Update** to the latest Claude Code release without losing persistence
- **Debug log viewer** for troubleshooting
- **CSRF protection** on all AJAX endpoints
- **SSH-ready** — PATH and shell config set up automatically so `claude` works in new SSH sessions

## Requirements

- **Unraid 6.10.0** or newer
- Internet access (installer downloads Claude Code from `claude.ai`)
- ~300 MB free space on `/boot` (the USB flash drive) for the persisted binary

## Installation

### Option 1: Community Applications (once published)

Search for "Claude Code" in the Unraid Community Applications plugin.

### Option 2: Manual URL install

1. In the Unraid WebGUI, go to **Plugins → Install Plugin**
2. Paste this URL and click **Install**:

```
https://raw.githubusercontent.com/qubex22/unraid-plugins/main/claude-code/claude-code.plg
```

3. After the plugin installs, go to **Settings → Utilities → Claude Code**
4. Click **Install Claude Code**
5. Once completed, open a terminal and run:

```bash
claude
```

## Usage

### First-run authentication

On first run, `claude` will open an interactive authentication flow. If you're running headless (no browser on the server), you can:

1. SSH into your Unraid server
2. Run `claude` — it will display a URL and code
3. Open the URL in a browser on another machine and enter the code

### WebGUI actions

| Action | What it does |
|--------|--------------|
| **Install Claude Code** | Downloads the official installer, copies the binary to `/boot` for persistence, and sets up symlinks |
| **Restore Symlinks** | Re-creates the symlinks from `/root` into `/boot/config/plugins/claude-code/persist/` (useful if they get lost, e.g., after a reboot before `/boot/config/go` runs) |
| **Uninstall** | Removes the persisted binary and symlinks, cleans `/boot/config/go` |
| **Update** | Runs `claude update` (or the official installer as fallback), then re-persists the new version |

### Configuration editors

- **Settings JSON** — edits `~/.claude/settings.json`. Supported keys: `apiKeyHelper`, `model`, `theme`, `skipDotfiles`, `env`, and more. See the [Claude Code settings docs](https://docs.anthropic.com/en/docs/claude-code/settings).
- **MCP Servers** — edits `~/.claude/mcp.json`. Add `command`-type MCP servers to extend Claude Code with custom tools. See the [Claude Code MCP docs](https://docs.anthropic.com/en/docs/claude-code/mcp).

### SSH access

After installing, `claude` is available on PATH in new SSH sessions. The plugin:
- Adds `export PATH="$HOME/.local/bin:$PATH"` to `~/.bashrc`
- Ensures `~/.bash_profile` sources `~/.bashrc` (SSH login shells read `.bash_profile`)

No manual PATH configuration needed.

## How persistence works

```
/boot/config/plugins/claude-code/
├── claude-code.plg          # Plugin definition
├── rc.claude-code           # Boot-time symlink restore script
├── install-claude.sh        # Download + persist logic
├── update-claude.sh         # Update + re-persist logic
├── uninstall-claude.sh      # Cleanup script
└── persist/                 # ← Survives reboots (on USB flash)
    ├── bin/
    │   └── claude → ../share/versions/<version>   (RELATIVE symlink)
    ├── share/                # Full ~/.local/share/claude/ tree
    │   └── versions/
    │       └── <version>    # Real 256 MB ELF binary
    ├── cfg/                  # Persisted ~/.claude/ config
    │   ├── .claude.json     # User-scope config (API key helper, model, etc.)
    │   ├── settings.json    # Project-scope settings
    │   ├── mcp.json         # MCP server definitions
    │   └── backups/
    └── bin/claude → ../share/versions/<version>
```

At boot, `rc.claude-code start` (invoked by `/boot/config/go`) restores:

| Live path (ramdisk) | Symlink target (on `/boot`) |
|---|---|
| `~/.local/bin/claude` | → `persist/bin/claude` |
| `~/.local/share/claude` | → `persist/share` |
| `~/.claude` | → `persist/cfg` |
| `~/.claude.json` | → `persist/cfg/.claude.json` |

The key design decision: `persist/bin/claude` is a **relative** symlink (`../share/versions/<ver>`) so it stays valid no matter where `/boot` is mounted.

## Updating the plugin

The plugin itself updates via Unraid's standard plugin update mechanism (check **Plugins → Check for Updates**). Updating the plugin does **not** update Claude Code itself — use the **Update** button on the settings page to upgrade to the latest Claude Code release.

## Troubleshooting

### "Not installed" after reboot, but install succeeded

Check the syslog:

```bash
grep claude-code /var/log/syslog | tail -10
```

If you see `Not installed. Use Settings/ClaudeCode to install.`, the symlinks aren't restoring. Click **Restore Symlinks** in the WebGUI, or run:

```bash
bash /boot/config/plugins/claude-code/rc.claude-code start
```

### `claude: command not found` in SSH

The plugin should configure this automatically. If it doesn't take effect immediately, run:

```bash
source ~/.bashrc
```

Or manually verify:

```bash
ls -la ~/.local/bin/claude   # should show a symlink
cat ~/.bash_profile          # should contain "source ~/.bashrc"
cat ~/.bashrc                # should contain "export PATH=.../local/bin..."
```

### "504 Gateway Timeout" or blank page after clicking a button

This can happen if nginx times out on slow operations. The WebGUI page includes inline guidance for fixing nginx timeout settings. Check the debug log on the settings page for details.

### Free space on `/boot`

The Claude Code binary is ~256 MB. Check free space:

```bash
df -h /boot
```

Older versions accumulate in `persist/share/versions/` — you can safely delete old ones:

```bash
ls /boot/config/plugins/claude-code/persist/share/versions/
# Keep the newest, delete older entries
```

### Debug log

The WebGUI includes a **Debug Log** section. For command-line access:

```bash
cat /boot/config/plugins/claude-code/last_action.log
```

## Manual commands

All scripts are idempotent — safe to run multiple times.

```bash
# Restore symlinks (after reboot, or if they get lost)
bash /boot/config/plugins/claude-code/rc.claude-code start

# Remove symlinks (clean shutdown)
bash /boot/config/plugins/claude-code/rc.claude-code stop

# Re-run the full install (downloads Claude Code again)
bash /boot/config/plugins/claude-code/install-claude.sh

# Update to the latest Claude Code release
bash /boot/config/plugins/claude-code/update-claude.sh

# Completely remove everything (binary, config, go hook)
bash /boot/config/plugins/claude-code/uninstall-claude.sh
```

## Structure

```
unraid-plugins/
└── claude-code/
    ├── claude-code.plg              # Plugin XML definition
    ├── ClaudeCode.page              # WebGUI page (Settings → Utilities)
    ├── action.php                   # AJAX backend (install/update/uninstall/settings/MCP/log)
    ├── claude-code-*.txz            # Package: .page + .php (installed to /usr/local/emhttp/plugins/)
    └── claude-code-*.md5            # Package checksums
```

Scripts are embedded inline in `claude-code.plg` as `<FILE>` entities. They are extracted to `/boot/config/plugins/claude-code/` at plugin install time.

## License

This plugin is community-maintained and provided as-is. Claude Code itself is subject to [Anthropic's terms of service](https://www.anthropic.com/legal/terms).

## Support

- **Unraid Forum**: [Search "Claude Code"](https://forums.unraid.net)
- **GitHub Issues**: [qubex22/unraid-plugins/issues](https://github.com/qubex22/unraid-plugins/issues)
- **Claude Code docs**: [docs.anthropic.com](https://docs.anthropic.com/en/docs/claude-code/overview)
