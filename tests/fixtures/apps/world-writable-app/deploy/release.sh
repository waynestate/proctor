#!/bin/sh
# chmod 777 storage is what we never do
chmod -R 777 storage
chmod 1777 /tmp/scratch
chmod 755 bootstrap/cache && chmod 0777 bootstrap/cache
