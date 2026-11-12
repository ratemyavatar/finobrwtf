#!/bin/sh
cd "$(dirname "$0")/compat"
cat core.lua globals.lua enums.lua instance.lua access.lua services.lua classes.lua gui.lua
printf '\nreturn C\n'
