1. git clone this repo and cd into it and composer install
2. sudo pip3 install srt webvtt-py
3. set proper configuration values in config/services.yaml
4. start the app `symfony server:start`
    alternatively you could wire this up in apache like
    ```
     Alias "/transcribe" "/var/www/html/AwsTranscribe/public"
     <Directory "/var/www/html/AwsTranscribe/public">
        FallbackResource /transcribe/index.php
        Require all granted
        DirectoryIndex index.php
        SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
     </Directory>
    ```
5. set aws credentials in `~/.aws/credentials`
6. add blueprint.xml to `/opt/karaf/deploy` - see `ca.islandora.alpaca.connector.awstranscribe.blueprint.xml`
7. make the var directory writable by all
8. install correlating drupal module `asu_derivatives`

