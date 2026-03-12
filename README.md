# Synchy

Synchy is a WordPress backup and site sync plugin focused on full-site exports, manual restore workflows, and destination push sync between WordPress installs.

## Release Packaging

For GitHub-based plugin updates, publish a GitHub Release and attach a zip asset named `synchy.zip`.

That zip should contain the plugin folder at the top level:

```text
synchy/
  synchy.php
  assets/
  ...
```

## Development Notes

- The main plugin bootstrap is `synchy.php`.
- Site Sync currently uploads a full package and standalone installer to the destination.
- Manual restore is launched through the generated `installer.php`.
