FROM php:8.0.28-apache

RUN a2enmod rewrite

ENV DEBIAN_FRONTEND noninteractive
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \  
    unzip \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmcrypt-dev \
    libpng-dev \
    libjpeg-dev \
    libmemcached-dev \
    zlib1g-dev \
    imagemagick \
    libmagickwand-dev \
    wget \
    ghostscript \
    cron \
    ffmpeg \
    rsync \
    dos2unix \
    net-tools \
    rsyslog \
    mailutils \
    && rm -rf /var/lib/apt/lists/*

# Install the PHP extensions we need
RUN docker-php-ext-install -j$(nproc) iconv pdo pdo_mysql mysqli

# GD
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j "$(nproc)" gd

WORKDIR /var/www/html/  

RUN usermod -u 1000 www-data
RUN wget --no-verbose "https://github.com/omeka/omeka-s/releases/download/v3.2.3/omeka-s-3.2.3.zip" -O /var/www/omeka-s.zip
RUN unzip -q /var/www/omeka-s.zip -d /var/www/ \
&&  rm /var/www/omeka-s.zip \
&&  rm -rf /var/www/html/ \
&&  mv /var/www/omeka-s /var/www/html/ \
&&  chown -R www-data:www-data /var/www/html/

# Content
COPY .htaccess /var/www/html/
COPY robots.txt /var/www/html/
COPY themes /var/www/html/themes
COPY modules /var/www/html/modules

# Cron
COPY import-cron /etc/cron.d/import-cron
RUN chmod 0744 /etc/cron.d/import-cron

RUN touch /var/log/cron.log

# PHP settings
COPY extra.ini /usr/local/etc/php/conf.d/

# ImageMagick settings - flexible approach for different versions
RUN find /etc -name policy.xml -exec sed -i 's/^.*policy.*coder.*none.*PDF.*//' {} \;

# Mail config
COPY update-exim4.conf.conf /etc/exim4/update-exim4.conf.conf
RUN chmod -R 775 /etc/exim4/
RUN update-exim4.conf

CMD ["apache2-foreground"]

