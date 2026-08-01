# Hermes Agent plugin for Unraid

Persistent [Hermes Agent](https://hermes-agent.nousresearch.com) (Nous Research's
open-source AI agent) for Unraid, modeled on the `claude-code` plugin in this repo.

- Survives reboots: the real install lives in `/boot/config/plugins/hermes-agent/persist`
  (the USB flash); `/root` and `/usr/local` are ramdisks that get wiped every boot.
- `rc.hermes-agent` re-creates the symlinks at boot (via the `/boot/config/go` hook).
- POST-INSTALL auto-runs the official installer the first time (takes a few minutes),
  so installing the plugin is all you need to do.
- WebGUI page under **Settings → Utilities → Hermes Agent**: live status (version,
  model, provider, base URL, sessions), config.yaml editor (api_key values masked
  and preserved on save), environment overview, action log, and one-click
  install / restore / update / uninstall with live progress.

## Install

Unraid webUI → **Plugins** → **Install Plugin** → paste:

```
https://raw.githubusercontent.com/qubex22/unraid-plugins/refs/heads/main/hermes-agent/hermes-agent.plg
```

Or manually:

```bash
curl -fsSL -o /boot/config/plugins/hermes-agent.plg \
  https://raw.githubusercontent.com/qubex22/unraid-plugins/refs/heads/main/hermes-agent/hermes-agent.plg
installplg /boot/config/plugins/hermes-agent.plg   # via the webUI Plugins tab
```

## What gets persisted

```
/boot/config/plugins/hermes-agent/persist/
  lib/hermes-agent/   -> /usr/local/lib/hermes-agent   (repo + venv)
  bin/hermes*         -> /usr/local/bin/hermes*        (launchers)
  uv/                 -> /usr/local/share/uv           (uv + managed Python)
  hermes-home/        -> /root/.hermes                 (config, .env, skills, state.db, sessions, logs)
```

## Configuration

After install, edit `/root/.hermes/config.yaml` and `/root/.hermes/.env`
(the symlink points at the persisted copies on `/boot`). API keys go in
`.env`; settings go in `config.yaml`.

The plugin does not ship a webUI settings page — manage via SSH (`hermes setup`).

## Manage

| Action | How |
|---|---|
| Remove plugin | Plugins tab → Remove (wipes `/boot/config/plugins/hermes-agent/persist`) |
| Re-run installer | `bash /boot/config/plugins/hermes-agent/install-hermes.sh` |
| Restore symlinks | `bash /boot/config/plugins/hermes-agent/rc.hermes-agent start` |
| Test | `hermes --version && hermes chat -q "hi"` |
