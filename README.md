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

It will also ask you which consumers you want to run throught RabbitMQ instead of MySQL. By default only `async.operations.all` runs through RabbitMQ.

```bash
$ magerun2 config:rabbitmq

Description:
  Check and optionally configure the RabbitMQ configuration

Usage:
  config:rabbitmq
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

    
Credits due where credits due
--------

Thanks to [Netz98](http://www.netz98.de) for creating the awesome Swiss army knife for Magento, [magerun2](https://github.com/netz98/n98-magerun2/).
