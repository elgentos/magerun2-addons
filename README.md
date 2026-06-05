magerun2 addons
==============

Some additional commands for the excellent m98-magerun2 Magento 2 command-line tool.

Installation
------------
There are a few options.  You can check out the different options in the [magerun2
Github wiki](https://github.com/netz98/n98-magerun2/wiki/Modules).

Here's the easiest:

1. Create ~/.n98-magerun2/modules/ if it doesn't already exist.

        mkdir -p ~/.n98-magerun2/modules/

2. Clone the magerun2-addons repository in there

        cd ~/.n98-magerun2/modules/ && git clone https://github.com/elgentos/magerun2-addons.git elgentos-addons

3. It should be installed. To see that it was installed, check to see if one of the new commands is in there;

        magerun2 | grep elgentos

Commands
--------

#### Checking for weak admin credentials

`magerun2 elgentos:crack:admin-passwords -f -r best64 vendors special -v`

Check your site for weak admin credentials by attempting to brute force the password with popular password / variations.

#### Configure ElasticSearch

Using this command you can check and optionally configure the ElasticSearch configuration in Magento. Supports ElasticSuite too. Default values might be opinionated.

```bash
$ magerun2 config:elasticsearch

Description:
  Check and optionally configure the Elasticsearch configuration

Usage:
  config:elasticsearch
```

#### Configure RabbitMQ

Using this command you can check and optionally configure the RabbitMQ configuration in Magento. Default values might be opinionated.

It will also ask you which consumers you want to run using RabbitMQ instead of MySQL. By default only `async.operations.all` runs through RabbitMQ.

```bash
$ magerun2 config:rabbitmq

Description:
  Check and optionally configure the RabbitMQ configuration

Usage:
  config:rabbitmq
```

#### Configure Redis

Using this command you can check and optionally configure the Redis configuration in Magento. Default values might be opinionated.

If you are running Magento 2.3.5 and upwards, it will ask you whether you want to enable L2 cache. You can read about the L2 cache [in the Magento devdocs](https://devdocs.magento.com/guides/v2.4/config-guide/cache/two-level-cache.html).

```bash
$ magerun2 config:redis

Description:
  Check and optionally configure the Redis configuration

Usage:
  config:redis
```

#### Configure Varnish

Using this command you can check and optionally configure the Varnish configuration in Magento. Default values might be opinionated.

```bash
$ magerun2 config:varnish

Description:
  Check and optionally configure the Varnish configuration

Usage:
  config:varnish
```

#### Generate Xdebug Step Filter Configuration

With this command, you can automatically fill the Xdebug Step Filters (Settings > PHP > Debug > Step Filters > Files) with all interceptors and proxies that are found in your installation.

```bash
$ magerun2 generate:xdebug-skip-filters

Description:
  Generate the Xdebug Skip Filter configuration [elgentos]

Usage:
  generate:xdebug-skip-filters
```

#### Dispatch/fire a Magento event ###

When building extensions, you often need to fire a certain event to trigger a function. With this command, you can choose one of the default events that can be found in the Magento core, or type in the name of another (custom) event. The command will also ask for any parameters.

You can instantiate an object and load a record into that object. You do this by using as parameter value `Magento\Catalog\Model\Product:1337`. This will instantiate the model `Magento\Catalog\Model\Product` and load entity `1337` in that model.

    $ magerun2 dev:events:fire

It is also possible to give command line arguments. These are '--event' (-e for shortcut) and '--parameters' (-p for shortcut). Parameters can contain multiple parameters, in which the various parameters should be stringed together with ';' and the name/value pair should be stringed together with '::'. Be sure to enclose this in double quotes.

    $ magerun2 dev:events:fire --event your_event_that_will_fire --parameters "product::Mage\Catalog\Model\Product:1337;testparam::testvalue"
    Event your_event_that_will_fire has been fired with parameters;
     - object product: `Magento\Catalog\Model\Product` ID 1337
     - testparam: testvalue

#### List Database Tables by Size and Defined Status

This command lets you see an overview of all tables in the Magento database, their size and whether they are defined or not. You can filter the undefined tables - these can possible be removed. Always create a backup before removing tables!

```
$ magerun2 elgentos:db:show:tables-size
Description:
  Shows all tables in the database, their sizes, and indicates if they are defined in db_schema.xml

Usage:
  elgentos:db:show:tables-size [options]

Options:
      --only-undefined                    Only show tables which are not defined
```

Thanks to Francis Gallagher for the initial code.

#### Reindex Partially

This command lets you reindex any indexer partially, as long as the indexer implements `executeList` correctly.

```bash
$ magerun2 index:reindex-partial --help
                            
Usage:
  index:reindex-partial <indexer> <ids> (<ids>)...

Arguments:
  indexer                                        Name of the indexer.
  ids                                            (Comma/space) seperated list of entity IDs to be reindexed

Help:
  Reindex partially by inputting ids [elgentos]
``` 

#### Collect missing translations

Collects every translatable phrase (`__()` in PHP, `.phtml`, layout XML and JS) from a directory or the
whole codebase, then reports the ones that have **no** translation for a given locale. It compares against the
fully merged frontend translation map for the emulated store — module/theme/language-pack CSVs *and* DB
(inline) translations — so the result is exactly what is still untranslated on the storefront. Output is a
ready-to-fill Magento i18n CSV (`"phrase",""`). No extension install needed.

This is a magerun2 reimplementation of [experius/module-missing-translations](https://github.com/experius/Magento-2-Module-Experius-MissingTranslations).

```bash
$ magerun2 i18n:collect-missing --help

Usage:
  i18n:collect-missing [options] [--] [<directory>]

Arguments:
  directory                 Directory (or comma-separated list of directories) to parse. Not needed when --magento is set.

Options:
  -m, --magento             Parse the whole Magento codebase (BP) instead of a directory.
  -l, --locale=LOCALE       Locale to check, e.g. nl_NL.
  -s, --store=STORE         Store id or code to emulate for theme/pack/DB translations (default: default store view).
  -o, --output=OUTPUT       Output CSV path. Use "-" for stdout. Default: <cwd>/i18n-missing_<locale>.csv
  -t, --translate           If the "claude" CLI is installed, one-shot translate the CSV into <output>.translated.csv
  -x, --exclude=EXCLUDE     Comma-separated path substrings to skip (case-insensitive), e.g. "/sample-data/,/Setup/Patch/".
      --include-tests       Do not auto-exclude test/dev directories.
      --types=TYPES         Comma-separated phrase sources: php (__() calls), js, html, xml (config/UI labels). Default: php,js,html,xml.
```

The collector mirrors Magento core's `i18n:collect-phrases` and gathers translatable strings from **four** sources,
not just PHP `__()`: `php` (`__()` calls in `.php`/`.phtml`), `js` (`$.mage.__()`), `html` (Knockout `i18n:`/`translate=`
bindings) and `xml` (`system.xml`/UI config & menu labels). If you only want `__()` phrases, pass `--types=php`.

The output CSV has a **third column** naming the extension/package each phrase was found in — `Vendor_Module` for
`app/code` modules and `vendor-name/package` for Composer packages (multiple sources are joined with `; `).

Files are parsed one at a time, so a single unparseable file (e.g. a test containing `__('')` or `__($var)`) is
skipped with a warning instead of aborting the whole run. Test/dev directories (`dev/tests`, `Test/`, `tests/`,
`_files/`) are excluded by default — pass `--include-tests` to parse them too, and `--exclude` to skip extra paths.

With `--translate`, after the missing-phrases CSV is written the command checks for the [`claude` CLI](https://docs.claude.com/en/docs/claude-code)
in your `PATH`. If present, it pipes the CSV through `claude -p` and writes a filled-in copy to
`<output>.translated.csv`, preserving Magento placeholders (`%1`, `%2`, …) and leaving non-UI/technical strings
untranslated. The original CSV is left untouched. If `claude` is not installed, the step is skipped with a notice.

Examples:

```bash
# Whole codebase, default store, written to ./i18n-missing_nl_NL.csv
$ magerun2 i18n:collect-missing --magento --locale=nl_NL

# A single module/theme, emulating a specific store view
$ magerun2 i18n:collect-missing app/design/frontend/Acme/default --locale=de_DE --store=acme_de

# Multiple directories at once (comma-separated, results merged & de-duplicated)
$ magerun2 i18n:collect-missing app/code/Acme,app/design/frontend/Acme/default --locale=nl_NL

# Pipe the CSV straight to a file (diagnostics go to stderr)
$ magerun2 i18n:collect-missing --magento --locale=fr_FR --output=- > missing_fr.csv

# Collect AND auto-translate with the claude CLI -> ./i18n-missing_nl_NL.translated.csv
$ magerun2 i18n:collect-missing --magento --locale=nl_NL --translate
```

Credits due where credits due
--------

Thanks to [Netz98](http://www.netz98.de) for creating the awesome Swiss army knife for Magento, [magerun2](https://github.com/netz98/n98-magerun2/).
