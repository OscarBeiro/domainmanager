#!/bin/bash

SCRIPT_DIR=$(dirname "$(readlink -f "$0")")
PARENT_FOLDER_PATH=$(dirname "$SCRIPT_DIR")
PLUGINNAME=$(basename "$PARENT_FOLDER_PATH")

POTFILE=$PLUGINNAME.pot
LOCALES=$PARENT_FOLDER_PATH/locales
PLUGINTITLE="Domain Manager"
COPYRIGHTHOLDER="TICGAL"

# Preserve the existing POT-Creation-Date: it should only reflect when
# strings actually changed, not every time this script is re-run.
EXISTING_POT_DATE=""
if [ -f "$LOCALES/$POTFILE" ]; then
    EXISTING_POT_DATE=$(grep -m1 "^\"POT-Creation-Date:" "$LOCALES/$POTFILE")
fi

# check if xgettext is installed
if ! command -v xgettext &>/dev/null; then
    echo "Error: xgettext is not installed"
    exit 1
fi

# if no exist locales folder, create it
if [ ! -d "$LOCALES" ]; then
    mkdir $LOCALES
fi

INIT_PWD=$PWD
if [ ! "$PARENT_FOLDER_PATH" = "$INIT_PWD" ]; then
    cd $PARENT_FOLDER_PATH
fi

# Clean existing file
rm -f $LOCALES/$POTFILE && touch $LOCALES/$POTFILE >/dev/null

# GLPI's translation wrappers beyond plain __(): __s, __e (no-op passthrough),
# _n (plural), _x/_sx (context). Keep in sync if new wrappers are adopted.
KEYWORDS=(--keyword=__:1,2t --keyword=__s:1,2t --keyword=__e:1,2t --keyword=_n:1,2,4t --keyword=_x:1c,2,3t --keyword=_sx:1c,2,3t)

echo Searching PHP files...
# Append locales from PHP
xgettext $(find -type f -name "*.php") -o $LOCALES/$POTFILE -L PHP --add-comments=TRANS --from-code=UTF-8 --force-po --join-existing \
    "${KEYWORDS[@]}" -d $PLUGINNAME --copyright-holder "$COPYRIGHTHOLDER" >/dev/null 2>&1

echo Searching JS files...
# Append locales from JavaScript
xgettext $(find -type f -name "*.js") -o $LOCALES/$POTFILE -L JavaScript --add-comments=TRANS --from-code=UTF-8 --force-po --join-existing \
    "${KEYWORDS[@]}" -d $PLUGINNAME --copyright-holder "$COPYRIGHTHOLDER" >/dev/null 2>&1

echo Searching TWIG files...
# Append locales from Twig templates
for file in $(find ./templates -type f -name "*.twig"); do
    # Wrap every translation call (__, __s, _n, _x, ...) found anywhere in the file —
    # inside {{ }} output tags, {% set %} tags, or plain text — as its own PHP statement,
    # so xgettext picks it up regardless of the surrounding Twig delimiter.
    # Replace "standard input:line_no" by file location in po file comments (below).
    cat $file | perl -pe "s/(_[a-z0-9_]*\([^()]*(?:\([^()]*\)[^()]*)*\))/<?php \1; ?>/gi" | xgettext - -o $LOCALES/$POTFILE -L PHP --add-comments=TRANS --from-code=UTF-8 --force-po --join-existing \
        "${KEYWORDS[@]}" -d $PLUGINNAME --copyright-holder "$COPYRIGHTHOLDER"
    sed -i -r "s|standard input:([0-9]+)|$(echo $file | sed "s|./||"):\1|g" $LOCALES/$POTFILE
done

#Update main language
LANG=C msginit --no-translator -i $LOCALES/$POTFILE -l en_GB -o $LOCALES/en_GB.po

### for using tx :
##tx set --execute --auto-local -r GLPI.glpipot 'locales/<lang>.po' --source-lang en_GB --source-file locales/glpi.pot
## tx push -s
## tx pull -a

cd $LOCALES

sed -i "s/SOME DESCRIPTIVE TITLE/$PLUGINTITLE Glpi Plugin/" $POTFILE
sed -i "s/FIRST AUTHOR <EMAIL@ADDRESS>, YEAR./$COPYRIGHTHOLDER, $(date +%Y)/" $POTFILE
sed -i "s/Copyright (C) YEAR/Copyright (C) $(date +%Y)/" $POTFILE

# Restore the original POT-Creation-Date: xgettext always stamps "now",
# but this file should only record when the extracted strings changed.
if [ -n "$EXISTING_POT_DATE" ]; then
    ESCAPED_POT_DATE=$(printf '%s\n' "$EXISTING_POT_DATE" | sed 's/[&/\]/\\&/g')
    sed -i "s/^\"POT-Creation-Date:.*/$ESCAPED_POT_DATE/" $POTFILE
fi

#Update all languages with localazy
if command -v localazy &>/dev/null; then
    if [ -f "localazy.keys.json" ] && [ -f "localazy.json" ]; then
        localazy upload
        localazy download
    else
        echo "Missing credentials to update translations"
    fi
fi

#Compile all languages
for a in $(ls *.po); do
    msgmerge -U $a $POTFILE
    msgfmt $a -o "${a%.*}.mo"
done
rm -f *.po~

cd $INIT_PWD