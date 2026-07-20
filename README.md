# Terminus Site Defrost Plugin

[![Terminus v3.x Compatible](https://img.shields.io/badge/terminus-v3.x-green.svg)](https://github.com/morganestes/terminus-defrost-plugin)
[![Terminus v4.x Compatible](https://img.shields.io/badge/terminus-v4.x-green.svg)](https://github.com/morganestes/terminus-defrost-plugin)

Terminus plugin to defrost a [frozen Pantheon website](https://pantheon.io/docs/platform-considerations#inactive-site-freezing).

## Examples (with aliases)

### Site name

```shell-script
terminus site:defrost my-cool-site
```

### URL

```shell-script
terminus site:thaw https://dev-my-cool-site.pantheonsite.io/
```

### ID/UUID

```shell-script
terminus site:unfreeze de305d54-75b4-431b-adb2-eb6b9e546014
```

## Installation
For help installing, see [Pantheon docs](https://pantheon.io/docs/terminus/plugins/)

```shell-script
terminus self:plugin:install morganestes/terminus-defrost-plugin
```

## Help
Run `terminus list site` for a complete list of available commands. Use `terminus help <command>` to get help on one command.

## Credits
Based on the Snowman plugin at https://github.com/terminus-plugin-project/terminus-snowman-plugin.
