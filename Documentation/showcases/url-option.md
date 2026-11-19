# Links of a showcase: the URL option

Each showcase has an option, **Links** in the ACP General tab, that decides how the links the plugin generates for it
are written. It has three choices, stored as `url_mode` on the showcase:

| Value | Choice | Links look like |
|---|---|---|
| 0 | No | `https://example.com/forum/showcase.php/blog/view/…` |
| 1 | Automatic (the default) | as the board decides (see below) |
| 2 | Yes | `https://example.com/forum/blog/view/…` |

The option only changes the links the plugin **generates** (list, entry, sort, pagination, search, comment, who's
online and the form actions). Both forms are always routed and answer the same page, whatever the option is set to.
So a link that was saved or bookmarked in the other form keeps working.

The plugin never writes server configuration. The friendly form needs a rewrite rule on the server, given below. If you
choose Yes (or Automatic with friendly links) without the rule, the links are broken until the rule is in place.

## One builder for every link

Every link the plugin generates is built by one function, `Routing::url()`: the base followed by the route's path. That
covers the list, entry, comment, attachment, export, import, search, sort, pagination, form action, canonical and
who's-online links, so the option changes all of them together. The templates do not add a prefix of their own. A check
in the plugin's test suite (`tests/url-builder/guard_test.py`) fails when a link is built anywhere else.

## Files are always plain

The file URLs an export writes (the attachments of an entry) are always the **plain** form, whatever the option is set
to, so a file written by a showcase set to Yes still works on a board without the rewrite rule. Only the links shown to
people follow the option.

## Own-board file URLs

An import recognises a file URL that points at its own board's attachment (the board's own address, decisions D52, D58),
in **both** forms: `https://example.com/forum/showcase.php/blog/attachment/…` and
`https://example.com/forum/blog/attachment/…`. The option does not change what an import accepts.

## Where the base comes from

Every link of a showcase is its **base** followed by the route path:

* The base is the showcase's **Url to Showcase** when it is set, otherwise the board address followed by
  `/showcase.php`. A value that does not end in `showcase.php` (a custom prefix) gives the same links in every mode.
* With a friendly form, the trailing `showcase.php` is removed from the base. The route path is unchanged.

## How Automatic decides

Automatic follows MyBB core's own rule for its links (`inc/init.php`), so the plugin and the rest of the board agree:

* the board's **SEO URLs** setting (`seourls`) is `yes`: friendly links;
* it is `auto` and the request carries `SEO_SUPPORT` set to `1` (core compares it loosely, so `true` and `"1"` count):
  friendly links;
* otherwise: plain links.

Mode 0 is always plain and mode 2 is always friendly, whatever the board and the request say.

### What SEO_SUPPORT means

`SEO_SUPPORT` is a value in the server environment of the request, not a board setting. MyBB reads it to know that the
server rewrites the board's addresses (so that a link without `index.php` or `showcase.php` reaches the board). The
server sets it in the rewrite configuration of the board, as shown below. Without it, `auto` gives plain links.

## Server rules

The routes a rewrite must send to `showcase.php` are the route identifiers of your showcases (for example `blog`,
`gallery`, `downloads`) followed by any path. Replace `forum` with the board's folder (or drop it for a board at the
root), and list your own route identifiers in place of the example ones.

### Apache (`.htaccess` in the board folder)

```apache
RewriteEngine On
RewriteBase /forum/
SetEnv SEO_SUPPORT 1

# a real file or folder is served as it is
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# /blog/…, /gallery/…, /downloads/… go to showcase.php with the same path
RewriteRule ^(blog|gallery|downloads)(/.*)?$ showcase.php/$1$2 [L,QSA]
```

### nginx (in the `server` block of the board)

```nginx
location ~ ^/forum/(blog|gallery|downloads)(/.*)?$ {
    rewrite ^/forum/(.*)$ /forum/showcase.php/$1 last;
}

location ~ ^/forum/showcase\.php(/.*)?$ {
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    fastcgi_param SEO_SUPPORT 1;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

Adjust the PHP socket to your PHP-FPM setup. Setting `SEO_SUPPORT` only where the rewrite is active keeps Automatic
honest: a board whose server cannot rewrite should not send it.

## Checking it

1. Set the showcase to Yes, open its list page and look at the links: they must have no `showcase.php`.
2. Open a friendly address directly, for example `/blog/view/<public id>/<slug>`: it must show the same entry as
   `/showcase.php/blog/view/<public id>/<slug>`.
3. Set the option back to No: the links return to the `showcase.php` form, and both addresses still answer.
