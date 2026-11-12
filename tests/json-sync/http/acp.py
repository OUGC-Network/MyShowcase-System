"""Minimal ACP client: cookie jar, admin login, real post keys scraped from the pages."""
import re, sys, urllib.request, urllib.parse, http.cookiejar, json

class Acp:
    def __init__(self, base, user, password):
        self.base = base.rstrip('/')
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.post_key = None
        r = self.post('/admin/index.php', {'username': user, 'password': password, 'do': 'login'}, raw=True)
        self.get('/admin/index.php?module=home-dashboard')
        if not self.post_key:
            raise RuntimeError('login failed: ' + r['body'][:300])

    def _do(self, req):
        try:
            resp = self.op.open(req)
        except urllib.error.HTTPError as e:
            resp = e
        body = resp.read().decode('utf-8', 'replace')
        loc = resp.headers.get('Location')
        m = re.search(r'my_post_key["\' ]*(?:value=|=)["\']?([0-9a-f]{32})', body) or re.search(r'var my_post_key = "([0-9a-f]{32})"', body)
        if m: self.post_key = m.group(1)
        return {'status': resp.status if hasattr(resp, 'status') else resp.code, 'body': body, 'location': loc, 'headers': resp.headers}

    def get(self, path, follow=True):
        r = self._do(urllib.request.Request(self.base + path))
        n = 0
        while follow and r['location'] and n < 5:
            loc = r['location']; n += 1
            r = self._do(urllib.request.Request(loc if loc.startswith('http') else self.base + '/admin/' + loc.lstrip('/')))
        return r

    def post(self, path, data, raw=False, follow=True, key=True):
        data = dict(data)
        if key and 'my_post_key' not in data and self.post_key: data['my_post_key'] = self.post_key
        enc = urllib.parse.urlencode(data, doseq=True).encode()
        r = self._do(urllib.request.Request(self.base + path, data=enc, method='POST'))
        n = 0
        while follow and not raw and r['location'] and n < 5:
            loc = r['location']; n += 1
            r = self._do(urllib.request.Request(loc if loc.startswith('http') else self.base + '/admin/' + loc.lstrip('/')))
        return r

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

def text(html):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html))
