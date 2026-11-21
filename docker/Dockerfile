# Evidencia receptov — obraz na LOKÁLNE spustenie (docker compose up).
#
# Kód appky v obraze nie je: docker-compose.yml pripojí priečinok projektu do
# /var/www/html, takže zmena v súbore je hneď vidno v prehliadači (stačí obnoviť
# stránku, pri CSS/JS pozor na cache service workera).
#
# Apache (nie vstavaný server PHP), aby platili .htaccess ako na hostingu —
# includes/.env a kucharky/ nie sú dostupné z prehliadača.

FROM php:8.4-apache

# Rozšírenia PHP: tie, ktoré kontroluje install.php a testy, a tie, ktoré
# vyžadujú knižnice vo vendor/ (biometria: gmp, bcmath, intl).
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mysqli gd intl gmp bcmath \
 && apt-get update \
 && apt-get install -y --no-install-recommends git curl unzip \
 && rm -rf /var/lib/apt/lists/*

# Composer stiahne vendor/ pri prvom štarte (do zvláštneho zväzku, nie do projektu).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY docker/php.ini /usr/local/etc/php/conf.d/recepty.ini
COPY docker/apache.conf /etc/apache2/conf-available/recepty.conf
RUN a2enmod rewrite headers && a2enconf recepty

COPY docker/entrypoint.sh /usr/local/bin/recepty-entrypoint
# Na Windows môže git uložiť skript s koncami riadkov CRLF — tie tu odstránime.
RUN sed -i 's/\r$//' /usr/local/bin/recepty-entrypoint && chmod +x /usr/local/bin/recepty-entrypoint \
 && git config --system --add safe.directory /var/www/html

WORKDIR /var/www/html
ENTRYPOINT ["recepty-entrypoint"]
CMD ["apache2-foreground"]
