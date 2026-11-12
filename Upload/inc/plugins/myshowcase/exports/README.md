# Stored exports

Entry exports (XML files) that users generated are kept here until they delete them or the scheduled task removes them
(30 days by default, `exportsRetentionDays` in `inc/plugins/myshowcase.php`).

* The files are only ever sent through PHP after an ownership and permission check; no URL points here.
* `.htaccess` denies web access on **Apache**. **Other web servers ignore it** (nginx, for example): deny the folder in the
  server configuration, or, better, set `exportsDirectory` in `inc/plugins/myshowcase.php` to an absolute path **outside
  the web root**. File names also contain 32 random hexadecimal characters, so they cannot be guessed.
* Nothing in this folder is part of the plugin: do not commit exported files.
