#!/usr/bin/env python3
"""
  arclamp-generate-index.py
  ~~~~~~~~~

  Writes a JSON index of SVGs stored within Swift.

"""

from tempfile import NamedTemporaryFile
import os
import shutil
import logging
from swiftclient.service import SwiftService, SwiftError
import json

class Output(object):
    def __enter__(self):
        self.out = NamedTemporaryFile(prefix='arclamp-index-', suffix='.json')
        self.first = True
        return self

    def __exit__(self, type, value, tb):
        self.out.close()

    def append(self, x):
        if self.first:
            self.out.write('[')
            self.first = False
        else:
            self.out.write(',')
        self.out.write(json.dumps(x))

    def done(self):
        self.out.write(']')
        self.out.flush()
        shutil.copy(self.out.name, '/srv/arclamp/index.json')
        os.chmod('/srv/arclamp/index.json', 0o644)

logging.basicConfig(level=logging.ERROR)
logging.getLogger("requests").setLevel(logging.CRITICAL)
logging.getLogger("swiftclient").setLevel(logging.CRITICAL)
logger = logging.getLogger(__name__)

with SwiftService() as swift:
    with Output() as out:
        try:
            for container in ['arclamp-svgs-daily', 'arclamp-svgs-hourly']:
                for page in swift.list(container=container):
                    if page["success"]:
                        for item in page["listing"]:
                            out.append(item)
                    else:
                        raise page["error"]
            out.done()
        except SwiftError as e:
            logger.error(e.value)
