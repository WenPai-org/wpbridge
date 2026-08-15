#!/usr/bin/env python3
import json
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def send(self, status, payload, content_type='application/json'):
        body = payload if isinstance(payload, bytes) else json.dumps(payload).encode()
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if '/timeout/' in self.path or self.path.startswith('/timeout'):
            time.sleep(3)
            self.send(200, {'status': 'ok'})
        elif '/non-json/' in self.path or self.path.startswith('/non-json'):
            self.send(200, b'not-json', 'text/plain')
        elif '/401/' in self.path or self.path.startswith('/401'):
            self.send(401, {'message': 'unauthorized'})
        elif '/403/' in self.path or self.path.startswith('/403'):
            self.send(403, {'message': 'forbidden'})
        elif self.path == '/health':
            self.send(200, {'status': 'ok'})
        elif self.path.startswith('/api/v1/plugin/'):
            slug = self.path.rsplit('/', 1)[-1]
            status = {'unauthorized': 401, 'forbidden': 403}.get(slug)
            if status:
                self.send(status, {'message': slug})
            elif slug == 'non-json':
                self.send(200, b'not-json', 'text/plain')
            elif slug == 'timeout':
                time.sleep(3)
                self.send(200, {'slug': slug})
            else:
                self.send(200, {'slug': slug, 'version': '2.0.0'})
        elif self.path.endswith('/wp-json/bridge/v1/sources'):
            self.send(200, [{'slug': 'mock-plugin', 'item_type': 'plugin', 'version': '2.0.0'}])
        else:
            self.send(404, {'message': 'missing'})


port = int(sys.argv[1]) if len(sys.argv) > 1 else 18765
ThreadingHTTPServer(('127.0.0.1', port), Handler).serve_forever()
