# Links of a showcase: the URL option

Each showcase has an option, **Links** in the ACP General tab, that decides how the links the plugin generates for it
are written. It is stored as `url_mode` on the showcase and has four choices:

| Value | Choice | Links look like | Server needs |
|---|---|---|---|
| 0 | Path | `https://example.com/forum/showcase.php/blog/view/…` | PATH_INFO support (a plain nginx `location ~ \.php$` block does not route it) |
| 1 | Automatic (the default) | friendly links when the board allows them, otherwise the query string form (see below) | nothing, unless you want friendly links |
| 2 | Friendly | `https://example.com/forum/blog/view/…` | the rewrite rule (see the server rules below) |
| 3 | Query string | `https://example.com/forum/showcase.php?route_identifier=blog&route=view/…` | nothing |

The query string form names the route in two parameters: `route_identifier` is the showcase's route identifier, and `route`
is the rest of the route (the path after the identifier). Other query parameters of the route follow them, for example
`&page=2`. A complete example:

    https://example.com/forum/showcase.php?route_identifier=blog&route=view/k3x9a2b7qz/my-first-post&page=2

The option only changes the links the plugin **generates** (list, entry, sort, pagination, search, comment, who's
online, the form actions and the file links of an export). Every form is always routed and answers the same page, whatever
the option is set to. So a link that was saved or bookmarked in another form keeps working.

The plugin never writes server configuration. The friendly form needs a rewrite rule on the server, given below. If you
choose Friendly (or Automatic with friendly links) without the rule, the links are broken until the rule is in place. The
path form needs a server that passes PATH_INFO to PHP; the query string form needs nothing and works everywhere.

## One builder for every link

Every link the plugin generates is built by one function, `Routing::url()`, which writes the form of the showcase's setting
for the request: the base followed by the route's path (path and friendly forms), or the plain base with the
`route_identifier` and `route` parameters (query string form). That covers the list, entry, comment, attachment, export,
import, search, sort, pagination, form action, canonical and who's-online links, so the option changes all of them
together. The templates do not add a prefix of their own. A check in the plugin's test suite
(`tests/url-builder/guard_test.py`) fails when a link is built anywhere else.

## The router reads every form

Each route is routed in every form, whatever the showcase's setting. A request with `route_identifier` and `route`
parameters is routed as the path `/<route_identifier>/<route>`; any other request is routed by its path. The minimal-load
routes (attachments, thumbnails and uploads) are recognised in the query string form too.

## Export file URLs

The file URLs an export writes (the attachments of an entry) use the **showcase's own form**: the form its pages use under its
setting, so a path showcase writes path links, a friendly one friendly links, and a query string one query string links. An
export built outside a web request (a command-line task, which carries no request markers) writes the query string form,
because Automatic cannot tell there that the server rewrites its addresses. Such a file works on any board.

## Own-board file URLs

An import recognises a file URL that points at its own board's attachment (the board's own address, decisions D52, D58),
in **all three** forms: `https://example.com/forum/showcase.php/blog/attachment/…`,
`https://example.com/forum/blog/attachment/…` and
`https://example.com/forum/showcase.php?route_identifier=blog&route=attachment/…`. An export written earlier in the path
form still imports. The option does not change what an import accepts.

## The showcase URL is absolute

Every URL the plugin's pages emit is absolute (decision D244): the links, the forms, the scripts, the images, the canonical
and the meta links. The showcase URL (**Url to Showcase**, in the ACP General tab) decides the base of the showcase's own
links, and it is resolved like this:

* a value with a scheme and a host (`https://example.com/forum/showcase.php`) is used as it is;
* a value that starts with `/` (`/forum/showcase.php`) is resolved against the origin of the board address;
* any other value (`showcase.php`, `forum/showcase.php`) is resolved against the board address;
* an empty value is the board address followed by `/showcase.php`.

The board address is the board's own address (`bburl`). A board in a subfolder keeps the folder in every link, image and
script, and a trailing slash in the board address does not produce a doubled slash. The profile, attachment and asset
links are based on the board address; a showcase's own pages are based on the showcase URL.

## Where the base comes from

Every link of a showcase is its **base** followed by the route path (or, for the query string form, the parameters above):

* The base is the showcase's **Url to Showcase** when it is set, otherwise the board address followed by
  `/showcase.php`. A value that does not end in `showcase.php` (a custom prefix) gives the same links in every mode.
* With a friendly form, the trailing `showcase.php` is removed from the base. The route path is unchanged.
* The query string form keeps `showcase.php` in the base and puts the route in the parameters.

## How Automatic decides

Automatic friendly links need **two** things, so a board that has not set up the rewrite never gets broken links:

* core's rule, which MyBB applies to its own links (`inc/init.php`): the board's **SEO URLs** setting (`seourls`) is
  `yes`, or it is `auto` and the request carries `SEO_SUPPORT` set to `1` (compared loosely, so `true` and `"1"` count);
* the plugin's marker: the request carries `SHOWCASE_SEO_SUPPORT` set to `1` (the same loose comparison). The rewrite
  snippets below set it.

When both hold, the links are friendly. Otherwise they are in the **query string** form, which needs nothing on the server.
Without the marker, Automatic does not write path links: it writes the query string form, whatever core's rule says.

Mode 0 is always the path form, mode 2 is always friendly and mode 3 is always the query string form, whatever the board and
the request say. Friendly requires the rewrite rule to be in place; Automatic needs the rewrite rule and the marker to
write friendly links.

### What SEO_SUPPORT means

`SEO_SUPPORT` is a value in the server environment of the request, not a board setting. MyBB reads it to know that the
server rewrites the board's addresses (so that a link without `index.php` or `showcase.php` reaches the board). The
server sets it in the rewrite configuration of the board, as shown below. Without it, `auto` gives plain links.

`SHOWCASE_SEO_SUPPORT` is the plugin's own marker, read from the same request. It says that the rewrite rule of the
showcases is in place, so Automatic may use friendly links. Setting it is what the rewrite snippets below do, next to
their rules.

## Server rules

The routes a rewrite must send to `showcase.php` are the route identifiers of your showcases (for example `blog`,
`gallery`, `downloads`) followed by any path. Replace `forum` with the board's folder (or drop it for a board at the
root), and list your own route identifiers in place of the example ones. The rules are needed only for the friendly form
and for Automatic's friendly links; the query string form works without them.

### Apache (`.htaccess` in the board folder)

```apache
RewriteEngine On
RewriteBase /forum/
SetEnv SEO_SUPPORT 1
SetEnv SHOWCASE_SEO_SUPPORT 1

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
    fastcgi_param SHOWCASE_SEO_SUPPORT 1;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

Adjust the PHP socket to your PHP-FPM setup. Setting `SEO_SUPPORT` and `SHOWCASE_SEO_SUPPORT` only where the rewrite is
active keeps Automatic honest: a board whose server cannot rewrite should send neither. A server that does not pass
PATH_INFO (the common `location ~ \.php$` block) cannot use the path form; use the query string form there.

## Checking it

1. Set the showcase to Query string and open its list page: the links must have `showcase.php?route_identifier=` in them, and
   they must answer on a server without any rewrite.
2. Set it to Friendly and open its list page: the links must have no `showcase.php`. Open a friendly address directly, for
   example `/blog/view/<public id>/<slug>`: it must show the same entry as `/showcase.php/blog/view/<public id>/<slug>`.
3. Set the option back to Path: the links return to the `showcase.php/…` form, and every form still answers.
