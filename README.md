# StorageBundle

A Symfony bundle to interact with storage (via Flysystem).  It exposes commands, controllers and twig utilities.  All of the underlying storage happens through Flysystem.



## Quickstart
```bash
symfony new storage-demo --webapp && cd storage-demo
composer require survos/storage-bundle
```

Configure Flysystem, including the relevant env vars if using something besides local

```yaml
# config/packages/flysystem.yaml
# Read the documentation at https://github.com/thephpleague/flysystem-bundle/blob/master/docs/1-getting-started.md
flysystem:
    storages:
        default.storage:
            adapter: 'aws'
            # visibility: public # Make the uploaded file publicly accessible in S3
            options:
                client: 'Aws\S3\S3Client' # The service ID of the Aws\S3\S3Client instance
                bucket: '%env(AWS_S3_BUCKET_NAME)%'
                streamReads: true
                prefix: '%env(S3_STORAGE_PREFIX)%'
when@dev:
    flysystem:
        storages:
            default.storage:
                adapter: 'local'
                options:
                    directory: '%kernel.project_dir%/public/storage'
```



```bash
symfony new storage-demo --webapp && cd storage-demo
composer require survos/storage-bundle
bin/console storage:config <api-key> >> .env.local 
bin/console storage:list
```

You can browse interactively with the basic admin controller.

```bash
composer require survos/simple-datatables-bundle
symfony server:start -d
symfony open:local --path=/storage/zones
```

Or edit .env.local and add your API key.

As each storage zone has its own passwords and id, these need to be configured individually in survos_storage.yaml.  Rather than tediously configuring each zone by cutting and pasting, we can use the first utility to dump the configuration with just the main api key.  This saves you from having to go to  https://dash.storage.net/storage and go to each storage zone, then click on it and select "FTP and ApiAccess" and selecting each key.


```bin
bin/console storage:config <api-key> 
```

Note: use --filter to limit to the zones to a regex (@todo)

You can skip passing the api key on the command line by defining it as an environment variable, etc.
```bash
echo "STORAGE_API_KEY=api-key >> .env.local
```

This command dumps the packages/config/survos_storage.yaml file with references to the environment variables, which are also dumped and should be added to .env.local.  If your application only reads from storage, you can remove the password environment variables, it is only used during writing.  You can also remove the main api key if your application doesn't need it in production.

Open .env.local and replace the values.

Your application now has a bare-bones controller located at /admin/storage, you may want to secure this route in security.yaml, or configure it in config/routes/survos_storage.yaml.

You also have access to a command line interface.

```bash
bin/console storage:list 
```

```bash
+------------- museado/ -----+--------+
| ObjectName     | Path      | Length |
+----------------+-----------+--------+
| photos finales | /museado/ | 0      |
+----------------+-----------+--------+


```

