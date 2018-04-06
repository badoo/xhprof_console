# xhprof_console
A console tool for grabbing profiles from XHProf database and collecting aggregates from them

## Requirements:
* PHP with XHProf installed and set up.
* This script works directly with xhprof database, profiles should be taken as usual.

## Why to use it instead of native XHProf interface:
It allows to collect and see stats for several XHProf profiles. Stats includes:
 * average method exec time
 * 90 percentile of method exec time
 * min, max of the exec time

## How to use
First run xhprof_console.php with no arguments. I'll give you a config format. Copy it to config.php

Then fill the config with mysql settings and also change the SQL to select desired profiles.

Run:
    php xhprof_console.php config.php

You'll see an error if something wrong. If not - the root method stats and a command prompt.

## Stats format
There are three sections:
* Self Stat - the stats of current method
* Children - the stats of method which are called by current
* Parents - the stats of method which call current method

In each sections table columns are:
* \# - id of method which is used for navigation in the prompt
* AVG_CT - average calls count of that method between all the collected profiles
* min_ct - minimum calls count in all the profiles (0 is not counted, so the minimum is 1)
* max_ct - maximum calls count in all the profiles
* AVG_WT - average wall time (total execution time of the method) between all the collected profiles
* PERCENT_WT - 90-th percentile of wall time between all the collected profiles
* max_wt - maximum wall time between all the collected profiles
* mark - used to marking method in prompt (just a star sign to mark anything you want, does not affect anything)
* method - method name

## Prompt commands
* q - exit
* 0 - go back in the tree
* 1,2,3... - go to method N
* m1,m2,m3... - place a star sign on method N (it was meant to mark methods you've already looked at)
