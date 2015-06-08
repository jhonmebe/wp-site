#!/bin/bash
sudo chown -R www-data:www-data /application/apache_home/
sudo chmod -R 744 /application/apache_home/
service apache2 restart > /var/log/restartapache.out 2>&1
