/**
 * MyShowcase Plugin for MyBB - Frontend File
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: http://www.communityplugins.com
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 			http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: readme.txt
 *
 */

============================================================================================
Requirements:
============================================================================================
MyBB 1.9 (the plugin is declared compatible with 19*).

PHP 8.2 or higher.

Database engines: MySQL / MariaDB (mysqli or mysql_pdo), PostgreSQL (pgsql or pgsql_pdo) and
SQLite. The plugin creates its own tables when you activate it, using the SQL that suits the
database engine your board runs on. You do not have to run any SQL by hand.
- New tables on MySQL / MariaDB are created with the InnoDB storage engine. Tables of an earlier
  build that are still MyISAM are converted when you activate the plugin (see "Upgrading earlier
  3.0 development installs" below).
- Fulltext indexes on showcase fields are only created where your database supports them
  (MySQL / MariaDB, InnoDB from MySQL 5.6). PostgreSQL and SQLite skip them.

PluginLibrary 13 or higher: <mybb_root>/inc/plugins/pluginlibrary.php

Composer packages (run this once after uploading the files, and again after every upgrade):
1) Open a terminal in the plugin folder:
   cd <mybb_root>/inc/plugins/myshowcase
2) Run:
   composer install
   This creates <mybb_root>/inc/plugins/myshowcase/vendor/. If Composer is not installed on your
   server, run the command on your own computer and upload the vendor folder to the same place.
   The files composer.json and composer.lock in that folder tell Composer what to install.

The plugin does not install its own copy of Twig, symfony/http-foundation, symfony/routing,
symfony/string or symfony/event-dispatcher. It uses the copies that MyBB 1.9 already ships in
<mybb_root>/inc/vendor/ (Twig 3 and symfony/http-foundation among them), so a MyBB 1.9 board
is required. Only the packages MyBB does not ship (symfony/form, symfony/dependency-injection,
symfony/uid and teamtnt/tntsearch, plus what they need) are installed in the plugin's vendor folder.

============================================================================================
Installation:
============================================================================================
If you want sample data installed, upload all files. 
If you do not want sample data installed, upload all files except for 
<mybb_root>\MyShowcase\sample_data.php

Once uploaded, enter ACP > Configuration > Plugins  and Activate & Install MyShowcase System

Then browse to ACP > Users and Groups > Admin Permissions and select the administrators you
want to have access to the MyShowcase System, click the MyShowcase tab and assign the
permissions you want (recommended all of them)

Browse to ACP > MyShowcase Admin > Help Page for detailed instructions and notes.

============================================================================================
Upgrading earlier 3.0 development installs:
============================================================================================
This applies to a board that ran a 3.0 development build of the plugin (field sets were
renamed to collections during 3.0 development). Back up your database first, then:
1) Upload the files
2) Run composer install in <mybb_root>/inc/plugins/myshowcase (see Requirements above)
3) Deactivate and activate the plugin in ACP > Configuration > Plugins

When you activate the plugin it updates the database by itself:
- Field sets become collections. The table myshowcase_fieldsets is renamed to
  myshowcase_collections, and the columns set_id / setid / field_set_id / fieldsetid become
  collection_id (in myshowcase_collections, myshowcase_config, myshowcase_fields and
  myshowcase_field_data) and set_name / setname becomes name. Existing rows and their ids are kept,
  and activating again changes nothing. If both myshowcase_fieldsets and myshowcase_collections
  already contain rows, nothing is migrated and the plugin shows a message: merge the two tables
  by hand and activate again.
- Unique indexes are added where the data allows it (for example one slug per entry). If a
  unique index cannot be created because the table already holds duplicate values, that index is
  skipped and the plugin shows a message with the table, the column and how many values are
  duplicated. Your data is never changed. Remove the duplicates, then activate the plugin
  again to create the index.
- MySQL / MariaDB tables that an earlier build created as MyISAM are converted to InnoDB. This
  covers the MyShowcase tables (their names start with your board's table prefix followed by
  myshowcase_, for example mybb_myshowcase_config) and the data table of each showcase
  (myshowcase_data1, myshowcase_data2, ...). Tables of MyBB itself are never touched, and tables
  that are already InnoDB are skipped, so activating again does nothing more.
  * The conversion only runs on MySQL 5.7.7 or higher and MariaDB 10.2.2 or higher. Older
    servers limit an InnoDB index key to 767 bytes, which wide text fields in your showcases can
    exceed. The plugin asks the database server for its version when you activate it.
  * On an older server nothing is converted. The tables stay MyISAM and the plugin shows a
    warning in the ACP that names the server version and says the tables were left as they are.
    The plugin still activates and works. If the version cannot be read, the same kind of warning
    is shown.
  * If one table cannot be converted, the plugin shows a warning that names the table and the
    database error, leaves that table as it is, and carries on with the others.
  * Converting is slow on a large board: the database rebuilds each table, and while it does,
    that table is locked, so visitors cannot use the showcases. Activate the plugin during a quiet
    period and make a backup of your database first.
  * PostgreSQL and SQLite boards are not affected.
- Indexes the database refuses. When you activate the plugin it creates the indexes of its
  tables, including one index for each required field of a showcase. If the database refuses
  one of them, for example because the field is a very long text or the index key is too long
  for the table's storage engine, the plugin skips that index and shows a warning in the ACP.
  The warning names the table, the index, the column(s) and the message the database gave. The
  plugin still activates, all the other indexes are created, and your data is never changed.
  * The skipped index is tried again every time you activate the plugin, so nothing is stored
    and nothing has to be reset. Deactivate and activate the plugin in ACP > Configuration >
    Plugins once the cause is fixed.
  * To fix the cause for a required field, open the showcase's fields in the ACP and give the
    field a shorter Maximum Length, or change it to a different field type (a long text field
    cannot be indexed on MySQL). Then activate the plugin again.
  * Servers older than MySQL 5.7.7 and MariaDB 10.2.2 are the ones most likely to refuse an
    index, because of the 767 byte limit above.
- The table myshowcase_moderators gets five new columns: entries_can_view, can_manage_comments,
  can_manage_attachments, can_manage_reports and can_manage_logs. They store the permissions you
  give to a moderator in ACP > MyShowcase Admin (edit a showcase, Moderators tab). They are added
  by the same activation step that adds any other missing column, existing moderators keep all
  their rows and start with every permission off (0), and the plugin cache of moderators is
  rebuilt at the end of the activation.

============================================================================================
Upgrading:
============================================================================================
A note on upgrading: When you upload all the new files, you need to be sure to upload the 
latest showcase.php to the location you have it existing as well as rename it for any additional
showcases you have created or customized.

To v2.5.2 from any version >= 2.0.3
1) Deactivate plugin
2) Delete old files if they exist: <MYBB_ROOT>/inc/plugins/MyShowcase/MyShowcase_*.php
3) Upload files
4) Activate plugin
5) Run Find Updated Templates to check for templates that may need updating
6) For each Showcase in MyShowcase Admin on the Summery menu, click Options > Show SEO and 
   update your .htaccess with the new codes. There is a new SEO friendly URL to add.

To v2.5.0 from any version >= 2.0.3
1) Deactivate plugin
2) Delete old files if they exist: <MYBB_ROOT>/inc/plugins/MyShowcase/MyShowcase_*.php
3) Upload files
4) Activate plugin
5) Run Find Updated Templates to check for templates that may need updating

To v2.4.0 from any version >= 2.0.3
1) Deactivate plugin
2) Delete old files if they exist: <MYBB_ROOT>/inc/plugins/MyShowcase/MyShowcase_*.php
3) Upload files
4) Activate plugin
5) Run Find Updated Templates to check for templates that may need updating

To v2.3.0 from any version >= 2.0.3
1) Deactivate plugin
2) Delete old files: <MYBB_ROOT>/inc/plugins/MyShowcase/MyShowcase_*.php
3) Upload files
4) Activate plugin

To v2.2.3 from any version >= 2.0.3
1) Deactivate plugin
2) Upload files
3) Activate plugin

v2.0.3
1) Deactivate plugin
2) Upload files
3) Activate plugin
4) Update template at Default Templates > Myshowcase Templates > myshowcase_field_db
   and repalce contents with
   
<select name="{$showcase_field_name}" {$showcase_field_enabled} style="width: 100px">
    {$showcase_field_options}
</select>   

v2.0.2
skipped

v2.0.1
No upgrade required, as this is the initial public release


============================================================================================
Change Log
============================================================================================
v2.5.2
- fixed issue with creating indexes on text type fields when creating showcase table
- fixed issue with some button not displaying when the showcase file is in MyBB root
- fixed permissions based on additional user groups
- fixed issue with moderator group selections where autocomplete is used and newer MyBB
  installs append "(Usergroup X)" text to the group name which was breaking a query

v.2.5.1
- revised attachment displays
- better support for no FancyBox or no JS
- optimized image output 
- output images via PHP and not as .attach files which some servers don't support

v2.5.0
- attachment display template changes 

v2.4.0
- fixed upgrade code that was setting some templates as empty
- added verification of versions between script file and the overall system version
- updated help page
- changed attachment view code to support no Javascript or no FancyBox or server
  configurations that can not open ".attach" filenames directly.
- cleaned up and improved code for displaying non-image attachments
- non-image attachments display now show inline if supported by browser, otherwise download
- cleaned up report form to use MyBB style popup window
- added watermark support for image attachments
- cleaned up and redesinged attachment templates for new/edit pages
- fixed bug that would not delete an attachment fully
- fixed bug introduced in 2.3.0 that double escaped text and broke mulitple line support
- modified upgrade code to support "Find Updated Templates" for MyShowcase templates


v2.3.0
- changed headers from PavementSucks.com to CommunityPlugins.com
- corrected myshowcase_view_admin template to include post_key input
- more security updates
- simplified names of plugin files
- made 1.6.5 compatible
- moved FancyBox code to separate template to avoid loading on all showcase pages
- added support for "date" fields, with dropdowns for M, D, Y
- added support for URL fields
- added search_field term highlighting
- updated help page

v2.2.1
- fixed bug with sample data not matching mew schema
- fixed bug with install/upgrade field name

v2.2.0
- reviewed code with emphasis on security, cleaned up a few potential issues

- added FancyBox support for image attachments
- added pruning support and related task file/entry
- added notification of comments, based on user cp allow notices setting
- added support for smilies, BBCode and HTML in fields (per field, per showcase)
- added support for admin setting error where field not is_enabled but set to required
- added support for auto-correcting supplied field names to make them valid for SQL
- added support for formatting numeric fields

- updated help doc

- optimized some queries to remove one large one and reuse another
- optimized queries for reports from multiple showcases 

- fixed non-image attachments, uses attach type icon and direct download when not an image
- fixed issue with using attachment instead of default image for non-images
- increased size of comment field in table to TEXT from VARCHAR(200)
- fixed bug where deleteing an entry in one showcase entry remove attachments in other showcases with same id
- fixed bug in report notice showing emtpy text and wrong link
- fixed issue regarding displaying entries from guests (if guest entries are allowed)
- fixed missing message for no entries/results


v2.1.0
- Added Report to Moderators feature
- Fixed bad template for random poartal block
- Added missing templates
- Added new tempaltes for report system
- Updated files broken by corrupted SVN
- Moved some code to separate files for performance/ease of edit

v2.0.4
- Fixed mssing default values for db and radio type fields
- Fixed disabled fields to keep them from showing on entry edit

v2.0.3
- Fixed issue with handling of disabled fields
- Fixed handling of changing field names for db and radio type field options
- Corrected tempalte width issue for HTML select type form objects

v2.0.1
Initial public release

