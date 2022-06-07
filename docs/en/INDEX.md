# how it works:

There is a [build task](https://github.com/sunnysideup/silverstripe-version-pruner/blob/master/src/Tasks/PruneAllVersionedRecords.php) that runs through all the versioned clases in your database.

For each versioned class (e.g. `SiteTree`, `File`, etc...), it takes 500 random records (or less)

For each of these random records, it passes it to the [RunForOneObject](https://github.com/sunnysideup/silverstripe-version-pruner/blob/master/src/Api/RunForOneObject.php). This runner can be configured using static variables (see [yml example file](https://github.com/sunnysideup/silverstripe-version-pruner/blob/master/_config/version-pruner.yml.example)) to determine what pruners run for what class.

By default, a [few pruners](https://github.com/sunnysideup/silverstripe-version-pruner/tree/master/src/PruningTemplates) have been included.  For any class, you can combine them as you see fit. The [default one](https://github.com/sunnysideup/silverstripe-version-pruner/blob/master/src/PruningTemplates/BasedOnTimeScale.php) prunes based on time: the further away you get from today, the more versions are pruned - e.g. we keep one every two hours for the last 24 hours, and one for every year once you go a lot further back in time. 


# how to configure

see [example](https://github.com/sunnysideup/silverstripe-version-pruner/blob/master/_config/version-pruner.yml.example)

# how to run

Run all the pruning in one go using this command on the command line:

```
vendor/bin/sake dev/tasks/prune-all-versioned-records
```
You can run this task every night, or once an hour or whatever works for you. A cron job or similar is recommended. 


**OR** browse to (not recommended):

```
http://www.mysite.com.nz/dev/tasks/prune-all-versioned-records
```
# how to customise

You can set up your own pruner templates based on the ones provided [here](https://github.com/sunnysideup/silverstripe-version-pruner/tree/master/src/PruningTemplates). 


# acknowledgement

Many thanks for Ralph: https://github.com/axllent/silverstripe-version-truncator/ - for providing a lot of the base code for this module.
