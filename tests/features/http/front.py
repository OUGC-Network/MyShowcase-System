"""Forum-side HTTP client: a cookie jar per user, MyBB login, post keys from the pages, multipart uploads."""
import http.cookiejar, mimetypes, os, re, secrets, urllib.error, urllib.parse, urllib.request


class Front:
    def __init__(self, base):
        self.base = base.rstrip('/')
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def _do(self, req):
        try:
            resp = self.op.open(req, timeout=120)
            status = resp.status
        except urllib.error.HTTPError as e:
            resp, status = e, e.code
        raw = resp.read()
        return {'status': status, 'raw': raw, 'body': raw.decode('utf-8', 'replace'), 'type': resp.headers.get('Content-Type', ''),
                'url': resp.geturl() if hasattr(resp, 'geturl') else ''}

    def url(self, path):
        return path if path.startswith('http') else self.base + path

    def get(self, path):
        return self._do(urllib.request.Request(self.url(path)))

    def post(self, path, data):
        return self._do(urllib.request.Request(self.url(path), data=urllib.parse.urlencode(data, doseq=True).encode(), method='POST'))

    def upload(self, path, data, field, file_path):
        boundary = '----g2' + secrets.token_hex(8)
        parts = []
        for k, v in data.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        mime = mimetypes.guess_type(file_path)[0] or 'application/octet-stream'
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{os.path.basename(file_path)}"\r\n'
                     f'Content-Type: {mime}\r\n\r\n'.encode() + open(file_path, 'rb').read() + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        req = urllib.request.Request(self.url(path), data=b''.join(parts), method='POST')
        req.add_header('Content-Type', f'multipart/form-data; boundary={boundary}')
        return self._do(req)

    def login(self, username, password):
        r = self.get('/member.php?action=login')
        r = self.post('/member.php', {'action': 'do_login', 'username': username, 'password': password, 'my_post_key': post_key(r['body']),
                                      'url': self.base + '/index.php'})
        return r


def post_key(html):
    m = re.search(r'name="my_post_key"\s+value="([a-f0-9]{32})"', html) or re.search(r'my_post_key\s*=\s*"([a-f0-9]{32})"', html)
    return m.group(1) if m else ''


def no_permission(r):
    return 'do not have permission' in r['body']


def bad_post_key(r):
    return 'Authorization code mismatch' in r['body']


def php_errors(r):
    m = re.search(r'(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|MyBB has experienced an internal error|SQL Error|Board Message)', r['body'])
    return m.group(1) if m else None
