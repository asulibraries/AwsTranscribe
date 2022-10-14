1. git clone this repo
2. cd into it and composer install
3. sudo pip3 install srt webvtt-py
4. set proper configuration values in config/services.yaml - this will include the s3Bucket(s) you're reading from as well as the fedora root
5. start the app `symfony server:start`
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
6. set aws credentials in `~/.aws/credentials` under the profile `transcribe` - note that the iam user must have access to all actions in Aws Transcribe as well as read from the s3 bucket you've configured for the s3Bucket(s) parameter
7. add blueprint.xml to `/opt/karaf/deploy` - see `ca.islandora.alpaca.connector.awstranscribe.blueprint.xml`
8. make the var directory writable by all
9. install correlating drupal module `asu_derivatives`

## Development

Use [PHP CS Fixer](https://cs.symfony.com/) for checking code style.

Currently, this service only works for staging and production environments because it relies on the source files living in an S3 bucket storing Fedora (5) binaries. This will eventually need to be updated for Fedora 6 (OCFL instead of Modeshape) and, possibly, a temporary S3 storage space for local testing.