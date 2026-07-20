# Terminus Site Defrost Plugin

[![Terminus v3.x/4.x Compatible](https://img.shields.io/badge/terminus-v3.x%20%7C%7C%20v4.x-green.svg)](https://github.com/pantheon-systems/terminus)

Terminus plugin to defrost a [frozen Pantheon website](https://pantheon.io/docs/platform-considerations#inactive-site-freezing).

## Examples (with aliases)
### Site name
```bash
terminus site:defrost my-cool-site
```

### Platform URL
```bash
terminus site:thaw https://dev-my-cool-site.pantheonsite.io/
```

### Dashboard URL
```bash
terminus thaw https://dashboard.pantheon.io/workspace/{org-uuid}/cms-site/{site-uuid}/frozen
```

### ID/UUID
```bash
terminus site:unfreeze de305d54-75b4-431b-adb2-eb6b9e546014
```

## Installation
For help installing, see [Manage Plugins](https://pantheon.io/docs/terminus/plugins/)
```bash
terminus self:plugin:install morganestes/terminus-defrost-plugin
```

## Help
Run `terminus help site:defrost` for a list of options.

## Credits
Based on the Snowman plugin at https://github.com/terminus-plugin-project/terminus-snowman-plugin.
