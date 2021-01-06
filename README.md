1. composer install
2. start the app `symfony server:start`
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
3. set aws credentials in `~/.aws/credentials`
4. add blueprint.xml to `/opt/karaf/deploy` - see `ca.islandora.alpaca.connector.awstranscribe.blueprint.xml`
5. make the var directory writable by all
6. install correlating drupal module `asu_derivatives`

